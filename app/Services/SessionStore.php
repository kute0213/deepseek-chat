<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\HttpError;
use PDO;

final class SessionStore
{
    private static bool $columnsReady = false;
    private static bool $archiveReady = false;

    /** 已安装站点没有迁移脚本，首次使用时补齐新增列（置顶）。 */
    private static function ensureColumns(): void
    {
        if (self::$columnsReady) {
            return;
        }
        self::$columnsReady = true;
        try {
            $column = Database::pdo()->query("SHOW COLUMNS FROM sessions LIKE 'pinned'")->fetch();
            if ($column === false) {
                Database::pdo()->exec('ALTER TABLE sessions ADD COLUMN pinned TINYINT NOT NULL DEFAULT 0 AFTER output_tokens');
            }
        } catch (\Throwable) {
            // 建表权限不足时保持原有行为：置顶字段缺省为 0
        }
    }

    /**
     * 事务开始前预建归档表。
     * MySQL 的 DDL 会隐式提交当前事务，因此建表必须放在事务之外。
     */
    public static function prepareArchive(): void
    {
        self::ensureArchive();
    }

    /** 已删除会话的用量汇总表：删除会话时把统计值累加进来，保证账号总用量不因删除而减少。 */
    private static function ensureArchive(): void
    {
        if (self::$archiveReady) {
            return;
        }
        if (Database::pdo()->inTransaction()) {
            // 事务内执行 DDL 会隐式提交，交由调用方在事务外预建
            return;
        }
        self::$archiveReady = true;
        try {
            Database::pdo()->exec(
                'CREATE TABLE IF NOT EXISTS usage_archives (
                    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                    input_tokens BIGINT NOT NULL DEFAULT 0,
                    output_tokens BIGINT NOT NULL DEFAULT 0,
                    messages BIGINT NOT NULL DEFAULT 0,
                    sessions BIGINT NOT NULL DEFAULT 0
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (\Throwable) {
            // 无建表权限时忽略，用量退化为仅当前会话
        }
    }

    /** 把单个会话的统计值累加到归档表（删除前调用）。 */
    public static function archiveSession(string $userId, string $sessionId): void
    {
        self::ensureArchive();
        try {
            $pdo = Database::pdo();
            $stmt = $pdo->prepare(
                'SELECT input_tokens, output_tokens, messages_count FROM sessions WHERE user_id = ? AND id = ?'
            );
            $stmt->execute([$userId, $sessionId]);
            $row = $stmt->fetch();
            if ($row === false) {
                return;
            }
            $stmt = $pdo->prepare(
                'INSERT INTO usage_archives (user_id, input_tokens, output_tokens, messages, sessions)
                 VALUES (?, ?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE
                    input_tokens = input_tokens + VALUES(input_tokens),
                    output_tokens = output_tokens + VALUES(output_tokens),
                    messages = messages + VALUES(messages),
                    sessions = sessions + 1'
            );
            $stmt->execute([
                $userId,
                (int) $row['input_tokens'],
                (int) $row['output_tokens'],
                (int) $row['messages_count'],
            ]);
        } catch (\Throwable) {
            // 归档失败不影响删除流程
        }
    }

    /** 导入历史覆盖全部会话前，先把现有统计值归档。 */
    public static function archiveAll(string $userId): void
    {
        self::ensureArchive();
        try {
            $stmt = Database::pdo()->prepare(
                'INSERT INTO usage_archives (user_id, input_tokens, output_tokens, messages, sessions)
                 SELECT user_id, COALESCE(SUM(input_tokens), 0), COALESCE(SUM(output_tokens), 0),
                        COALESCE(SUM(messages_count), 0), COUNT(*)
                 FROM sessions WHERE user_id = ? GROUP BY user_id
                 ON DUPLICATE KEY UPDATE
                    input_tokens = input_tokens + VALUES(input_tokens),
                    output_tokens = output_tokens + VALUES(output_tokens),
                    messages = messages + VALUES(messages),
                    sessions = sessions + VALUES(sessions)'
            );
            $stmt->execute([$userId]);
        } catch (\Throwable) {
            // 归档失败时不影响导入
        }
    }

    /**
     * 按置顶优先、更新时间倒序分页返回会话摘要，配合前端滚动加载，避免一次拉取全部记录。
     *
     * @return array{sessions: array<int, array>, nextCursor: ?string}
     */
    public static function summaries(
        string $userId,
        int $limit = 30,
        ?int $beforeUpdated = null,
        ?string $beforeId = null,
        ?int $beforePinned = null
    ): array {
        self::ensureColumns();
        $limit = max(1, min(100, $limit));
        $sql = 'SELECT id, title, pinned, created_ms, updated_ms FROM sessions WHERE user_id = ?';
        $args = [$userId];
        // 键集分页：用 (pinned, updated_ms, id) 作为游标，避免数据变动时 OFFSET 跳页或重复
        if ($beforeUpdated !== null && $beforeId !== null) {
            $pinned = $beforePinned ?? 0;
            $sql .= ' AND (pinned < ? OR (pinned = ? AND (updated_ms < ? OR (updated_ms = ? AND id < ?))))';
            $args[] = $pinned;
            $args[] = $pinned;
            $args[] = $beforeUpdated;
            $args[] = $beforeUpdated;
            $args[] = $beforeId;
        }
        $sql .= ' ORDER BY pinned DESC, updated_ms DESC, id DESC LIMIT ' . ($limit + 1);
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($args);
        $rows = $stmt->fetchAll();
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }
        $sessions = array_map(static fn (array $row): array => [
            'id' => (string) $row['id'],
            'title' => (string) $row['title'],
            'pinned' => (int) ($row['pinned'] ?? 0) === 1,
            'createdAt' => (int) $row['created_ms'],
            'updatedAt' => (int) $row['updated_ms'],
        ], $rows);
        $nextCursor = null;
        if ($hasMore && $sessions !== []) {
            $last = $sessions[count($sessions) - 1];
            $nextCursor = ($last['pinned'] ? '1' : '0') . '_' . $last['updatedAt'] . '_' . $last['id'];
        }
        return ['sessions' => $sessions, 'nextCursor' => $nextCursor];
    }

    /** 置顶 / 取消置顶：只更新标记，不改动更新时间 */
    public static function setPinned(string $userId, string $sessionId, bool $pinned): void
    {
        self::ensureColumns();
        $stmt = Database::pdo()->prepare('UPDATE sessions SET pinned = ? WHERE user_id = ? AND id = ?');
        $stmt->execute([$pinned ? 1 : 0, $userId, $sessionId]);
    }

    /** 账号 Token 用量：总计与按会话明细（仅已写入的统计值） */
    public static function usage(string $userId): array
    {
        self::ensureColumns();
        self::ensureArchive();
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) AS sessions, COALESCE(SUM(input_tokens), 0) AS input_tokens,
                    COALESCE(SUM(output_tokens), 0) AS output_tokens, COALESCE(SUM(messages_count), 0) AS messages
             FROM sessions WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $totals = $stmt->fetch() ?: [];
        $archived = ['input_tokens' => 0, 'output_tokens' => 0, 'messages' => 0, 'sessions' => 0];
        try {
            $stmt = $pdo->prepare(
                'SELECT input_tokens, output_tokens, messages, sessions FROM usage_archives WHERE user_id = ?'
            );
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            if ($row !== false) {
                $archived = [
                    'input_tokens' => (int) $row['input_tokens'],
                    'output_tokens' => (int) $row['output_tokens'],
                    'messages' => (int) $row['messages'],
                    'sessions' => (int) $row['sessions'],
                ];
            }
        } catch (\Throwable) {
            // 归档表不可用时仅统计当前会话
        }
        $stmt = $pdo->prepare(
            'SELECT id, title, messages_count, input_tokens, output_tokens, updated_ms
             FROM sessions WHERE user_id = ? ORDER BY (input_tokens + output_tokens) DESC LIMIT 50'
        );
        $stmt->execute([$userId]);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = [
                'id' => (string) $row['id'],
                'title' => (string) $row['title'],
                'messages' => (int) $row['messages_count'],
                'inputTokens' => (int) $row['input_tokens'],
                'outputTokens' => (int) $row['output_tokens'],
                'updatedAt' => (int) $row['updated_ms'],
            ];
        }
        $input = (int) ($totals['input_tokens'] ?? 0) + $archived['input_tokens'];
        $output = (int) ($totals['output_tokens'] ?? 0) + $archived['output_tokens'];
        return [
            'inputTokens' => $input,
            'outputTokens' => $output,
            'totalTokens' => $input + $output,
            'sessions' => (int) ($totals['sessions'] ?? 0) + $archived['sessions'],
            'messages' => (int) ($totals['messages'] ?? 0) + $archived['messages'],
            'items' => $items,
        ];
    }

