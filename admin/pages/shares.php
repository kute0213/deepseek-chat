<?php
/** @var array $list */
$base = ['page' => 'shares', 'q' => $q];
?>
<form class="toolbar" method="get" action="/admin/">
    <input type="hidden" name="page" value="shares">
    <input class="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('admin.shares.search_placeholder')) ?>">
    <button class="btn" type="submit"><?= e(t('common.search')) ?></button>
</form>

<div class="panel">
    <table>
        <thead>
        <tr>
            <th><?= e(t('admin.common.title')) ?></th>
            <th><?= e(t('admin.shares.sharer')) ?></th>
            <th><?= e(t('admin.common.session_id')) ?></th>
            <th><?= e(t('admin.shares.views')) ?></th>
            <th><?= e(t('admin.common.status')) ?></th>
            <th><?= e(t('admin.common.created_at')) ?></th>
            <th><?= e(t('admin.common.actions')) ?></th>
        </tr>
        </thead>
        <tbody>
        <?php if ($list['rows'] === []): ?>
            <tr><td colspan="7" class="muted"><?= e(t('admin.shares.empty')) ?></td></tr>
        <?php endif; ?>
        <?php foreach ($list['rows'] as $row): ?>
            <tr>
                <td>
                    <a class="link" href="/?share=<?= e($row['token']) ?>" target="_blank" rel="noopener"><?= e($row['title'] !== '' ? $row['title'] : t('common.unnamed')) ?></a>
                    <div class="mono muted"><?= e($row['token']) ?></div>
                </td>
                <td><?= e($row['username']) ?></td>
                <td class="mono"><?= e($row['session_id']) ?></td>
                <td><?= (int) $row['views'] ?></td>
                <td><?= (int) $row['revoked'] === 1 ? '<span class="tag danger">' . e(t('admin.shares.revoked')) . '</span>' : '<span class="tag ok">' . e(t('admin.shares.active')) . '</span>' ?></td>
                <td class="muted"><?= e(fmt_ms((int) $row['created_ms'])) ?></td>
                <td class="actions">
                    <?php if ((int) $row['revoked'] === 1): ?>
                        <form class="mini" method="post">
                            <input type="hidden" name="action" value="share_restore">
                            <input type="hidden" name="csrf" value="<?= e($user['csrf_token']) ?>">
                            <input type="hidden" name="back" value="shares">
                            <input type="hidden" name="id" value="<?= e($row['token']) ?>">
                            <button class="link" type="submit"><?= e(t('admin.shares.restore')) ?></button>
                        </form>
                    <?php else: ?>
                        <form class="mini" method="post" data-confirm="<?= e(t('admin.shares.confirm_revoke')) ?>">
                            <input type="hidden" name="action" value="share_revoke">
                            <input type="hidden" name="csrf" value="<?= e($user['csrf_token']) ?>">
                            <input type="hidden" name="back" value="shares">
                            <input type="hidden" name="id" value="<?= e($row['token']) ?>">
                            <button class="link danger" type="submit"><?= e(t('admin.shares.revoke')) ?></button>
                        </form>
                    <?php endif; ?>
                    <form class="mini" method="post" data-confirm="<?= e(t('admin.shares.confirm_delete')) ?>">
                        <input type="hidden" name="action" value="share_delete">
                        <input type="hidden" name="csrf" value="<?= e($user['csrf_token']) ?>">
                        <input type="hidden" name="id" value="<?= e($row['token']) ?>">
                        <button class="link danger" type="submit"><?= e(t('common.delete')) ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php require __DIR__ . '/_pager.php'; ?>
</div>
