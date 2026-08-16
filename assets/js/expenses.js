$(function () {
  Auth.guardPage();
  Layout.init('expenses', function () {
    loadCategoryOptions();
    loadExpenses(1);
  });

  let allCategories = [];
  let allSubcategories = [];
  let currentPage = 1;

  // ---------- Category / Sub-category dropdowns ----------

  function loadCategoryOptions() {
    Api.get('/api/categories?status=active').done(function (resp) {
      allCategories = resp.data;
      const $filter = $('#filter-category').empty().append('<option value="">All</option>');
      const $modal = $('#expense_category_id').empty();

      allCategories.forEach(function (c) {
        $filter.append('<option value="' + c.id + '">' + $('<div>').text(c.name).html() + '</option>');
        $modal.append('<option value="' + c.id + '">' + $('<div>').text(c.name).html() + '</option>');
      });
    });

    Api.get('/api/subcategories?status=active').done(function (resp) {
      allSubcategories = resp.data;
      refreshFilterSubcategoryOptions();
    });
  }

  function refreshFilterSubcategoryOptions() {
    const categoryId = $('#filter-category').val();
    const $sub = $('#filter-subcategory').empty().append('<option value="">All</option>');
    allSubcategories
      .filter(function (s) { return !categoryId || String(s.category_id) === String(categoryId); })
      .forEach(function (s) {
        $sub.append('<option value="' + s.id + '">' + $('<div>').text(s.name).html() + '</option>');
      });
  }

  function refreshModalSubcategoryOptions(selectedId) {
    const categoryId = $('#expense_category_id').val();
    const $sub = $('#expense_subcategory_id').empty().append('<option value="">None</option>');
    allSubcategories
      .filter(function (s) { return String(s.category_id) === String(categoryId); })
      .forEach(function (s) {
        $sub.append('<option value="' + s.id + '">' + $('<div>').text(s.name).html() + '</option>');
      });
    if (selectedId) $sub.val(selectedId);
  }

  $('#filter-category').on('change', function () {
    refreshFilterSubcategoryOptions();
    loadExpenses(1);
  });
  $('#filter-subcategory, #filter-from, #filter-to, #filter-status').on('change', function () {
    loadExpenses(1);
  });
  $('#expense_category_id').on('change', function () {
    refreshModalSubcategoryOptions(null);
  });

  $('#btn-clear-filters').on('click', function () {
    $('#filter-category').val('');
    $('#filter-subcategory').val('');
    $('#filter-from').val('');
    $('#filter-to').val('');
    $('#filter-status').val('active');
    refreshFilterSubcategoryOptions();
    loadExpenses(1);
  });

  // ---------- List + pagination ----------

  function buildQuery(page) {
    const params = ['page=' + page, 'per_page=10'];
    const cat = $('#filter-category').val();
    const sub = $('#filter-subcategory').val();
    const from = $('#filter-from').val();
    const to = $('#filter-to').val();
    const status = $('#filter-status').val();

    if (cat) params.push('category_id=' + cat);
    if (sub) params.push('subcategory_id=' + sub);
    if (from) params.push('from=' + from);
    if (to) params.push('to=' + to);
    if (status) params.push('status=' + status);

    return params.join('&');
  }

  function loadExpenses(page) {
    currentPage = page;
    Api.get('/api/expenses?' + buildQuery(page)).done(function (resp) {
      renderRows(resp.data.items);
      renderPagination(resp.data.pagination);
    });
  }

  function renderRows(rows) {
    const $body = $('#expenses-body').empty();
    let pageTotal = 0;

    if (!rows.length) {
      $body.append('<tr><td colspan="7" class="text-center py-4 text-muted">No expenses found for the selected filters.</td></tr>');
      $('#page-total').text(UI.money(0));
      return;
    }

    rows.forEach(function (e) {
      pageTotal += Number(e.amount);
      const toggleLabel = e.status === 'active' ? 'Disable' : 'Enable';
      const toggleIcon = e.status === 'active' ? 'fa-ban' : 'fa-check';
      const toggleClass = e.status === 'active' ? 'btn-outline-danger' : 'btn-outline-success';

      $body.append(
        '<tr>' +
        '<td>' + UI.formatDate(e.expense_date) + '<br><span class="text-muted small">' + UI.formatTime(e.expense_time) + '</span></td>' +
        '<td>' + $('<div>').text(e.reason).html() + (e.remark ? '<br><span class="text-muted small">' + $('<div>').text(e.remark).html() + '</span>' : '') + '</td>' +
        '<td><span class="badge badge-light border">' + $('<div>').text(e.category_name || '-').html() + '</span>' +
          (e.subcategory_name ? '<br><span class="text-muted small">' + $('<div>').text(e.subcategory_name).html() + '</span>' : '') + '</td>' +
        '<td>' + UI.paymentModeBadge(e.payment_mode) + '</td>' +
        '<td>' + UI.statusBadge(e.status) + '</td>' +
        '<td class="text-right font-weight-bold">' + UI.money(e.amount) + '</td>' +
        '<td class="text-right table-actions">' +
        '<button class="btn btn-sm btn-outline-primary btn-edit" data-id="' + e.id + '"><i class="fas fa-edit"></i></button> ' +
        '<button class="btn btn-sm ' + toggleClass + ' btn-toggle" data-id="' + e.id + '" data-status="' + e.status + '" title="' + toggleLabel + '"><i class="fas ' + toggleIcon + '"></i></button>' +
        '</td>' +
        '</tr>'
      );
    });

    $('#page-total').text(UI.money(pageTotal));
  }

  function renderPagination(p) {
    $('#pagination-info').text(
      p.total === 0 ? 'No results' : 'Showing page ' + p.page + ' of ' + p.pages + ' (' + p.total + ' total)'
    );

    const $pg = $('#pagination-controls').empty();
    if (p.pages <= 1) return;

    function pageItem(label, page, disabled, active) {
      return $(
        '<li class="page-item' + (disabled ? ' disabled' : '') + (active ? ' active' : '') + '">' +
        '<a class="page-link" href="#" data-page="' + page + '">' + label + '</a></li>'
      );
    }

    $pg.append(pageItem('&laquo;', p.page - 1, p.page <= 1, false));
    for (let i = 1; i <= p.pages; i++) {
      $pg.append(pageItem(i, i, false, i === p.page));
    }
    $pg.append(pageItem('&raquo;', p.page + 1, p.page >= p.pages, false));
  }

  $(document).on('click', '#pagination-controls a', function (e) {
    e.preventDefault();
    const page = parseInt($(this).data('page'), 10);
    if (page >= 1) loadExpenses(page);
  });

  // ---------- Add / Edit ----------

  $('#btn-new-expense').on('click', function () {
    $('#expense-form')[0].reset();
    $('#expense_id').val('');
    $('#expense-modal-title').text('New Expense');
    refreshModalSubcategoryOptions(null);

    const now = new Date();
    $('#expense_date').val(now.toISOString().slice(0, 10));
    $('#expense_time').val(now.toTimeString().slice(0, 5));

    $('#expense-modal').modal('show');
  });

  $(document).on('click', '.btn-edit', function () {
    const id = $(this).data('id');
    Api.get('/api/expenses/' + id).done(function (resp) {
      const e = resp.data;
      $('#expense_id').val(e.id);
      $('#expense_category_id').val(e.category_id);
      refreshModalSubcategoryOptions(e.subcategory_id);
      $('#expense_reason').val(e.reason);
      $('#expense_amount').val(e.amount);
      $('#expense_date').val(e.expense_date);
      $('#expense_time').val(e.expense_time ? e.expense_time.slice(0, 5) : '');
      $('#expense_payment_mode').val(e.payment_mode);
      $('#expense_details').val(e.details || '');
      $('#expense_remark').val(e.remark || '');
      $('#expense-modal-title').text('Edit Expense');
      $('#expense-modal').modal('show');
    });
  });

  $(document).on('click', '.btn-toggle', function () {
    const id = $(this).data('id');
    const newStatus = $(this).data('status') === 'active' ? 'disabled' : 'active';
    const action = newStatus === 'disabled' ? 'disable' : 'enable';

    if (!confirm('Are you sure you want to ' + action + ' this expense?')) return;

    Api.patch('/api/expenses/' + id + '/disable', { status: newStatus })
      .done(function () {
        UI.toast('Expense ' + action + 'd successfully');
        loadExpenses(currentPage);
      })
      .fail(function (resp) {
        UI.toast(UI.apiErrorMessage(resp), 'danger');
      });
  });

  $('#expense-form').on('submit', function (e) {
    e.preventDefault();
    const id = $('#expense_id').val();

    const payload = {
      category_id: $('#expense_category_id').val(),
      subcategory_id: $('#expense_subcategory_id').val() || null,
      reason: $('#expense_reason').val().trim(),
      details: $('#expense_details').val().trim() || null,
      amount: $('#expense_amount').val(),
      date: $('#expense_date').val(),
      time: $('#expense_time').val() + ':00',
      payment_mode: $('#expense_payment_mode').val(),
      remark: $('#expense_remark').val().trim() || null,
    };

    const req = id ? Api.put('/api/expenses/' + id, payload) : Api.post('/api/expenses', payload);

    req.done(function () {
      $('#expense-modal').modal('hide');
      UI.toast('Expense saved successfully');
      loadExpenses(id ? currentPage : 1);
    }).fail(function (resp) {
      UI.toast(UI.apiErrorMessage(resp), 'danger');
    });
  });
});
