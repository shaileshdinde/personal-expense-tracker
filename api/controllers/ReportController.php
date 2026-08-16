<?php

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Response.php';

class ReportController
{
    /**
     * GET /api/reports/by-category?from=&to=  (requires auth)
     * Totals grouped by category (active expenses only, unless status param given)
     */
    public static function byCategory(int $userId, array $query): void
    {
        $pdo = Database::connection();
        [$where, $params] = self::commonFilters($userId, $query);

        $sql = "SELECT c.id AS category_id, c.name AS category_name,
                       COUNT(e.id) AS expense_count, COALESCE(SUM(e.amount), 0) AS total_amount
                FROM expenses e
                JOIN categories c ON c.id = e.category_id
                WHERE $where
                GROUP BY c.id, c.name
                ORDER BY total_amount DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        Response::success($stmt->fetchAll());
    }

    /**
     * GET /api/reports/by-subcategory?category_id=&from=&to=  (requires auth)
     */
    public static function bySubcategory(int $userId, array $query): void
    {
        $pdo = Database::connection();
        [$where, $params] = self::commonFilters($userId, $query);

        if (!empty($query['category_id'])) {
            $where .= ' AND e.category_id = :category_id';
            $params[':category_id'] = (int) $query['category_id'];
        }

        $sql = "SELECT s.id AS subcategory_id, s.name AS subcategory_name, c.id AS category_id, c.name AS category_name,
                       COUNT(e.id) AS expense_count, COALESCE(SUM(e.amount), 0) AS total_amount
                FROM expenses e
                JOIN categories c ON c.id = e.category_id
                LEFT JOIN subcategories s ON s.id = e.subcategory_id
                WHERE $where
                GROUP BY s.id, s.name, c.id, c.name
                ORDER BY total_amount DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        Response::success($stmt->fetchAll());
    }

    /**
     * GET /api/reports/by-date-range?from=2026-01-01&to=2026-01-31  (requires auth)
     * Daily breakdown between two dates (inclusive)
     */
    public static function byDateRange(int $userId, array $query): void
    {
        if (empty($query['from']) || empty($query['to'])) {
            Response::error('Both from and to dates are required (YYYY-MM-DD)', 422);
        }

        $pdo = Database::connection();
        [$where, $params] = self::commonFilters($userId, $query);

        $sql = "SELECT e.expense_date, COUNT(e.id) AS expense_count, COALESCE(SUM(e.amount), 0) AS total_amount
                FROM expenses e
                WHERE $where
                GROUP BY e.expense_date
                ORDER BY e.expense_date ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $grandTotal = array_sum(array_column($rows, 'total_amount'));

        Response::success([
            'from'        => $query['from'],
            'to'          => $query['to'],
            'daily'       => $rows,
            'grand_total' => $grandTotal,
        ]);
    }

    /**
     * GET /api/reports/monthly?year=2026&month=1  (requires auth)
     * If only 'year' given, returns a month-by-month summary for that year.
     * If both 'year' and 'month' given, returns that month's totals + category breakdown.
     */
    public static function monthly(int $userId, array $query): void
    {
        $pdo = Database::connection();
        $year = !empty($query['year']) ? (int) $query['year'] : (int) date('Y');

        if (!empty($query['month'])) {
            $month = (int) $query['month'];
            if ($month < 1 || $month > 12) {
                Response::error('month must be between 1 and 12', 422);
            }

            $from = sprintf('%04d-%02d-01', $year, $month);
            $to = date('Y-m-t', strtotime($from));

            $query['from'] = $from;
            $query['to'] = $to;
            [$where, $params] = self::commonFilters($userId, $query);

            $totalStmt = $pdo->prepare(
                "SELECT COUNT(e.id) AS expense_count, COALESCE(SUM(e.amount), 0) AS total_amount
                 FROM expenses e WHERE $where"
            );
            $totalStmt->execute($params);
            $totals = $totalStmt->fetch();

            $catStmt = $pdo->prepare(
                "SELECT c.id AS category_id, c.name AS category_name,
                        COUNT(e.id) AS expense_count, COALESCE(SUM(e.amount), 0) AS total_amount
                 FROM expenses e
                 JOIN categories c ON c.id = e.category_id
                 WHERE $where
                 GROUP BY c.id, c.name
                 ORDER BY total_amount DESC"
            );
            $catStmt->execute($params);

            Response::success([
                'year'            => $year,
                'month'           => $month,
                'expense_count'   => (int) $totals['expense_count'],
                'total_amount'    => $totals['total_amount'],
                'by_category'     => $catStmt->fetchAll(),
            ]);
            return;
        }

        // Whole-year, month by month
        [$where, $params] = self::commonFilters($userId, array_merge($query, [
            'from' => sprintf('%04d-01-01', $year),
            'to'   => sprintf('%04d-12-31', $year),
        ]));

        $sql = "SELECT DATE_FORMAT(e.expense_date, '%Y-%m') AS month,
                       COUNT(e.id) AS expense_count, COALESCE(SUM(e.amount), 0) AS total_amount
                FROM expenses e
                WHERE $where
                GROUP BY DATE_FORMAT(e.expense_date, '%Y-%m')
                ORDER BY month ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        Response::success([
            'year'    => $year,
            'monthly' => $stmt->fetchAll(),
        ]);
    }

    /**
     * Builds the common WHERE clause + params used across reports.
     * Defaults to only 'active' expenses unless status=all|disabled is passed.
     */
    private static function commonFilters(int $userId, array $query): array
    {
        $where = 'e.user_id = :user_id';
        $params = [':user_id' => $userId];

        $status = $query['status'] ?? 'active';
        if (in_array($status, ['active', 'disabled'], true)) {
            $where .= ' AND e.status = :status';
            $params[':status'] = $status;
        }

        if (!empty($query['from'])) {
            $where .= ' AND e.expense_date >= :from';
            $params[':from'] = $query['from'];
        }
        if (!empty($query['to'])) {
            $where .= ' AND e.expense_date <= :to';
            $params[':to'] = $query['to'];
        }

        return [$where, $params];
    }
}
