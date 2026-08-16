<?php

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Response.php';
require_once __DIR__ . '/../src/Validator.php';

class LoanController
{
    private const PAYMENT_MODES = ['cash', 'card', 'upi', 'netbanking', 'wallet', 'other'];
    private const STATUSES = ['active', 'closed', 'disabled'];

    /**
     * GET /api/loans  (requires auth)
     * query: status, search (borrower name), from, to (loan_date range), page, per_page
     */
    public static function index(int $userId, array $query): void
    {
        $pdo = Database::connection();

        $where = 'l.user_id = :user_id';
        $params = [':user_id' => $userId];

        if (!empty($query['status']) && in_array($query['status'], self::STATUSES, true)) {
            $where .= ' AND l.status = :status';
            $params[':status'] = $query['status'];
        }
        if (!empty($query['search'])) {
            $where .= ' AND l.borrower_name LIKE :search';
            $params[':search'] = '%' . $query['search'] . '%';
        }
        if (!empty($query['from'])) {
            $where .= ' AND l.loan_date >= :from';
            $params[':from'] = $query['from'];
        }
        if (!empty($query['to'])) {
            $where .= ' AND l.loan_date <= :to';
            $params[':to'] = $query['to'];
        }

        $perPage = max(1, min(100, (int) ($query['per_page'] ?? 20)));
        $page = max(1, (int) ($query['page'] ?? 1));
        $offset = ($page - 1) * $perPage;

        $countStmt = $pdo->prepare("SELECT COUNT(*) AS total FROM loans l WHERE $where");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['total'];

        $sql = "SELECT l.*,
                    COALESCE((SELECT SUM(r.amount) FROM loan_repayments r WHERE r.loan_id = l.id), 0) AS total_repaid
                FROM loans l
                WHERE $where
                ORDER BY l.loan_date DESC, l.id DESC
                LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = array_map([self::class, 'withBalance'], $stmt->fetchAll());

        Response::success([
            'items' => $rows,
            'pagination' => [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => (int) ceil($total / $perPage),
            ],
        ]);
    }