    /**
     * 会话搜索：标题或消息正文命中的会话，按更新时间倒序返回；
     * 命中正文时给出该条消息的 ID 与片段，前端据此跳转到对应位置。
     */
    public static function search(string $userId, string $query, int $limit = 20): array
    {
        $query = trim($query);
        if ($query === '') {
            return ['results' => []];
        }
        $limit = max(1, min(50, $limit));
        $like = '%' . addcslashes($query, '%_\\') . '%';
        $stmt = Database::pdo()->prepare(
            'SELECT id, title, updated_ms, data FROM sessions
             WHERE user_id = ? AND (title LIKE ? OR data LIKE ?)
             ORDER BY updated_ms DESC LIMIT ' . ($limit * 4)
        );
        $stmt->execute([$userId, $like, $like]);
        $results = [];
        foreach ($stmt->fetchAll() as $row) {
            $data = json_decode((string) $row['data'], true);
            $match = is_array($data) ? self::findMatch($data, $query) : null;
            $results[] = [
                'sessionId' => (string) $row['id'],
                'title' => (string) $row['title'],
                'updatedAt' => (int) $row['updated_ms'],
                'messageId' => $match['id'] ?? null,
                'snippet' => $match['snippet'] ?? '',
            ];
            if (count($results) >= $limit) {
                break;
            }
        }
        return ['results' => $results];
    }

