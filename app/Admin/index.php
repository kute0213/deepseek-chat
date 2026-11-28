<?php
declare(strict_types=1);

use App\Bootstrap;
use App\Services\AdminService;
use App\Services\Captcha;
use App\Services\DeepSeekClient;
use App\Services\ShareService;
use App\Services\SiteSettings;
use App\Services\UserService;
use App\Support\Auth;
use App\Support\Database;
use App\Support\Request;

require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::boot();
\App\Support\Lang::setLocale(($_COOKIE['dsw_locale'] ?? '') === 'en-US' ? 'en-US' : 'zh-CN');

const ADMIN_PER_PAGE = 20;

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** 当前后台语言：前端写入 dsw_locale Cookie，未设置时使用中文。 */
function admin_locale(): string
{
    return ($_COOKIE['dsw_locale'] ?? '') === 'en-US' ? 'en-US' : 'zh-CN';
}

/** 服务端文案，{name} 形式的占位符会被替换。 */
function t(string $key, array $params = []): string
{
    $text = \App\Support\Lang::get($key, $key);
    foreach ($params as $name => $value) {
        $text = str_replace('{' . $name . '}', (string) $value, $text);
    }
    return $text;
}

function admin_url(array $params = []): string
{
    return '/admin/' . ($params === [] ? '' : '?' . http_build_query($params));
}

function fmt_ms(?int $ms): string
{
    if ($ms === null || $ms <= 0) {
        return '—';
    }
    return date('Y-m-d H:i:s', (int) floor($ms / 1000));
}

function fmt_bytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $value = (float) $bytes;
    $unit = 0;
    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        $unit++;
    }
    return ($unit === 0 ? (string) (int) $value : number_format($value, 1)) . ' ' . $units[$unit];
}

function admin_redirect(string $page, ?string $ok = null, ?string $error = null, array $extra = []): never
{
    $params = ['page' => $page] + $extra;
    if ($ok !== null) {
        $params['ok'] = $ok;
    }
    if ($error !== null) {
        $params['err'] = $error;
    }
    header('Location: ' . admin_url($params));
    exit;
}

$user = Auth::user();
$isAdmin = $user !== null && $user['role'] === 'admin';

/* ---------------------------------------------------------------- 登录 / 退出 */

if (Request::method() === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $ip = Request::ip();
    try {
        Captcha::verify(
            isset($_POST['captcha_id']) ? (string) $_POST['captcha_id'] : '',
            isset($_POST['captcha_code']) ? (string) $_POST['captcha_code'] : ''
        );
        UserService::checkLoginAllowed($username, $ip);
        $row = $username === '' ? null : UserService::findByUsername($username);
        if ($row === null || !password_verify($password, (string) $row['password_hash'])) {
            UserService::recordLoginFailure($username, $ip);
            admin_redirect('login', null, t('admin.flash.bad_credentials'));
        }
        if ((string) $row['role'] !== 'admin') {
            admin_redirect('login', null, t('admin.flash.no_permission'));
        }
        if ((int) $row['status'] !== 1) {
            admin_redirect('login', null, t('admin.flash.disabled'));
        }
        UserService::clearLoginFailures($username, $ip);
        Auth::login((string) $row['id']);
        admin_redirect('dashboard', t('admin.flash.login_success'));
    } catch (\App\Support\HttpError $error) {
        admin_redirect('login', null, $error->getMessage());
    }
}

if (!$isAdmin) {
    $flashError = isset($_GET['err']) ? (string) $_GET['err'] : null;
    $captcha = Captcha::generate(Request::ip());
    require __DIR__ . '/pages/login.php';
    exit;
}

