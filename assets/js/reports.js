$(function () {
  Auth.guardPage();
  Layout.init('reports', function () {
    setDefaultRange('month');
    populateYearSelect();
    loadAllRangeReports();
    loadMonthlyReport($('#monthly-year').val());
  });

  const charts = {}; // holds Chart.js instances so we can destroy/recreate on refresh

  function destroyChart(key) {
    if (charts[key]) {
      charts[key].destroy();
      delete charts[key];
    }
  }

  function todayStr(d) {
    d = d || new Date();
    return d.toISOString().slice(0, 10);
  }

  function setDefaultRange(kind) {
    const now = new Date();
    let from, to;

    if (kind === '7') {
      from = new Date(now); from.setDate(now.getDate() - 6);
      to = now;
    } else if (kind === '30') {
      from = new Date(now); from.setDate(now.getDate() - 29);
      to = now;
    } else {
      from = new Date(now.getFullYear(), now.getMonth(), 1);
      to = now;
    }

    $('#range-from').val(todayStr(from));
    $('#range-to').val(todayStr(to));
  }

  $('[data-range]').on('click', function () {
    setDefaultRange($(this).data('range').toString());
    loadAllRangeReports();
  });

  $('#range-form').on('submit', function (e) {
    e.preventDefault();
    loadAllRangeReports();
  });

  function currentRange() {
    return { from: $('#range-from').val(), to: $('#range-to').val() };
  }

  function loadAllRangeReports() {
    loadCategoryReport();
    loadSubcategoryReport();
    loadDailyTrend();
  }

  // ---------- Donut: by category ----------
  function loadCategoryReport() {
    const r = currentRange();
    Api.get('/api/reports/by-category?from=' + r.from + '&to=' + r.to).done(function (resp) {
      const rows = resp.data.filter(function (row) { return Number(row.total_amount) > 0; });
      destroyChart('categoryDonut');

      const grandTotal = rows.reduce(function (sum, row) { return sum + Number(row.total_amount); }, 0);
      $('#category-total-badge').text(UI.money(grandTotal));

      renderCategoryTable(rows, grandTotal);

      if (!rows.length) {
        $('#category-empty').removeClass('d-none');
        $('#categoryDonutChart').closest('.chart-container').addClass('d-none');
        return;
      }
      $('#category-empty').addClass('d-none');
      $('#categoryDonutChart').closest('.chart-container').removeClass('d-none');

      const labels = rows.map(function (r) { return r.category_name; });
      const values = rows.map(function (r) { return Number(r.total_amount); });

      charts.categoryDonut = new Chart(document.getElementById('categoryDonutChart').getContext('2d'), {
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
            tooltip: { callbacks: { label: function (ctx) { return ' ' + ctx.label + ': ' + UI.money(ctx.parsed); } } },
          },
          cutout: '62%',
        },
      });
    });
  }

  function renderCategoryTable(rows, grandTotal) {
    const $body = $('#category-table-body').empty();
    if (!rows.length) {
      $body.append('<tr><td colspan="4" class="text-center py-4 text-muted">No data for this date range.</td></tr>');
      return;
    }
    rows.forEach(function (row) {
      const pct = grandTotal > 0 ? ((Number(row.total_amount) / grandTotal) * 100).toFixed(1) : '0.0';
      $body.append(
        '<tr>' +
        '<td>' + $('<div>').text(row.category_name).html() + '</td>' +
        '<td class="text-right">' + row.expense_count + '</td>' +
        '<td class="text-right font-weight-bold">' + UI.money(row.total_amount) + '</td>' +
        '<td class="text-right">' + pct + '%</td>' +
        '</tr>'
      );
    });
  }

  // ---------- Horizontal bar: by subcategory ----------
  function loadSubcategoryReport() {
    const r = currentRange();
    Api.get('/api/reports/by-subcategory?from=' + r.from + '&to=' + r.to).done(function (resp) {
      const rows = resp.data.filter(function (row) { return Number(row.total_amount) > 0 && row.subcategory_name; });
      destroyChart('subcategoryBar');

      if (!rows.length) {
        $('#subcategory-empty').removeClass('d-none');
        $('#subcategoryBarChart').closest('.chart-container').addClass('d-none');
        return;
      }
      $('#subcategory-empty').addClass('d-none');
      $('#subcategoryBarChart').closest('.chart-container').removeClass('d-none');

      rows.sort(function (a, b) { return Number(b.total_amount) - Number(a.total_amount); });
      const top = rows.slice(0, 10);
      const labels = top.map(function (r) { return r.subcategory_name + ' (' + r.category_name + ')'; });
      const values = top.map(function (r) { return Number(r.total_amount); });

      charts.subcategoryBar = new Chart(document.getElementById('subcategoryBarChart').getContext('2d'), {
        type: 'bar',
        data: {
          labels: labels,
          datasets: [{
            label: 'Total spent',
            data: values,
            backgroundColor: UI.colors(labels.length),
            borderRadius: 4,
          }],
        },
        options: {
          indexAxis: 'y',
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: function (ctx) { return ' ' + UI.money(ctx.parsed.x); } } },
          },
          scales: {
            x: { beginAtZero: true, ticks: { callback: function (v) { return UI.money(v); } } },
          },
        },
      });
    });
  }

  // ---------- Line: daily trend ----------
  function loadDailyTrend() {
    const r = currentRange();
    Api.get('/api/reports/by-date-range?from=' + r.from + '&to=' + r.to).done(function (resp) {
      const rows = resp.data.daily || [];
      destroyChart('dailyLine');

      if (!rows.length) {
        $('#daily-empty').removeClass('d-none');
        $('#dailyLineChart').closest('.chart-container').addClass('d-none');
        return;
      }
      $('#daily-empty').addClass('d-none');
      $('#dailyLineChart').closest('.chart-container').removeClass('d-none');

      const labels = rows.map(function (row) { return UI.formatDate(row.expense_date); });
      const values = rows.map(function (row) { return Number(row.total_amount); });

      charts.dailyLine = new Chart(document.getElementById('dailyLineChart').getContext('2d'), {
        type: 'line',
        data: {
          labels: labels,
          datasets: [{
            label: 'Daily spend',
            data: values,
            fill: true,
            backgroundColor: 'rgba(78, 115, 223, 0.12)',
            borderColor: '#4e73df',
            pointBackgroundColor: '#4e73df',
            tension: 0.3,
          }],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: function (ctx) { return ' ' + UI.money(ctx.parsed.y); } } },
          },
          scales: {
            y: { beginAtZero: true, ticks: { callback: function (v) { return UI.money(v); } } },
          },
        },
      });
    });
  }

  // ---------- Monthly bar chart (year picker) ----------
  function populateYearSelect() {
    const currentYear = new Date().getFullYear();
    const $sel = $('#monthly-year').empty();
    for (let y = currentYear; y >= currentYear - 4; y--) {
      $sel.append('<option value="' + y + '">' + y + '</option>');
    }
    $sel.val(currentYear);
  }

  $('#monthly-year').on('change', function () {
    loadMonthlyReport($(this).val());
  });

  function loadMonthlyReport(year) {
    Api.get('/api/reports/monthly?year=' + year).done(function (resp) {
      destroyChart('monthlyBar');

      const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
      const totals = {};
      (resp.data.monthly || []).forEach(function (row) {
        totals[row.month] = Number(row.total_amount);
      });

      const labels = [];
      const values = [];
      for (let m = 1; m <= 12; m++) {
        const key = year + '-' + String(m).padStart(2, '0');
        labels.push(monthNames[m - 1]);
        values.push(totals[key] || 0);
      }

      charts.monthlyBar = new Chart(document.getElementById('monthlyBarChart').getContext('2d'), {
        type: 'bar',
        data: {
          labels: labels,
          datasets: [{
            label: 'Total spent',
            data: values,
            backgroundColor: '#1cc88a',
            borderRadius: 4,
            maxBarThickness: 46,
          }],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: function (ctx) { return ' ' + UI.money(ctx.parsed.y); } } },
          },
          scales: {
            y: { beginAtZero: true, ticks: { callback: function (v) { return UI.money(v); } } },
          },
        },
      });
    });
  }
});
