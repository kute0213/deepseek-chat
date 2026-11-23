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
        <div class="brand">管理控制台</div>
        <nav>
            <?php foreach ($nav as $key => $label): ?>
                <a class="nav<?= $page === $key || ($page === 'session' && $key === 'sessions') ? ' active' : '' ?>"
                   href="<?= e(admin_url(['page' => $key])) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="side-foot">
            <div class="who"><?= e($user['username']) ?></div>
            <a class="link" href="/" target="_blank" rel="noopener">打开网站</a>
            <form method="post">
                <input type="hidden" name="action" value="logout">
                <input type="hidden" name="csrf" value="<?= e($user['csrf_token']) ?>">
                <button class="link danger" type="submit">退出登录</button>
            </form>
        </div>
    </aside>
    <main class="main">
        <header class="topbar">
            <h1><?= e($title) ?></h1>
        </header>
        <?php if ($ok !== null): ?>
            <div class="flash ok"><?= e($ok) ?></div>
        <?php endif; ?>
        <?php if ($error !== null): ?>
            <div class="flash error"><?= e($error) ?></div>
        <?php endif; ?>
        <?php require __DIR__ . '/' . $page . '.php'; ?>
    </main>
</div>
</body>
</html>
