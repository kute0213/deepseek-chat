<?php
/** @var array $list */
$base = ['page' => 'users', 'q' => $q];
?>
<form class="toolbar" method="get" action="/admin/">
    <input type="hidden" name="page" value="users">
    <input class="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('admin.users.search_placeholder')) ?>">
    <button class="btn" type="submit"><?= e(t('common.search')) ?></button>
    <a class="btn" href="<?= e(admin_url(['page' => 'users', 'export' => 'users', 'q' => $q])) ?>"><?= e(t('common.export_csv')) ?></a>
</form>

<details class="panel">
    <summary><?= e(t('admin.users.new')) ?></summary>
    <form class="inline-form" method="post">
        <input type="hidden" name="action" value="create_user">
        <input type="hidden" name="csrf" value="<?= e($user['csrf_token']) ?>">
        <input type="hidden" name="back" value="users">
        <input name="username" placeholder="<?= e(t('admin.users.username_placeholder')) ?>" required>
        <input name="password" type="password" placeholder="<?= e(t('admin.users.password_placeholder')) ?>" required>
        <select name="role">
            <option value="user"><?= e(t('admin.users.role_user')) ?></option>
            <option value="admin"><?= e(t('admin.users.role_admin')) ?></option>
        </select>
        <button class="btn primary" type="submit"><?= e(t('admin.users.create')) ?></button>
    </form>
</details>

<div class="panel">
    <table>
        <thead>
        <tr>
            <th><?= e(t('admin.common.id')) ?></th>
            <th><?= e(t('admin.common.username')) ?></th>
            <th><?= e(t('admin.common.role')) ?></th>
            <th><?= e(t('admin.common.status')) ?></th>
            <th><?= e(t('admin.user.api_key')) ?></th>
            <th><?= e(t('admin.common.sessions')) ?></th>
            <th><?= e(t('admin.common.created')) ?></th>
            <th><?= e(t('admin.common.last_login')) ?></th>
            <th><?= e(t('admin.common.actions')) ?></th>
        </tr>
        </thead>
        <tbody>
        <?php if ($list['rows'] === []): ?>
            <tr><td colspan="9" class="muted"><?= e(t('admin.users.empty')) ?></td></tr>
        <?php endif; ?>
        <?php foreach ($list['rows'] as $row): ?>
            <tr>
                <td class="mono"><?= e($row['id']) ?></td>
                <td><?= e($row['username']) ?></td>
                <td>
                    <?php if ((string) $row['id'] === (string) $user['id']): ?>
                        <span class="tag"><?= e($row['role'] === 'admin' ? t('admin.users.role_admin') : t('admin.users.role_user_short')) ?></span>
                    <?php else: ?>
                        <form class="mini" method="post">
                            <input type="hidden" name="action" value="user_role">
                            <input type="hidden" name="csrf" value="<?= e($user['csrf_token']) ?>">
                            <input type="hidden" name="back" value="users">
                            <input type="hidden" name="id" value="<?= e($row['id']) ?>">
                            <select name="role" onchange="this.form.submit()">
                                <option value="user"<?= $row['role'] === 'user' ? ' selected' : '' ?>><?= e(t('admin.users.role_user')) ?></option>
                                <option value="admin"<?= $row['role'] === 'admin' ? ' selected' : '' ?>><?= e(t('admin.users.role_admin')) ?></option>
                            </select>
                        </form>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ((int) $row['status'] === 1): ?>
                        <span class="tag ok"><?= e(t('admin.common.enabled')) ?></span>
                    <?php else: ?>
                        <span class="tag danger"><?= e(t('admin.common.disabled')) ?></span>
                    <?php endif; ?>
                </td>
                <td class="mono key-cell"><?= e(($row['api_key'] ?? '') !== '' ? $row['api_key'] : t('common.empty_dash')) ?></td>
                <td><?= (int) $row['sessions_count'] ?></td>
                <td class="muted"><?= e(fmt_ms((int) $row['created_ms'])) ?></td>
                <td class="muted"><?= e(fmt_ms($row['last_login_ms'] !== null ? (int) $row['last_login_ms'] : null)) ?></td>
                <td class="actions">
                    <a class="link" href="<?= e(admin_url(['page' => 'user', 'id' => $row['id']])) ?>"><?= e(t('common.view')) ?></a>
                    <form class="mini" method="post">
                        <input type="hidden" name="action" value="user_status">
                        <input type="hidden" name="csrf" value="<?= e($user['csrf_token']) ?>">
                        <input type="hidden" name="back" value="users">
                        <input type="hidden" name="id" value="<?= e($row['id']) ?>">
                        <input type="hidden" name="status" value="<?= (int) $row['status'] === 1 ? '0' : '1' ?>">
                        <button class="link" type="submit"><?= e((int) $row['status'] === 1 ? t('admin.users.disable') : t('admin.users.enable')) ?></button>
                    </form>
                    <details class="row-detail">
                        <summary class="link"><?= e(t('admin.users.reset_password')) ?></summary>
                        <form class="mini" method="post">
                            <input type="hidden" name="action" value="user_password">
                            <input type="hidden" name="csrf" value="<?= e($user['csrf_token']) ?>">
                            <input type="hidden" name="back" value="users">
                            <input type="hidden" name="id" value="<?= e($row['id']) ?>">
                            <input name="password" type="password" placeholder="<?= e(t('admin.users.new_password')) ?>" required>
                            <button class="btn small" type="submit"><?= e(t('common.save')) ?></button>
                        </form>
                    </details>
                    <?php if (($row['api_key'] ?? '') !== ''): ?>
                        <form class="mini" method="post" data-confirm="<?= e(t('admin.users.confirm_clear_key')) ?>">
                            <input type="hidden" name="action" value="user_clear_key">
                            <input type="hidden" name="csrf" value="<?= e($user['csrf_token']) ?>">
                            <input type="hidden" name="back" value="users">
                            <input type="hidden" name="id" value="<?= e($row['id']) ?>">
                            <button class="link danger" type="submit"><?= e(t('admin.users.clear_key')) ?></button>
                        </form>
                    <?php endif; ?>
                    <?php if ((string) $row['id'] !== (string) $user['id']): ?>
                        <form class="mini" method="post" data-confirm="<?= e(t('admin.users.confirm_delete')) ?>">
                            <input type="hidden" name="action" value="user_delete">
                            <input type="hidden" name="csrf" value="<?= e($user['csrf_token']) ?>">
                            <input type="hidden" name="back" value="users">
                            <input type="hidden" name="id" value="<?= e($row['id']) ?>">
                            <button class="link danger" type="submit"><?= e(t('common.delete')) ?></button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php require __DIR__ . '/_pager.php'; ?>
</div>
