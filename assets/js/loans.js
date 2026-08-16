$(function () {
  Auth.guardPage();
  Layout.init('loans', function () {
    loadSummary();
    loadLoans(1);
  });

  let currentPage = 1;
  let currentLoanId = null;

  // ---------- Summary cards ----------

  function loadSummary() {
    Api.get('/api/loans/summary').done(function (resp) {
      const d = resp.data;
      $('#stat-total-lent').text(UI.money(d.total_lent));
      $('#stat-outstanding').text(UI.money(d.total_outstanding));
      $('#stat-repaid').text(UI.money(d.total_repaid));
      $('#stat-active-count').text(d.active_count);
    });
  }

  // ---------- Filters ----------

  $('#filter-search, #filter-status, #filter-from, #filter-to').on('change', function () {
    loadLoans(1);
  });
  let searchDebounce;
  $('#filter-search').on('keyup', function () {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(function () { loadLoans(1); }, 400);
  });

  $('#btn-clear-filters').on('click', function () {
    $('#filter-search').val('');
    $('#filter-status').val('active');
    $('#filter-from').val('');
    $('#filter-to').val('');
    loadLoans(1);
  });

  function buildQuery(page) {
    const params = ['page=' + page, 'per_page=10'];
    const search = $('#filter-search').val().trim();
    const status = $('#filter-status').val();
    const from = $('#filter-from').val();
    const to = $('#filter-to').val();

    if (search) params.push('search=' + encodeURIComponent(search));
    if (status) params.push('status=' + status);
    if (from) params.push('from=' + from);
    if (to) params.push('to=' + to);

    return params.join('&');
  }

  // ---------- List + pagination ----------

  function loadLoans(page) {
    currentPage = page;
    Api.get('/api/loans?' + buildQuery(page)).done(function (resp) {
      renderRows(resp.data.items);
      renderPagination(resp.data.pagination);
    });
  }

  function statusBadgeLoan(status) {
    if (status === 'active') return '<span class="badge badge-warning">Active</span>';
    if (status === 'closed') return '<span class="badge badge-success">Closed</span>';
    return '<span class="badge badge-secondary">Disabled</span>';
  }

  function renderRows(rows) {
    const $body = $('#loans-body').empty();

    if (!rows.length) {
      $body.append('<tr><td colspan="8" class="text-center py-4 text-muted">No loans found for the selected filters.</td></tr>');
      return;
    }

    rows.forEach(function (l) {
      const toggleDisabled = l.status === 'disabled';
      $body.append(
        '<tr>' +
        '<td class="font-weight-bold">' + $('<div>').text(l.borrower_name).html() +
          (l.borrower_contact ? '<br><span class="text-muted small">' + $('<div>').text(l.borrower_contact).html() + '</span>' : '') + '</td>' +
        '<td>' + UI.formatDate(l.loan_date) + '</td>' +
        '<td>' + (l.due_date ? UI.formatDate(l.due_date) : '<span class="text-muted">-</span>') + '</td>' +
        '<td class="text-right">' + UI.money(l.amount) + '</td>' +
        '<td class="text-right text-success">' + UI.money(l.total_repaid) + '</td>' +
        '<td class="text-right font-weight-bold ' + (l.balance > 0 ? 'text-danger' : '') + '">' + UI.money(l.balance) + '</td>' +
        '<td>' + statusBadgeLoan(l.status) + '</td>' +
        '<td class="text-right table-actions">' +
        '<button class="btn btn-sm btn-outline-info btn-view" data-id="' + l.id + '" title="View / Repay"><i class="fas fa-eye"></i></button> ' +
        '<button class="btn btn-sm btn-outline-primary btn-edit" data-id="' + l.id + '" title="Edit"><i class="fas fa-edit"></i></button> ' +
        '<button class="btn btn-sm btn-outline-danger btn-disable" data-id="' + l.id + '" title="Disable" ' + (toggleDisabled ? 'disabled' : '') + '><i class="fas fa-ban"></i></button>' +
        '</td>' +
        '</tr>'
      );
    });
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
    if (page >= 1) loadLoans(page);
  });

  // ---------- Add / Edit loan ----------

  $('#btn-new-loan').on('click', function () {
    $('#loan-form')[0].reset();
    $('#loan_id').val('');
    $('#loan-modal-title').text('New Loan');
    $('#loan_date').val(new Date().toISOString().slice(0, 10));
    $('#loan-modal').modal('show');
  });

  $(document).on('click', '.btn-edit', function () {
    const id = $(this).data('id');
    Api.get('/api/loans/' + id).done(function (resp) {
      const l = resp.data;
      $('#loan_id').val(l.id);
      $('#loan_borrower_name').val(l.borrower_name);
      $('#loan_borrower_contact').val(l.borrower_contact || '');
      $('#loan_amount').val(l.amount);
      $('#loan_date').val(l.loan_date);
      $('#loan_due_date').val(l.due_date || '');
      $('#loan_interest_rate').val(l.interest_rate || '');
      $('#loan_reason').val(l.reason || '');
      $('#loan_remark').val(l.remark || '');
      $('#loan-modal-title').text('Edit Loan');
      $('#loan-modal').modal('show');
    });
  });

  $('#loan-form').on('submit', function (e) {
    e.preventDefault();
    const id = $('#loan_id').val();

    const payload = {
      borrower_name: $('#loan_borrower_name').val().trim(),
      borrower_contact: $('#loan_borrower_contact').val().trim() || null,
      amount: $('#loan_amount').val(),
      loan_date: $('#loan_date').val(),
      due_date: $('#loan_due_date').val() || null,
      interest_rate: $('#loan_interest_rate').val() || null,
      reason: $('#loan_reason').val().trim() || null,
      remark: $('#loan_remark').val().trim() || null,
    };

    const req = id ? Api.put('/api/loans/' + id, payload) : Api.post('/api/loans', payload);

    req.done(function () {
      $('#loan-modal').modal('hide');
      UI.toast('Loan saved successfully');
      loadSummary();
      loadLoans(id ? currentPage : 1);
    }).fail(function (resp) {
      UI.toast(UI.apiErrorMessage(resp), 'danger');
    });
  });

  // ---------- Disable ----------

  $(document).on('click', '.btn-disable', function () {
    const id = $(this).data('id');
    if (!confirm('Disable this loan record? It will be excluded from active totals.')) return;

    Api.patch('/api/loans/' + id + '/status', { status: 'disabled' })
      .done(function () {
        UI.toast('Loan disabled');
        loadSummary();
        loadLoans(currentPage);
      })
      .fail(function (resp) {
        UI.toast(UI.apiErrorMessage(resp), 'danger');
      });
  });

  // ---------- Detail / Repayments modal ----------

  $(document).on('click', '.btn-view', function () {
    currentLoanId = $(this).data('id');
    openDetailModal();
  });

  function openDetailModal() {
    Api.get('/api/loans/' + currentLoanId).done(function (resp) {
      const l = resp.data;

      $('#detail-borrower-name').text(l.borrower_name);
      $('#detail-status-badge').html(statusBadgeLoan(l.status));
      $('#detail-amount').text(UI.money(l.amount));
      $('#detail-repaid').text(UI.money(l.total_repaid));
      $('#detail-balance').text(UI.money(l.balance));

      const pct = l.amount > 0 ? Math.min(100, (l.total_repaid / l.amount) * 100) : 0;
      $('#detail-progress-bar').css('width', pct + '%');

      let meta = 'Loan date: ' + UI.formatDate(l.loan_date);
      if (l.due_date) meta += ' &middot; Due: ' + UI.formatDate(l.due_date);
      if (l.interest_rate) meta += ' &middot; Interest: ' + l.interest_rate + '%';
      if (l.reason) meta += ' &middot; ' + $('<div>').text(l.reason).html();
      $('#detail-meta').html(meta);

      $('#repayment_date').val(new Date().toISOString().slice(0, 10));
      $('#repayment_amount').val('');
      $('#repayment_remark').val('');

      const disableForm = l.status === 'disabled';
      $('#repayment-form :input').prop('disabled', disableForm);

      renderRepayments(l.repayments || []);

      $('#detail-modal').modal('show');
    });
  }

  function renderRepayments(rows) {
    const $body = $('#repayments-body').empty();
    if (!rows.length) {
      $body.append('<tr><td colspan="5" class="text-center text-muted py-3">No repayments yet.</td></tr>');
      return;
    }
    rows.forEach(function (r) {
      $body.append(
        '<tr>' +
        '<td>' + UI.formatDate(r.payment_date) + '</td>' +
        '<td>' + UI.paymentModeBadge(r.payment_mode) + '</td>' +
        '<td>' + (r.remark ? $('<div>').text(r.remark).html() : '<span class="text-muted">-</span>') + '</td>' +
        '<td class="text-right font-weight-bold">' + UI.money(r.amount) + '</td>' +
        '<td class="text-right">' +
        '<button class="btn btn-xs btn-outline-danger btn-delete-repayment" data-id="' + r.id + '" title="Remove"><i class="fas fa-trash"></i></button>' +
        '</td>' +
        '</tr>'
      );
    });
  }

  $('#repayment-form').on('submit', function (e) {
    e.preventDefault();

    const payload = {
      amount: $('#repayment_amount').val(),
      payment_date: $('#repayment_date').val(),
      payment_mode: $('#repayment_mode').val(),
      remark: $('#repayment_remark').val().trim() || null,
    };

    Api.post('/api/loans/' + currentLoanId + '/repayments', payload)
      .done(function () {
        UI.toast('Repayment recorded');
        openDetailModal();
        loadSummary();
        loadLoans(currentPage);
      })
      .fail(function (resp) {
        UI.toast(UI.apiErrorMessage(resp), 'danger');
      });
  });

  $(document).on('click', '.btn-delete-repayment', function () {
    const repaymentId = $(this).data('id');
    if (!confirm('Remove this repayment entry?')) return;

    Api.delete('/api/loans/' + currentLoanId + '/repayments/' + repaymentId)
      .done(function () {
        UI.toast('Repayment removed');
        openDetailModal();
        loadSummary();
        loadLoans(currentPage);
      })
      .fail(function (resp) {
        UI.toast(UI.apiErrorMessage(resp), 'danger');
      });
  });
});
