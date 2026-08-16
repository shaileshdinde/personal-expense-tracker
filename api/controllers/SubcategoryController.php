<?php

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Response.php';
require_once __DIR__ . '/../src/Validator.php';
require_once __DIR__ . '/CategoryController.php';

class SubcategoryController
{
    /**
     * GET /api/subcategories?category_id=1&status=active  (requires auth)
     */
    public static function index(int $userId, array $query): void
    {
        $pdo = Database::connection();

        $sql = 'SELECT id, category_id, name, status, created_at, updated_at FROM subcategories WHERE user_id = :user_id';
        $params = [':user_id' => $userId];

        if (!empty($query['category_id'])) {
            $sql .= ' AND category_id = :category_id';
            $params[':category_id'] = (int) $query['category_id'];
        }

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
     * POST /api/subcategories  (requires auth)
     * body: category_id, name
     */
    public static function store(int $userId, array $input): void
    {
        $errors = Validator::validate($input, [
            'category_id' => 'required|numeric',
            'name'        => 'required|min:1|max:100',
        ]);
        if ($errors) {
            Response::error('Validation failed', 422, $errors);
        }

        $pdo = Database::connection();
        // Ensure the parent category belongs to this user
        CategoryController::findOwned($pdo, $userId, (int) $input['category_id']);

        $stmt = $pdo->prepare('SELECT id FROM subcategories WHERE category_id = :cid AND name = :name');
        $stmt->execute([':cid' => $input['category_id'], ':name' => $input['name']]);
        if ($stmt->fetch()) {
            Response::error('Sub-category already exists under this category', 409);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO subcategories (category_id, user_id, name) VALUES (:cid, :uid, :name)'
        );
        $stmt->execute([':cid' => $input['category_id'], ':uid' => $userId, ':name' => $input['name']]);

        Response::success([
            'id'          => (int) $pdo->lastInsertId(),
            'category_id' => (int) $input['category_id'],
            'name'        => $input['name'],
        ], 'Sub-category created', 201);
    }

    /**
     * PUT /api/subcategories/{id}  (requires auth)
     * body: name, category_id (optional, to move it to another category)
     */
    public static function update(int $userId, int $subcategoryId, array $input): void
    {
        $errors = Validator::validate($input, ['name' => 'required|min:1|max:100']);
        if ($errors) {
            Response::error('Validation failed', 422, $errors);
        }

        $pdo = Database::connection();
        $sub = self::findOwned($pdo, $userId, $subcategoryId);

        $categoryId = $sub['category_id'];
        if (!empty($input['category_id'])) {
            CategoryController::findOwned($pdo, $userId, (int) $input['category_id']);
            $categoryId = (int) $input['category_id'];
        }

        $stmt = $pdo->prepare('UPDATE subcategories SET name = :name, category_id = :cid WHERE id = :id');
        $stmt->execute([':name' => $input['name'], ':cid' => $categoryId, ':id' => $subcategoryId]);

        Response::success(['id' => $subcategoryId, 'name' => $input['name'], 'category_id' => $categoryId], 'Sub-category updated');
    }

    /**
     * PATCH /api/subcategories/{id}/disable  (requires auth)
     * body (optional): status = active|disabled  (defaults to 'disabled')
     */
    public static function toggleStatus(int $userId, int $subcategoryId, array $input): void
    {
        $pdo = Database::connection();
        self::findOwned($pdo, $userId, $subcategoryId);

        $status = $input['status'] ?? 'disabled';
        if (!in_array($status, ['active', 'disabled'], true)) {
            Response::error('status must be active or disabled', 422);
        }

        $stmt = $pdo->prepare('UPDATE subcategories SET status = :status WHERE id = :id');
        $stmt->execute([':status' => $status, ':id' => $subcategoryId]);

        Response::success(['id' => $subcategoryId, 'status' => $status], 'Sub-category status updated');
    }

    public static function findOwned(PDO $pdo, int $userId, int $subcategoryId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM subcategories WHERE id = :id AND user_id = :user_id');
        $stmt->execute([':id' => $subcategoryId, ':user_id' => $userId]);
        $sub = $stmt->fetch();

        if (!$sub) {
            Response::error('Sub-category not found', 404);
        }

        return $sub;
    }
}
