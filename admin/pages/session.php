<?php
/** @var array $session */
$messages = is_array($session['data']['messages'] ?? null) ? $session['data']['messages'] : [];
?>
<div class="panel">
    <dl class="meta">
        <dt><?= e(t('admin.common.session_id')) ?></dt><dd class="mono"><?= e($session['id']) ?></dd>
        <dt><?= e(t('admin.common.title')) ?></dt><dd><?= e($session['title'] !== '' ? $session['title'] : t('common.unnamed')) ?></dd>
        <dt><?= e(t('admin.session.owner')) ?></dt><dd><?= e($session['username']) ?></dd>
        <dt><?= e(t('admin.common.messages')) ?></dt><dd><?= (int) $session['messages_count'] ?></dd>
        <dt><?= e(t('admin.common.token')) ?></dt><dd><?= e(t('admin.dashboard.input')) ?> <?= number_format((int) $session['input_tokens']) ?> / <?= e(t('admin.dashboard.output')) ?> <?= number_format((int) $session['output_tokens']) ?></dd>
        <dt><?= e(t('admin.common.created_at')) ?></dt><dd><?= e(fmt_ms((int) $session['created_ms'])) ?></dd>
        <dt><?= e(t('admin.common.updated')) ?></dt><dd><?= e(fmt_ms((int) $session['updated_ms'])) ?></dd>
    </dl>
    <div class="quick">
        <a class="btn" href="<?= e(admin_url(['page' => 'sessions'])) ?>"><?= e(t('common.back')) ?></a>
        <form class="mini" method="post" data-confirm="<?= e(t('admin.session.confirm_delete')) ?>">
            <input type="hidden" name="action" value="session_delete">
            <input type="hidden" name="csrf" value="<?= e($user['csrf_token']) ?>">
            <input type="hidden" name="back" value="sessions">
            <input type="hidden" name="id" value="<?= e($session['id']) ?>">
            <button class="btn danger" type="submit"><?= e(t('admin.session.delete')) ?></button>
        </form>
    </div>
</div>

<div class="panel">
    <h2><?= e(t('admin.session.messages')) ?></h2>
    <table>
        <thead>
        <tr><th><?= e(t('admin.common.id')) ?></th><th><?= e(t('admin.common.role')) ?></th><th><?= e(t('admin.session.content')) ?></th><th><?= e(t('admin.session.attachments')) ?></th></tr>
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
                <td><?= e($role === 'user' ? t('admin.role.user') : ($role === 'assistant' ? t('admin.role.assistant') : $role)) ?></td>
                <td class="preview"><?= e(mb_substr(trim($text), 0, 120)) ?></td>
                <td><?= $fileCount ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<details class="panel">
    <summary><?= e(t('admin.session.raw_json')) ?></summary>
    <pre class="json"><?= e(json_encode($session['data'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '') ?></pre>
</details>
