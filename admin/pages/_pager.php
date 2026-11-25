<?php
/** @var array $list */
/** @var array $base */
$totalPages = $list['pages'];
$current = $list['page'];
if ($totalPages <= 1) {
    return;
}
?>
<div class="pager">
    <?php if ($current > 1): ?>
        <a class="btn small" href="<?= e(admin_url($base + ['p' => $current - 1])) ?>"><?= e(t('admin.pager.prev')) ?></a>
    <?php endif; ?>
    <span class="muted"><?= e(t('admin.pager.info', ['current' => $current, 'total' => $totalPages, 'count' => $list['total']])) ?></span>
    <?php if ($current < $totalPages): ?>
        <a class="btn small" href="<?= e(admin_url($base + ['p' => $current + 1])) ?>"><?= e(t('admin.pager.next')) ?></a>
    <?php endif; ?>
</div>
