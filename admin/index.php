<?php
declare(strict_types=1);

use App\Bootstrap;
use App\Services\AdminService;
use App\Services\Captcha;
use App\Services\SiteSettings;
use App\Services\UserService;
use App\Support\Auth;
use App\Support\Request;

require dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::boot();

const ADMIN_PER_PAGE = 20;

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
            admin_redirect('login', null, '用户名或密码错误');
        }
        if ((string) $row['role'] !== 'admin') {
            admin_redirect('login', null, '该账号没有管理权限');
        }
        if ((int) $row['status'] !== 1) {
            admin_redirect('login', null, '该账号已被禁用');
        }
        UserService::clearLoginFailures($username, $ip);
        Auth::login((string) $row['id']);
        admin_redirect('dashboard', '登录成功');
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
        admin_redirect('dashboard', null, '表单已过期，请重试');
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
                AdminService::log((string) $user['id'], 'user.create', 'user', '', trim((string) ($_POST['username'] ?? '')));
                admin_redirect('users', '用户已创建');
            case 'user_role':
                if ($id === (string) $user['id']) {
                    throw new \App\Support\HttpError('不能修改自己的角色', 422);
                }
                $role = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
                AdminService::setRole($id, $role);
                AdminService::log((string) $user['id'], 'user.role', 'user', $id, $role);
                admin_redirect($back, '角色已更新', null, $backExtra);
            case 'user_status':
                $enabled = ($_POST['status'] ?? '1') === '1';
                AdminService::setStatus($id, $enabled);
                AdminService::log((string) $user['id'], 'user.status', 'user', $id, $enabled ? 'enabled' : 'disabled');
                admin_redirect($back, '状态已更新', null, $backExtra);
            case 'user_password':
                $password = (string) ($_POST['password'] ?? '');
                if (strlen($password) < 8) {
                    throw new \App\Support\HttpError('新密码至少 8 位', 422);
                }
                UserService::updatePassword($id, $password);
                Auth::revokeOtherTokens($id);
                AdminService::log((string) $user['id'], 'user.password', 'user', $id);
                admin_redirect($back, '密码已重置', null, $backExtra);
            case 'user_clear_key':
                UserService::deleteApiKey($id);
                AdminService::log((string) $user['id'], 'user.apiKey.clear', 'user', $id);
                admin_redirect($back, 'API Key 已清除', null, $backExtra);
            case 'user_delete':
                if ($id === (string) $user['id']) {
                    throw new \App\Support\HttpError('不能删除当前登录的账号', 422);
                }
                AdminService::deleteUser($id);
                AdminService::log((string) $user['id'], 'user.delete', 'user', $id);
                admin_redirect('users', '用户及其数据已删除');
            case 'session_delete':
                AdminService::deleteSession($id);
                AdminService::log((string) $user['id'], 'session.delete', 'session', $id);
                admin_redirect('sessions', '会话已删除');
            case 'settings_save':
                $fields = [
                    'site_name' => mb_substr(trim((string) ($_POST['site_name'] ?? '')), 0, 64),
                    'announcement' => mb_substr(trim((string) ($_POST['announcement'] ?? '')), 0, 2000),
                    'allow_register' => isset($_POST['allow_register']) ? '1' : '0',
                    'max_upload_mb' => (string) max(1, min(512, (int) ($_POST['max_upload_mb'] ?? 64))),
                    'max_sessions_per_user' => (string) max(0, (int) ($_POST['max_sessions_per_user'] ?? 0)),
                    'api_base_url' => rtrim(trim((string) ($_POST['api_base_url'] ?? '')), '/'),
                ];
                if ($fields['site_name'] === '') {
                    throw new \App\Support\HttpError('站点名称不能为空', 422);
                }
                foreach ($fields as $key => $value) {
                    SiteSettings::set($key, $value);
                }
                AdminService::log((string) $user['id'], 'settings.update', 'setting', 'site');
                admin_redirect('settings', '设置已保存');
            default:
                admin_redirect('dashboard', null, '未知操作');
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
        fputcsv($out, ['ID', '用户名', '角色', '状态', 'API Key', '会话数', '注册时间', '最后登录']);
        foreach (AdminService::listUsers((string) ($_GET['q'] ?? ''), 1, 10000)['rows'] as $row) {
            fputcsv($out, [
                $row['id'], $row['username'], $row['role'], (int) $row['status'] === 1 ? '正常' : '禁用',
                $row['api_key'] ?? '', (int) $row['sessions_count'],
                fmt_ms((int) $row['created_ms']), fmt_ms($row['last_login_ms'] !== null ? (int) $row['last_login_ms'] : null),
            ]);
        }
    } elseif ($export === 'logs') {
        fputcsv($out, ['时间', '管理员', '操作', '对象', '对象 ID', '详情', 'IP']);
        foreach (AdminService::listLogs(1, 10000)['rows'] as $row) {
            fputcsv($out, [
                fmt_ms((int) $row['created_ms']), $row['username'] ?? '',
                $row['action'], $row['target_type'], $row['target_id'], $row['detail'], $row['ip'],
            ]);
        }
    }
    fclose($out);
    exit;
}

/* ---------------------------------------------------------------- 页面数据 */

$page = (string) ($_GET['page'] ?? 'dashboard');
$pages = ['dashboard', 'users', 'user', 'sessions', 'session', 'settings', 'logs'];
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
            admin_redirect('users', null, '用户不存在');
        }
        $data = ['detail' => $detail];
        break;
    case 'sessions':
        $data = ['list' => AdminService::listSessions($q, $pageNo, ADMIN_PER_PAGE)];
        break;
    case 'session':
        $session = AdminService::getSession((string) ($_GET['id'] ?? ''));
        if ($session === null) {
            admin_redirect('sessions', null, '会话不存在');
        }
        $data = ['session' => $session];
        break;
    case 'settings':
        $data = ['settings' => SiteSettings::all()];
        break;
    case 'logs':
        $data = ['list' => AdminService::listLogs($pageNo, ADMIN_PER_PAGE)];
        break;
}

$titles = [
    'dashboard' => '概览',
    'users' => '用户管理',
    'user' => '用户详情',
    'sessions' => '会话管理',
    'session' => '会话详情',
    'settings' => '站点设置',
    'logs' => '操作日志',
];
$title = $titles[$page];
$ok = isset($_GET['ok']) ? (string) $_GET['ok'] : null;
$error = isset($_GET['err']) ? (string) $_GET['err'] : null;
extract($data);

require __DIR__ . '/pages/layout.php';
