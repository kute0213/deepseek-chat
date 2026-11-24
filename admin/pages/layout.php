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
    'shares' => '分享',
    'settings' => '站点设置',
    'logs' => '操作日志',
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
    <title><?= e($title) ?> - <?= e(\App\Support\Lang::siteName()) ?></title>
    <link rel="stylesheet" href="/assets/ui.css">
</head>
<body>
<div class="shell">
    <aside class="side">
        <div class="side-title"><?= e(\App\Support\Lang::siteName()) ?></div>
        <nav>
            <?php foreach ($nav as $key => $label): ?>
                <a class="nav<?= $active($key) ? ' active' : '' ?>"
                   href="<?= e(admin_url(['page' => $key])) ?>"><?= e($label) ?></a>
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
<div class="modal-mask" id="confirm-modal" hidden>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="confirm-title">
        <h2 class="modal-title" id="confirm-title">确认操作</h2>
        <p class="modal-body" id="confirm-text"></p>
        <div class="modal-actions">
            <button class="btn" type="button" data-confirm-cancel>取消</button>
            <button class="btn primary" type="button" data-confirm-ok>确定</button>
        </div>
    </div>
</div>
<script>
(function () {
    var mask = document.getElementById('confirm-modal');
    var text = document.getElementById('confirm-text');
    var ok = mask.querySelector('[data-confirm-ok]');
    var cancel = mask.querySelector('[data-confirm-cancel]');
    var pending = null;

    function close() {
        mask.hidden = true;
        pending = null;
    }

    function open(form) {
        text.textContent = form.getAttribute('data-confirm') || '确定执行该操作？';
        mask.hidden = false;
        ok.focus();
        pending = form;
    }

    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (form.dataset.confirmed === '1') {
                return;
            }
            event.preventDefault();
            open(form);
        });
    });

    ok.addEventListener('click', function () {
        if (pending) {
            pending.dataset.confirmed = '1';
            pending.submit();
        }
    });
    cancel.addEventListener('click', close);
    mask.addEventListener('click', function (event) {
        if (event.target === mask) {
            close();
        }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !mask.hidden) {
            close();
        }
    });
})();
</script>
</body>
</html>
