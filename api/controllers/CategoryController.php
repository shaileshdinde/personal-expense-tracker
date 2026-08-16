<?php

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Response.php';
require_once __DIR__ . '/../src/Validator.php';

class CategoryController
{
    /**
     * GET /api/categories?status=active  (requires auth)
     */
    public static function index(int $userId, array $query): void
    {
        $pdo = Database::connection();

        $sql = 'SELECT id, name, status, created_at, updated_at FROM categories WHERE user_id = :user_id';
        $params = [':user_id' => $userId];

        if (!empty($query['status']) && in_array($query['status'], ['active', 'disabled'], true)) {
            $sql .= ' AND status = :status';
            $params[':status'] = $query['status'];
        }

        $sql .= ' ORDER BY name ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        Response::success($stmt->fetchAll());
    }

    /**
     * POST /api/categories  (requires auth)
     * body: name
     */
    public static function store(int $userId, array $input): void
    {
        $errors = Validator::validate($input, ['name' => 'required|min:1|max:100']);
        if ($errors) {
            Response::error('Validation failed', 422, $errors);
        }

        $pdo = Database::connection();

        $stmt = $pdo->prepare('SELECT id FROM categories WHERE user_id = :user_id AND name = :name');
        $stmt->execute([':user_id' => $userId, ':name' => $input['name']]);
        if ($stmt->fetch()) {
            Response::error('Category already exists', 409);
        }

        $stmt = $pdo->prepare('INSERT INTO categories (user_id, name) VALUES (:user_id, :name)');
        $stmt->execute([':user_id' => $userId, ':name' => $input['name']]);

        Response::success(['id' => (int) $pdo->lastInsertId(), 'name' => $input['name']], 'Category created', 201);
    }

    /**
     * PUT /api/categories/{id}  (requires auth)
     * body: name
     */
    public static function update(int $userId, int $categoryId, array $input): void
    {
        $errors = Validator::validate($input, ['name' => 'required|min:1|max:100']);
        if ($errors) {
            Response::error('Validation failed', 422, $errors);
        }

        $pdo = Database::connection();
        $category = self::findOwned($pdo, $userId, $categoryId);

        $stmt = $pdo->prepare('UPDATE categories SET name = :name WHERE id = :id');
        $stmt->execute([':name' => $input['name'], ':id' => $categoryId]);

        Response::success(['id' => $categoryId, 'name' => $input['name']], 'Category updated');
    }

    /**
     * PATCH /api/categories/{id}/disable  (requires auth)
     * body (optional): status = active|disabled  (defaults to 'disabled')
     */
    public static function toggleStatus(int $userId, int $categoryId, array $input): void
    {
        $pdo = Database::connection();
        self::findOwned($pdo, $userId, $categoryId);

        $status = $input['status'] ?? 'disabled';
        if (!in_array($status, ['active', 'disabled'], true)) {
            Response::error('status must be active or disabled', 422);
        }

        $stmt = $pdo->prepare('UPDATE categories SET status = :status WHERE id = :id');
        $stmt->execute([':status' => $status, ':id' => $categoryId]);

        // Cascade disable to sub-categories when disabling a category
        if ($status === 'disabled') {
            $stmt = $pdo->prepare('UPDATE subcategories SET status = "disabled" WHERE category_id = :id');
            $stmt->execute([':id' => $categoryId]);
        }

        Response::success(['id' => $categoryId, 'status' => $status], 'Category status updated');
    }

    public static function findOwned(PDO $pdo, int $userId, int $categoryId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM categories WHERE id = :id AND user_id = :user_id');
        $stmt->execute([':id' => $categoryId, ':user_id' => $userId]);
        $category = $stmt->fetch();

        if (!$category) {
            Response::error('Category not found', 404);
        }

        return $category;
    }
}