    /** 在可见分支中查找第一条命中的消息，返回消息 ID 与上下文片段。 */
    private static function findMatch(array $session, string $query): ?array
    {
        $messages = array_values(array_filter(
            is_array($session['messages'] ?? null) ? $session['messages'] : [],
            static fn ($message): bool => is_array($message)
        ));
        $ordered = self::visiblePath($session, $messages);
        if ($ordered === []) {
            $ordered = $messages;
        }
        foreach ($ordered as $message) {
            $text = self::messageText($message);
            $position = $text === '' ? false : mb_stripos($text, $query, 0, 'UTF-8');
            if ($position === false) {
                continue;
            }
            $start = max(0, $position - 30);
            $snippet = mb_substr($text, $start, mb_strlen($query, 'UTF-8') + 60, 'UTF-8');
            return [
                'id' => (string) ($message['id'] ?? ''),
                'snippet' => ($start > 0 ? '…' : '') . $snippet . '…',
            ];
        }
        return null;
    }

    /** 沿 selectedChildId 还原当前可见分支；线性会话按存储顺序返回。 */
    private static function visiblePath(array $session, array $messages): array
    {
        if (!array_key_exists('rootMessageId', $session)) {
            return $messages;
        }
        $byId = [];
        foreach ($messages as $message) {
            if (isset($message['id'])) {
                $byId[(string) $message['id']] = $message;
            }
        }
        $path = [];
        $id = $session['rootMessageId'];
        while (is_string($id) && $id !== '' && isset($byId[$id])) {
            $message = $byId[$id];
            unset($byId[$id]);
            $path[] = $message;
            $next = $message['selectedChildId'] ?? null;
            $id = is_string($next) && $next !== '' ? $next : null;
        }
        return $path;
    }

