<?php
/** @var string $title */
/** @var string $page */
/** @var array $user */
/** @var array $data */
/** @var string|null $ok */
/** @var string|null $error */
$nav = [
    'dashboard' => ['概览', '概'],
    'users' => ['用户', '用'],
    'sessions' => ['会话', '会'],
    'shares' => ['分享', '分'],
    'settings' => ['站点设置', '设'],
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
    <aside class="side" id="admin-side">
        <div class="side-head">
            <div class="side-title"><span class="side-title-text"><?= e(\App\Support\Lang::siteName()) ?></span></div>
            <button class="side-toggle" type="button" id="side-toggle" aria-label="展开或收起侧边栏">‹</button>
        </div>
        <nav>
            <?php foreach ($nav as $key => $item): ?>
                <a class="nav<?= $active($key) ? ' active' : '' ?>" data-page="<?= e($key) ?>"
                   data-short="<?= e($item[1]) ?>"
                   href="<?= e(admin_url(['page' => $key])) ?>"><?= e($item[0]) ?></a>
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
            <h1 id="admin-title"><?= e($title) ?></h1>
        </header>
        <div class="content" id="admin-content">
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

    function bindForms() {
        document.querySelectorAll('form[data-confirm]').forEach(function (form) {
            if (form.dataset.bound === '1') {
                return;
            }
            form.dataset.bound = '1';
            form.addEventListener('submit', function (event) {
                if (form.dataset.confirmed === '1') {
                    return;
                }
                event.preventDefault();
                open(form);
            });
        });
    }
    bindForms();
    window.bindAdminConfirm = bindForms;

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

/* 左侧列表无刷新切换 + 展开 / 收起动画 */
(function () {
    var side = document.getElementById('admin-side');
    var toggle = document.getElementById('side-toggle');
    var content = document.getElementById('admin-content');
    var title = document.getElementById('admin-title');
    var storageKey = 'dsw-admin-side-collapsed';

    function applyCollapsed(collapsed) {
        side.classList.toggle('collapsed', collapsed);
        toggle.textContent = collapsed ? '›' : '‹';
        toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    }

    applyCollapsed(localStorage.getItem(storageKey) === '1');
    toggle.addEventListener('click', function () {
        var collapsed = !side.classList.contains('collapsed');
        applyCollapsed(collapsed);
        localStorage.setItem(storageKey, collapsed ? '1' : '0');
    });

    function markActive(href) {
        var target = new URL(href, location.origin);
        var page = target.searchParams.get('page') || 'dashboard';
        document.querySelectorAll('.nav').forEach(function (link) {
            var key = link.getAttribute('data-page');
            var match = key === page || (page === 'session' && key === 'sessions') || (page === 'user' && key === 'users');
            link.classList.toggle('active', match);
        });
        var titles = {
            dashboard: '概览',
            users: '用户管理',
            user: '用户详情',
            sessions: '会话管理',
            session: '会话详情',
            shares: '分享管理',
            settings: '站点设置'
        };
        title.textContent = titles[page] || '概览';
    }

    function load(href) {
        content.classList.add('loading');
        fetch(href, { headers: { 'X-Admin-Partial': '1' }, credentials: 'same-origin' })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.text();
            })
            .then(function (html) {
                content.innerHTML = html;
                markActive(href);
                if (window.bindAdminConfirm) {
                    window.bindAdminConfirm();
                }
                bindLinks();
                history.pushState({ admin: true }, '', href);
                window.scrollTo(0, 0);
            })
            .catch(function () {
                location.href = href;
            })
            .finally(function () {
                content.classList.remove('loading');
            });
    }

    function bindLinks() {
        var links = document.querySelectorAll('.nav, #admin-content a[href*="/admin/"]');
        links.forEach(function (link) {
            if (!link.href || link.dataset.navBound === '1' || link.target === '_blank' || link.href.indexOf('export=') !== -1) {
                return;
            }
            link.dataset.navBound = '1';
            link.addEventListener('click', function (event) {
                if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) {
                    return;
                }
                event.preventDefault();
                load(link.href);
            });
        });
    }
    bindLinks();

    window.addEventListener('popstate', function () {
        if (location.pathname.indexOf('/admin') === 0) {
            load(location.href);
        }
    });
})();
</script>
</body>
</html>
