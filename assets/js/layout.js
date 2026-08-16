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

  /**
   * App-wide "bills due this month" indicator: badges the bell icon in the
   * navbar, and - if the browser has granted Notification permission and we
   * haven't already shown one today - fires a native OS notification once
   * per day. This is what surfaces the "day 1 of the month" alert even on
   * pages other than the Bills page itself.
   */
  function checkDueBills() {
    if (typeof Api === "undefined") return;

    Api.get("/api/bills/due").done(function (resp) {
      const count = resp.data.count || 0;
      const $badge = $("#bills-bell-badge");

      if (count > 0) {
        $badge.text(count).removeClass("d-none");
      } else {
        $badge.addClass("d-none");
      }

      if (count > 0) {
        maybeNotify(resp.data.items, count);
      }
    });
  }

  function maybeNotify(items, count) {
    if (typeof Notification === "undefined") return;

    const todayKey = new Date().toISOString().slice(0, 10);
    let lastShown = null;
    try {
      lastShown = localStorage.getItem("et_bills_notified_on");
    } catch (e) {
      return; // localStorage unavailable (e.g. private browsing edge cases) - skip silently
    }
    if (lastShown === todayKey) return;

    const show = function () {
      const names = items.slice(0, 3).map(function (b) { return b.name; }).join(", ");
      const body = count + " bill" + (count > 1 ? "s" : "") + " due this month: " + names + (count > 3 ? "…" : "");
      try {
        new Notification("Bills due", { body: body, tag: "et-bills-due" });
      } catch (e) {
        /* some browsers restrict Notification outside a user gesture on first use - ignore */
      }
      try {
        localStorage.setItem("et_bills_notified_on", todayKey);
      } catch (e) {}
    };

    if (Notification.permission === "granted") {
      show();
    } else if (Notification.permission !== "denied") {
      Notification.requestPermission().then(function (perm) {
        if (perm === "granted") show();
      });
    }
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
        checkDueBills();
        if (typeof onReady === "function") onReady();
      }
    }

    $("#navbar-placeholder").load("partials/navbar.html", done);
    $("#sidebar-placeholder").load("partials/sidebar.html", done);
    $("#footer-placeholder").load("partials/footer.html");
  }

  return { init };
})();
