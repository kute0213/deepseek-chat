<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\DeepSeekClient;
use App\Services\AdminService;
use App\Services\SessionStore;
use App\Services\UserService;
use App\Support\Auth;
use App\Support\HttpError;
use App\Support\Request;
use App\Support\Response;

final class AccountController
{
    public function changePassword(array $params, Request $request): void
    {
        $user = Auth::requireUser();
        $body = $request->json();
        $current = (string) ($body['currentPassword'] ?? '');
        $next = (string) ($body['newPassword'] ?? '');

        $stmt = \App\Support\Database::pdo()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        $hash = (string) $stmt->fetchColumn();
        if (!password_verify($current, $hash)) {
            throw new HttpError('当前密码不正确', 422);
        }
        if ($current === $next) {
            throw new HttpError('新密码不能与当前密码相同', 422);
        }

        UserService::updatePassword((string) $user['id'], $next);
        Auth::revokeOtherTokens((string) $user['id']);
        Response::json(['ok' => true]);
    }

    public function renameUsername(array $params, Request $request): void
    {
        $user = Auth::requireUser();
        $body = $request->json();
        $username = trim((string) ($body['username'] ?? ''));
        if ($username === (string) $user['username']) {
            Response::json(['ok' => true, 'username' => $username]);
            return;
        }
        $updated = UserService::rename((string) $user['id'], $username);
        Response::json(['ok' => true, 'username' => $updated]);
    }

    public function saveApiKey(array $params, Request $request): void
    {
        $user = Auth::requireUser();
        $body = $request->json();
        $key = trim((string) ($body['apiKey'] ?? ''));
        if ($key === '' || strlen($key) < 10) {
            throw new HttpError('API Key 格式不正确', 422);
        }
        if (!DeepSeekClient::verifyKey($key)) {
            throw new HttpError('API Key 校验失败，请确认密钥有效', 422);
        }
        $mask = UserService::saveApiKey((string) $user['id'], $key);
        Response::json(['apiKeyMask' => $mask, 'hasApiKey' => true]);
    }

    public function deleteApiKey(array $params, Request $request): void
    {
        $user = Auth::requireUser();
        UserService::deleteApiKey((string) $user['id']);
        Response::json(['ok' => true]);
    }

    public function balance(array $params, Request $request): void
    {
        $user = Auth::requireUser();
        $key = (string) ($user['api_key'] ?? '');
        if ($key === '') {
            throw new HttpError('请先配置 API Key', 422);
        }
        Response::json(['balance' => DeepSeekClient::balance($key)]);
    }

    /** Token 用量：总输入 / 输出与按会话明细 */
    public function usage(array $params, Request $request): void
    {
        $user = Auth::requireUser();
        Response::json(SessionStore::usage((string) $user['id']));
    }

    /** 注销账号：校验当前密码后删除账号及全部数据 */
    public function destroy(array $params, Request $request): void
    {
        $user = Auth::requireUser();
        $body = $request->json();
        $password = (string) ($body['password'] ?? '');
        if (!password_verify($password, (string) $user['password_hash'])) {
            throw new HttpError('当前密码不正确', 422);
        }
        if ((string) $user['role'] === 'admin') {
            $stmt = \App\Support\Database::pdo()->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 1");
            $stmt->execute();
            if ((int) $stmt->fetchColumn() <= 1) {
                throw new HttpError('不能注销唯一的管理员账号', 422);
            }
        }
        AdminService::deleteUser((string) $user['id']);
        Auth::logout();
        Response::json(['ok' => true]);
    }
}
