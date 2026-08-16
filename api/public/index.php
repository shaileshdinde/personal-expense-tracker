<?php
/**
 * Front controller / router for the Expense Tracker API.
 * All requests are routed through here (see .htaccess).
 */

error_reporting(E_ALL);
ini_set('display_errors', '0'); // never leak PHP errors into JSON output

require_once __DIR__ . '/../src/Response.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../controllers/AuthController.php';
require_once __DIR__ . '/../controllers/ProfileController.php';
require_once __DIR__ . '/../controllers/CategoryController.php';
require_once __DIR__ . '/../controllers/SubcategoryController.php';
require_once __DIR__ . '/../controllers/ExpenseController.php';
require_once __DIR__ . '/../controllers/ReportController.php';
require_once __DIR__ . '/../controllers/LoanController.php';
require_once __DIR__ . '/../controllers/BillController.php';

// CORS (adjust origins for production)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

set_exception_handler(function (Throwable $e) {
    $cfg = require __DIR__ . '/../config/config.php';
    Response::error('Internal server error', 500, $cfg['debug'] ? ['detail' => $e->getMessage()] : null);
});

// ---- Parse request ----
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Strip base path so this works whether the app sits at / or /public etc.
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
if ($basePath && strpos($uri, $basePath) === 0) {
    $uri = substr($uri, strlen($basePath));
}
$uri = '/' . trim($uri, '/');

parse_str($_SERVER['QUERY_STRING'] ?? '', $query);

$rawBody = file_get_contents('php://input');
$input = [];
if ($rawBody) {
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}
// Also allow form-encoded bodies
if (!$input && $_POST) {
    $input = $_POST;
}

/**
 * Matches $uri against a pattern like '/api/categories/{id}'.
 * Returns an array of captured params, or null if no match.
 */
function matchRoute(string $pattern, string $uri): ?array
{
    $regex = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern);
    $regex = '#^' . $regex . '$#';
    if (preg_match($regex, $uri, $matches)) {
        return array_filter($matches, fn($k) => !is_int($k), ARRAY_FILTER_USE_KEY);
    }
    return null;
}

function authUserId(): int
{
    $payload = Auth::authenticate();
    return (int) $payload['sub'];
}

