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
        <a class="btn small" href="<?= e(admin_url($base + ['p' => $current - 1])) ?>">上一页</a>
    <?php endif; ?>
    <span class="muted">第 <?= $current ?> / <?= $totalPages ?> 页，共 <?= $list['total'] ?> 条</span>
    <?php if ($current < $totalPages): ?>
        <a class="btn small" href="<?= e(admin_url($base + ['p' => $current + 1])) ?>">下一页</a>
    <?php endif; ?>
</div>
