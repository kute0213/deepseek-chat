<?php
/** @var array $list */
$base = ['page' => 'sessions', 'q' => $q];
?>
<form class="toolbar" method="get" action="/admin/">
    <input type="hidden" name="page" value="sessions">
    <input class="search" name="q" value="<?= e($q) ?>" placeholder="搜索标题、用户名或会话 ID">
    <button class="btn" type="submit">搜索</button>
</form>

<div class="panel">
    <table>
        <thead>
        <tr>
            <th>会话 ID</th>
            <th>标题</th>
            <th>所属用户</th>
            <th>消息数</th>
            <th>Token（入 / 出）</th>
            <th>更新时间</th>
            <th>操作</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($list['rows'] === []): ?>
            <tr><td colspan="7" class="muted">没有符合条件的会话</td></tr>
        <?php endif; ?>
        <?php foreach ($list['rows'] as $row): ?>
            <tr>
                <td class="mono"><?= e($row['id']) ?></td>
                <td><a class="link" href="<?= e(admin_url(['page' => 'session', 'id' => $row['id']])) ?>"><?= e($row['title'] !== '' ? $row['title'] : '（未命名）') ?></a></td>
                <td><?= e($row['username']) ?></td>
                <td><?= (int) $row['messages_count'] ?></td>
                <td class="mono"><?= number_format((int) $row['input_tokens']) ?> / <?= number_format((int) $row['output_tokens']) ?></td>
                <td class="muted"><?= e(fmt_ms((int) $row['updated_ms'])) ?></td>
                <td class="actions">
                    <form class="mini" method="post" onsubmit="return confirm('确定删除该会话？')">
                        <input type="hidden" name="action" value="session_delete">
                        <input type="hidden" name="csrf" value="<?= e($user['csrf_token']) ?>">
                        <input type="hidden" name="back" value="sessions">
                        <input type="hidden" name="id" value="<?= e($row['id']) ?>">
                        <button class="link danger" type="submit">删除</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php require __DIR__ . '/_pager.php'; ?>
</div>