// ---- Routes ----
try {
    // ---- Auth ----
    if ($uri === '/api/register' && $method === 'POST') {
        AuthController::register($input);
    } elseif ($uri === '/api/login' && $method === 'POST') {
        AuthController::login($input);
    } elseif ($uri === '/api/logout' && $method === 'POST') {
        $payload = Auth::authenticate();
        AuthController::logout($payload);
    } elseif ($uri === '/api/forgot-password' && $method === 'POST') {
        AuthController::forgotPassword($input);
    } elseif ($uri === '/api/reset-password' && $method === 'POST') {
        AuthController::resetPassword($input);

    // ---- Profile ----
    } elseif ($uri === '/api/profile' && $method === 'GET') {
        ProfileController::show(authUserId());
    } elseif ($uri === '/api/profile' && $method === 'PUT') {
        ProfileController::update(authUserId(), $input);

    // ---- Categories ----
    } elseif ($uri === '/api/categories' && $method === 'GET') {
        CategoryController::index(authUserId(), $query);
    } elseif ($uri === '/api/categories' && $method === 'POST') {
        CategoryController::store(authUserId(), $input);
    } elseif (($p = matchRoute('/api/categories/{id}', $uri)) && $method === 'PUT') {
        CategoryController::update(authUserId(), (int) $p['id'], $input);
    } elseif (($p = matchRoute('/api/categories/{id}/disable', $uri)) && $method === 'PATCH') {
        CategoryController::toggleStatus(authUserId(), (int) $p['id'], $input ?: ['status' => 'disabled']);

    // ---- Sub-categories ----
    } elseif ($uri === '/api/subcategories' && $method === 'GET') {
        SubcategoryController::index(authUserId(), $query);
    } elseif ($uri === '/api/subcategories' && $method === 'POST') {
        SubcategoryController::store(authUserId(), $input);
    } elseif (($p = matchRoute('/api/subcategories/{id}', $uri)) && $method === 'PUT') {
        SubcategoryController::update(authUserId(), (int) $p['id'], $input);
    } elseif (($p = matchRoute('/api/subcategories/{id}/disable', $uri)) && $method === 'PATCH') {
        SubcategoryController::toggleStatus(authUserId(), (int) $p['id'], $input ?: ['status' => 'disabled']);

    // ---- Expenses ----
    } elseif ($uri === '/api/expenses' && $method === 'GET') {
        ExpenseController::index(authUserId(), $query);
    } elseif ($uri === '/api/expenses' && $method === 'POST') {
        ExpenseController::store(authUserId(), $input);
    } elseif (($p = matchRoute('/api/expenses/{id}', $uri)) && $method === 'GET') {
        ExpenseController::show(authUserId(), (int) $p['id']);
    } elseif (($p = matchRoute('/api/expenses/{id}', $uri)) && $method === 'PUT') {
        ExpenseController::update(authUserId(), (int) $p['id'], $input);
    } elseif (($p = matchRoute('/api/expenses/{id}/disable', $uri)) && $method === 'PATCH') {
        ExpenseController::toggleStatus(authUserId(), (int) $p['id'], $input ?: ['status' => 'disabled']);

    // ---- Reports ----
    } elseif ($uri === '/api/reports/by-category' && $method === 'GET') {
        ReportController::byCategory(authUserId(), $query);
    } elseif ($uri === '/api/reports/by-subcategory' && $method === 'GET') {
        ReportController::bySubcategory(authUserId(), $query);
    } elseif ($uri === '/api/reports/by-date-range' && $method === 'GET') {
        ReportController::byDateRange(authUserId(), $query);
    } elseif ($uri === '/api/reports/monthly' && $method === 'GET') {
        ReportController::monthly(authUserId(), $query);

    // ---- Loans ----
    } elseif ($uri === '/api/loans' && $method === 'GET') {
        LoanController::index(authUserId(), $query);
    } elseif ($uri === '/api/loans' && $method === 'POST') {
        LoanController::store(authUserId(), $input);
    } elseif ($uri === '/api/loans/summary' && $method === 'GET') {
        LoanController::summary(authUserId());
    } elseif (($p = matchRoute('/api/loans/{id}', $uri)) && $method === 'GET') {
        LoanController::show(authUserId(), (int) $p['id']);
    } elseif (($p = matchRoute('/api/loans/{id}', $uri)) && $method === 'PUT') {
        LoanController::update(authUserId(), (int) $p['id'], $input);
    } elseif (($p = matchRoute('/api/loans/{id}/status', $uri)) && $method === 'PATCH') {
        LoanController::setStatus(authUserId(), (int) $p['id'], $input);
    } elseif (($p = matchRoute('/api/loans/{id}/repayments', $uri)) && $method === 'POST') {
        LoanController::addRepayment(authUserId(), (int) $p['id'], $input);
    } elseif (($p = matchRoute('/api/loans/{id}/repayments/{repaymentId}', $uri)) && $method === 'DELETE') {
        LoanController::deleteRepayment(authUserId(), (int) $p['id'], (int) $p['repaymentId']);

    // ---- Recurring Bills ----
    } elseif ($uri === '/api/bills' && $method === 'GET') {
        BillController::index(authUserId(), $query);
    } elseif ($uri === '/api/bills' && $method === 'POST') {
        BillController::store(authUserId(), $input);
    } elseif ($uri === '/api/bills/due' && $method === 'GET') {
        BillController::due(authUserId());
    } elseif (($p = matchRoute('/api/bills/{id}', $uri)) && $method === 'PUT') {
        BillController::update(authUserId(), (int) $p['id'], $input);
    } elseif (($p = matchRoute('/api/bills/{id}/disable', $uri)) && $method === 'PATCH') {
        BillController::toggleStatus(authUserId(), (int) $p['id'], $input ?: ['status' => 'disabled']);
    } elseif (($p = matchRoute('/api/bills/{id}/complete', $uri)) && $method === 'POST') {
        BillController::complete(authUserId(), (int) $p['id'], $input);
    } elseif (($p = matchRoute('/api/bills/{id}/complete', $uri)) && $method === 'DELETE') {
        BillController::uncomplete(authUserId(), (int) $p['id'], $query);

    } else {
        Response::error('Route not found', 404);
    }
} catch (Throwable $e) {
    $cfg = require __DIR__ . '/../config/config.php';
    Response::error('Internal server error', 500, $cfg['debug'] ? ['detail' => $e->getMessage()] : null);
}
