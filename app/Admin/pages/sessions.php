<?php
/** @var array $list */
$base = ['page' => 'sessions', 'q' => $q];
?>
<form class="toolbar" method="get" action="/admin/">
    <input type="hidden" name="page" value="sessions">
    <input class="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('admin.sessions.search_placeholder')) ?>">
    <button class="btn" type="submit"><?= e(t('common.search')) ?></button>
</form>

<div class="panel">
    <table>
        <thead>
        <tr>
            <th><?= e(t('admin.common.session_id')) ?></th>
            <th><?= e(t('admin.common.title')) ?></th>
            <th><?= e(t('admin.common.username')) ?></th>
            <th><?= e(t('admin.common.messages')) ?></th>
            <th><?= e(t('admin.common.token')) ?>（<?= e(t('admin.dashboard.input')) ?> / <?= e(t('admin.dashboard.output')) ?>）</th>
            <th><?= e(t('admin.common.updated')) ?></th>
            <th><?= e(t('admin.common.actions')) ?></th>
        </tr>
        </thead>
        <tbody>
        <?php if ($list['rows'] === []): ?>
            <tr><td colspan="7" class="muted"><?= e(t('admin.sessions.empty')) ?></td></tr>
        <?php endif; ?>
        <?php foreach ($list['rows'] as $row): ?>
            <tr>
                <td class="mono"><?= e($row['id']) ?></td>
                <td><a class="link" href="<?= e(admin_url(['page' => 'session', 'id' => $row['id']])) ?>"><?= e($row['title'] !== '' ? $row['title'] : t('common.unnamed')) ?></a></td>
                <td><?= e($row['username']) ?></td>
                <td><?= (int) $row['messages_count'] ?></td>
                <td class="mono"><?= number_format((int) $row['input_tokens']) ?> / <?= number_format((int) $row['output_tokens']) ?></td>
                <td class="muted"><?= e(fmt_ms((int) $row['updated_ms'])) ?></td>
                <td class="actions">
                    <form class="mini" method="post" data-confirm="<?= e(t('admin.session.confirm_delete')) ?>">
                        <input type="hidden" name="action" value="session_delete">
                        <input type="hidden" name="csrf" value="<?= e($user['csrf_token']) ?>">
                        <input type="hidden" name="back" value="sessions">
                        <input type="hidden" name="id" value="<?= e($row['id']) ?>">
                        <button class="link danger" type="submit"><?= e(t('common.delete')) ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php require __DIR__ . '/_pager.php'; ?>
</div>
