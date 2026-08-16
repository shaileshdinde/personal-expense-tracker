$(function () {
  Auth.guardPage();
  Layout.init('bills', function () {
    loadCategoryOptions();
    loadDueBanner();
    loadBills();
  });

  let currentStatus = 'active';
  let allBills = [];

  function loadCategoryOptions() {
    Api.get('/api/categories?status=active').done(function (resp) {
      const $sel = $('#bill_category_id');
      resp.data.forEach(function (c) {
        $sel.append('<option value="' + c.id + '">' + $('<div>').text(c.name).html() + '</option>');
      });
    });
  }

  function loadDueBanner() {
    Api.get('/api/bills/due').done(function (resp) {
      const items = resp.data.items;
      if (!items.length) {
        $('#due-banner').addClass('d-none');
        return;
      }
      const names = items.slice(0, 3).map(function (b) { return b.name; }).join(', ');
      const extra = items.length > 3 ? ' and ' + (items.length - 3) + ' more' : '';
      $('#due-banner-text').text(items.length + ' bill' + (items.length > 1 ? 's' : '') + ' due this month: ' + names + extra + '.');
      $('#due-banner').removeClass('d-none');
    });
  }

  $('#status-filter button').on('click', function () {
    $('#status-filter button').removeClass('active');
    $(this).addClass('active');
    currentStatus = $(this).data('status');
    loadBills();
  });

  function loadBills() {
    const qs = currentStatus ? '?status=' + currentStatus : '';
    Api.get('/api/bills' + qs).done(function (resp) {
      allBills = resp.data.items;
      renderStats(allBills);
      renderRows(allBills);
    });
  }

  function renderStats(rows) {
    const activeRows = rows.filter(function (b) { return b.status === 'active'; });
    const pending = activeRows.filter(function (b) { return b.month_status === 'pending'; }).length;
    const done = activeRows.filter(function (b) { return b.month_status === 'done'; }).length;
    const total = activeRows.reduce(function (sum, b) { return sum + Number(b.amount || 0); }, 0);

    $('#stat-pending').text(pending);
    $('#stat-done').text(done);
    $('#stat-total-amount').text(UI.money(total));
  }

  function renderRows(rows) {
    const $body = $('#bills-body').empty();

    if (!rows.length) {
      $body.append('<tr><td colspan="6" class="text-center py-4 text-muted">No bills found. <a href="#" id="empty-add-link">Add one?</a></td></tr>');
      return;
    }

    rows.forEach(function (b) {
      const isDone = b.month_status === 'done';
      const toggleLabel = b.status === 'active' ? 'Disable' : 'Enable';
      const toggleIcon = b.status === 'active' ? 'fa-ban' : 'fa-check';
      const toggleClass = b.status === 'active' ? 'btn-outline-danger' : 'btn-outline-success';

      const markCell = b.status !== 'active'
        ? '<span class="text-muted small">-</span>'
        : isDone
          ? '<span class="badge badge-success mr-1">Done</span><button class="btn btn-xs btn-outline-secondary btn-unmark" data-id="' + b.id + '">Undo</button>'
          : '<button class="btn btn-sm btn-success btn-mark-done" data-id="' + b.id + '"><i class="fas fa-check mr-1"></i>Mark Done</button>';

      $body.append(
        '<tr>' +
        '<td class="font-weight-bold">' + $('<div>').text(b.name).html() +
          (b.remark ? '<br><span class="text-muted small">' + $('<div>').text(b.remark).html() + '</span>' : '') + '</td>' +
        '<td>' + (b.category_name ? '<span class="badge badge-light border">' + $('<div>').text(b.category_name).html() + '</span>' : '<span class="text-muted">-</span>') + '</td>' +
        '<td>Day ' + b.day_of_month + '</td>' +
        '<td class="text-right">' + (b.amount !== null ? UI.money(b.amount) : '<span class="text-muted">-</span>') + '</td>' +
        '<td>' + markCell + '</td>' +
        '<td class="text-right table-actions">' +
        '<button class="btn btn-sm btn-outline-primary btn-edit" data-id="' + b.id + '" title="Edit"><i class="fas fa-edit"></i></button> ' +
        '<button class="btn btn-sm ' + toggleClass + ' btn-toggle" data-id="' + b.id + '" data-status="' + b.status + '" title="' + toggleLabel + '"><i class="fas ' + toggleIcon + '"></i></button>' +
        '</td>' +
        '</tr>'
      );
    });
  }

  $(document).on('click', '#empty-add-link', function (e) {
    e.preventDefault();
    $('#btn-new-bill').trigger('click');
  });

  $(document).on('click', '.btn-mark-done', function () {
    const id = $(this).data('id');
    Api.post('/api/bills/' + id + '/complete', {})
      .done(function () {
        UI.toast('Marked as done for this month');
        loadBills();
        loadDueBanner();
      })
      .fail(function (resp) {
        UI.toast(UI.apiErrorMessage(resp), 'danger');
      });
  });

  $(document).on('click', '.btn-unmark', function () {
    const id = $(this).data('id');
    Api.delete('/api/bills/' + id + '/complete')
      .done(function () {
        UI.toast('Marked as pending again');
        loadBills();
        loadDueBanner();
      })
      .fail(function (resp) {
        UI.toast(UI.apiErrorMessage(resp), 'danger');
      });
  });

  $('#btn-new-bill').on('click', function () {
    $('#bill-form')[0].reset();
    $('#bill_id').val('');
    $('#bill_day_of_month').val('1');
    $('#bill-modal-title').text('New Bill');
    $('#bill-modal').modal('show');
  });

  $(document).on('click', '.btn-edit', function () {
    const id = $(this).data('id');
    const bill = allBills.find(function (b) { return String(b.id) === String(id); });
    if (!bill) return;

    $('#bill_id').val(bill.id);
    $('#bill_name').val(bill.name);
    $('#bill_amount').val(bill.amount !== null ? bill.amount : '');
    $('#bill_day_of_month').val(bill.day_of_month);
    $('#bill_category_id').val(bill.category_id || '');
    $('#bill_remark').val(bill.remark || '');
    $('#bill-modal-title').text('Edit Bill');
    $('#bill-modal').modal('show');
  });

  $('#bill-form').on('submit', function (e) {
    e.preventDefault();
    const id = $('#bill_id').val();

    const payload = {
      name: $('#bill_name').val().trim(),
      amount: $('#bill_amount').val() || null,
      day_of_month: $('#bill_day_of_month').val(),
      category_id: $('#bill_category_id').val() || null,
      remark: $('#bill_remark').val().trim() || null,
    };

    const req = id ? Api.put('/api/bills/' + id, payload) : Api.post('/api/bills', payload);

    req.done(function () {
      $('#bill-modal').modal('hide');
      UI.toast('Bill saved successfully');
      loadBills();
      loadDueBanner();
    }).fail(function (resp) {
      UI.toast(UI.apiErrorMessage(resp), 'danger');
    });
  });

  $(document).on('click', '.btn-toggle', function () {
    const id = $(this).data('id');
    const newStatus = $(this).data('status') === 'active' ? 'disabled' : 'active';
    const action = newStatus === 'disabled' ? 'disable' : 'enable';

    if (!confirm('Are you sure you want to ' + action + ' this bill?')) return;

    Api.patch('/api/bills/' + id + '/disable', { status: newStatus })
      .done(function () {
        UI.toast('Bill ' + action + 'd successfully');
        loadBills();
        loadDueBanner();
      })
      .fail(function (resp) {
        UI.toast(UI.apiErrorMessage(resp), 'danger');
      });
  });
});
