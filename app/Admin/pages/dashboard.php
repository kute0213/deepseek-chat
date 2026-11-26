<?php
/** @var array $stats */
$cards = [
    [t('admin.dashboard.users'), (string) $stats['users'], t('admin.dashboard.users_hint', ['count' => $stats['activeUsers']])],
    [t('admin.dashboard.sessions'), (string) $stats['sessions'], t('admin.dashboard.sessions_hint', ['count' => $stats['messages']])],
    [t('admin.dashboard.input'), number_format($stats['inputTokens']), t('admin.dashboard.total_hint')],
    [t('admin.dashboard.output'), number_format($stats['outputTokens']), t('admin.dashboard.total_hint')],
    [t('admin.dashboard.files'), (string) $stats['files'], fmt_bytes($stats['fileBytes'])],
    [t('admin.dashboard.shares'), (string) $stats['shares'], t('admin.dashboard.shares_hint', ['active' => $stats['activeShares'], 'views' => $stats['shareViews']])],
];
?>
<div class="cards">
    <?php foreach ($cards as [$label, $value, $hint]): ?>
        <div class="card">
            <div class="card-label"><?= e($label) ?></div>
            <div class="card-value"><?= e($value) ?></div>
            <div class="card-hint"><?= e($hint) ?></div>
        </div>
    <?php endforeach; ?>
</div>
<div class="panel">
    <h2><?= e(t('admin.dashboard.quick')) ?></h2>
    <div class="quick">
        <a class="btn" href="<?= e(admin_url(['page' => 'users'])) ?>"><?= e(t('admin.dashboard.manage_users')) ?></a>
        <a class="btn" href="<?= e(admin_url(['page' => 'sessions'])) ?>"><?= e(t('admin.dashboard.view_sessions')) ?></a>
        <a class="btn" href="<?= e(admin_url(['page' => 'shares'])) ?>"><?= e(t('admin.dashboard.manage_shares')) ?></a>
        <a class="btn" href="<?= e(admin_url(['page' => 'settings'])) ?>"><?= e(t('admin.dashboard.edit_settings')) ?></a>
    </div>
</div>
