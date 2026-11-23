<?php
/** @var array $settings */
?>
<form class="panel form" method="post">
    <input type="hidden" name="action" value="settings_save">
    <input type="hidden" name="csrf" value="<?= e($user['csrf_token']) ?>">
    <input type="hidden" name="back" value="settings">
    <label>
        <span>站点名称</span>
        <input name="site_name" value="<?= e($settings['site_name'] ?? 'DeepSeek') ?>" required>
    </label>
    <label>
        <span>公告（显示在登录页，可留空）</span>
        <textarea name="announcement" rows="4"><?= e($settings['announcement'] ?? '') ?></textarea>
    </label>
    <label class="check">
        <input type="checkbox" name="allow_register" value="1"<?= ($settings['allow_register'] ?? '1') === '1' ? ' checked' : '' ?>>
        <span>开放注册</span>
    </label>
    <label>
        <span>单个附件大小上限（MB）</span>
        <input name="max_upload_mb" type="number" min="1" max="512" value="<?= e($settings['max_upload_mb'] ?? '64') ?>">
    </label>
    <label>
        <span>单用户会话数量上限（0 为不限制）</span>
        <input name="max_sessions_per_user" type="number" min="0" value="<?= e($settings['max_sessions_per_user'] ?? '0') ?>">
    </label>
    <label>
        <span>DeepSeek API 地址</span>
        <input name="api_base_url" value="<?= e($settings['api_base_url'] ?? 'https://api.deepseek.com') ?>">
    </label>
    <div class="form-actions">
        <button class="btn primary" type="submit">保存设置</button>
    </div>
</form>
