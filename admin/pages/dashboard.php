<?php
/** @var array $stats */
$cards = [
    ['用户总数', (string) $stats['users'], '正常 ' . $stats['activeUsers']],
    ['会话总数', (string) $stats['sessions'], '消息 ' . $stats['messages']],
    ['输入 Token', number_format($stats['inputTokens']), '累计'],
    ['输出 Token', number_format($stats['outputTokens']), '累计'],
    ['附件', (string) $stats['files'], fmt_bytes($stats['fileBytes'])],
    ['分享链接', (string) $stats['shares'], '生效 ' . $stats['activeShares'] . ' · 访问 ' . $stats['shareViews']],
    ['审计日志', (string) $stats['logs'], '条'],
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
    <h2>快捷操作</h2>
    <div class="quick">
        <a class="btn" href="<?= e(admin_url(['page' => 'users'])) ?>">管理用户</a>
        <a class="btn" href="<?= e(admin_url(['page' => 'sessions'])) ?>">查看会话</a>
        <a class="btn" href="<?= e(admin_url(['page' => 'shares'])) ?>">管理分享</a>
        <a class="btn" href="<?= e(admin_url(['page' => 'settings'])) ?>">修改站点设置</a>
        <a class="btn" href="<?= e(admin_url(['page' => 'logs'])) ?>">查看操作日志</a>
    </div>
</div>
