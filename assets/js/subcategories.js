$(function () {
  Auth.guardPage();
  Layout.init('subcategories', function () {
    loadCategoryOptions();
    loadSubcategories();
  });

  let currentStatus = '';
  let currentCategory = '';
  let allCategories = [];

  function loadCategoryOptions() {
    Api.get('/api/categories').done(function (resp) {
      allCategories = resp.data;

      const $filter = $('#filter-category');
      const $modalSelect = $('#subcategory_category_id');
      $modalSelect.empty();

      allCategories.forEach(function (c) {
        const disabledLabel = c.status === 'disabled' ? ' (disabled)' : '';
        $filter.append('<option value="' + c.id + '">' + $('<div>').text(c.name).html() + disabledLabel + '</option>');
        $modalSelect.append('<option value="' + c.id + '">' + $('<div>').text(c.name).html() + disabledLabel + '</option>');
      });

      if (!allCategories.length) {
        $modalSelect.append('<option value="">No categories yet - create one first</option>');
      }
    });
  }

  $('#filter-category').on('change', function () {
    currentCategory = $(this).val();
    loadSubcategories();
  });

  $('#status-filter button').on('click', function () {
    $('#status-filter button').removeClass('active');
    $(this).addClass('active');
    currentStatus = $(this).data('status');
    loadSubcategories();
  });

  function loadSubcategories() {
    const params = [];
    if (currentStatus) params.push('status=' + currentStatus);
    if (currentCategory) params.push('category_id=' + currentCategory);
    const qs = params.length ? '?' + params.join('&') : '';

    Api.get('/api/subcategories' + qs).done(function (resp) {
      renderRows(resp.data);
    });
  }

  function categoryName(id) {
    const cat = allCategories.find(function (c) { return String(c.id) === String(id); });
    return cat ? cat.name : '-';
  }

  function renderRows(rows) {
    const $body = $('#subcategories-body').empty();

    if (!rows.length) {
      $body.append('<tr><td colspan="4" class="text-center py-4 text-muted">No sub-categories found.</td></tr>');
      return;
    }

    rows.forEach(function (s) {
      const toggleLabel = s.status === 'active' ? 'Disable' : 'Enable';
      const toggleIcon = s.status === 'active' ? 'fa-ban' : 'fa-check';
      const toggleClass = s.status === 'active' ? 'btn-outline-danger' : 'btn-outline-success';

      $body.append(
        '<tr>' +
        '<td class="font-weight-bold">' + $('<div>').text(s.name).html() + '</td>' +
        '<td><span class="badge badge-light border">' + $('<div>').text(categoryName(s.category_id)).html() + '</span></td>' +
        '<td>' + UI.statusBadge(s.status) + '</td>' +
        '<td class="text-right table-actions">' +
        '<button class="btn btn-sm btn-outline-primary btn-edit" data-id="' + s.id + '" data-name="' + $('<div>').text(s.name).html() + '" data-category-id="' + s.category_id + '"><i class="fas fa-edit"></i></button> ' +
        '<button class="btn btn-sm ' + toggleClass + ' btn-toggle" data-id="' + s.id + '" data-status="' + s.status + '" title="' + toggleLabel + '"><i class="fas ' + toggleIcon + '"></i></button>' +
        '</td>' +
        '</tr>'
      );
    });
  }

  $('#btn-new-subcategory').on('click', function () {
    $('#subcategory-form')[0].reset();
    $('#subcategory_id').val('');
    $('#subcategory-modal-title').text('New Sub-Category');
    if (currentCategory) $('#subcategory_category_id').val(currentCategory);
    $('#subcategory-modal').modal('show');
  });

  $(document).on('click', '.btn-edit', function () {
    $('#subcategory_id').val($(this).data('id'));
    $('#subcategory_name').val($(this).data('name'));
    $('#subcategory_category_id').val($(this).data('category-id'));
    $('#subcategory-modal-title').text('Edit Sub-Category');
    $('#subcategory-modal').modal('show');
  });

  $(document).on('click', '.btn-toggle', function () {
    const id = $(this).data('id');
    const newStatus = $(this).data('status') === 'active' ? 'disabled' : 'active';
    const action = newStatus === 'disabled' ? 'disable' : 'enable';

    if (!confirm('Are you sure you want to ' + action + ' this sub-category?')) return;

    Api.patch('/api/subcategories/' + id + '/disable', { status: newStatus })
      .done(function () {
        UI.toast('Sub-category ' + action + 'd successfully');
        loadSubcategories();
      })
      .fail(function (resp) {
        UI.toast(UI.apiErrorMessage(resp), 'danger');
      });
  });

  $('#subcategory-form').on('submit', function (e) {
    e.preventDefault();
    const id = $('#subcategory_id').val();
    const payload = {
      category_id: $('#subcategory_category_id').val(),
      name: $('#subcategory_name').val().trim(),
    };

    const req = id ? Api.put('/api/subcategories/' + id, payload) : Api.post('/api/subcategories', payload);

    req.done(function () {
      $('#subcategory-modal').modal('hide');
      UI.toast('Sub-category saved successfully');
      loadSubcategories();
    }).fail(function (resp) {
      UI.toast(UI.apiErrorMessage(resp), 'danger');
    });
  });
});