if (Request::method() === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $csrf = (string) ($_POST['csrf'] ?? '');
    if (!hash_equals((string) $user['csrf_token'], $csrf)) {
        admin_redirect('dashboard', null, t('admin.flash.csrf'));
    }
    $id = (string) ($_POST['id'] ?? '');
    $back = (string) ($_POST['back'] ?? 'dashboard');
    $backExtra = $back === 'user' ? ['id' => $id] : [];
    try {
        switch ($action) {
            case 'logout':
                Auth::logout();
                header('Location: /admin/');
                exit;
            case 'create_user':
                $role = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
                AdminService::createUser(trim((string) ($_POST['username'] ?? '')), (string) ($_POST['password'] ?? ''), $role);
                admin_redirect('users', t('admin.flash.user_created'));
            case 'user_role':
                if ($id === (string) $user['id']) {
                    throw new \App\Support\HttpError(t('admin.error.self_role'), 422);
                }
                $role = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
                AdminService::setRole($id, $role);
                admin_redirect($back, t('admin.flash.role_updated'), null, $backExtra);
            case 'user_status':
                $enabled = ($_POST['status'] ?? '1') === '1';
                AdminService::setStatus($id, $enabled);
                admin_redirect($back, t('admin.flash.status_updated'), null, $backExtra);
            case 'user_password':
                $password = (string) ($_POST['password'] ?? '');
                if (strlen($password) < 8) {
                    throw new \App\Support\HttpError(t('admin.error.password_min'), 422);
                }
                UserService::updatePassword($id, $password);
                Auth::revokeOtherTokens($id);
                admin_redirect($back, t('admin.flash.password_reset'), null, $backExtra);
            case 'user_clear_key':
                UserService::deleteApiKey($id);
                admin_redirect($back, t('admin.flash.key_cleared'), null, $backExtra);
            case 'user_delete':
                if ($id === (string) $user['id']) {
                    throw new \App\Support\HttpError(t('admin.error.self_delete'), 422);
                }
                AdminService::deleteUser($id);
                admin_redirect('users', t('admin.flash.user_deleted'));
            case 'session_delete':
                AdminService::deleteSession($id);
                admin_redirect('sessions', t('admin.flash.session_deleted'));
            case 'share_revoke':
                ShareService::setRevoked($id, true);
                admin_redirect($back === 'shares' ? 'shares' : 'dashboard', t('admin.flash.share_revoked'));
            case 'share_restore':
                ShareService::setRevoked($id, false);
                admin_redirect('shares', t('admin.flash.share_restored'));
            case 'share_delete':
                ShareService::delete($id);
                admin_redirect('shares', t('admin.flash.share_deleted'));
            case 'settings_save':
                $fields = [
                    'site_name' => mb_substr(trim((string) ($_POST['site_name'] ?? '')), 0, 64),
                    'announcement' => mb_substr(trim((string) ($_POST['announcement'] ?? '')), 0, 2000),
                    'allow_register' => isset($_POST['allow_register']) ? '1' : '0',
                    'max_upload_mb' => (string) max(1, min(512, (int) ($_POST['max_upload_mb'] ?? 64))),
                    'max_sessions_per_user' => (string) max(0, (int) ($_POST['max_sessions_per_user'] ?? 0)),
                    'api_base_url' => rtrim(trim((string) ($_POST['api_base_url'] ?? '')), '/'),
                    'price_cache_hit' => (string) max(0, (float) ($_POST['price_cache_hit'] ?? 0.02)),
                    'price_cache_miss' => (string) max(0, (float) ($_POST['price_cache_miss'] ?? 1)),
                    'price_output' => (string) max(0, (float) ($_POST['price_output'] ?? 4)),
                    'max_context' => (string) max(1, (int) ($_POST['max_context'] ?? 128000)),
                ];
                if ($fields['site_name'] === '') {
                    throw new \App\Support\HttpError(t('admin.error.site_name'), 422);
                }
                foreach ($fields as $key => $value) {
                    SiteSettings::set($key, $value);
                }
                admin_redirect('settings', t('admin.flash.settings_saved'));
            case 'settings_fetch_context':
                // 自动获取最大上下文：优先用当前管理员已配置的 API Key，否则取任一已配置的 Key
                $key = trim((string) ($user['api_key'] ?? ''));
                if ($key === '') {
                    $key = (string) Database::pdo()->query(
                        "SELECT api_key FROM users WHERE api_key IS NOT NULL AND api_key <> '' ORDER BY updated_ms DESC LIMIT 1"
                    )->fetchColumn();
                }
                if ($key === '') {
                    throw new \App\Support\HttpError(t('admin.error.api_key_required'), 422);
                }
                $context = DeepSeekClient::fetchContextWindow($key);
                SiteSettings::set('max_context', (string) $context);
                admin_redirect('settings', t('admin.flash.context_fetched', [
                    'value' => number_format($context),
                ]));
            default:
                admin_redirect('dashboard', null, t('admin.flash.unknown'));
        }
    } catch (\App\Support\HttpError $error) {
        admin_redirect((string) ($_POST['back'] ?? 'dashboard'), null, $error->getMessage());
    }
}

