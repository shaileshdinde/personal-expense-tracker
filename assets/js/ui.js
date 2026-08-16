/**
 * Small reusable UI helpers shared across pages.
 */
const UI = (function () {
  function toast(message, type) {
    type = type || "success";
    const icon =
      type === "success" ? "fa-check-circle" : type === "danger" ? "fa-exclamation-circle" : "fa-info-circle";

    const $toast = $(
      '<div class="toast align-items-center" role="alert" aria-live="assertive" aria-atomic="true" ' +
        'style="position:fixed; top:75px; right:20px; z-index:9999; min-width:280px;" data-delay="3500">' +
        '<div class="toast-header bg-' +
        type +
        ' text-white">' +
        '<i class="fas ' +
        icon +
        ' mr-2"></i>' +
        '<strong class="mr-auto">' +
        (type === "danger" ? "Error" : type === "success" ? "Success" : "Notice") +
        "</strong>" +
        '<button type="button" class="ml-2 mb-1 close text-white" data-dismiss="toast">&times;</button>' +
        "</div>" +
        '<div class="toast-body">' +
        $("<div>").text(message).html() +
        "</div>" +
        "</div>"
    );

    $("body").append($toast);
    $toast.toast("show");
    $toast.on("hidden.bs.toast", function () {
      $(this).remove();
    });
  }

  function apiErrorMessage(resp) {
    if (!resp) return "Something went wrong. Please try again.";
    if (resp.errors) {
      if (Array.isArray(resp.errors)) return resp.errors.join(", ");
      if (typeof resp.errors === "object") {
        const parts = [];
        Object.keys(resp.errors).forEach(function (k) {
          const v = resp.errors[k];
          parts.push(Array.isArray(v) ? v.join(", ") : v);
        });
        if (parts.length) return parts.join(" ");
      }
    }
    return resp.message || "Something went wrong. Please try again.";
  }

  function money(amount) {
    const n = Number(amount || 0);
    return "₹" + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function formatDate(dateStr) {
    if (!dateStr) return "";
    const d = new Date(dateStr + "T00:00:00");
    if (isNaN(d.getTime())) return dateStr;
    return d.toLocaleDateString(undefined, { year: "numeric", month: "short", day: "numeric" });
  }

  function formatTime(timeStr) {
    if (!timeStr) return "";
    const parts = timeStr.split(":");
    if (parts.length < 2) return timeStr;
    const d = new Date();
    d.setHours(parseInt(parts[0], 10), parseInt(parts[1], 10));
    return d.toLocaleTimeString(undefined, { hour: "2-digit", minute: "2-digit" });
  }

  function statusBadge(status) {
    return status === "active"
      ? '<span class="badge badge-success">Active</span>'
      : '<span class="badge badge-secondary">Disabled</span>';
  }

  function paymentModeBadge(mode) {
    const map = {
      cash: "badge-warning",
      card: "badge-primary",
      upi: "badge-info",
      netbanking: "badge-purple",
      wallet: "badge-teal",
      other: "badge-secondary",
    };
    const cls = map[mode] || "badge-secondary";
    return '<span class="badge ' + cls + '">' + (mode || "").toUpperCase() + "</span>";
  }

  // A consistent, distinguishable palette used across all charts on the Reports page
  const PALETTE = [
    "#4e73df",
    "#1cc88a",
    "#f6c23e",
    "#e74a3b",
    "#36b9cc",
    "#858796",
    "#fd7e14",
    "#6f42c1",
    "#20c997",
    "#e83e8c",
    "#17a2b8",
    "#6610f2",
  ];

  function colors(n) {
    const out = [];
    for (let i = 0; i < n; i++) out.push(PALETTE[i % PALETTE.length]);
    return out;
  }

  return { toast, apiErrorMessage, money, formatDate, formatTime, statusBadge, paymentModeBadge, colors, PALETTE };
})();
