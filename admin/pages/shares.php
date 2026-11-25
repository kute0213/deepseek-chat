<?php
/** @var array $list */
$base = ['page' => 'shares', 'q' => $q];
?>
<form class="toolbar" method="get" action="/admin/">
    <input type="hidden" name="page" value="shares">
    <input class="search" name="q" value="<?= e($q) ?>" placeholder="搜索标题、用户名、分享口令或会话 ID">
    <button class="btn" type="submit">搜索</button>
</form>

<div class="panel">
    <table>
        <thead>
        <tr>
            <th>标题</th>
            <th>分享者</th>
            <th>会话 ID</th>
            <th>访问量</th>
            <th>状态</th>
            <th>创建时间</th>
            <th>操作</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($list['rows'] === []): ?>
            <tr><td colspan="7" class="muted">没有符合条件的分享</td></tr>
        <?php endif; ?>
        <?php foreach ($list['rows'] as $row): ?>
            <tr>
                <td>
                    <a class="link" href="/?share=<?= e($row['token']) ?>" target="_blank" rel="noopener"><?= e($row['title'] !== '' ? $row['title'] : '（未命名）') ?></a>
                    <div class="mono muted"><?= e($row['token']) ?></div>
                </td>
                <td><?= e($row['username']) ?></td>
                <td class="mono"><?= e($row['session_id']) ?></td>
                <td><?= (int) $row['views'] ?></td>
                <td><?= (int) $row['revoked'] === 1 ? '<span class="tag danger">已撤销</span>' : '<span class="tag ok">生效中</span>' ?></td>
                <td class="muted"><?= e(fmt_ms((int) $row['created_ms'])) ?></td>
                <td class="actions">
                    <?php if ((int) $row['revoked'] === 1): ?>
                        <form class="mini" method="post">
                            <input type="hidden" name="action" value="share_restore">
                            <input type="hidden" name="csrf" value="<?= e($user['csrf_token']) ?>">
                            <input type="hidden" name="back" value="shares">
                            <input type="hidden" name="id" value="<?= e($row['token']) ?>">
                            <button class="link" type="submit">恢复</button>
                        </form>
                    <?php else: ?>
                        <form class="mini" method="post" data-confirm="撤销后该分享链接将无法访问，确定撤销？">
                            <input type="hidden" name="action" value="share_revoke">
                            <input type="hidden" name="csrf" value="<?= e($user['csrf_token']) ?>">
                            <input type="hidden" name="back" value="shares">
                            <input type="hidden" name="id" value="<?= e($row['token']) ?>">
                            <button class="link danger" type="submit">撤销</button>
                        </form>
                    <?php endif; ?>
                    <form class="mini" method="post" data-confirm="删除后不可恢复，确定删除该分享？">
                        <input type="hidden" name="action" value="share_delete">
                        <input type="hidden" name="csrf" value="<?= e($user['csrf_token']) ?>">
                        <input type="hidden" name="id" value="<?= e($row['token']) ?>">
                        <button class="link danger" type="submit">删除</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php require __DIR__ . '/_pager.php'; ?>
</div>
