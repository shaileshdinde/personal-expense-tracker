<?php

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Response.php';
require_once __DIR__ . '/../src/Validator.php';
require_once __DIR__ . '/CategoryController.php';

class BillController
{
    private const STATUSES = ['active', 'disabled'];

    /**
     * GET /api/bills  (requires auth)
     * query: status (default active), year, month (default current year/month)
     * Each item includes the completion status for the requested year/month.
     */
    public static function index(int $userId, array $query): void
    {
        $pdo = Database::connection();
        [$year, $month] = self::resolveYearMonth($query);

        $sql = "SELECT b.*, c.name AS category_name,
                    CASE WHEN bc.id IS NULL THEN 'pending' ELSE 'done' END AS month_status,
                    bc.completed_at
                FROM recurring_bills b
                LEFT JOIN categories c ON c.id = b.category_id
                LEFT JOIN bill_completions bc
                    ON bc.bill_id = b.id AND bc.year = :year AND bc.month = :month
                WHERE b.user_id = :user_id";
        $params = [':user_id' => $userId, ':year' => $year, ':month' => $month];

        if (!empty($query['status']) && in_array($query['status'], self::STATUSES, true)) {
            $sql .= ' AND b.status = :status';
            $params[':status'] = $query['status'];
        }

        $sql .= ' ORDER BY b.day_of_month ASC, b.name ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        Response::success([
            'year'  => $year,
            'month' => $month,
            'items' => $stmt->fetchAll(),
        ]);
    }

    /**
     * GET /api/bills/due  (requires auth)
     * Active bills not yet marked done for the current month, whose due day has arrived.
     * Powers the app-wide "you have bills due" notification banner.
     */
    public static function due(int $userId): void
    {
        $pdo = Database::connection();
        $today = new DateTime();
        $year = (int) $today->format('Y');
        $month = (int) $today->format('n');
        $day = (int) $today->format('j');

        $stmt = $pdo->prepare(
            "SELECT b.id, b.name, b.amount, b.day_of_month, c.name AS category_name
             FROM recurring_bills b
             LEFT JOIN categories c ON c.id = b.category_id
             LEFT JOIN bill_completions bc
                 ON bc.bill_id = b.id AND bc.year = :year AND bc.month = :month
             WHERE b.user_id = :user_id
               AND b.status = 'active'
               AND bc.id IS NULL
               AND b.day_of_month <= :day
             ORDER BY b.day_of_month ASC"
        );
        $stmt->execute([':user_id' => $userId, ':year' => $year, ':month' => $month, ':day' => $day]);
        $rows = $stmt->fetchAll();

        Response::success([
            'year'  => $year,
            'month' => $month,
            'count' => count($rows),
            'items' => $rows,
        ]);
    }

    /**
     * POST /api/bills  (requires auth)
     * body: name, amount (optional), category_id (optional), day_of_month (1-28, default 1), remark (optional)
     */
    public static function store(int $userId, array $input): void
    {
        $errors = Validator::validate($input, [
            'name' => 'required|min:1|max:150',
        ]);
        $dayOfMonth = isset($input['day_of_month']) ? (int) $input['day_of_month'] : 1;
        if ($dayOfMonth < 1 || $dayOfMonth > 28) {
            $errors['day_of_month'][] = 'day_of_month must be between 1 and 28';
        }
        if (array_key_exists('amount', $input) && $input['amount'] !== null && $input['amount'] !== '' && !is_numeric($input['amount'])) {
            $errors['amount'][] = 'amount must be numeric';
        }
        if ($errors) {
            Response::error('Validation failed', 422, $errors);
        }

        $pdo = Database::connection();
        if (!empty($input['category_id'])) {
            CategoryController::findOwned($pdo, $userId, (int) $input['category_id']);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO recurring_bills (user_id, name, amount, category_id, day_of_month, remark)
             VALUES (:user_id, :name, :amount, :category_id, :day_of_month, :remark)'
        );
        $stmt->execute([
            ':user_id'      => $userId,
            ':name'         => $input['name'],
            ':amount'       => ($input['amount'] ?? '') === '' ? null : $input['amount'],
            ':category_id'  => $input['category_id'] ?? null,
            ':day_of_month' => $dayOfMonth,
            ':remark'       => $input['remark'] ?? null,
        ]);

        $billId = (int) $pdo->lastInsertId();
        Response::success(self::findOwned($pdo, $userId, $billId), 'Bill created', 201);
    }

    /**
     * PUT /api/bills/{id}  (requires auth) - partial update
     */
    public static function update(int $userId, int $billId, array $input): void
    {
        $pdo = Database::connection();
        $existing = self::findOwned($pdo, $userId, $billId);

        $errors = Validator::validate($input, ['name' => 'max:150']);
        if (array_key_exists('day_of_month', $input)) {
            $d = (int) $input['day_of_month'];
            if ($d < 1 || $d > 28) {
                $errors['day_of_month'][] = 'day_of_month must be between 1 and 28';
            }
        }
        if (array_key_exists('amount', $input) && $input['amount'] !== null && $input['amount'] !== '' && !is_numeric($input['amount'])) {
            $errors['amount'][] = 'amount must be numeric';
        }
        if ($errors) {
            Response::error('Validation failed', 422, $errors);
        }

        if (array_key_exists('category_id', $input) && !empty($input['category_id'])) {
            CategoryController::findOwned($pdo, $userId, (int) $input['category_id']);
        }

        $fields = [
            'name'         => $input['name'] ?? $existing['name'],
            'amount'       => array_key_exists('amount', $input) ? (($input['amount'] ?? '') === '' ? null : $input['amount']) : $existing['amount'],
            'category_id'  => array_key_exists('category_id', $input) ? ($input['category_id'] ?: null) : $existing['category_id'],
            'day_of_month' => array_key_exists('day_of_month', $input) ? (int) $input['day_of_month'] : $existing['day_of_month'],
            'remark'       => array_key_exists('remark', $input) ? $input['remark'] : $existing['remark'],
        ];

        $stmt = $pdo->prepare(
            'UPDATE recurring_bills SET
                name = :name, amount = :amount, category_id = :category_id,
                day_of_month = :day_of_month, remark = :remark
             WHERE id = :id'
        );
        $stmt->execute([
            ':name'         => $fields['name'],
            ':amount'       => $fields['amount'],
            ':category_id'  => $fields['category_id'],
            ':day_of_month' => $fields['day_of_month'],
            ':remark'       => $fields['remark'],
            ':id'           => $billId,
        ]);

        Response::success(self::findOwned($pdo, $userId, $billId), 'Bill updated');
    }

    /**
     * PATCH /api/bills/{id}/disable  (requires auth)
     * body (optional): status = active|disabled  (defaults to 'disabled')
     */
    public static function toggleStatus(int $userId, int $billId, array $input): void
    {
        $pdo = Database::connection();
        self::findOwned($pdo, $userId, $billId);

        $status = $input['status'] ?? 'disabled';
        if (!in_array($status, self::STATUSES, true)) {
            Response::error('status must be active or disabled', 422);
        }

        $stmt = $pdo->prepare('UPDATE recurring_bills SET status = :status WHERE id = :id');
        $stmt->execute([':status' => $status, ':id' => $billId]);

        Response::success(self::findOwned($pdo, $userId, $billId), 'Bill status updated');
    }

    /**
     * POST /api/bills/{id}/complete  (requires auth)
     * body (optional): year, month - defaults to current year/month
     * Marks the bill done for that month (idempotent).
     */
    public static function complete(int $userId, int $billId, array $input): void
    {
        $pdo = Database::connection();
        self::findOwned($pdo, $userId, $billId);
        [$year, $month] = self::resolveYearMonth($input);

        $stmt = $pdo->prepare(
            'INSERT INTO bill_completions (bill_id, user_id, year, month)
             VALUES (:bill_id, :user_id, :year, :month)
             ON DUPLICATE KEY UPDATE completed_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([':bill_id' => $billId, ':user_id' => $userId, ':year' => $year, ':month' => $month]);

        Response::success(self::billWithMonthStatus($pdo, $userId, $billId, $year, $month), 'Marked as done');
    }

    /**
     * DELETE /api/bills/{id}/complete  (requires auth)
     * query (optional): year, month - defaults to current year/month
     * Undoes a "mark done" for that month.
     */
    public static function uncomplete(int $userId, int $billId, array $query): void
    {
        $pdo = Database::connection();
        self::findOwned($pdo, $userId, $billId);
        [$year, $month] = self::resolveYearMonth($query);

        $stmt = $pdo->prepare('DELETE FROM bill_completions WHERE bill_id = :bill_id AND year = :year AND month = :month');
        $stmt->execute([':bill_id' => $billId, ':year' => $year, ':month' => $month]);

        Response::success(self::billWithMonthStatus($pdo, $userId, $billId, $year, $month), 'Marked as pending');
    }

    // ---------------------------------------------------------------------

    private static function resolveYearMonth(array $source): array
    {
        $now = new DateTime();
        $year = !empty($source['year']) ? (int) $source['year'] : (int) $now->format('Y');
        $month = !empty($source['month']) ? (int) $source['month'] : (int) $now->format('n');
        if ($month < 1 || $month > 12) {
            Response::error('month must be between 1 and 12', 422);
        }
        return [$year, $month];
    }

    private static function billWithMonthStatus(PDO $pdo, int $userId, int $billId, int $year, int $month): array
    {
        $stmt = $pdo->prepare(
            "SELECT b.*, c.name AS category_name,
                    CASE WHEN bc.id IS NULL THEN 'pending' ELSE 'done' END AS month_status,
                    bc.completed_at
             FROM recurring_bills b
             LEFT JOIN categories c ON c.id = b.category_id
             LEFT JOIN bill_completions bc
                 ON bc.bill_id = b.id AND bc.year = :year AND bc.month = :month
             WHERE b.id = :id AND b.user_id = :user_id"
        );
        $stmt->execute([':id' => $billId, ':user_id' => $userId, ':year' => $year, ':month' => $month]);
        return $stmt->fetch();
    }

    public static function findOwned(PDO $pdo, int $userId, int $billId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM recurring_bills WHERE id = :id AND user_id = :user_id');
        $stmt->execute([':id' => $billId, ':user_id' => $userId]);
        $bill = $stmt->fetch();

        if (!$bill) {
            Response::error('Bill not found', 404);
        }

        return $bill;
    }
}
