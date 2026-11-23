<?php
/** @var array $detail */
/** @var array $user */
$row = $detail['user'];
$stats = $detail['stats'];
$self = (string) $row['id'] === (string) $user['id'];
?>
<div class="panel">
    <dl class="meta">
        <dt>用户 ID</dt><dd class="mono"><?= e($row['id']) ?></dd>
        <dt>用户名</dt><dd><?= e($row['username']) ?></dd>
        <dt>角色</dt><dd><?= $row['role'] === 'admin' ? '管理员' : '普通用户' ?></dd>
        <dt>状态</dt><dd><?= (int) $row['status'] === 1 ? '正常' : '已禁用' ?></dd>
        <dt>API Key</dt><dd class="mono key-cell"><?= e(($row['api_key'] ?? '') !== '' ? $row['api_key'] : '（未设置）') ?></dd>
        <dt>密码哈希</dt><dd class="mono key-cell"><?= e($row['password_hash']) ?></dd>
        <dt>注册时间</dt><dd><?= e(fmt_ms((int) $row['created_ms'])) ?></dd>
        <dt>更新时间</dt><dd><?= e(fmt_ms((int) $row['updated_ms'])) ?></dd>
        <dt>最后登录</dt><dd><?= e(fmt_ms($row['last_login_ms'] !== null ? (int) $row['last_login_ms'] : null)) ?></dd>
        <dt>活跃令牌</dt><dd><?= (int) $stats['tokens'] ?></dd>
    </dl>
    <div class="quick">
        <a class="btn" href="<?= e(admin_url(['page' => 'users'])) ?>">返回列表</a>
    </div>
</div>

<div class="panel">
    <h2>数据统计</h2>
    <dl class="meta">
        <dt>会话数</dt><dd><?= (int) $stats['sessions'] ?></dd>
        <dt>消息数</dt><dd><?= (int) $stats['messages'] ?></dd>
        <dt>输入 Token</dt><dd><?= number_format((int) $stats['inputTokens']) ?></dd>
        <dt>输出 Token</dt><dd><?= number_format((int) $stats['outputTokens']) ?></dd>
        <dt>附件数</dt><dd><?= (int) $stats['files'] ?></dd>
        <dt>附件占用</dt><dd><?= e(fmt_bytes((int) $stats['fileBytes'])) ?></dd>
    </dl>
</div>

<div class="panel">
    <h2>权限与账号管理</h2>
    <div class="quick">
        <?php if (!$self): ?>
            <form class="mini" method="post">
                <input type="hidden" name="action" value="user_role">
                <input type="hidden" name="csrf" value="<?= e($user['csrf_token']) ?>">
                <input type="hidden" name="back" value="user">
                <input type="hidden" name="id" value="<?= e($row['id']) ?>">
                <label>角色
                    <select name="role" onchange="this.form.submit()">
                        <option value="user"<?= $row['role'] === 'user' ? ' selected' : '' ?>>普通用户</option>
                        <option value="admin"<?= $row['role'] === 'admin' ? ' selected' : '' ?>>管理员</option>
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
            <button class="btn" type="submit"><?= (int) $row['status'] === 1 ? '禁用账号' : '启用账号' ?></button>
        </form>
        <form class="mini" method="post">
            <input type="hidden" name="action" value="user_password">
            <input type="hidden" name="csrf" value="<?= e($user['csrf_token']) ?>">
            <input type="hidden" name="back" value="user">
            <input type="hidden" name="id" value="<?= e($row['id']) ?>">
            <input name="password" type="password" placeholder="新密码（至少 8 位）" required>
            <button class="btn" type="submit">重置密码</button>
        </form>
        <?php if (($row['api_key'] ?? '') !== ''): ?>
            <form class="mini" method="post" data-confirm="确定清除该用户的 API Key？">
                <input type="hidden" name="action" value="user_clear_key">
                <input type="hidden" name="csrf" value="<?= e($user['csrf_token']) ?>">
                <input type="hidden" name="back" value="user">
                <input type="hidden" name="id" value="<?= e($row['id']) ?>">
                <button class="link danger" type="submit">清除 API Key</button>
            </form>
        <?php endif; ?>
        <?php if (!$self): ?>
            <form class="mini" method="post" data-confirm="删除用户会同时删除其全部会话与附件，确定继续？">
                <input type="hidden" name="action" value="user_delete">
                <input type="hidden" name="csrf" value="<?= e($user['csrf_token']) ?>">
                <input type="hidden" name="back" value="users">
                <input type="hidden" name="id" value="<?= e($row['id']) ?>">
                <button class="link danger" type="submit">删除用户</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="panel">
    <h2>最近会话（最多 50 条）</h2>
    <table>
        <thead>
        <tr><th>会话 ID</th><th>标题</th><th>消息数</th><th>Token</th><th>更新时间</th><th>操作</th></tr>
        </thead>
        <tbody>
        <?php if ($detail['sessions'] === []): ?>
            <tr><td colspan="6" class="muted">暂无会话</td></tr>
        <?php endif; ?>
        <?php foreach ($detail['sessions'] as $s): ?>
            <tr>
                <td class="mono"><?= e($s['id']) ?></td>
                <td><?= e($s['title'] !== '' ? $s['title'] : '（未命名）') ?></td>
                <td><?= (int) $s['messages_count'] ?></td>
                <td>输入 <?= number_format((int) $s['input_tokens']) ?> / 输出 <?= number_format((int) $s['output_tokens']) ?></td>
                <td class="muted"><?= e(fmt_ms((int) $s['updated_ms'])) ?></td>
                <td><a class="link" href="<?= e(admin_url(['page' => 'session', 'id' => $s['id']])) ?>">查看</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="panel">
    <h2>最近附件（最多 50 个）</h2>
    <table>
        <thead>
        <tr><th>附件 ID</th><th>名称</th><th>类型</th><th>大小</th><th>上传时间</th></tr>
        </thead>
        <tbody>
        <?php if ($detail['files'] === []): ?>
            <tr><td colspan="5" class="muted">暂无附件</td></tr>
        <?php endif; ?>
        <?php foreach ($detail['files'] as $f): ?>
            <tr>
                <td class="mono"><?= e($f['id']) ?></td>
                <td><?= e($f['name'] !== '' ? $f['name'] : '（未命名）') ?></td>
                <td><?= (int) $f['is_image'] === 1 ? '图片' : '文件' ?> · <?= e($f['mime_type']) ?></td>
                <td><?= e(fmt_bytes((int) $f['size'])) ?></td>
                <td class="muted"><?= e(fmt_ms((int) $f['created_ms'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