    private static function messageText(array $message): string
    {
        if (($message['role'] ?? '') === 'user') {
            return (string) ($message['content'] ?? '');
        }
        $parts = [];
        foreach (is_array($message['blocks'] ?? null) ? $message['blocks'] : [] as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text' && isset($block['text'])) {
                $parts[] = (string) $block['text'];
            }
        }
        return implode("\n", $parts);
    }

    public static function load(string $userId, string $sessionId): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT data FROM sessions WHERE user_id = ? AND id = ?');
        $stmt->execute([$userId, $sessionId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $data = json_decode((string) $row['data'], true);
        return is_array($data) ? $data : null;
    }

    /**
     * 消息分页：不传 $before 返回最近 $limit 条，传 $before 返回它之前的 $limit 条。
     * 仍有更早消息时，把窗口首条消息作为临时根节点，保证前端能沿 selectedChildId 渲染。
     *
     * @return array{session: array, hasMore: bool}
     */
    public static function page(array $session, ?string $before, int $limit): array
    {
        $limit = max(1, min(50, $limit));
        $messages = array_values(array_filter(
            is_array($session['messages'] ?? null) ? $session['messages'] : [],
            static fn ($message): bool => is_array($message)
        ));
        $end = count($messages);
        if ($before !== null && $before !== '') {
            $index = null;
            foreach ($messages as $position => $message) {
                if ((string) ($message['id'] ?? '') === $before) {
                    $index = $position;
                    break;
                }
            }
            // 游标对应消息已被删除时直接结束，避免重复返回同一页
            if ($index === null) {
                $session['messages'] = [];
                return ['session' => $session, 'hasMore' => false];
            }
            $end = $index;
        }
        $start = max(0, $end - $limit);
        $slice = array_slice($messages, $start, $end - $start);
        $hasMore = $start > 0;
        $session['messages'] = $slice;
        if ($hasMore && $slice !== []) {
            $session['rootMessageId'] = (string) $slice[0]['id'];
        }
        return ['session' => $session, 'hasMore' => $hasMore];
    }

    /**
     * 会话精简大纲：只返回用户提问的 ID、全局序号与前 50 个字预览，
     * 供右侧导航栏按窗口加载（每次 20 条），避免为定位而读取整段聊天记录。
     *
     * @return array{total:int, offset:int, items:array<int, array{id:string,index:int,preview:string}>}
     */
    public static function outline(array $session, int $offset, int $limit, ?string $around = null): array
    {
        $limit = max(1, min(20, $limit));
        $messages = array_values(array_filter(
            is_array($session['messages'] ?? null) ? $session['messages'] : [],
            static fn ($message): bool => is_array($message)
        ));
        $ordered = array_key_exists('rootMessageId', $session)
            ? self::visiblePath($session, $messages)
            : $messages;
        if ($ordered === []) {
            $ordered = $messages;
        }
        $questions = [];
        foreach ($ordered as $message) {
            if (($message['role'] ?? '') === 'user') {
                $questions[] = $message;
            }
        }
        $total = count($questions);
        if ($total === 0) {
            return ['total' => 0, 'offset' => 0, 'items' => []];
        }
        $start = max(0, min($offset, $total - $limit));
        if ($around !== null && $around !== '') {
            foreach ($questions as $position => $message) {
                if ((string) ($message['id'] ?? '') === $around) {
                    $start = max(0, min($position - intdiv($limit, 2), $total - $limit));
                    break;
                }
            }
        } else {
            $start = max(0, min($start, $total - $limit));
        }
        $slice = array_slice($questions, $start, $limit);
        $items = [];
        foreach ($slice as $position => $message) {
            $text = preg_replace('/\s+/u', ' ', trim((string) ($message['content'] ?? '')));
            $items[] = [
                'id' => (string) ($message['id'] ?? ''),
                'index' => $start + $position,
                'preview' => mb_substr((string) $text, 0, 50, 'UTF-8'),
            ];
        }
        return ['total' => $total, 'offset' => $start, 'items' => $items];
    }

    /**
     * 定位到某条消息所在的窗口：以该消息为窗口末尾，最多返回 $limit 条可见消息。
     * 搜索结果跳转只需这一页，不再把它之后的全部内容一并返回。
     *
     * @return array{session: array, hasMore: bool}
     */
    public static function pageAround(array $session, string $messageId, int $limit): array
    {
        $limit = max(1, min(50, $limit));
        $messages = array_values(array_filter(
            is_array($session['messages'] ?? null) ? $session['messages'] : [],
            static fn ($message): bool => is_array($message)
        ));
        $ordered = array_key_exists('rootMessageId', $session)
            ? self::visiblePath($session, $messages)
            : $messages;
        if ($ordered === []) {
            $ordered = $messages;
        }
        $index = null;
        foreach ($ordered as $position => $message) {
            if ((string) ($message['id'] ?? '') === $messageId) {
                $index = $position;
                break;
            }
        }
        if ($index === null) {
            return ['session' => $session, 'hasMore' => false];
        }
        $end = $index + 1;
        $start = max(0, $end - $limit);
        $slice = array_slice($ordered, $start, $end - $start);
        $session['messages'] = $slice;
        if ($slice !== []) {
            $session['rootMessageId'] = (string) $slice[0]['id'];
        }
        return ['session' => $session, 'hasMore' => $start > 0];
    }

    public static function save(string $userId, string $sessionId, array $session, bool $partial = false): void
    {
        if (!preg_match('/^[0-9]{1,20}$/', $sessionId)) {
            throw new HttpError('会话 ID 非法', 400);
        }
        if (($session['id'] ?? null) !== $sessionId) {
            throw new HttpError('会话 ID 不匹配', 400);
        }
        if (!isset($session['messages']) || !is_array($session['messages'])) {
            throw new HttpError('会话数据不完整', 422);
        }

        $now = (int) floor(microtime(true) * 1000);
        $createdAt = (int) ($session['createdAt'] ?? $now);
        if ($createdAt < 946684800000 || $createdAt > $now + 86400000) {
            $createdAt = $now;
        }
        $updatedAt = (int) ($session['updatedAt'] ?? $now);
        if ($updatedAt < $createdAt || $updatedAt > $now + 86400000) {
            $updatedAt = $now;
        }
        $title = mb_substr((string) ($session['title'] ?? ''), 0, 200);

        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT user_id, data FROM sessions WHERE id = ?');
        $stmt->execute([$sessionId]);
        $existing = $stmt->fetch();
        if ($existing && (string) $existing['user_id'] !== $userId) {
            throw new HttpError('会话不存在', 404);
        }
        // 分页打开后只持有部分消息，保存时与库内完整消息合并，避免覆盖更早的历史
        if ($partial && $existing) {
            $previous = json_decode((string) $existing['data'], true);
            if (is_array($previous)) {
                $session = self::mergeMessages($previous, $session);
            }
        }
        if (!$existing) {
            $limit = (int) SiteSettings::get('max_sessions_per_user', '0');
            if ($limit > 0) {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM sessions WHERE user_id = ?');
                $stmt->execute([$userId]);
                if ((int) $stmt->fetchColumn() >= $limit) {
                    throw new HttpError('会话数量已达上限', 422);
                }
            }
        }

        $messagesCount = count($session['messages']);
        $inputTokens = 0;
        $outputTokens = 0;
        $fileIds = [];
        foreach ($session['messages'] as $message) {
            if (!is_array($message)) {
                continue;
            }
            if (($message['role'] ?? '') === 'assistant' && isset($message['usage']) && is_array($message['usage'])) {
                $usage = $message['usage'];
                $inputTokens += (int) ($usage['input_tokens'] ?? 0)
                    + (int) ($usage['cache_creation_input_tokens'] ?? 0)
                    + (int) ($usage['cache_read_input_tokens'] ?? 0);
                $outputTokens += (int) ($usage['output_tokens'] ?? 0);
            }
            if (($message['role'] ?? '') === 'user' && isset($message['files']) && is_array($message['files'])) {
                foreach ($message['files'] as $file) {
                    $fileId = is_array($file) ? (string) ($file['id'] ?? '') : '';
                    if ($fileId !== '' && preg_match('/^[A-Za-z0-9._-]{1,64}$/', $fileId)) {
                        $fileIds[$fileId] = true;
                    }
                }
            }
        }

        $rootMessageId = $session['rootMessageId'] ?? null;
        $rootMessageId = is_string($rootMessageId) && preg_match('/^[0-9]{1,20}$/', $rootMessageId)
            ? $rootMessageId
            : null;

        $data = json_encode($session, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($data === false) {
            throw new HttpError('会话数据序列化失败', 422);
        }

        Database::tx(function () use ($pdo, $userId, $sessionId, $title, $rootMessageId, $messagesCount, $inputTokens, $outputTokens, $data, $createdAt, $updatedAt, $fileIds): void {
            $stmt = $pdo->prepare(
                'INSERT INTO sessions (id, user_id, title, root_message_id, messages_count, input_tokens, output_tokens, data, created_ms, updated_ms)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE title = VALUES(title), root_message_id = VALUES(root_message_id),
                   messages_count = VALUES(messages_count),
                   input_tokens = GREATEST(input_tokens, VALUES(input_tokens)),
                   output_tokens = GREATEST(output_tokens, VALUES(output_tokens)),
                   data = VALUES(data), updated_ms = VALUES(updated_ms)'
            );
            $stmt->execute([
                $sessionId,
                $userId,
                $title,
                $rootMessageId,
                $messagesCount,
                $inputTokens,
                $outputTokens,
                $data,
                $createdAt,
                $updatedAt,
            ]);

            $stmt = $pdo->prepare('DELETE FROM session_files WHERE user_id = ? AND session_id = ?');
            $stmt->execute([$userId, $sessionId]);
            if ($fileIds !== []) {
                $insert = $pdo->prepare('INSERT IGNORE INTO session_files (user_id, session_id, file_id) VALUES (?, ?, ?)');
                foreach (array_keys($fileIds) as $fileId) {
                    $insert->execute([$userId, $sessionId, $fileId]);
                }
            }
        });

        self::cleanupOrphans($userId);
    }

    public static function rename(string $userId, string $sessionId, string $title): void
    {
        $stmt = Database::pdo()->prepare('UPDATE sessions SET title = ?, updated_ms = ? WHERE user_id = ? AND id = ?');
        $stmt->execute([mb_substr(trim($title), 0, 200), (int) floor(microtime(true) * 1000), $userId, $sessionId]);
    }

    public static function delete(string $userId, string $sessionId): void
    {
        self::archiveSession($userId, $sessionId);
        Database::tx(function () use ($userId, $sessionId): void {
            $pdo = Database::pdo();
            $stmt = $pdo->prepare('DELETE FROM session_files WHERE user_id = ? AND session_id = ?');
            $stmt->execute([$userId, $sessionId]);
            $stmt = $pdo->prepare('DELETE FROM sessions WHERE user_id = ? AND id = ?');
            $stmt->execute([$userId, $sessionId]);
        });
        self::cleanupOrphans($userId);
    }

    /**
     * 把分页窗口内的消息覆盖到库内完整消息上：同 ID 用新版本替换，新消息追加到末尾；
     * 根节点沿用库内值，防止分页窗口的临时根覆盖真实根。
     */
    private static function mergeMessages(array $previous, array $incoming): array
    {
        $merged = [];
        $positions = [];
        $previousMessages = is_array($previous['messages'] ?? null) ? $previous['messages'] : [];
        foreach ($previousMessages as $message) {
            if (!is_array($message) || !isset($message['id'])) {
                continue;
            }
            $positions[(string) $message['id']] = count($merged);
            $merged[] = $message;
        }
        $incomingMessages = is_array($incoming['messages'] ?? null) ? $incoming['messages'] : [];
        foreach ($incomingMessages as $message) {
            if (!is_array($message) || !isset($message['id'])) {
                continue;
            }
            $id = (string) $message['id'];
            if (array_key_exists($id, $positions)) {
                $merged[$positions[$id]] = $message;
            } else {
                $positions[$id] = count($merged);
                $merged[] = $message;
            }
        }
        $incoming['messages'] = $merged;
        $root = $previous['rootMessageId'] ?? null;
        if (is_string($root) && $root !== '') {
            $incoming['rootMessageId'] = $root;
        }
        return $incoming;
    }

    public static function cleanupOrphans(string $userId): void
    {
        $stmt = Database::pdo()->prepare(
            'SELECT f.id FROM files f
             LEFT JOIN session_files sf ON sf.user_id = f.user_id AND sf.file_id = f.id
             WHERE f.user_id = ? AND sf.file_id IS NULL'
        );
        $stmt->execute([$userId]);
        $orphans = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if ($orphans === []) {
            return;
        }
        $delete = Database::pdo()->prepare('DELETE FROM files WHERE user_id = ? AND id = ?');
        foreach ($orphans as $fileId) {
            $delete->execute([$userId, $fileId]);
            FileStore::removeFile($userId, (string) $fileId);
        }
    }
}
