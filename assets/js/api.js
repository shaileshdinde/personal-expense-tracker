/**
 * Thin wrapper around $.ajax for talking to the Expense Tracker API.
 * Automatically attaches the JWT (if present) and normalizes error handling.
 */
const Api = (function () {
  const BASE = window.APP_CONFIG.API_BASE_URL.replace(/\/$/, "");

  function token() {
    return localStorage.getItem("et_token");
  }

  function request(method, path, data) {
    const opts = {
      url: BASE + path,
      method: method,
      contentType: "application/json",
      dataType: "json",
    };

    if (data !== undefined && data !== null) {
      opts.data = JSON.stringify(data);
    }

    const t = token();
    opts.headers = t ? { Authorization: "Bearer " + t } : {};

    return $.ajax(opts)
      .then(
        function (resp) {
          return resp;
        },
        function (jqXHR) {
          const resp = jqXHR.responseJSON || {
            success: false,
            message: "Network error. Please check your connection and API URL in assets/js/config.js.",
          };

          if (jqXHR.status === 401) {
            // Token missing/expired/revoked -> force re-login
            Auth.clearSession();
            if (!/login\.html$/.test(window.location.pathname)) {
              window.location.href = "login.html?expired=1";
            }
          }

          return $.Deferred().reject(resp).promise();
        }
      );
  }

  return {
    get: function (path) {
      return request("GET", path);
    },
    post: function (path, data) {
      return request("POST", path, data);
    },
    put: function (path, data) {
      return request("PUT", path, data);
    },
    patch: function (path, data) {
      return request("PATCH", path, data);
    },
    delete: function (path) {
      return request("DELETE", path);
    },
  };
})();
