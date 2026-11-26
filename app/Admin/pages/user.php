<?php
/** @var array $detail */
/** @var array $user */
$row = $detail['user'];
$stats = $detail['stats'];
$self = (string) $row['id'] === (string) $user['id'];
?>
<div class="panel">
    <dl class="meta">
        <dt><?= e(t('admin.user.id')) ?></dt><dd class="mono"><?= e($row['id']) ?></dd>
        <dt><?= e(t('admin.common.username')) ?></dt><dd><?= e($row['username']) ?></dd>
        <dt><?= e(t('admin.common.role')) ?></dt><dd><?= e($row['role'] === 'admin' ? t('admin.users.role_admin') : t('admin.users.role_user')) ?></dd>
        <dt><?= e(t('admin.common.status')) ?></dt><dd><?= e((int) $row['status'] === 1 ? t('admin.common.enabled') : t('admin.common.disabled')) ?></dd>
        <dt><?= e(t('admin.user.api_key')) ?></dt><dd class="mono key-cell"><?= e(($row['api_key'] ?? '') !== '' ? $row['api_key'] : t('common.not_set')) ?></dd>
        <dt><?= e(t('admin.user.password_hash')) ?></dt><dd class="mono key-cell"><?= e($row['password_hash']) ?></dd>
        <dt><?= e(t('admin.common.created')) ?></dt><dd><?= e(fmt_ms((int) $row['created_ms'])) ?></dd>
        <dt><?= e(t('admin.common.updated')) ?></dt><dd><?= e(fmt_ms((int) $row['updated_ms'])) ?></dd>
        <dt><?= e(t('admin.common.last_login')) ?></dt><dd><?= e(fmt_ms($row['last_login_ms'] !== null ? (int) $row['last_login_ms'] : null)) ?></dd>
        <dt><?= e(t('admin.user.active_tokens')) ?></dt><dd><?= (int) $stats['tokens'] ?></dd>
    </dl>
    <div class="quick">
        <a class="btn" href="<?= e(admin_url(['page' => 'users'])) ?>"><?= e(t('common.back')) ?></a>
    </div>
</div>

<div class="panel">
    <h2><?= e(t('admin.user.stats')) ?></h2>
    <dl class="meta">
        <dt><?= e(t('admin.user.sessions')) ?></dt><dd><?= (int) $stats['sessions'] ?></dd>
        <dt><?= e(t('admin.common.messages')) ?></dt><dd><?= (int) $stats['messages'] ?></dd>
        <dt><?= e(t('admin.user.input')) ?></dt><dd><?= number_format((int) $stats['inputTokens']) ?></dd>
        <dt><?= e(t('admin.user.output')) ?></dt><dd><?= number_format((int) $stats['outputTokens']) ?></dd>
        <dt><?= e(t('admin.user.files')) ?></dt><dd><?= (int) $stats['files'] ?></dd>
        <dt><?= e(t('admin.user.file_bytes')) ?></dt><dd><?= e(fmt_bytes((int) $stats['fileBytes'])) ?></dd>
    </dl>
</div>

