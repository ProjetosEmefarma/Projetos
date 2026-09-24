<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Log;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\Audit;
use App\Services\LoginThrottle;
use App\Services\Mailer;
use App\Services\SettingsService;
use App\Support\Present;
use Throwable;

final class AuthController
{
    public function csrf(Request $request): Response
    {
        return Response::ok(['csrf_token' => Csrf::token()]);
    }

    public function login(Request $request): Response
    {
        $email = mb_strtolower(trim((string) $request->input('email', '')));
        $password = (string) $request->input('password', '');
        $errors = [];
        if ($email === '') {
            $errors['email'] = 'Informe o e-mail.';
        }
        if ($password === '') {
            $errors['password'] = 'Informe a senha.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        LoginThrottle::check($email, $request->ip);

        $user = Db::fetch('SELECT * FROM users WHERE email = ? AND deleted_at IS NULL', [$email]);
        if ($user === null || !password_verify($password, $user['password_hash'])) {
            LoginThrottle::hit($email, $request->ip, false);
            throw new HttpException(401, 'INVALID_CREDENTIALS', 'E-mail ou senha inválidos.');
        }
        if (!(int) $user['active']) {
            LoginThrottle::hit($email, $request->ip, false);
            throw HttpException::forbidden('Usuário inativo. Procure o administrador do sistema.', 'USER_INACTIVE');
        }

        LoginThrottle::hit($email, $request->ip, true);
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            Db::update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], ['id' => $user['id']]);
        }
        Auth::login($user);
        Audit::log('login', 'user', (int) $user['id'], null, null, $user['name']);

        return Response::ok($this->sessionPayload());
    }

    public function logout(Request $request): Response
    {
        $user = Auth::user();
        Audit::log('logout', 'user', (int) $user['id'], null, null, $user['name']);
        Auth::logout();
        return Response::ok(['csrf_token' => Csrf::token()]);
    }

    public function me(Request $request): Response
    {
        return Response::ok($this->sessionPayload());
    }

    public function changePassword(Request $request): Response
    {
        $user = Auth::user();
        $current = (string) $request->input('current_password', '');
        $new = (string) $request->input('new_password', '');
        $confirm = (string) $request->input('new_password_confirmation', '');

        if (!password_verify($current, $user['password_hash'])) {
            throw HttpException::validation(['current_password' => 'Senha atual incorreta.']);
        }
        Validator::password('new_password', $new);
        if ($new !== $confirm) {
            throw HttpException::validation(['new_password_confirmation' => 'A confirmação não confere com a nova senha.']);
        }
        if (password_verify($new, $user['password_hash'])) {
            throw HttpException::validation(['new_password' => 'A nova senha deve ser diferente da atual.']);
        }

        Db::transaction(function () use ($user, $new) {
            Db::query(
                'UPDATE users SET password_hash = ?, must_change_password = 0, password_changed_at = ?,
                        session_version = session_version + 1 WHERE id = ?',
                [password_hash($new, PASSWORD_DEFAULT), now(), (int) $user['id']]
            );
            Audit::log('password_change', 'user', (int) $user['id'], null, null, $user['name']);
        });
        // Other sessions of this user are signed out; this one continues.
        Auth::refresh((int) $user['id']);

        return Response::ok($this->sessionPayload());
    }

    public function updateProfile(Request $request): Response
    {
        $user = Auth::user();
        $data = Validator::validate($request->all(), [
            'name' => 'sometimes|required|string|max:150',
            'phone' => 'sometimes|nullable|string|max:30',
        ]);
        if ($data !== []) {
            Db::transaction(function () use ($user, $data) {
                Db::update('users', $data + ['updated_by' => $user['id'], 'updated_at' => now()], ['id' => (int) $user['id']]);
                Audit::logUpdate('user', (int) $user['id'], $user, $data, $data['name'] ?? $user['name']);
            });
            Auth::refresh((int) $user['id']);
        }
        return Response::ok($this->sessionPayload());
    }

    /** Always answers the same message (does not reveal which e-mails exist). */
    public function forgot(Request $request): Response
    {
        $data = Validator::validate($request->all(), ['email' => 'required|email|max:190']);
        $message = 'Se o e-mail estiver cadastrado, você receberá as instruções para redefinir a senha.';

        $user = Db::fetch('SELECT * FROM users WHERE email = ? AND deleted_at IS NULL AND active = 1', [$data['email']]);
        if ($user === null) {
            return Response::ok(['message' => $message]);
        }
        $recent = (int) Db::value(
            'SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at > ?',
            [(int) $user['id'], date('Y-m-d H:i:s', time() - 900)]
        );
        if ($recent >= 3) {
            return Response::ok(['message' => $message]);
        }

        $token = bin2hex(random_bytes(32));
        $minutes = (int) Config::get('security.password_reset_minutes', 60);
        Db::insert('password_resets', [
            'user_id' => (int) $user['id'],
            'token_hash' => hash('sha256', $token),
            'expires_at' => date('Y-m-d H:i:s', time() + $minutes * 60),
            'ip' => $request->ip,
            'created_at' => now(),
        ]);

        $link = Config::get('app.url') . '/redefinir-senha?token=' . $token;
        $body = '<p>Olá, ' . e($user['name']) . '.</p>'
            . '<p>Recebemos um pedido para redefinir sua senha. Clique no botão abaixo (válido por ' . $minutes . ' minutos):</p>'
            . '<p><a href="' . e($link) . '" style="display:inline-block;background:' . e(SettingsService::get('primary_color')) . ';color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none">Redefinir senha</a></p>'
            . '<p style="font-size:12px;color:#6b7280">Se você não fez este pedido, ignore este e-mail.</p>';
        try {
            Mailer::send($user['email'], 'Redefinição de senha', Mailer::layout('Redefinição de senha', $body));
        } catch (Throwable $e) {
            Log::error('Password reset e-mail failed', ['user' => $user['id'], 'error' => $e->getMessage()]);
        }

        return Response::ok(['message' => $message]);
    }

    public function reset(Request $request): Response
    {
        $token = (string) $request->input('token', '');
        $password = (string) $request->input('password', '');
        $confirm = (string) $request->input('password_confirmation', '');

        $reset = $token === '' ? null : Db::fetch(
            'SELECT * FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?',
            [hash('sha256', $token), now()]
        );
        if ($reset === null) {
            throw HttpException::rule('RESET_TOKEN_INVALID', 'Link inválido ou expirado. Solicite uma nova redefinição de senha.');
        }
        Validator::password('password', $password);
        if ($password !== $confirm) {
            throw HttpException::validation(['password_confirmation' => 'A confirmação não confere com a senha.']);
        }

        Db::transaction(function () use ($reset, $password) {
            $user = Db::fetch('SELECT id, name FROM users WHERE id = ?', [(int) $reset['user_id']]);
            Db::query(
                'UPDATE users SET password_hash = ?, must_change_password = 0, password_changed_at = ?,
                        session_version = session_version + 1 WHERE id = ?',
                [password_hash($password, PASSWORD_DEFAULT), now(), (int) $reset['user_id']]
            );
            Db::query('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [now(), (int) $reset['user_id']]);
            Audit::log('password_reset', 'user', (int) $reset['user_id'], null, null, $user['name'] ?? null);
        });

        return Response::ok(['message' => 'Senha redefinida com sucesso. Faça login com a nova senha.']);
    }

    private function sessionPayload(): array
    {
        $user = Auth::user();
        if ($user === null) {
            throw new \RuntimeException('Sessão inconsistente: usuário não carregado após autenticação.');
        }
        return [
            'user' => Present::user($user),
            'permissions' => Auth::permissions(),
            'must_change_password' => (bool) ($user['must_change_password'] ?? false),
            'csrf_token' => Csrf::token(),
            'settings' => SettingsService::branding(),
        ];
    }
}
