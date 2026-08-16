/**
 * Global front-end configuration.
 * Point API_BASE_URL at your deployed Expense Tracker PHP API root
 * (the folder that contains index.php / .htaccess), e.g.:
 *   "http://localhost:8000"
 *   "https://api.yourdomain.com"
 */
const basePath = window.location.href.substring(
  0,
  window.location.href.lastIndexOf("/") + 1
);

window.APP_CONFIG = {
  API_BASE_URL: basePath + "api/public/"
};