    /**
     * GET /api/loans/summary  (requires auth)
     * Quick totals for cards/widgets: total lent, total outstanding, total repaid, counts by status.
     */
    public static function summary(int $userId): void
    {
        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            "SELECT
                COUNT(*) AS loan_count,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_count,
                SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) AS closed_count,
                COALESCE(SUM(CASE WHEN status != 'disabled' THEN amount ELSE 0 END), 0) AS total_lent,
                COALESCE((
                    SELECT SUM(r.amount) FROM loan_repayments r
                    JOIN loans l2 ON l2.id = r.loan_id
                    WHERE l2.user_id = :user_id_sub AND l2.status != 'disabled'
                ), 0) AS total_repaid
             FROM loans l
             WHERE l.user_id = :user_id AND l.status != 'disabled'"
        );
        $stmt->execute([':user_id' => $userId, ':user_id_sub' => $userId]);
        $row = $stmt->fetch();

        $totalLent = (float) $row['total_lent'];
        $totalRepaid = (float) $row['total_repaid'];

        Response::success([
            'loan_count'        => (int) $row['loan_count'],
            'active_count'      => (int) $row['active_count'],
            'closed_count'      => (int) $row['closed_count'],
            'total_lent'        => $totalLent,
            'total_repaid'      => $totalRepaid,
            'total_outstanding' => round($totalLent - $totalRepaid, 2),
        ]);
    }

    /**
     * GET /api/loans/{id}  (requires auth) - includes repayment history
     */
    public static function show(int $userId, int $loanId): void
    {
        $pdo = Database::connection();
        $loan = self::withBalance(self::findOwned($pdo, $userId, $loanId));

        $stmt = $pdo->prepare('SELECT * FROM loan_repayments WHERE loan_id = :loan_id ORDER BY payment_date DESC, id DESC');
        $stmt->execute([':loan_id' => $loanId]);
        $loan['repayments'] = $stmt->fetchAll();

        Response::success($loan);
    }

    /**
     * POST /api/loans  (requires auth)
     * body: borrower_name, borrower_contact (optional), amount, loan_date, due_date (optional),
     *       interest_rate (optional), reason (optional), remark (optional)
     */
    public static function store(int $userId, array $input): void
    {
        $errors = Validator::validate($input, [
            'borrower_name' => 'required|min:1|max:150',
            'amount'        => 'required|numeric',
            'loan_date'     => 'required|date',
        ]);
        if (!empty($input['due_date']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $input['due_date'])) {
            $errors['due_date'][] = 'due_date must be in YYYY-MM-DD format';
        }
        if ((float) ($input['amount'] ?? 0) <= 0) {
            $errors['amount'][] = 'amount must be greater than 0';
        }
        if ($errors) {
            Response::error('Validation failed', 422, $errors);
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO loans
                (user_id, borrower_name, borrower_contact, amount, loan_date, due_date, interest_rate, reason, remark)
             VALUES
                (:user_id, :borrower_name, :borrower_contact, :amount, :loan_date, :due_date, :interest_rate, :reason, :remark)'
        );
        $stmt->execute([
            ':user_id'          => $userId,
            ':borrower_name'    => $input['borrower_name'],
            ':borrower_contact' => $input['borrower_contact'] ?? null,
            ':amount'           => $input['amount'],
            ':loan_date'        => $input['loan_date'],
            ':due_date'         => $input['due_date'] ?? null,
            ':interest_rate'    => $input['interest_rate'] ?? null,
            ':reason'           => $input['reason'] ?? null,
            ':remark'           => $input['remark'] ?? null,
        ]);

        $loanId = (int) $pdo->lastInsertId();
        Response::success(self::withBalance(self::findOwned($pdo, $userId, $loanId)), 'Loan recorded', 201);
    }

    /**
     * PUT /api/loans/{id}  (requires auth) - partial update
     */
    public static function update(int $userId, int $loanId, array $input): void
    {
        $pdo = Database::connection();
        $existing = self::findOwned($pdo, $userId, $loanId);

        $errors = Validator::validate($input, [
            'borrower_name' => 'max:150',
            'amount'        => 'numeric',
            'loan_date'     => 'date',
        ]);
        if (array_key_exists('amount', $input) && (float) $input['amount'] <= 0) {
            $errors['amount'][] = 'amount must be greater than 0';
        }
        if (!empty($input['due_date']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $input['due_date'])) {
            $errors['due_date'][] = 'due_date must be in YYYY-MM-DD format';
        }
        if ($errors) {
            Response::error('Validation failed', 422, $errors);
        }

        $fields = [
            'borrower_name'    => $input['borrower_name'] ?? $existing['borrower_name'],
            'borrower_contact' => array_key_exists('borrower_contact', $input) ? $input['borrower_contact'] : $existing['borrower_contact'],
            'amount'           => $input['amount'] ?? $existing['amount'],
            'loan_date'        => $input['loan_date'] ?? $existing['loan_date'],
            'due_date'         => array_key_exists('due_date', $input) ? $input['due_date'] : $existing['due_date'],
            'interest_rate'    => array_key_exists('interest_rate', $input) ? $input['interest_rate'] : $existing['interest_rate'],
            'reason'           => array_key_exists('reason', $input) ? $input['reason'] : $existing['reason'],
            'remark'           => array_key_exists('remark', $input) ? $input['remark'] : $existing['remark'],
        ];

        $stmt = $pdo->prepare(
            'UPDATE loans SET
                borrower_name = :borrower_name, borrower_contact = :borrower_contact, amount = :amount,
                loan_date = :loan_date, due_date = :due_date, interest_rate = :interest_rate,
                reason = :reason, remark = :remark
             WHERE id = :id'
        );
        $stmt->execute([
            ':borrower_name'    => $fields['borrower_name'],
            ':borrower_contact' => $fields['borrower_contact'],
            ':amount'           => $fields['amount'],
            ':loan_date'        => $fields['loan_date'],
            ':due_date'         => $fields['due_date'],
            ':interest_rate'    => $fields['interest_rate'],
            ':reason'           => $fields['reason'],
            ':remark'           => $fields['remark'],
            ':id'               => $loanId,
        ]);

        // Re-evaluate closed/active status in case the amount changed relative to repayments made
        self::recalculateStatus($pdo, $loanId);

        Response::success(self::withBalance(self::findOwned($pdo, $userId, $loanId)), 'Loan updated');
    }

    /**
     * PATCH /api/loans/{id}/status  (requires auth)
     * body: status = active | closed | disabled
     */
    public static function setStatus(int $userId, int $loanId, array $input): void
    {
        $pdo = Database::connection();
        self::findOwned($pdo, $userId, $loanId);

        $status = $input['status'] ?? '';
        if (!in_array($status, self::STATUSES, true)) {
            Response::error('status must be one of: ' . implode(', ', self::STATUSES), 422);
        }

        $stmt = $pdo->prepare('UPDATE loans SET status = :status WHERE id = :id');
        $stmt->execute([':status' => $status, ':id' => $loanId]);

        Response::success(self::withBalance(self::findOwned($pdo, $userId, $loanId)), 'Loan status updated');
    }

    /**
     * POST /api/loans/{id}/repayments  (requires auth)
     * body: amount, payment_date, payment_mode, remark (optional)
     * Automatically marks the loan 'closed' once total repayments reach the loan amount.
     */
    public static function addRepayment(int $userId, int $loanId, array $input): void
    {
        $pdo = Database::connection();
        $loan = self::findOwned($pdo, $userId, $loanId);

        if ($loan['status'] === 'disabled') {
            Response::error('Cannot add a repayment to a disabled loan', 422);
        }

        $errors = Validator::validate($input, [
            'amount'       => 'required|numeric',
            'payment_date' => 'required|date',
            'payment_mode' => 'required|in:' . implode(',', self::PAYMENT_MODES),
        ]);
        if ((float) ($input['amount'] ?? 0) <= 0) {
            $errors['amount'][] = 'amount must be greater than 0';
        }
        if ($errors) {
            Response::error('Validation failed', 422, $errors);
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO loan_repayments (loan_id, user_id, amount, payment_date, payment_mode, remark)
                 VALUES (:loan_id, :user_id, :amount, :payment_date, :payment_mode, :remark)'
            );
            $stmt->execute([
                ':loan_id'      => $loanId,
                ':user_id'      => $userId,
                ':amount'       => $input['amount'],
                ':payment_date' => $input['payment_date'],
                ':payment_mode' => $input['payment_mode'],
                ':remark'       => $input['remark'] ?? null,
            ]);

            self::recalculateStatus($pdo, $loanId);
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            Response::error('Failed to record repayment', 500);
        }

        Response::success(self::withBalance(self::findOwned($pdo, $userId, $loanId)), 'Repayment recorded', 201);
    }

    /**
     * DELETE /api/loans/{id}/repayments/{repaymentId}  (requires auth)
     * Removes a mistakenly-added repayment and re-evaluates the loan status.
     */
    public static function deleteRepayment(int $userId, int $loanId, int $repaymentId): void
    {
        $pdo = Database::connection();
        self::findOwned($pdo, $userId, $loanId);

        $stmt = $pdo->prepare('SELECT id FROM loan_repayments WHERE id = :id AND loan_id = :loan_id');
        $stmt->execute([':id' => $repaymentId, ':loan_id' => $loanId]);
        if (!$stmt->fetch()) {
            Response::error('Repayment not found', 404);
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('DELETE FROM loan_repayments WHERE id = :id');
            $stmt->execute([':id' => $repaymentId]);

            self::recalculateStatus($pdo, $loanId);
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            Response::error('Failed to delete repayment', 500);
        }

        Response::success(self::withBalance(self::findOwned($pdo, $userId, $loanId)), 'Repayment removed');
    }

    // ---------------------------------------------------------------------

    private static function recalculateStatus(PDO $pdo, int $loanId): void
    {
        $stmt = $pdo->prepare('SELECT amount, status FROM loans WHERE id = :id');
        $stmt->execute([':id' => $loanId]);
        $loan = $stmt->fetch();
        if (!$loan || $loan['status'] === 'disabled') {
            return; // don't auto-flip a disabled/cancelled loan
        }

        $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) AS total FROM loan_repayments WHERE loan_id = :id');
        $stmt->execute([':id' => $loanId]);
        $totalRepaid = (float) $stmt->fetch()['total'];

        $newStatus = $totalRepaid >= (float) $loan['amount'] ? 'closed' : 'active';
        if ($newStatus !== $loan['status']) {
            $stmt = $pdo->prepare('UPDATE loans SET status = :status WHERE id = :id');
            $stmt->execute([':status' => $newStatus, ':id' => $loanId]);
        }
    }

    private static function withBalance(array $loan): array
    {
        $totalRepaid = (float) ($loan['total_repaid'] ?? 0);
        $loan['total_repaid'] = $totalRepaid;
        $loan['balance'] = round((float) $loan['amount'] - $totalRepaid, 2);
        return $loan;
    }

    public static function findOwned(PDO $pdo, int $userId, int $loanId): array
    {
        $stmt = $pdo->prepare(
            "SELECT l.*,
                COALESCE((SELECT SUM(r.amount) FROM loan_repayments r WHERE r.loan_id = l.id), 0) AS total_repaid
             FROM loans l
             WHERE l.id = :id AND l.user_id = :user_id"
        );
        $stmt->execute([':id' => $loanId, ':user_id' => $userId]);
        $loan = $stmt->fetch();

        if (!$loan) {
            Response::error('Loan not found', 404);
        }

        return $loan;
    }
}
