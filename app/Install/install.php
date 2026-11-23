<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';

use App\Bootstrap;
use App\Support\Config;
use App\Support\Snowflake;

// 经由 index.php 进入时已启动过引导流程，避免重复注册
if (!defined('APP_ROOT')) {
    Bootstrap::boot();
}

$root = APP_ROOT;
$installed = Config::installed();
$error = null;
$done = false;

$checks = [
    'PHP >= 8.1' => version_compare(PHP_VERSION, '8.1.0', '>='),
    'pdo_mysql' => extension_loaded('pdo_mysql'),
    'curl' => extension_loaded('curl'),
    'zip' => extension_loaded('zip'),
    'mbstring' => extension_loaded('mbstring'),
    'uploads/ 可写' => is_writable($root . '/uploads'),
];

$defaults = [
    'db_host' => '127.0.0.1',
    'db_port' => '3306',
    'db_name' => 'deepseek_chat',
    'db_user' => 'deepseek',
    'db_pass' => '',
    'site_name' => 'DeepSeekChat',
    'api_base' => 'https://api.deepseek.com',
    'allow_register' => '1',
    'admin_user' => 'admin',
    'admin_pass' => 'admin1324',
];

$form = $defaults;

if (!$installed && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    foreach ($defaults as $key => $value) {
        if (isset($_POST[$key]) && is_string($_POST[$key])) {
            $form[$key] = trim($_POST[$key]);
        }
    }
    $form['allow_register'] = isset($_POST['allow_register']) ? '1' : '0';

    try {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $form['db_name'])) {
            throw new RuntimeException('数据库名只能包含字母、数字和下划线');
        }
        if (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $form['admin_user'])) {
            throw new RuntimeException('管理员用户名需为 3-32 位字母、数字或下划线');
        }
        if (strlen($form['admin_pass']) < 8) {
            throw new RuntimeException('管理员密码至少 8 位');
        }
        if (!filter_var($form['api_base'], FILTER_VALIDATE_URL)) {
            throw new RuntimeException('DeepSeek API Base URL 格式不正确');
        }
        foreach ($checks as $label => $ok) {
            if (!$ok) {
                throw new RuntimeException('环境检查未通过：' . $label);
            }
        }

        $host = $form['db_host'];
        $port = (int) $form['db_port'];
        $user = $form['db_user'];
        $pass = $form['db_pass'];

        $pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
        ]);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$form['db_name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$form['db_name']}`");

        $schema = file_get_contents($root . '/database/schema.sql');
        if ($schema === false) {
            throw new RuntimeException('无法读取 database/schema.sql');
        }
        $pdo->exec($schema);

        if (function_exists('random_int')) {
            $workerId = random_int(0, 1023);
        } else {
            $workerId = mt_rand(0, 1023);
        }

        $config = [
            'db' => [
                'host' => $host,
                'port' => $port,
                'name' => $form['db_name'],
                'user' => $user,
                'pass' => $pass,
                'charset' => 'utf8mb4',
            ],
            'snowflake_worker_id' => $workerId,
            'debug' => false,
        ];
        $configDir = $root . '/uploads/config';
        if (!is_dir($configDir) && !mkdir($configDir, 0775, true) && !is_dir($configDir)) {
            throw new RuntimeException('无法创建 uploads/config 目录');
        }
        $configPath = $configDir . '/config.php';
        $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
        $tmp = $configPath . '.tmp';
        if (file_put_contents($tmp, $content) === false || !rename($tmp, $configPath)) {
            throw new RuntimeException('无法写入 uploads/config/config.php，请检查目录权限');
        }
        @chmod($configPath, 0640);

        $now = (int) floor(microtime(true) * 1000);
        $passwordHash = password_hash($form['admin_pass'], PASSWORD_DEFAULT);

        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $stmt->execute([$form['admin_user']]);
        $existing = $stmt->fetch();
        if ($existing) {
            $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, role = 'admin', status = 1, updated_ms = ? WHERE id = ?");
            $stmt->execute([$passwordHash, $now, $existing['id']]);
        } else {
            $adminId = (new Snowflake($pdo, $workerId))->nextId();
            $stmt = $pdo->prepare(
                "INSERT INTO users (id, username, password_hash, role, status, created_ms, updated_ms)
                 VALUES (?, ?, ?, 'admin', 1, ?, ?)"
            );
            $stmt->execute([$adminId, $form['admin_user'], $passwordHash, $now, $now]);
        }

        $settings = [
            'site_name' => $form['site_name'] !== '' ? $form['site_name'] : 'DeepSeekChat',
            'allow_register' => $form['allow_register'],
            'api_base_url' => rtrim($form['api_base'], '/'),
            'announcement' => '',
            'max_upload_mb' => '64',
            'max_sessions_per_user' => '0',
        ];
        $stmt = $pdo->prepare('INSERT INTO site_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)');
        foreach ($settings as $key => $value) {
            $stmt->execute([$key, $value]);
        }

        $lock = "installed_at=" . date('c') . "\nworker_id={$workerId}\n";
        if (file_put_contents($configDir . '/installed.lock', $lock) === false) {
            throw new RuntimeException('无法写入 uploads/config/installed.lock');
        }

        $done = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>安装 DeepSeekChat</title>
<style>
    :root { color-scheme: light dark; --brand:#4d6bfe; --bg:#f7f8fc; --panel:#fff; --text:#1a1c23; --muted:#667085; --line:#e4e7ec; --danger:#d92d20; --ok:#12805c; }
    @media (prefers-color-scheme: dark) { :root { --bg:#111318; --panel:#191c22; --text:#e7e9ee; --muted:#98a2b3; --line:#2a2f3a; } }
    * { box-sizing: border-box; }
    body { margin:0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif; background:var(--bg); color:var(--text); }
    .wrap { max-width: 760px; margin: 48px auto; padding: 0 20px; }
    h1 { font-size: 26px; margin: 0 0 6px; }
    .sub { color: var(--muted); margin: 0 0 24px; }
    .card { background: var(--panel); border: 1px solid var(--line); border-radius: 14px; padding: 24px; margin-bottom: 18px; }
    h2 { font-size: 16px; margin: 0 0 14px; }
    .checks { display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:8px; padding:0; margin:0; list-style:none; }
    .checks li { display:flex; justify-content:space-between; gap:12px; padding:8px 10px; border:1px solid var(--line); border-radius:8px; font-size:13px; }
    .ok { color: var(--ok); font-weight:600; }
    .bad { color: var(--danger); font-weight:600; }
    .grid { display:grid; grid-template-columns: 1fr 1fr; gap:14px; }
    @media (max-width: 620px) { .grid { grid-template-columns: 1fr; } }
    label { display:block; font-size:13px; color:var(--muted); margin-bottom:6px; }
    input[type=text], input[type=password], input[type=number] { width:100%; padding:10px 12px; border:1px solid var(--line); border-radius:8px; background:transparent; color:var(--text); font-size:14px; }
    input:focus { outline:2px solid color-mix(in srgb, var(--brand) 35%, transparent); border-color: var(--brand); }
    .checkbox { display:flex; align-items:center; gap:8px; margin-top:26px; color:var(--text); font-size:14px; }
    button { margin-top:22px; width:100%; padding:12px 16px; border:0; border-radius:10px; background:var(--brand); color:#fff; font-size:15px; font-weight:600; cursor:pointer; }
    button:hover { filter:brightness(1.05); }
    .error { background: color-mix(in srgb, var(--danger) 10%, transparent); color:var(--danger); padding:12px 14px; border-radius:10px; font-size:14px; margin-bottom:16px; }
    .success { color: var(--ok); font-size:15px; line-height:1.8; }
    code { background: color-mix(in srgb, var(--brand) 10%, transparent); padding:2px 6px; border-radius:6px; }
    a { color: var(--brand); }
</style>
</head>
<body>
<div class="wrap">
    <h1>DeepSeekChat 安装向导</h1>
    <p class="sub">配置 MySQL 连接并初始化站点，仅需一次。</p>

<?php if ($installed): ?>
    <div class="card">
        <h2>系统已安装</h2>
        <p class="success">检测到 <code>uploads/config/installed.lock</code>，安装向导已锁定。</p>
        <p>如需重新安装，请先删除 <code>uploads/config/config.php</code> 与 <code>uploads/config/installed.lock</code>。</p>
    </div>
<?php elseif ($done): ?>
    <div class="card">
        <h2>安装完成</h2>
        <div class="success">
            <p>数据库与管理员账号已创建。</p>
            <p>用户端：<a href="/">/</a><br>管理后台：<a href="/admin/login.php">/admin/login.php</a></p>
            <p>管理员用户名：<code><?= e($form['admin_user']) ?></code></p>
            <p style="color:var(--danger)">安装向导已锁定。如需重新安装，请删除 <code>uploads/config/config.php</code> 与 <code>uploads/config/installed.lock</code>。</p>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <h2>环境检查</h2>
        <ul class="checks">
            <?php foreach ($checks as $label => $ok): ?>
                <li><span><?= e($label) ?></span><span class="<?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? '通过' : '缺失' ?></span></li>
            <?php endforeach; ?>
        </ul>
    </div>

    <form method="post" action="/install">
        <div class="card">
            <h2>MySQL 连接</h2>
            <?php if ($error !== null): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
            <div class="grid">
                <div><label>主机</label><input type="text" name="db_host" value="<?= e($form['db_host']) ?>" required></div>
                <div><label>端口</label><input type="number" name="db_port" value="<?= e($form['db_port']) ?>" required></div>
                <div><label>数据库名</label><input type="text" name="db_name" value="<?= e($form['db_name']) ?>" required></div>
                <div><label>用户名</label><input type="text" name="db_user" value="<?= e($form['db_user']) ?>" required></div>
                <div><label>密码</label><input type="password" name="db_pass" value="<?= e($form['db_pass']) ?>"></div>
            </div>
        </div>

        <div class="card">
            <h2>站点与管理员</h2>
            <div class="grid">
                <div><label>站点名称</label><input type="text" name="site_name" value="<?= e($form['site_name']) ?>" required></div>
                <div><label>DeepSeek API Base URL</label><input type="text" name="api_base" value="<?= e($form['api_base']) ?>" required></div>
                <div><label>管理员用户名</label><input type="text" name="admin_user" value="<?= e($form['admin_user']) ?>" required></div>
                <div><label>管理员密码</label><input type="password" name="admin_pass" value="<?= e($form['admin_pass']) ?>" required></div>
            </div>
            <label class="checkbox"><input type="checkbox" name="allow_register" value="1" <?= $form['allow_register'] === '1' ? 'checked' : '' ?>> 开放用户注册</label>
        </div>

        <button type="submit">开始安装</button>
    </form>
<?php endif; ?>
</div>
</body>
</html>
