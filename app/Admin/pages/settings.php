<?php
/** @var array $settings */
?>
<form class="panel form" method="post">
    <input type="hidden" name="action" value="settings_save">
    <input type="hidden" name="csrf" value="<?= e($user['csrf_token']) ?>">
    <input type="hidden" name="back" value="settings">
    <label>
        <span><?= e(t('admin.settings.site_name')) ?></span>
        <input name="site_name" value="<?= e($settings['site_name'] ?? \App\Support\Lang::siteName()) ?>" required>
    </label>
    <label>
        <span><?= e(t('admin.settings.announcement')) ?></span>
        <textarea name="announcement" rows="4"><?= e($settings['announcement'] ?? '') ?></textarea>
    </label>
    <label class="check">
        <input type="checkbox" name="allow_register" value="1"<?= ($settings['allow_register'] ?? '1') === '1' ? ' checked' : '' ?>>
        <span><?= e(t('admin.settings.allow_register')) ?></span>
    </label>
    <label>
        <span><?= e(t('admin.settings.max_upload')) ?></span>
        <input name="max_upload_mb" type="number" min="1" max="512" value="<?= e($settings['max_upload_mb'] ?? '64') ?>">
    </label>
    <label>
        <span><?= e(t('admin.settings.max_sessions')) ?></span>
        <input name="max_sessions_per_user" type="number" min="0" value="<?= e($settings['max_sessions_per_user'] ?? '0') ?>">
    </label>
    <label>
        <span><?= e(t('admin.settings.api_base')) ?></span>
        <input name="api_base_url" value="<?= e($settings['api_base_url'] ?? 'https://api.deepseek.com') ?>">
    </label>
    <label>
        <span><?= e(t('admin.settings.price_cache_hit')) ?></span>
        <input name="price_cache_hit" type="number" step="0.0001" min="0" value="<?= e($settings['price_cache_hit'] ?? '0.02') ?>">
    </label>
    <label>
        <span><?= e(t('admin.settings.price_cache_miss')) ?></span>
        <input name="price_cache_miss" type="number" step="0.0001" min="0" value="<?= e($settings['price_cache_miss'] ?? '1') ?>">
    </label>
    <label>
        <span><?= e(t('admin.settings.price_output')) ?></span>
        <input name="price_output" type="number" step="0.0001" min="0" value="<?= e($settings['price_output'] ?? '4') ?>">
    </label>
    <label>
        <span><?= e(t('admin.settings.max_context')) ?></span>
        <input name="max_context" type="number" step="1" min="1" value="<?= e($settings['max_context'] ?? '128000') ?>">
    </label>
    <div class="form-actions">
        <button class="btn primary" type="submit"><?= e(t('admin.settings.save')) ?></button>
    </div>
</form>
<form class="panel form" method="post">
    <input type="hidden" name="action" value="settings_fetch_context">
    <input type="hidden" name="csrf" value="<?= e($user['csrf_token']) ?>">
    <input type="hidden" name="back" value="settings">
    <p><?= e(t('admin.settings.fetch_hint')) ?></p>
    <div class="form-actions">
        <button class="btn" type="submit"><?= e(t('admin.settings.fetch_context')) ?></button>
    </div>
</form>
