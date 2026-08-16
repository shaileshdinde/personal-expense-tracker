/**
 * Loads the shared navbar/sidebar/footer partials into a protected page,
 * marks the active sidebar link, fills in the logged-in user's info, and
 * wires up logout. Call Layout.init('dashboard') etc. after Auth.guardPage().
 */
const Layout = (function () {
  function wireLogout() {
    function doLogout(e) {
      e.preventDefault();
      Api.post("/api/logout").always(function () {
        Auth.clearSession();
        window.location.href = "login.html";
      });
    }
    $(document).on("click", "#logout-link, #sidebar-logout-link", doLogout);
  }

  function fillUserBox() {
    const user = Auth.currentUser();
    if (!user) return;
    $("#navbar-user-name").text(user.name || "Account");
    $("#sidebar-user-name").text(user.name || "");
    $("#sidebar-user-email").text(user.email || "");
  }

  function markActive(page) {
    $('.nav-sidebar [data-nav="' + page + '"]').addClass("active");
  }

  function init(activePage, onReady) {
    let pending = 2;
    function done() {
      pending--;
      if (pending === 0) {
        markActive(activePage);
        fillUserBox();
        wireLogout();
        if (typeof onReady === "function") onReady();
      }
    }

    $("#navbar-placeholder").load("partials/navbar.html", done);
    $("#sidebar-placeholder").load("partials/sidebar.html", done);
    $("#footer-placeholder").load("partials/footer.html");
  }

  return { init };
})();
