<?php
/** @var array $session */
$messages = is_array($session['data']['messages'] ?? null) ? $session['data']['messages'] : [];
?>
<div class="panel">
    <dl class="meta">
        <dt>会话 ID</dt><dd class="mono"><?= e($session['id']) ?></dd>
        <dt>标题</dt><dd><?= e($session['title'] !== '' ? $session['title'] : '（未命名）') ?></dd>
        <dt>所属用户</dt><dd><?= e($session['username']) ?></dd>
        <dt>消息数</dt><dd><?= (int) $session['messages_count'] ?></dd>
        <dt>Token</dt><dd>输入 <?= number_format((int) $session['input_tokens']) ?> / 输出 <?= number_format((int) $session['output_tokens']) ?></dd>
        <dt>创建时间</dt><dd><?= e(fmt_ms((int) $session['created_ms'])) ?></dd>
        <dt>更新时间</dt><dd><?= e(fmt_ms((int) $session['updated_ms'])) ?></dd>
    </dl>
    <div class="quick">
        <a class="btn" href="<?= e(admin_url(['page' => 'sessions'])) ?>">返回列表</a>
        <form class="mini" method="post" onsubmit="return confirm('确定删除该会话？')">
            <input type="hidden" name="action" value="session_delete">
            <input type="hidden" name="csrf" value="<?= e($user['csrf_token']) ?>">
            <input type="hidden" name="back" value="sessions">
            <input type="hidden" name="id" value="<?= e($session['id']) ?>">
            <button class="btn danger" type="submit">删除会话</button>
        </form>
    </div>
</div>

<div class="panel">
    <h2>消息列表</h2>
    <table>
        <thead>
        <tr><th>ID</th><th>角色</th><th>内容摘要</th><th>附件</th></tr>
        </thead>
        <tbody>
        <?php foreach ($messages as $message): ?>
            <?php
            $role = (string) ($message['role'] ?? '');
            $text = '';
            $blocks = is_array($message['blocks'] ?? null) ? $message['blocks'] : [];
            foreach ($blocks as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $text .= (string) ($block['text'] ?? '');
                }
            }
            if ($text === '' && isset($message['content']) && is_string($message['content'])) {
                $text = $message['content'];
            }
            $fileCount = is_array($message['files'] ?? null) ? count($message['files']) : 0;
            ?>
            <tr>
                <td class="mono"><?= e($message['id'] ?? '') ?></td>
                <td><?= e($role === 'user' ? '用户' : ($role === 'assistant' ? '助手' : $role)) ?></td>
                <td class="preview"><?= e(mb_substr(trim($text), 0, 120)) ?></td>
                <td><?= $fileCount ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<details class="panel">
    <summary>原始 JSON</summary>
    <pre class="json"><?= e(json_encode($session['data'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '') ?></pre>
</details>
