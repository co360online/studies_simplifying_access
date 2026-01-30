jQuery(function ($) {
  $('.co360-ssa-login-form').on('submit', function (e) {
    e.preventDefault();
    const $form = $(this);
    const $error = $form.find('.co360-ssa-login-error');
    const data = {
      action: 'co360_ssa_check_login',
      nonce: co360SSA.nonce,
      username: $form.find('[name="username"]').val(),
      password: $form.find('[name="password"]').val(),
      remember: $form.find('[name="remember"]').is(':checked') ? 1 : 0,
      redirect_to: $form.data('redirect') || ''
    };

    $.post(co360SSA.ajaxUrl, data)
      .done(function (response) {
        if (response.success) {
          window.location.href = response.data.redirect;
          return;
        }
        $error.text(response.data.message).show();
      })
      .fail(function () {
        $error.text('No se pudo iniciar sesión.').show();
      });
  });
});
