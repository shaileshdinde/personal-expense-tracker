<?php

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Response.php';
require_once __DIR__ . '/../src/Validator.php';
require_once __DIR__ . '/CategoryController.php';
require_once __DIR__ . '/SubcategoryController.php';

class ExpenseController
{
    private const PAYMENT_MODES = ['cash', 'card', 'upi', 'netbanking', 'wallet', 'other'];

    /**
     * GET /api/expenses  (requires auth)
     * query: status, category_id, subcategory_id, from, to, page, per_page
     */
    public static function index(int $userId, array $query): void
    {
        $pdo = Database::connection();

        $sql = 'SELECT e.*, c.name AS category_name, s.name AS subcategory_name
                FROM expenses e
                LEFT JOIN categories c ON c.id = e.category_id
                LEFT JOIN subcategories s ON s.id = e.subcategory_id
                WHERE e.user_id = :user_id';
        $params = [':user_id' => $userId];

        if (!empty($query['status']) && in_array($query['status'], ['active', 'disabled'], true)) {
            $sql .= ' AND e.status = :status';
            $params[':status'] = $query['status'];
        }
        if (!empty($query['category_id'])) {
            $sql .= ' AND e.category_id = :category_id';
            $params[':category_id'] = (int) $query['category_id'];
        }
        if (!empty($query['subcategory_id'])) {
            $sql .= ' AND e.subcategory_id = :subcategory_id';
            $params[':subcategory_id'] = (int) $query['subcategory_id'];
        }
        if (!empty($query['from'])) {
            $sql .= ' AND e.expense_date >= :from';
            $params[':from'] = $query['from'];
        }
        if (!empty($query['to'])) {
            $sql .= ' AND e.expense_date <= :to';
            $params[':to'] = $query['to'];
        }

        $sql .= ' ORDER BY e.expense_date DESC, e.expense_time DESC';

        $perPage = max(1, min(100, (int) ($query['per_page'] ?? 20)));
        $page = max(1, (int) ($query['page'] ?? 1));
        $offset = ($page - 1) * $perPage;

        $countStmt = $pdo->prepare(str_replace('e.*, c.name AS category_name, s.name AS subcategory_name', 'COUNT(*) AS total', $sql));
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['total'];

        $sql .= ' LIMIT :limit OFFSET :offset';
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        Response::success([
            'items' => $stmt->fetchAll(),
            'pagination' => [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => (int) ceil($total / $perPage),
            ],
        ]);
    }

    /**
     * GET /api/expenses/{id}  (requires auth)
     */
    public static function show(int $userId, int $expenseId): void
    {
        $pdo = Database::connection();
        $expense = self::findOwned($pdo, $userId, $expenseId);
        Response::success($expense);
    }

    /**
     * POST /api/expenses  (requires auth)
     * body: category_id, subcategory_id (optional), reason, details (optional),
     *       amount, date, time, payment_mode, remark (optional)
     */
    public static function store(int $userId, array $input): void
    {
        $errors = Validator::validate($input, [
            'category_id'  => 'required|numeric',
            'reason'       => 'required|min:1|max:255',
            'amount'       => 'required|numeric',
            'date'         => 'required|date',
            'payment_mode' => 'required|in:' . implode(',', self::PAYMENT_MODES),
        ]);
        if (!empty($input['time']) && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $input['time'])) {
            $errors['time'][] = 'time must be in HH:MM or HH:MM:SS format';
        }
        if ($errors) {
            Response::error('Validation failed', 422, $errors);
        }

        $pdo = Database::connection();
        CategoryController::findOwned($pdo, $userId, (int) $input['category_id']);

        $subcategoryId = null;
        if (!empty($input['subcategory_id'])) {
            $sub = SubcategoryController::findOwned($pdo, $userId, (int) $input['subcategory_id']);
            if ((int) $sub['category_id'] !== (int) $input['category_id']) {
                Response::error('Sub-category does not belong to the given category', 422);
            }
            $subcategoryId = (int) $input['subcategory_id'];
        }

        $stmt = $pdo->prepare(
            'INSERT INTO expenses
                (user_id, category_id, subcategory_id, reason, details, amount, expense_date, expense_time, payment_mode, remark)
             VALUES
                (:user_id, :category_id, :subcategory_id, :reason, :details, :amount, :date, :time, :payment_mode, :remark)'
        );
        $stmt->execute([
            ':user_id'        => $userId,
            ':category_id'    => $input['category_id'],
            ':subcategory_id' => $subcategoryId,
            ':reason'         => $input['reason'],
            ':details'        => $input['details'] ?? null,
            ':amount'         => $input['amount'],
            ':date'           => $input['date'],
            ':time'           => $input['time'] ?? date('H:i:s'),
            ':payment_mode'   => $input['payment_mode'],
            ':remark'         => $input['remark'] ?? null,
        ]);

