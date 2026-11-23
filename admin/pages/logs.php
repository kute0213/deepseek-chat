<?php
/** @var array $list */
$base = ['page' => 'logs'];
?>
<form class="toolbar" method="get" action="/admin/">
    <a class="btn" href="<?= e(admin_url(['page' => 'logs', 'export' => 'logs'])) ?>">导出 CSV</a>
    <span class="muted">共计 <?= $list['total'] ?> 条记录</span>
</form>

<div class="panel">
    <table>
        <thead>
        <tr>
            <th>时间</th>
            <th>管理员</th>
            <th>操作</th>
            <th>对象</th>
            <th>对象 ID</th>
            <th>详情</th>
            <th>IP</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($list['rows'] === []): ?>
            <tr><td colspan="7" class="muted">暂无日志</td></tr>
        <?php endif; ?>
        <?php foreach ($list['rows'] as $row): ?>
            <tr>
                <td class="muted"><?= e(fmt_ms((int) $row['created_ms'])) ?></td>
                <td><?= e($row['username'] ?? '') ?></td>
                <td class="mono"><?= e($row['action']) ?></td>
                <td><?= e($row['target_type']) ?></td>
                <td class="mono"><?= e($row['target_id']) ?></td>
                <td><?= e($row['detail']) ?></td>
                <td class="mono"><?= e($row['ip']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php require __DIR__ . '/_pager.php'; ?>
</div>
