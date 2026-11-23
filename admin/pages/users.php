<?php
/** @var array $list */
$base = ['page' => 'users', 'q' => $q];
?>
<form class="toolbar" method="get" action="/admin/">
    <input type="hidden" name="page" value="users">
    <input class="search" name="q" value="<?= e($q) ?>" placeholder="搜索用户名或 ID">
    <button class="btn" type="submit">搜索</button>
    <a class="btn" href="<?= e(admin_url(['page' => 'users', 'export' => 'users', 'q' => $q])) ?>">导出 CSV</a>
</form>

<details class="panel">
    <summary>新建用户</summary>
    <form class="inline-form" method="post">
        <input type="hidden" name="action" value="create_user">
        <input type="hidden" name="csrf" value="<?= e($user['csrf_token']) ?>">
        <input type="hidden" name="back" value="users">
        <input name="username" placeholder="用户名（3-32 位字母数字下划线）" required>
        <input name="password" type="password" placeholder="密码（至少 8 位）" required>
        <select name="role">
            <option value="user">普通用户</option>
            <option value="admin">管理员</option>
        </select>
        <button class="btn primary" type="submit">创建</button>
    </form>
</details>

<div class="panel">
    <table>
        <thead>
        <tr>
            <th>ID</th>
            <th>用户名</th>
            <th>角色</th>
            <th>状态</th>
            <th>API Key</th>
            <th>会话</th>
            <th>注册时间</th>
            <th>最后登录</th>
            <th>操作</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($list['rows'] === []): ?>
            <tr><td colspan="9" class="muted">没有符合条件的用户</td></tr>
        <?php endif; ?>
        <?php foreach ($list['rows'] as $row): ?>
            <tr>
                <td class="mono"><?= e($row['id']) ?></td>
                <td><?= e($row['username']) ?></td>
                <td>
                    <?php if ((string) $row['id'] === (string) $user['id']): ?>
                        <span class="tag"><?= $row['role'] === 'admin' ? '管理员' : '用户' ?></span>
                    <?php else: ?>
                        <form class="mini" method="post">
                            <input type="hidden" name="action" value="user_role">
                            <input type="hidden" name="csrf" value="<?= e($user['csrf_token']) ?>">
                            <input type="hidden" name="back" value="users">
                            <input type="hidden" name="id" value="<?= e($row['id']) ?>">
                            <select name="role" onchange="this.form.submit()">
                                <option value="user"<?= $row['role'] === 'user' ? ' selected' : '' ?>>用户</option>
                                <option value="admin"<?= $row['role'] === 'admin' ? ' selected' : '' ?>>管理员</option>
                            </select>
                        </form>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ((int) $row['status'] === 1): ?>
                        <span class="tag ok">正常</span>
                    <?php else: ?>
                        <span class="tag danger">禁用</span>
                    <?php endif; ?>
                </td>
                <td class="mono key-cell"><?= e(($row['api_key'] ?? '') !== '' ? $row['api_key'] : '—') ?></td>
                <td><?= (int) $row['sessions_count'] ?></td>
                <td class="muted"><?= e(fmt_ms((int) $row['created_ms'])) ?></td>
                <td class="muted"><?= e(fmt_ms($row['last_login_ms'] !== null ? (int) $row['last_login_ms'] : null)) ?></td>
                <td class="actions">
                    <a class="link" href="<?= e(admin_url(['page' => 'user', 'id' => $row['id']])) ?>">查看</a>
                    <form class="mini" method="post">
                        <input type="hidden" name="action" value="user_status">
                        <input type="hidden" name="csrf" value="<?= e($user['csrf_token']) ?>">
                        <input type="hidden" name="back" value="users">
                        <input type="hidden" name="id" value="<?= e($row['id']) ?>">
                        <input type="hidden" name="status" value="<?= (int) $row['status'] === 1 ? '0' : '1' ?>">
                        <button class="link" type="submit"><?= (int) $row['status'] === 1 ? '禁用' : '启用' ?></button>
                    </form>
                    <details class="row-detail">
                        <summary class="link">重置密码</summary>
                        <form class="mini" method="post">
                            <input type="hidden" name="action" value="user_password">
                            <input type="hidden" name="csrf" value="<?= e($user['csrf_token']) ?>">
                            <input type="hidden" name="back" value="users">
                            <input type="hidden" name="id" value="<?= e($row['id']) ?>">
                            <input name="password" type="password" placeholder="新密码" required>
                            <button class="btn small" type="submit">保存</button>
                        </form>
                    </details>
                    <?php if (($row['api_key'] ?? '') !== ''): ?>
                        <form class="mini" method="post" data-confirm="确定清除该用户的 API Key？">
                            <input type="hidden" name="action" value="user_clear_key">
                            <input type="hidden" name="csrf" value="<?= e($user['csrf_token']) ?>">
                            <input type="hidden" name="back" value="users">
                            <input type="hidden" name="id" value="<?= e($row['id']) ?>">
                            <button class="link danger" type="submit">清除 Key</button>
                        </form>
                    <?php endif; ?>
                    <?php if ((string) $row['id'] !== (string) $user['id']): ?>
                        <form class="mini" method="post" data-confirm="删除用户会同时删除其全部会话与附件，确定继续？">
                            <input type="hidden" name="action" value="user_delete">
                            <input type="hidden" name="csrf" value="<?= e($user['csrf_token']) ?>">
                            <input type="hidden" name="back" value="users">
                            <input type="hidden" name="id" value="<?= e($row['id']) ?>">
                            <button class="link danger" type="submit">删除</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php require __DIR__ . '/_pager.php'; ?>
</div>
