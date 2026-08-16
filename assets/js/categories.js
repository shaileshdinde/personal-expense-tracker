$(function () {
  Auth.guardPage();
  Layout.init('categories', function () {
    loadCategories();
  });

  let currentStatus = '';

  $('#status-filter button').on('click', function () {
    $('#status-filter button').removeClass('active');
    $(this).addClass('active');
    currentStatus = $(this).data('status');
    loadCategories();
  });

  function loadCategories() {
    const qs = currentStatus ? '?status=' + currentStatus : '';
    Api.get('/api/categories' + qs).done(function (resp) {
      renderRows(resp.data);
    });
  }

  function renderRows(rows) {
    const $body = $('#categories-body').empty();

    if (!rows.length) {
      $body.append('<tr><td colspan="4" class="text-center py-4 text-muted">No categories found.</td></tr>');
      return;
    }

    rows.forEach(function (c) {
      const toggleLabel = c.status === 'active' ? 'Disable' : 'Enable';
      const toggleIcon = c.status === 'active' ? 'fa-ban' : 'fa-check';
      const toggleClass = c.status === 'active' ? 'btn-outline-danger' : 'btn-outline-success';

      $body.append(
        '<tr>' +
        '<td class="font-weight-bold">' + $('<div>').text(c.name).html() + '</td>' +
        '<td>' + UI.statusBadge(c.status) + '</td>' +
        '<td>' + UI.formatDate(c.created_at ? c.created_at.slice(0, 10) : '') + '</td>' +
        '<td class="text-right table-actions">' +
        '<button class="btn btn-sm btn-outline-primary btn-edit" data-id="' + c.id + '" data-name="' + $('<div>').text(c.name).html() + '"><i class="fas fa-edit"></i></button> ' +
        '<button class="btn btn-sm ' + toggleClass + ' btn-toggle" data-id="' + c.id + '" data-status="' + c.status + '" title="' + toggleLabel + '"><i class="fas ' + toggleIcon + '"></i></button>' +
        '</td>' +
        '</tr>'
      );
    });
  }

  $('#btn-new-category').on('click', function () {
    $('#category-form')[0].reset();
    $('#category_id').val('');
    $('#category-modal-title').text('New Category');
    $('#category-modal').modal('show');
  });

  $(document).on('click', '.btn-edit', function () {
    $('#category_id').val($(this).data('id'));
    $('#category_name').val($(this).data('name'));
    $('#category-modal-title').text('Edit Category');
    $('#category-modal').modal('show');
  });

  $(document).on('click', '.btn-toggle', function () {
    const id = $(this).data('id');
    const newStatus = $(this).data('status') === 'active' ? 'disabled' : 'active';
    const action = newStatus === 'disabled' ? 'disable' : 'enable';

    if (!confirm('Are you sure you want to ' + action + ' this category?')) return;

    Api.patch('/api/categories/' + id + '/disable', { status: newStatus })
      .done(function () {
        UI.toast('Category ' + action + 'd successfully');
        loadCategories();
      })
      .fail(function (resp) {
        UI.toast(UI.apiErrorMessage(resp), 'danger');
      });
  });

  $('#category-form').on('submit', function (e) {
    e.preventDefault();
    const id = $('#category_id').val();
    const name = $('#category_name').val().trim();

    const req = id ? Api.put('/api/categories/' + id, { name: name }) : Api.post('/api/categories', { name: name });

    req.done(function () {
      $('#category-modal').modal('hide');
      UI.toast('Category saved successfully');
      loadCategories();
    }).fail(function (resp) {
      UI.toast(UI.apiErrorMessage(resp), 'danger');
    });
  });
});
