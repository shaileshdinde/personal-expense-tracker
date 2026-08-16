<?php

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Response.php';
require_once __DIR__ . '/../src/Validator.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Mailer.php';

class AuthController
{
    /**
     * POST /api/register
     * body: name, email, password, phone (optional)
     */
    public static function register(array $input): void
    {
        $errors = Validator::validate($input, [
            'name'     => 'required|min:2|max:150',
            'email'    => 'required|email|max:191',
            'password' => 'required|min:6',
        ]);
        if ($errors) {
            Response::error('Validation failed', 422, $errors);
        }

        $pdo = Database::connection();

        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $input['email']]);
        if ($stmt->fetch()) {
            Response::error('Email is already registered', 409);
        }

        $hash = password_hash($input['password'], PASSWORD_BCRYPT);

        $stmt = $pdo->prepare(
            'INSERT INTO users (name, email, phone, password_hash) VALUES (:name, :email, :phone, :hash)'
        );
        $stmt->execute([
            ':name'  => $input['name'],
            ':email' => $input['email'],
            ':phone' => $input['phone'] ?? null,
            ':hash'  => $hash,
        ]);

        $userId = (int) $pdo->lastInsertId();

        Response::success([
            'user_id' => $userId,
            'name'    => $input['name'],
            'email'   => $input['email'],
        ], 'Registration successful', 201);
    }

    /**
     * POST /api/login
     * body: email, password, device ('web' | 'mobile')
     */
    public static function login(array $input): void
    {
        $errors = Validator::validate($input, [
            'email'    => 'required|email',
            'password' => 'required',
            'device'   => 'required|in:web,mobile',
        ]);
        if ($errors) {
            Response::error('Validation failed', 422, $errors);
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $input['email']]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($input['password'], $user['password_hash'])) {
            Response::error('Invalid email or password', 401);
        }

        if ($user['status'] !== 'active') {
            Response::error('This account has been disabled', 403);
        }

        $tokenData = Auth::issueToken((int) $user['id'], $input['device']);

        Response::success([
            'user' => [
                'id'    => (int) $user['id'],
                'name'  => $user['name'],
                'email' => $user['email'],
                'phone' => $user['phone'],
            ],
            'auth' => $tokenData,
        ], 'Login successful');
    }

    /**
     * POST /api/logout  (requires auth)
     */
    public static function logout(array $tokenPayload): void
    {
        Auth::blacklistToken($tokenPayload['jti'], (int) $tokenPayload['sub'], (int) $tokenPayload['exp']);
        Response::success(null, 'Logged out successfully');
    }

    /**
     * POST /api/forgot-password
     * body: email
     */
    public static function forgotPassword(array $input): void
    {
        $errors = Validator::validate($input, ['email' => 'required|email']);
        if ($errors) {
            Response::error('Validation failed', 422, $errors);
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, name, email FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $input['email']]);
        $user = $stmt->fetch();

        // Always respond success (don't leak which emails are registered)
        if (!$user) {
            Response::success(null, 'If that email is registered, a reset link has been sent');
        }

        $cfg = require __DIR__ . '/../config/config.php';
        $ttlMinutes = $cfg['password_reset']['ttl_minutes'];

        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = date('Y-m-d H:i:s', time() + $ttlMinutes * 60);

        $stmt = $pdo->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (:user_id, :token_hash, :expires_at)'
        );
        $stmt->execute([
            ':user_id'    => $user['id'],
            ':token_hash' => $tokenHash,
            ':expires_at' => $expiresAt,
        ]);

        Mailer::sendPasswordResetEmail($user['email'], $user['name'], $rawToken);

        Response::success(null, 'If that email is registered, a reset link has been sent');
    }

    /**
     * POST /api/reset-password
     * body: email, token, new_password
     */
    public static function resetPassword(array $input): void
    {
        $errors = Validator::validate($input, [
            'email'        => 'required|email',
            'token'        => 'required',
            'new_password' => 'required|min:6',
        ]);
        if ($errors) {
            Response::error('Validation failed', 422, $errors);
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $input['email']]);
        $user = $stmt->fetch();

        if (!$user) {
            Response::error('Invalid token or email', 400);
        }

        $tokenHash = hash('sha256', $input['token']);

        $stmt = $pdo->prepare(
            'SELECT * FROM password_resets
             WHERE user_id = :user_id AND token_hash = :token_hash AND used = 0
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':user_id' => $user['id'], ':token_hash' => $tokenHash]);
        $reset = $stmt->fetch();

        if (!$reset || strtotime($reset['expires_at']) < time()) {
            Response::error('Invalid or expired reset token', 400);
        }

        $newHash = password_hash($input['new_password'], PASSWORD_BCRYPT);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
            $stmt->execute([':hash' => $newHash, ':id' => $user['id']]);

            $stmt = $pdo->prepare('UPDATE password_resets SET used = 1 WHERE id = :id');
            $stmt->execute([':id' => $reset['id']]);

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            Response::error('Failed to reset password', 500);
        }

        Response::success(null, 'Password has been reset successfully. Please login again.');
    }
}