        $expenseId = (int) $pdo->lastInsertId();
        Response::success(self::findOwned($pdo, $userId, $expenseId), 'Expense created', 201);
    }

    /**
     * PUT /api/expenses/{id}  (requires auth)
     */
    public static function update(int $userId, int $expenseId, array $input): void
    {
        $pdo = Database::connection();
        $existing = self::findOwned($pdo, $userId, $expenseId);

        $errors = Validator::validate($input, [
            'category_id'  => 'numeric',
            'reason'       => 'max:255',
            'amount'       => 'numeric',
            'date'         => 'date',
            'payment_mode' => 'in:' . implode(',', self::PAYMENT_MODES),
        ]);
        if (!empty($input['time']) && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $input['time'])) {
            $errors['time'][] = 'time must be in HH:MM or HH:MM:SS format';
        }
        if ($errors) {
            Response::error('Validation failed', 422, $errors);
        }

        $categoryId = $existing['category_id'];
        if (!empty($input['category_id'])) {
            CategoryController::findOwned($pdo, $userId, (int) $input['category_id']);
            $categoryId = (int) $input['category_id'];
        }

        $subcategoryId = $existing['subcategory_id'];
        if (array_key_exists('subcategory_id', $input)) {
            if (empty($input['subcategory_id'])) {
                $subcategoryId = null;
            } else {
                $sub = SubcategoryController::findOwned($pdo, $userId, (int) $input['subcategory_id']);
                if ((int) $sub['category_id'] !== (int) $categoryId) {
                    Response::error('Sub-category does not belong to the given category', 422);
                }
                $subcategoryId = (int) $input['subcategory_id'];
            }
        }

        $fields = [
            'category_id'    => $categoryId,
            'subcategory_id' => $subcategoryId,
            'reason'         => $input['reason'] ?? $existing['reason'],
            'details'        => array_key_exists('details', $input) ? $input['details'] : $existing['details'],
            'amount'         => $input['amount'] ?? $existing['amount'],
            'expense_date'   => $input['date'] ?? $existing['expense_date'],
            'expense_time'   => $input['time'] ?? $existing['expense_time'],
            'payment_mode'   => $input['payment_mode'] ?? $existing['payment_mode'],
            'remark'         => array_key_exists('remark', $input) ? $input['remark'] : $existing['remark'],
        ];

        $stmt = $pdo->prepare(
            'UPDATE expenses SET
                category_id = :category_id, subcategory_id = :subcategory_id, reason = :reason,
                details = :details, amount = :amount, expense_date = :expense_date,
                expense_time = :expense_time, payment_mode = :payment_mode, remark = :remark
             WHERE id = :id'
        );
        $stmt->execute([
            ':category_id'    => $fields['category_id'],
            ':subcategory_id' => $fields['subcategory_id'],
            ':reason'         => $fields['reason'],
            ':details'        => $fields['details'],
            ':amount'         => $fields['amount'],
            ':expense_date'   => $fields['expense_date'],
            ':expense_time'   => $fields['expense_time'],
            ':payment_mode'   => $fields['payment_mode'],
            ':remark'         => $fields['remark'],
            ':id'             => $expenseId,
        ]);

        Response::success(self::findOwned($pdo, $userId, $expenseId), 'Expense updated');
    }

    /**
     * PATCH /api/expenses/{id}/disable  (requires auth)
     * body (optional): status = active|disabled  (defaults to 'disabled')
     */
    public static function toggleStatus(int $userId, int $expenseId, array $input): void
    {
        $pdo = Database::connection();
        self::findOwned($pdo, $userId, $expenseId);

        $status = $input['status'] ?? 'disabled';
        if (!in_array($status, ['active', 'disabled'], true)) {
            Response::error('status must be active or disabled', 422);
        }

        $stmt = $pdo->prepare('UPDATE expenses SET status = :status WHERE id = :id');
        $stmt->execute([':status' => $status, ':id' => $expenseId]);

        Response::success(['id' => $expenseId, 'status' => $status], 'Expense status updated');
    }

    public static function findOwned(PDO $pdo, int $userId, int $expenseId): array
    {
        $stmt = $pdo->prepare(
            'SELECT e.*, c.name AS category_name, s.name AS subcategory_name
             FROM expenses e
             LEFT JOIN categories c ON c.id = e.category_id
             LEFT JOIN subcategories s ON s.id = e.subcategory_id
             WHERE e.id = :id AND e.user_id = :user_id'
        );
        $stmt->execute([':id' => $expenseId, ':user_id' => $userId]);
        $expense = $stmt->fetch();

        if (!$expense) {
            Response::error('Expense not found', 404);
        }

        return $expense;
    }
}