/* ---------------------------------------------------------------- CSV 导出 */

$export = (string) ($_GET['export'] ?? '');
if ($export !== '') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $export . '-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");
    if ($export === 'users') {
        fputcsv($out, ['ID', t('admin.common.username'), t('admin.common.role'), t('admin.common.status'), 'API Key', t('admin.common.sessions'), t('admin.common.created'), t('admin.common.last_login')]);
        foreach (AdminService::listUsers((string) ($_GET['q'] ?? ''), 1, 10000)['rows'] as $row) {
            fputcsv($out, [
                $row['id'], $row['username'], $row['role'], (int) $row['status'] === 1 ? t('admin.common.enabled') : t('admin.common.disabled'),
                $row['api_key'] ?? '', (int) $row['sessions_count'],
                fmt_ms((int) $row['created_ms']), fmt_ms($row['last_login_ms'] !== null ? (int) $row['last_login_ms'] : null),
            ]);
        }
    }
    fclose($out);
    exit;
}

/* ---------------------------------------------------------------- 页面数据 */

$page = (string) ($_GET['page'] ?? 'dashboard');
$pages = ['dashboard', 'users', 'user', 'sessions', 'session', 'shares', 'settings'];
if (!in_array($page, $pages, true)) {
    $page = 'dashboard';
}
$pageNo = max(1, (int) ($_GET['p'] ?? 1));
$q = trim((string) ($_GET['q'] ?? ''));

$data = [];
switch ($page) {
    case 'dashboard':
        $data = ['stats' => AdminService::stats()];
        break;
    case 'users':
        $data = ['list' => AdminService::listUsers($q, $pageNo, ADMIN_PER_PAGE)];
        break;
    case 'user':
        $detail = AdminService::userDetail((string) ($_GET['id'] ?? ''));
        if ($detail === null) {
            admin_redirect('users', null, t('admin.flash.user_not_found'));
        }
        $data = ['detail' => $detail];
        break;
    case 'sessions':
        $data = ['list' => AdminService::listSessions($q, $pageNo, ADMIN_PER_PAGE)];
        break;
    case 'session':
        $session = AdminService::getSession((string) ($_GET['id'] ?? ''));
        if ($session === null) {
            admin_redirect('sessions', null, t('admin.flash.session_not_found'));
        }
        $data = ['session' => $session];
        break;
    case 'shares':
        $data = ['list' => ShareService::listAll($q, $pageNo, ADMIN_PER_PAGE)];
        break;
    case 'settings':
        $data = ['settings' => SiteSettings::all()];
        break;
}

$titles = [
    'dashboard' => t('admin.page.dashboard'),
    'users' => t('admin.page.users'),
    'user' => t('admin.page.user'),
    'sessions' => t('admin.page.sessions'),
    'session' => t('admin.page.session'),
    'shares' => t('admin.page.shares'),
    'settings' => t('admin.page.settings'),
];
$title = $titles[$page];
$ok = isset($_GET['ok']) ? (string) $_GET['ok'] : null;
$error = isset($_GET['err']) ? (string) $_GET['err'] : null;
extract($data);

// 侧边栏切换时只返回内容片段，前端无刷新替换
if (($_SERVER['HTTP_X_ADMIN_PARTIAL'] ?? '') === '1') {
    if ($ok !== null) {
        echo '<div class="flash ok">' . e($ok) . '</div>';
    }
    if ($error !== null) {
        echo '<div class="flash error">' . e($error) . '</div>';
    }
    require __DIR__ . '/pages/' . $page . '.php';
    exit;
}

require __DIR__ . '/pages/layout.php';
