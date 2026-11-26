<?php
/** @var string $title */
/** @var string $page */
/** @var array $user */
/** @var array $data */
/** @var string|null $ok */
/** @var string|null $error */
$nav = [
    'dashboard' => t('admin.nav.dashboard'),
    'users' => t('admin.nav.users'),
    'sessions' => t('admin.nav.sessions'),
    'shares' => t('admin.nav.shares'),
    'settings' => t('admin.nav.settings'),
];
$active = static fn (string $key): bool => $page === $key
    || ($page === 'session' && $key === 'sessions')
    || ($page === 'user' && $key === 'users');
?>
<!doctype html>
<html lang="<?= admin_locale() === 'en-US' ? 'en' : 'zh-CN' ?>" data-locale="<?= e(admin_locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> - <?= e(\App\Support\Lang::siteName()) ?></title>
    <script>
    /* 首屏前按站点设置应用明暗与语言，避免闪烁 */
    (function () {
        try {
            var pref = localStorage.getItem('chat-demo:theme-preference');
            var dark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            var theme = pref === 'dark' || (pref !== 'light' && dark) ? 'dark' : 'light';
            document.documentElement.setAttribute('data-theme', theme);
            var saved = localStorage.getItem('chat-demo:locale');
            var locale = saved ? (saved === 'en-US' ? 'en-US' : 'zh-CN') : ((navigator.language || 'zh').toLowerCase().indexOf('zh') === 0 ? 'zh-CN' : 'en-US');
            document.documentElement.setAttribute('data-locale', locale);
            if (locale !== <?= json_encode(admin_locale()) ?>) {
                document.cookie = 'dsw_locale=' + locale + '; path=/; max-age=31536000; samesite=lax';
                if (!sessionStorage.getItem('ds-admin-locale-sync')) {
                    sessionStorage.setItem('ds-admin-locale-sync', '1');
                    location.reload();
                    return;
                }
            }
        } catch (e) {}
    })();
    </script>
    <link rel="stylesheet" href="/assets/ui.css?v=<?= (int) @filemtime(dirname(__DIR__, 3) . '/assets/ui.css') ?>">
</head>
<body>
<div class="shell">
    <aside class="side">
        <div class="side-title"><?= e(\App\Support\Lang::siteName()) ?></div>
        <nav>
            <?php foreach ($nav as $key => $label): ?>
                <a class="nav<?= $active($key) ? ' active' : '' ?>" data-page="<?= e($key) ?>"
                   href="<?= e(admin_url(['page' => $key])) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="side-foot">
            <div class="user-chip">
                <span class="avatar"><?= e(mb_substr((string) $user['username'], 0, 1)) ?></span>
                <span class="who"><?= e($user['username']) ?></span>
            </div>
            <div class="side-links">
                <a class="link" href="/" target="_blank" rel="noopener"><?= e(t('admin.open_site')) ?></a>
                <form method="post">
                    <input type="hidden" name="action" value="logout">
                    <input type="hidden" name="csrf" value="<?= e($user['csrf_token']) ?>">
                    <button class="link danger" type="submit"><?= e(t('admin.logout')) ?></button>
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
<div class="modal-mask" id="confirm-modal" data-default="<?= e(t('admin.confirm.default')) ?>" hidden>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="confirm-title">
        <h2 class="modal-title" id="confirm-title"><?= e(t('admin.confirm.title')) ?></h2>
        <p class="modal-body" id="confirm-text"></p>
        <div class="modal-actions">
            <button class="btn" type="button" data-confirm-cancel><?= e(t('common.cancel')) ?></button>
            <button class="btn primary" type="button" data-confirm-ok><?= e(t('common.confirm')) ?></button>
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
        text.textContent = form.getAttribute('data-confirm') || mask.getAttribute('data-default') || '';
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

/* 左侧标签页无刷新切换 */
(function () {
    var content = document.getElementById('admin-content');
    var title = document.getElementById('admin-title');
    if (!content || !title) {
        return;
    }
    var titles = <?= json_encode([
        'dashboard' => t('admin.page.dashboard'),
        'users' => t('admin.page.users'),
        'user' => t('admin.page.user'),
        'sessions' => t('admin.page.sessions'),
        'session' => t('admin.page.session'),
        'shares' => t('admin.page.shares'),
        'settings' => t('admin.page.settings'),
    ], JSON_UNESCAPED_UNICODE) ?>;
    var fallbackTitle = <?= json_encode(t('admin.page.dashboard'), JSON_UNESCAPED_UNICODE) ?>;

    function markActive(href) {
        var target = new URL(href, location.origin);
        var page = target.searchParams.get('page') || 'dashboard';
        document.querySelectorAll('.nav').forEach(function (link) {
            var key = link.getAttribute('data-page');
            var match = key === page || (page === 'session' && key === 'sessions') || (page === 'user' && key === 'users');
            link.classList.toggle('active', match);
        });
        title.textContent = titles[page] || fallbackTitle;
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
