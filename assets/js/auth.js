/**
 * Session storage + page guarding helpers.
 */
const Auth = (function () {
  function saveSession(auth, user) {
    localStorage.setItem("et_token", auth.token);
    localStorage.setItem("et_expires_at", auth.expires_at);
    localStorage.setItem("et_device", auth.device);
    localStorage.setItem("et_user", JSON.stringify(user));
  }

  function clearSession() {
    localStorage.removeItem("et_token");
    localStorage.removeItem("et_expires_at");
    localStorage.removeItem("et_device");
    localStorage.removeItem("et_user");
  }

  function isLoggedIn() {
    return !!localStorage.getItem("et_token");
  }

  function currentUser() {
    try {
      return JSON.parse(localStorage.getItem("et_user") || "null");
    } catch (e) {
      return null;
    }
  }

  /** Call at the top of every protected page. Redirects to login if not authenticated. */
  function guardPage() {
    if (!isLoggedIn()) {
      window.location.href = "login.html";
    }
  }

  /** Call at the top of login/register pages. Redirects to dashboard if already authenticated. */
  function redirectIfLoggedIn() {
    if (isLoggedIn()) {
      window.location.href = "index.html";
    }
  }

  return {
    saveSession,
    clearSession,
    isLoggedIn,
    currentUser,
    guardPage,
    redirectIfLoggedIn,
  };
})();
