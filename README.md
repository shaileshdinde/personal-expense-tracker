# Expense Tracker — Frontend (HTML + CSS + jQuery + AdminLTE 3)

A complete admin-style front end for the Expense Tracker API: login/register,
profile, categories, sub-categories, expenses (with pagination & filters),
and a Reports page with a donut chart plus bar and line charts.

Built with **AdminLTE 3**, **Bootstrap 4**, **jQuery**, and **Chart.js** —
all bundled locally in `assets/vendor/` (no CDN required, works offline).

## 1. Point it at your API

Open `assets/js/config.js` and set your backend URL:

```js
window.APP_CONFIG = {
  API_BASE_URL: "http://localhost:80/api-public-folder-path",  // <- your PHP API's public/ root
};
```

## 2. Serve the files over HTTP

The pages load shared navbar/sidebar/footer via `$.load()` (AJAX), so they
must be served over `http://`, not opened directly as `file://`. Any static
server works, e.g.:

Copy folder in htdocs folder of xampp
```bash
cd expense-tracker-frontend
# then visit http://localhost:80/expense-tracker-frontend/login.html
```

Or point Apache/Nginx's document root at this folder.

**CORS:** the backend already sends `Access-Control-Allow-Origin: *`, so it
will accept requests from any origin/port you serve this frontend on.

## 3. Pages

| Page | Description |
|---|---|
| `login.html` | Sign in. "Keep me signed in (30 days)" maps to the API's `device: mobile` (1hr otherwise). |
| `register.html` | Create an account |
| `forgot-password.html` / `reset-password.html` | Password reset flow |
| `index.html` | Dashboard — today/month totals, **donut chart** (spend by category this month), bar chart (6-month trend), recent expenses |
| `categories.html` | Add / edit / enable / disable categories |
| `subcategories.html` | Add / edit / enable / disable sub-categories, filterable by category |
| `expenses.html` | Full CRUD with filters (category, sub-category, date range, status) + pagination |
| `loans.html` | **Loans Given** — track money lent to people: add loans, record partial/full repayments, auto-closes when fully repaid, summary cards (total lent / outstanding / repaid / active count) |
| `profile.html` | View/update name & phone, change password |
| `reports.html` | **Donut chart** (by category), horizontal bar chart (by sub-category), line chart (daily trend), bar chart (monthly per year), plus a detail table |

## 4. Structure

```
assets/
  vendor/        AdminLTE 3, Bootstrap 4, jQuery, Font Awesome, Chart.js (local, no CDN)
  css/custom.css Small visual tweaks on top of AdminLTE
  js/
    config.js    <- set your API_BASE_URL here
    api.js       Fetch wrapper: attaches JWT, handles 401 (auto logout + redirect)
    auth.js      Session storage + page guards
    ui.js        Toasts, currency/date formatting, badges, chart color palette
    layout.js    Injects navbar/sidebar/footer partials, marks active nav item
    dashboard.js / categories.js / subcategories.js / expenses.js / profile.js / reports.js / loans.js
partials/        navbar.html, sidebar.html, footer.html (shared layout, loaded via AJAX)
*.html           One page per screen (see table above)
```

## 5. Mobile-first design

The whole UI is written mobile-first: base styles target phones, with
`min-width` media queries layering on desktop refinements. Specific fixes on
top of AdminLTE 3's defaults (`assets/css/custom.css`):

- **Auth pages**: AdminLTE hardcodes the login/register box to 360px, which
  overflows on phones narrower than ~380px — now fluid with a max-width.
- **Tables**: every table is wrapped in `.table-responsive` so it scrolls
  horizontally on narrow screens instead of squeezing columns unreadably.
- **Filter bars**: two-per-row on phones (`col-6 col-md-*`) instead of a
  long single-column stack of 5-6 fields.
- **Charts**: canvases sit in a fixed-height `.chart-container` with
  `maintainAspectRatio: false`, so they resize predictably at every
  breakpoint instead of becoming too tall/short based on aspect-ratio math.
- **Page-header action buttons**: full-width (`.btn-page-action`) on phones
  for an easy thumb target, inline on tablet/desktop.
- **Touch targets**: buttons and form controls keep a ~40px minimum height
  on small screens.
- **Loan detail modal**: stat row is 2-up on phones, 3-up from the `sm`
  breakpoint (`.stat-col`).
- **Quick-range buttons / pagination**: wrap onto multiple lines instead of
  overflowing horizontally.

The sidebar already collapses to an off-canvas menu on phones via AdminLTE's
built-in `pushmenu` widget (the hamburger icon in the navbar) — no extra
work needed there.

## 6. Notes

- Auth token + user info are kept in `localStorage` (`et_token`, `et_user`, ...).
  A 401 response from any API call automatically clears the session and
  redirects to `login.html?expired=1`.
- Logout calls `POST /api/logout` (revokes the token server-side) before
  clearing local storage.
- All amounts are formatted with `₹` — change the symbol in `UI.money()` in
  `assets/js/ui.js` if you need a different currency.
- This has been tested end-to-end against the companion PHP/MySQL API
  (registration → login → categories → sub-categories → expenses → all four
  report endpoints → logout), including live chart rendering and CRUD forms.
