<?php

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Response.php';
require_once __DIR__ . '/../src/Validator.php';

class ProfileController
{
    /**
     * GET /api/profile  (requires auth)
     */
    public static function show(int $userId): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, name, email, phone, status, created_at FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();

        if (!$user) {
            Response::error('User not found', 404);
        }

        Response::success($user);
    }

    /**
     * PUT /api/profile  (requires auth)
     * body: name, phone, password (optional - to change password), current_password (required if changing password)
     */
    public static function update(int $userId, array $input): void
    {
        $errors = Validator::validate($input, [
            'name'  => 'max:150',
            'phone' => 'max:20',
        ]);
        if ($errors) {
            Response::error('Validation failed', 422, $errors);
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();

        if (!$user) {
            Response::error('User not found', 404);
        }

        $fields = [];
        $params = [':id' => $userId];

        if (isset($input['name']) && $input['name'] !== '') {
            $fields[] = 'name = :name';
            $params[':name'] = $input['name'];
        }

        if (array_key_exists('phone', $input)) {
            $fields[] = 'phone = :phone';
            $params[':phone'] = $input['phone'];
        }

        if (!empty($input['password'])) {
            if (empty($input['current_password']) || !password_verify($input['current_password'], $user['password_hash'])) {
                Response::error('Current password is incorrect', 422);
            }
            if (strlen($input['password']) < 6) {
                Response::error('New password must be at least 6 characters', 422);
            }
            $fields[] = 'password_hash = :password_hash';
            $params[':password_hash'] = password_hash($input['password'], PASSWORD_BCRYPT);
        }

        if (!$fields) {
            Response::error('No fields to update', 422);
        }

        $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $stmt = $pdo->prepare('SELECT id, name, email, phone, status, created_at, updated_at FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);

        Response::success($stmt->fetch(), 'Profile updated successfully');
    }
}