<div class="panel">
    <h2><?= e(t('admin.user.permissions')) ?></h2>
    <div class="quick">
        <?php if (!$self): ?>
            <form class="mini" method="post">
                <input type="hidden" name="action" value="user_role">
                <input type="hidden" name="csrf" value="<?= e($user['csrf_token']) ?>">
                <input type="hidden" name="back" value="user">
                <input type="hidden" name="id" value="<?= e($row['id']) ?>">
                <label><?= e(t('admin.common.role')) ?>
                    <select name="role" onchange="this.form.submit()">
                        <option value="user"<?= $row['role'] === 'user' ? ' selected' : '' ?>><?= e(t('admin.users.role_user')) ?></option>
                        <option value="admin"<?= $row['role'] === 'admin' ? ' selected' : '' ?>><?= e(t('admin.users.role_admin')) ?></option>
                    </select>
                </label>
            </form>
        <?php endif; ?>
        <form class="mini" method="post">
            <input type="hidden" name="action" value="user_status">
            <input type="hidden" name="csrf" value="<?= e($user['csrf_token']) ?>">
            <input type="hidden" name="back" value="user">
            <input type="hidden" name="id" value="<?= e($row['id']) ?>">
            <input type="hidden" name="status" value="<?= (int) $row['status'] === 1 ? '0' : '1' ?>">
            <button class="btn" type="submit"><?= e((int) $row['status'] === 1 ? t('admin.user.disable_account') : t('admin.user.enable_account')) ?></button>
        </form>
        <form class="mini" method="post">
            <input type="hidden" name="action" value="user_password">
            <input type="hidden" name="csrf" value="<?= e($user['csrf_token']) ?>">
            <input type="hidden" name="back" value="user">
            <input type="hidden" name="id" value="<?= e($row['id']) ?>">
            <input name="password" type="password" placeholder="<?= e(t('admin.user.new_password_placeholder')) ?>" required>
            <button class="btn" type="submit"><?= e(t('admin.users.reset_password')) ?></button>
        </form>
        <?php if (($row['api_key'] ?? '') !== ''): ?>
            <form class="mini" method="post" data-confirm="<?= e(t('admin.users.confirm_clear_key')) ?>">
                <input type="hidden" name="action" value="user_clear_key">
                <input type="hidden" name="csrf" value="<?= e($user['csrf_token']) ?>">
                <input type="hidden" name="back" value="user">
                <input type="hidden" name="id" value="<?= e($row['id']) ?>">
                <button class="link danger" type="submit"><?= e(t('admin.user.clear_key')) ?></button>
            </form>
        <?php endif; ?>
        <?php if (!$self): ?>
            <form class="mini" method="post" data-confirm="<?= e(t('admin.users.confirm_delete')) ?>">
                <input type="hidden" name="action" value="user_delete">
                <input type="hidden" name="csrf" value="<?= e($user['csrf_token']) ?>">
                <input type="hidden" name="back" value="users">
                <input type="hidden" name="id" value="<?= e($row['id']) ?>">
                <button class="link danger" type="submit"><?= e(t('admin.user.delete')) ?></button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="panel">
    <h2><?= e(t('admin.user.recent_sessions')) ?></h2>
    <table>
        <thead>
        <tr><th><?= e(t('admin.common.id')) ?></th><th><?= e(t('admin.common.title')) ?></th><th><?= e(t('admin.common.messages')) ?></th><th><?= e(t('admin.common.token')) ?></th><th><?= e(t('admin.common.updated')) ?></th><th><?= e(t('admin.common.actions')) ?></th></tr>
        </thead>
        <tbody>
        <?php if ($detail['sessions'] === []): ?>
            <tr><td colspan="6" class="muted"><?= e(t('admin.user.empty_sessions')) ?></td></tr>
        <?php endif; ?>
        <?php foreach ($detail['sessions'] as $s): ?>
            <tr>
                <td class="mono"><?= e($s['id']) ?></td>
                <td><?= e($s['title'] !== '' ? $s['title'] : t('common.unnamed')) ?></td>
                <td><?= (int) $s['messages_count'] ?></td>
                <td><?= e(t('admin.user.input')) ?> <?= number_format((int) $s['input_tokens']) ?> / <?= e(t('admin.user.output')) ?> <?= number_format((int) $s['output_tokens']) ?></td>
                <td class="muted"><?= e(fmt_ms((int) $s['updated_ms'])) ?></td>
                <td><a class="link" href="<?= e(admin_url(['page' => 'session', 'id' => $s['id']])) ?>"><?= e(t('common.view')) ?></a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="panel">
    <h2><?= e(t('admin.user.recent_files')) ?></h2>
    <table>
        <thead>
        <tr><th><?= e(t('admin.common.id')) ?></th><th><?= e(t('admin.common.name')) ?></th><th><?= e(t('admin.common.type')) ?></th><th><?= e(t('admin.common.size')) ?></th><th><?= e(t('admin.common.uploaded')) ?></th></tr>
        </thead>
        <tbody>
        <?php if ($detail['files'] === []): ?>
            <tr><td colspan="5" class="muted"><?= e(t('admin.user.empty_files')) ?></td></tr>
        <?php endif; ?>
        <?php foreach ($detail['files'] as $f): ?>
            <tr>
                <td class="mono"><?= e($f['id']) ?></td>
                <td><?= e($f['name'] !== '' ? $f['name'] : t('common.unnamed')) ?></td>
                <td><?= e((int) $f['is_image'] === 1 ? t('admin.user.image') : t('admin.user.file')) ?> · <?= e($f['mime_type']) ?></td>
                <td><?= e(fmt_bytes((int) $f['size'])) ?></td>
                <td class="muted"><?= e(fmt_ms((int) $f['created_ms'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
