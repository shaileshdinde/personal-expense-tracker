$(function () {
  Auth.guardPage();
  Layout.init('profile', function () {
    loadProfile();
  });

  function loadProfile() {
    Api.get('/api/profile').done(function (resp) {
      const u = resp.data;
      $('#profile-name').text(u.name);
      $('#profile-email').text(u.email);
      $('#profile-since').text('Member since ' + UI.formatDate(u.created_at ? u.created_at.slice(0, 10) : ''));

      $('#name').val(u.name);
      $('#email').val(u.email);
      $('#phone').val(u.phone || '');
    });
  }

  $('#profile-form').on('submit', function (e) {
    e.preventDefault();

    const payload = {
      name: $('#name').val().trim(),
      phone: $('#phone').val().trim(),
    };

    const newPassword = $('#password').val();
    const currentPassword = $('#current_password').val();

    if (newPassword || currentPassword) {
      if (!currentPassword || !newPassword) {
        $('#alert-box').removeClass('d-none alert-success').addClass('alert-danger')
          .text('Please fill in both current and new password to change your password.');
        return;
      }
      payload.current_password = currentPassword;
      payload.password = newPassword;
    }

    const $btn = $('#save-btn');
    $btn.prop('disabled', true);
    $btn.find('.btn-text').addClass('d-none');
    $btn.find('.spinner-border').removeClass('d-none');

    Api.put('/api/profile', payload)
      .done(function (resp) {
        $('#alert-box').removeClass('d-none alert-danger').addClass('alert-success').text('Profile updated successfully.');
        $('#current_password, #password').val('');

        // Keep local user cache in sync (used by navbar/sidebar)
        const user = Auth.currentUser() || {};
        user.name = resp.data.name;
        user.phone = resp.data.phone;
        localStorage.setItem('et_user', JSON.stringify(user));

        loadProfile();
        UI.toast('Profile updated successfully');
      })
      .fail(function (resp) {
        $('#alert-box').removeClass('d-none alert-success').addClass('alert-danger').text(UI.apiErrorMessage(resp));
      })
      .always(function () {
        $btn.prop('disabled', false);
        $btn.find('.btn-text').removeClass('d-none');
        $btn.find('.spinner-border').addClass('d-none');
      });
  });
});
