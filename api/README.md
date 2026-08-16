# Expense Tracker API (PHP + MySQL + JWT)

A REST API for a personal expense tracker: registration, login (web/mobile
aware token expiry), profile management, forgot/reset password, logout,
categories/sub-categories, expenses, and reports.

Framework-free — plain PHP (PDO) + a custom dependency-free JWT (HS256)
implementation, so it will run on almost any standard LAMP host.

## 1. Requirements

- PHP 8.0+ with `pdo_mysql` extension
- MySQL 5.7+ / MariaDB 10.3+
- Apache with `mod_rewrite` (or any server that can route all requests to `public/index.php`)

## 2. Setup

1. **Create the database**
   ```bash
   mysql -u root -p < sql/schema.sql
   ```

2. **Configure environment variables** (copy `.env.example` and set real
   values — as actual env vars, or via your host's env panel / `.htaccess`
   `SetEnv` directives):
   ```
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_NAME=expense_tracker
   DB_USER=your_db_user
   DB_PASS=your_db_password
   JWT_SECRET=a-long-random-secret-string
   JWT_ISSUER=expense-tracker-api
   APP_DEBUG=0
   ```
   If you don't set env vars, edit the defaults directly in `config/config.php`.

3. **Point your webserver's document root at `public/`.**
   `public/.htaccess` routes every request through `public/index.php`.

4. **Quick local test** (PHP built-in server):
   ```bash
   cd public
   DB_HOST=127.0.0.1 DB_USER=root DB_PASS=yourpass DB_NAME=expense_tracker \
   JWT_SECRET=testsecret php -S localhost:8000 index.php
   ```

This project has been end-to-end tested against a live MySQL/MariaDB instance
(registration → login → profile → categories → sub-categories → expenses →
reports → logout → forgot/reset password all verified working).

## 3. Project structure

```
config/config.php          Central config (DB, JWT, password reset TTL)
sql/schema.sql              Database schema
src/Database.php            PDO singleton connection
src/JWT.php                 Dependency-free HS256 JWT encode/decode
src/Auth.php                Token issuing + request authentication middleware
src/Response.php             JSON response helper
src/Validator.php           Simple input validation
src/Mailer.php               Password reset email (falls back to storage/mail.log locally)
controllers/AuthController.php
controllers/ProfileController.php
controllers/CategoryController.php
controllers/SubcategoryController.php
controllers/ExpenseController.php
controllers/ReportController.php
public/index.php            Front controller / router
public/.htaccess            Rewrite rules
```

## 4. Authentication

All endpoints except `register`, `login`, `forgot-password`, and
`reset-password` require:

```
Authorization: Bearer <jwt_token>
```

**Token expiry depends on the `device` field sent at login:**
| device   | expiry   |
|----------|----------|
| `web`    | 1 hour   |
| `mobile` | 30 days  |

Logout blacklists the token's unique `jti`, so a logged-out token is rejected
immediately even though JWTs are otherwise stateless.

## 5. API Reference

All responses follow this envelope:
```json
{ "success": true|false, "message": "...", "data": {...} | "errors": {...} }
```

### Auth

**POST `/api/register`**
```json
{ "name": "Jane Doe", "email": "jane@example.com", "password": "secret123", "phone": "9999999999" }
```

**POST `/api/login`**
```json
{ "email": "jane@example.com", "password": "secret123", "device": "web" }
```
`device` must be `"web"` or `"mobile"`. Response includes `auth.token`,
`auth.expires_in` (seconds), `auth.expires_at`.

**POST `/api/logout`** *(auth required)* — no body. Revokes the current token.

**POST `/api/forgot-password`**
```json
{ "email": "jane@example.com" }
```
Sends (or logs, in dev) a reset token valid for 30 minutes.

**POST `/api/reset-password`**
```json
{ "email": "jane@example.com", "token": "<raw_token_from_email>", "new_password": "newpass456" }
```

### Profile *(auth required)*

**GET `/api/profile`**

**PUT `/api/profile`**
```json
{ "name": "Jane D.", "phone": "8888888888", "current_password": "old", "password": "new123456" }
```
`password`/`current_password` are optional — only needed when changing the password.

### Categories *(auth required)*

**GET `/api/categories?status=active`**

**POST `/api/categories`**
```json
{ "name": "Food" }
```

**PUT `/api/categories/{id}`**
```json
{ "name": "Food & Dining" }
```

**PATCH `/api/categories/{id}/disable`**
```json
{ "status": "disabled" }
```
(`status` optional, defaults to `"disabled"`; use `{"status":"active"}` to re-enable.
Disabling a category cascades to disable its sub-categories.)

### Sub-categories *(auth required)*

**GET `/api/subcategories?category_id=1&status=active`**

**POST `/api/subcategories`**
```json
{ "category_id": 1, "name": "Groceries" }
```

**PUT `/api/subcategories/{id}`**
```json
{ "name": "Supermarket", "category_id": 1 }
```

**PATCH `/api/subcategories/{id}/disable`**
```json
{ "status": "disabled" }
```

### Expenses *(auth required)*

**GET `/api/expenses?status=active&category_id=1&subcategory_id=1&from=2026-08-01&to=2026-08-31&page=1&per_page=20`**

**GET `/api/expenses/{id}`**

**POST `/api/expenses`**
```json
{
  "category_id": 1,
  "subcategory_id": 1,
  "reason": "Weekly groceries",
  "details": "Bought veggies and fruits",
  "amount": 45.50,
  "date": "2026-08-01",
  "time": "18:30",
  "payment_mode": "upi",
  "remark": "Paid via GPay"
}
```
`payment_mode` ∈ `cash | card | upi | netbanking | wallet | other`.

**PUT `/api/expenses/{id}`** — same fields as create, all optional (partial update).

**PATCH `/api/expenses/{id}/disable`**
```json
{ "status": "disabled" }
```

### Reports *(auth required)*

**GET `/api/reports/by-category?from=2026-08-01&to=2026-08-31`**
Totals grouped by category.

**GET `/api/reports/by-subcategory?category_id=1&from=&to=`**
Totals grouped by sub-category.

**GET `/api/reports/by-date-range?from=2026-08-01&to=2026-08-31`**
Daily breakdown + grand total for the range.

**GET `/api/reports/monthly?year=2026`**
Month-by-month totals for the whole year.

**GET `/api/reports/monthly?year=2026&month=8`**
That month's total + breakdown by category.

All report endpoints accept an optional `status` query param
(`active` default, or `disabled`/`all`) to include/exclude disabled expenses.

## 6. Security notes for production

- Set a strong random `JWT_SECRET` (32+ random bytes).
- Serve over HTTPS only.
- Tighten the CORS header in `public/index.php` (`Access-Control-Allow-Origin`)
  to your actual frontend domain(s) instead of `*`.
- Replace `Mailer::sendPasswordResetEmail` with a real transactional email
  provider (SES, SendGrid, Mailgun, etc.) — the current implementation
  falls back to logging emails to `storage/mail.log` for local testing.
- Consider adding rate-limiting on `/api/login` and `/api/forgot-password`
  to slow down brute-force / enumeration attempts.
- Run `DELETE FROM token_blacklist WHERE expires_at < NOW()` periodically
  (cron) to keep that table small.
