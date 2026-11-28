<?php
declare(strict_types=1);

namespace App\Support;

use PDO;

/**
 * 轻量级结构自愈：老版本安装升级代码后，补齐新增表与新增列。
 *
 * 所有语句都是幂等的，并且只在版本标记变化后的首次数据库连接时执行。
 * 禁止在事务中调用（MySQL 的 DDL 会隐式提交事务）。
 */
final class Schema
{
    /** 结构版本：新增表或列时递增，触发已安装站点自愈。 */
    private const VERSION = '2026-11-28.1';

    private static bool $done = false;

    public static function ensure(PDO $pdo): void
    {
        if (self::$done) {
            return;
        }
        self::$done = true;

        $marker = APP_ROOT . '/uploads/config/schema.version';
        if (is_file($marker) && trim((string) @file_get_contents($marker)) === self::VERSION) {
            return;
        }

        $ok = self::createTables($pdo);
        $ok = self::addColumns($pdo) && $ok;
        $ok = self::ensureCharset($pdo) && $ok;

        if ($ok) {
            $dir = dirname($marker);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            @file_put_contents($marker, self::VERSION);
        } else {
            Log::write('schema', '结构自愈未完全成功，请检查数据库账号是否具备 CREATE / ALTER 权限');
        }
    }

    /** 按 schema.sql 建表；逐条执行，单条失败不影响其他表。 */
    private static function createTables(PDO $pdo): bool
    {
        $file = APP_ROOT . '/app/Install/schema.sql';
        $sql = @file_get_contents($file);
        if ($sql === false) {
            return true;
        }

        $ok = true;
        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [] as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }
            // 已存在的表不再执行 CREATE，避免无 CREATE 权限的库被误判为升级失败
            $probe = preg_replace('/^\s*(?:--[^\n]*\n\s*)+/', '', $statement) ?? $statement;
            if (preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?/i', $probe, $m)) {
                if (self::tableExists($pdo, $m[1])) {
                    continue;
                }
            }
            try {
                $pdo->exec($statement);
            } catch (\Throwable) {
                $ok = false;
            }
        }
        return $ok;
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
            );
            $stmt->execute([$table]);
            return $stmt->fetchColumn() !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 老版本建表时可能使用 utf8mb3，MySQL 会把 4 字节 emoji 静默存成「?」。
     * 这里把非 utf8mb4 的表统一转为 utf8mb4；转换后不再命中，可安全重复执行。
     */
    private static function ensureCharset(PDO $pdo): bool
    {
        $tables = [
            'users',
            'auth_tokens',
            'sessions',
            'usage_archives',
            'files',
            'session_files',
            'share_links',
            'staging_sessions',
            'staging_files',
        ];
        $ok = true;
        foreach ($tables as $table) {
            try {
                $stmt = $pdo->prepare(
                    'SELECT table_collation FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
                );
                $stmt->execute([$table]);
                $collation = (string) ($stmt->fetchColumn() ?: '');
                if ($collation === '' || str_starts_with($collation, 'utf8mb4')) {
                    continue;
                }
                $pdo->exec(
                    'ALTER TABLE `' . $table . '` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
                );
            } catch (\Throwable) {
                $ok = false;
            }
        }
        return $ok;
    }

    /** 补齐历史上新增的列，避免老库因缺列导致接口 500。 */
    private static function addColumns(PDO $pdo): bool
    {
        $columns = [
            'users' => [
                'role' => "VARCHAR(16) NOT NULL DEFAULT 'user'",
                'status' => 'TINYINT NOT NULL DEFAULT 1',
                'api_key' => 'TEXT NULL',
                'api_key_mask' => 'VARCHAR(32) NULL',
                'created_ms' => 'BIGINT NOT NULL DEFAULT 0',
                'updated_ms' => 'BIGINT NOT NULL DEFAULT 0',
                'last_login_ms' => 'BIGINT NULL',
            ],
            'auth_tokens' => [
                'csrf_token' => "CHAR(64) NOT NULL DEFAULT ''",
                'created_ms' => 'BIGINT NOT NULL DEFAULT 0',
                'expires_ms' => 'BIGINT NOT NULL DEFAULT 0',
                'last_used_ms' => 'BIGINT NULL',
                'ip' => 'VARCHAR(45) NULL',
                'ua' => 'VARCHAR(255) NULL',
            ],
            'sessions' => [
                'title' => "VARCHAR(255) NOT NULL DEFAULT ''",
                'root_message_id' => 'BIGINT UNSIGNED NULL',
                'messages_count' => 'INT NOT NULL DEFAULT 0',
                'input_tokens' => 'BIGINT NOT NULL DEFAULT 0',
                'output_tokens' => 'BIGINT NOT NULL DEFAULT 0',
                'pinned' => 'TINYINT NOT NULL DEFAULT 0',
                'data' => 'LONGTEXT NULL',
                'created_ms' => 'BIGINT NOT NULL DEFAULT 0',
                'updated_ms' => 'BIGINT NOT NULL DEFAULT 0',
            ],
            'files' => [
                'name' => "VARCHAR(255) NOT NULL DEFAULT ''",
                'size' => 'BIGINT NOT NULL DEFAULT 0',
                'is_image' => 'TINYINT NOT NULL DEFAULT 0',
                'mime_type' => "VARCHAR(128) NOT NULL DEFAULT ''",
                'server_id' => 'VARCHAR(128) NULL',
                'image_size_checked' => 'TINYINT NOT NULL DEFAULT 0',
                'created_ms' => 'BIGINT NOT NULL DEFAULT 0',
            ],
            'usage_archives' => [
                'input_tokens' => 'BIGINT NOT NULL DEFAULT 0',
                'output_tokens' => 'BIGINT NOT NULL DEFAULT 0',
                'messages' => 'BIGINT NOT NULL DEFAULT 0',
                'sessions' => 'BIGINT NOT NULL DEFAULT 0',
            ],
            'share_links' => [
                'title' => "VARCHAR(255) NOT NULL DEFAULT ''",
                'data' => 'LONGTEXT NULL',
                'views' => 'INT NOT NULL DEFAULT 0',
                'revoked' => 'TINYINT NOT NULL DEFAULT 0',
                'created_ms' => 'BIGINT NOT NULL DEFAULT 0',
                'expires_ms' => 'BIGINT NULL',
            ],
        ];

        $ok = true;
        foreach ($columns as $table => $defs) {
            try {
                $stmt = $pdo->query('SHOW COLUMNS FROM `' . $table . '`');
                $existing = array_fill_keys(array_map(
                    static fn (array $row): string => (string) $row['Field'],
                    $stmt->fetchAll()
                ), true);
            } catch (\Throwable) {
                // 表不存在时由 createTables 负责，跳过
                $ok = false;
                continue;
            }

            foreach ($defs as $column => $definition) {
                if (isset($existing[$column])) {
                    continue;
                }
                try {
                    $pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
                } catch (\Throwable) {
                    $ok = false;
                }
            }
        }
        return $ok;
    }
}
