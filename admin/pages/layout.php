<?php
/** @var string $title */
/** @var string $page */
/** @var array $user */
/** @var array $data */
/** @var string|null $ok */
/** @var string|null $error */
$nav = [
    'dashboard' => '概览',
    'users' => '用户',
    'sessions' => '会话',
    'settings' => '站点设置',
    'logs' => '操作日志',
];
$icons = [
    'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1.6"/><rect x="14" y="3" width="7" height="7" rx="1.6"/><rect x="3" y="14" width="7" height="7" rx="1.6"/><rect x="14" y="14" width="7" height="7" rx="1.6"/>',
    'users' => '<circle cx="9" cy="8" r="3.2"/><path d="M3.5 19.5c0-3.2 2.5-5.3 5.5-5.3s5.5 2.1 5.5 5.3"/><path d="M16 5.6a3 3 0 0 1 0 5.8M17.6 14.5c2 .4 3.4 2.2 3.4 4.5"/>',
    'sessions' => '<path d="M20 14.5a3.5 3.5 0 0 1-3.5 3.5H9l-4.5 3v-13A3.5 3.5 0 0 1 8 4.5h8.5A3.5 3.5 0 0 1 20 8z"/>',
    'settings' => '<circle cx="12" cy="12" r="3.2"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.7 2.7-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6v.2h-3.8v-.2a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.6 17l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.6-1H3v-3.8h.2a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.4 7l2.7-2.7.1.1a1.7 1.7 0 0 0 1.9.3 1.7 1.7 0 0 0 1-1.6v-.2H14v.2a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.7 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.2v3.8h-.2a1.7 1.7 0 0 0-1.5 1.2z"/>',
    'logs' => '<path d="M6 3.5h9l3 3v14H6z"/><path d="M14.5 3.5v4h4M9 12h6M9 15.5h6"/>',
];
$active = static fn (string $key): bool => $page === $key
    || ($page === 'session' && $key === 'sessions')
    || ($page === 'user' && $key === 'users');
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> - 管理控制台</title>
    <link rel="stylesheet" href="/admin/assets/admin.css">
</head>
<body>
<div class="shell">
    <aside class="side">
        <div class="brand-row">
            <img class="brand-mark" src="/admin/assets/deepseek-logo.png" alt="" width="30" height="30">
            <div class="brand-text">
                <span class="brand-name">DeepSeek</span>
                <span class="brand-badge">管理</span>
            </div>
        </div>
        <nav>
            <?php foreach ($nav as $key => $label): ?>
                <a class="nav<?= $active($key) ? ' active' : '' ?>"
                   href="<?= e(admin_url(['page' => $key])) ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $icons[$key] ?></svg>
                    <?= e($label) ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="side-foot">
            <div class="user-chip">
                <span class="avatar"><?= e(mb_substr((string) $user['username'], 0, 1)) ?></span>
                <span class="who"><?= e($user['username']) ?></span>
            </div>
            <div class="side-links">
                <a class="link" href="/" target="_blank" rel="noopener">打开网站</a>
                <form method="post">
                    <input type="hidden" name="action" value="logout">
                    <input type="hidden" name="csrf" value="<?= e($user['csrf_token']) ?>">
                    <button class="link danger" type="submit">退出登录</button>
                </form>
            </div>
        </div>
    </aside>
    <main class="main">
        <header class="topbar">
            <h1><?= e($title) ?></h1>
            <span class="topbar-note">DeepSeekChat 管理控制台</span>
        </header>
        <div class="content">
            <?php if ($ok !== null): ?>
                <div class="flash ok"><?= e($ok) ?></div>
            <?php endif; ?>
            <?php if ($error !== null): ?>
                <div class="flash error"><?= e($error) ?></div>
            <?php endif; ?>
            <?php require __DIR__ . '/' . $page . '.php'; ?>
        </div>
    </main>
</div>
</body>
</html>
