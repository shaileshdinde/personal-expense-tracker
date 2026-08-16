$(function () {
  Auth.guardPage();

  const user = Auth.currentUser();
  if (user) $('#welcome-name').text(user.name || 'there');

  Layout.init('dashboard', function () {
    loadStats();
    loadDonutChart();
    loadTrendChart();
    loadRecentExpenses();
    loadLoanSummary();
  });

  function loadLoanSummary() {
    Api.get('/api/loans/summary').done(function (resp) {
      const d = resp.data;
      $('#loan-stat-lent').text(UI.money(d.total_lent));
      $('#loan-stat-outstanding').text(UI.money(d.total_outstanding));
      $('#loan-stat-active').text(d.active_count);
    });
  }

  function todayStr() {
    const d = new Date();
    return d.toISOString().slice(0, 10);
  }

  function loadStats() {
    const now = new Date();
    const year = now.getFullYear();
    const month = now.getMonth() + 1;
    const today = todayStr();

    // Spent today
    Api.get('/api/reports/by-date-range?from=' + today + '&to=' + today).done(function (resp) {
      $('#stat-today').text(UI.money(resp.data.grand_total));
    });

    // Spent this month
    Api.get('/api/reports/monthly?year=' + year + '&month=' + month).done(function (resp) {
      $('#stat-month').text(UI.money(resp.data.total_amount));
    });

    // Active expense count
    Api.get('/api/expenses?status=active&per_page=1').done(function (resp) {
      $('#stat-count').text(resp.data.pagination.total);
    });

    // Active category count
    Api.get('/api/categories?status=active').done(function (resp) {
      $('#stat-categories').text(resp.data.length);
    });
  }

  function loadDonutChart() {
    const now = new Date();
    Api.get('/api/reports/monthly?year=' + now.getFullYear() + '&month=' + (now.getMonth() + 1)).done(function (resp) {
      const rows = (resp.data.by_category || []).filter(function (r) { return Number(r.total_amount) > 0; });

      if (!rows.length) {
        $('#donut-empty').removeClass('d-none');
        $('#donutChart').closest('.chart-container').addClass('d-none');
        return;
      }

      const labels = rows.map(function (r) { return r.category_name; });
      const values = rows.map(function (r) { return Number(r.total_amount); });

      new Chart(document.getElementById('donutChart').getContext('2d'), {
        type: 'doughnut',
        data: {
          labels: labels,
          datasets: [{
            data: values,
            backgroundColor: UI.colors(labels.length),
            borderWidth: 2,
            borderColor: '#fff',
          }],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { position: 'bottom', labels: { boxWidth: 12, padding: 12 } },
            tooltip: {
              callbacks: {
                label: function (ctx) {
                  return ' ' + ctx.label + ': ' + UI.money(ctx.parsed);
                },
              },
            },
          },
          cutout: '65%',
        },
      });
    });
  }

  function loadTrendChart() {
    const now = new Date();
    // Build the list of the last 6 "YYYY-MM" keys ending this month
    const keys = [];
    for (let i = 5; i >= 0; i--) {
      const d = new Date(now.getFullYear(), now.getMonth() - i, 1);
      keys.push(d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0'));
    }
    const yearsNeeded = Array.from(new Set(keys.map(function (k) { return k.split('-')[0]; })));

    $.when.apply(
      $,
      yearsNeeded.map(function (y) { return Api.get('/api/reports/monthly?year=' + y); })
    ).done(function () {
      const responses = yearsNeeded.length === 1 ? [arguments[0]] : Array.prototype.slice.call(arguments).map(function (a) { return a[0]; });
      const totals = {};
      responses.forEach(function (resp) {
        (resp.data.monthly || []).forEach(function (row) {
          totals[row.month] = Number(row.total_amount);
        });
      });

      const values = keys.map(function (k) { return totals[k] || 0; });
      const labels = keys.map(function (k) {
        const parts = k.split('-');
        const d = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, 1);
        return d.toLocaleDateString(undefined, { month: 'short', year: '2-digit' });
      });

      new Chart(document.getElementById('trendChart').getContext('2d'), {
        type: 'bar',
        data: {
          labels: labels,
          datasets: [{
            label: 'Total spent',
            data: values,
            backgroundColor: '#4e73df',
            borderRadius: 4,
            maxBarThickness: 46,
          }],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { display: false },
            tooltip: {
              callbacks: {
                label: function (ctx) { return ' ' + UI.money(ctx.parsed.y); },
              },
            },
          },
          scales: {
            y: { beginAtZero: true, ticks: { callback: function (v) { return UI.money(v); } } },
          },
        },
      });
    });
  }

  function loadRecentExpenses() {
    Api.get('/api/expenses?status=active&per_page=6&page=1').done(function (resp) {
      const items = resp.data.items;
      const $body = $('#recent-expenses-body').empty();

      if (!items.length) {
        $body.append('<tr><td colspan="5" class="text-center py-4 text-muted">No expenses yet. <a href="expenses.html">Add your first one</a>.</td></tr>');
        return;
      }

      items.forEach(function (e) {
        $body.append(
          '<tr>' +
          '<td>' + UI.formatDate(e.expense_date) + '</td>' +
          '<td>' + $('<div>').text(e.reason).html() + '</td>' +
          '<td><span class="badge badge-light border">' + $('<div>').text(e.category_name || '-').html() + '</span></td>' +
          '<td>' + UI.paymentModeBadge(e.payment_mode) + '</td>' +
          '<td class="text-right font-weight-bold">' + UI.money(e.amount) + '</td>' +
          '</tr>'
        );
      });
    });
  }
});
