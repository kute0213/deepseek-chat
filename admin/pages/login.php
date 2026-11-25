<?php
/** @var string|null $flashError */
/** @var array{id: string, image: string} $captcha */
?>
<!doctype html>
<html lang="<?= admin_locale() === 'en-US' ? 'en' : 'zh-CN' ?>" data-locale="<?= e(admin_locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(t('admin.login_title')) ?> - <?= e(\App\Support\Lang::siteName()) ?></title>
    <script>
    (function () {
        try {
            var pref = localStorage.getItem('chat-demo:theme-preference');
            var dark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            document.documentElement.setAttribute('data-theme', pref === 'dark' || (pref !== 'light' && dark) ? 'dark' : 'light');
            var saved = localStorage.getItem('chat-demo:locale');
            var locale = saved ? (saved === 'en-US' ? 'en-US' : 'zh-CN') : ((navigator.language || 'zh').toLowerCase().indexOf('zh') === 0 ? 'zh-CN' : 'en-US');
            if (locale !== <?= json_encode(admin_locale()) ?>) {
                document.cookie = 'dsw_locale=' + locale + '; path=/; max-age=31536000; samesite=lax';
                if (!sessionStorage.getItem('ds-admin-locale-sync')) {
                    sessionStorage.setItem('ds-admin-locale-sync', '1');
                    location.reload();
                }
            }
        } catch (e) {}
    })();
    </script>
    <link rel="stylesheet" href="/assets/ui.css?v=<?= (int) @filemtime(dirname(__DIR__, 2) . '/assets/ui.css') ?>">
</head>
<body class="login-body">
<form class="login-card" method="post" action="/admin/">
    <h1><?= e(\App\Support\Lang::siteName()) ?> · <?= e(t('admin.login_title')) ?></h1>
    <p class="muted"><?= e(t('admin.login.subtitle')) ?></p>
    <?php if ($flashError !== null): ?>
        <div class="flash error"><?= e($flashError) ?></div>
    <?php endif; ?>
    <input type="hidden" name="action" value="login">
    <label>
        <span><?= e(t('admin.login.username')) ?></span>
        <input name="username" autocomplete="username" required autofocus>
    </label>
    <label>
        <span><?= e(t('admin.login.password')) ?></span>
        <input name="password" type="password" autocomplete="current-password" required>
    </label>
    <label>
        <span><?= e(t('admin.login.captcha')) ?></span>
        <div class="captcha-row">
            <input name="captcha_code" maxlength="4" autocomplete="off" placeholder="<?= e(t('admin.login.captcha_placeholder')) ?>" required>
            <img src="<?= e($captcha['image']) ?>" width="132" height="44" alt="<?= e(t('admin.login.captcha_alt')) ?>"
                 title="<?= e(t('admin.login.captcha_hint')) ?>" onclick="location.reload()">
        </div>
        <input type="hidden" name="captcha_id" value="<?= e($captcha['id']) ?>">
    </label>
    <button class="btn primary" type="submit"><?= e(t('admin.login.button')) ?></button>
    <a class="link" href="/"><?= e(t('admin.login.back')) ?></a>
</form>
</body>
</html>
