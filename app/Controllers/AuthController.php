<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\SiteSettings;
use App\Services\UserService;
use App\Support\Auth;
use App\Support\HttpError;
use App\Support\Request;
use App\Support\Response;

final class AuthController
{
    public function register(array $params, Request $request): void
    {
        if (SiteSettings::get('allow_register', '1') !== '1') {
            throw new HttpError('当前未开放注册', 403);
        }
        $body = $request->json();
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $user = UserService::create($username, $password);
        $login = Auth::login((string) $user['id']);

        Response::json(['user' => UserService::payload($user, $login['csrf'])], 201);
    }

    public function login(array $params, Request $request): void
    {
        $body = $request->json();
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        if ($username === '' || $password === '') {
            throw new HttpError('请输入用户名和密码', 422);
        }

        $ip = Request::ip();
        UserService::checkLoginAllowed($username, $ip);
        $user = UserService::findByUsername($username);
        if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
            UserService::recordLoginFailure($username, $ip);
            throw new HttpError('用户名或密码错误', 401);
        }
        if ((int) $user['status'] !== 1) {
            throw new HttpError('账号已被禁用', 403);
        }

        UserService::clearLoginFailures($username, $ip);
        $now = (int) floor(microtime(true) * 1000);
        $stmt = \App\Support\Database::pdo()->prepare('UPDATE users SET last_login_ms = ? WHERE id = ?');
        $stmt->execute([$now, $user['id']]);
        $user['last_login_ms'] = $now;
        $login = Auth::login((string) $user['id']);

        Response::json(['user' => UserService::payload($user, $login['csrf'])]);
    }

    public function me(array $params, Request $request): void
    {
        $user = Auth::requireUser();
        Response::json(['user' => UserService::payload($user, (string) $user['csrf_token'])]);
    }

    public function logout(array $params, Request $request): void
    {
        Auth::logout();
        Response::json(['ok' => true]);
    }
}
