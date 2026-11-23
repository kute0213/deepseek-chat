<?php
/** @var string|null $flashError */
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>管理登录</title>
    <link rel="stylesheet" href="/admin/assets/admin.css">
</head>
<body class="login-body">
<form class="login-card" method="post" action="/admin/">
    <h1>管理控制台</h1>
    <p class="muted">请使用管理员账号登录</p>
    <?php if ($flashError !== null): ?>
        <div class="flash error"><?= e($flashError) ?></div>
    <?php endif; ?>
    <input type="hidden" name="action" value="login">
    <label>
        <span>用户名</span>
        <input name="username" autocomplete="username" required autofocus>
    </label>
    <label>
        <span>密码</span>
        <input name="password" type="password" autocomplete="current-password" required>
    </label>
    <button class="btn primary" type="submit">登录</button>
    <a class="link" href="/">返回网站</a>
</form>
</body>
</html>
