jQuery(document).ready(function($) {
    if (!$('.sm-login-form').length) return;

    // Token handling code remains the same...

    $('.sm-login-form').on('submit', function(e) {
        e.preventDefault();
        const form = $(this);
        const submitBtn = form.find('button[type="submit"]');
        const originalBtnText = submitBtn.text();
        
        submitBtn.prop('disabled', true).text('Signing in...');
        
        // Ensure we have the proper AJAX URL
        const ajaxData = {
            action: 'sm_process_login',
            sm_email: form.find('[name="sm_email"]').val(),
            sm_password: form.find('[name="sm_password"]').val(),
            sm_redirect: form.find('[name="sm_redirect"]').val(),
            sm_nonce: form.find('[name="sm_nonce"]').val()
        };

        $.ajax({
            url: sm_login_vars.ajax_url,
            type: 'POST',
            data: ajaxData,
            dataType: 'json'
        })
        .done(function(response) {
            if (response.success) {
                window.location.href = response.data.redirect || sm_login_vars.home_url;
            } else {
                showError(response.data.message || 'Login failed. Please try again.');
            }
        })
        .fail(function(jqXHR) {
            let errorMsg = 'An error occurred. Please try again.';
            if (jqXHR.responseJSON && jqXHR.responseJSON.data) {
                errorMsg = jqXHR.responseJSON.data.message || errorMsg;
            }
            showError(errorMsg);
        })
        .always(function() {
            submitBtn.prop('disabled', false).text(originalBtnText);
        });

        function showError(message) {
            $('.sm-login-header .sm-alert-error').remove();
            $('.sm-login-header').append(
                `<div class="sm-alert sm-alert-error">${message}</div>`
            );
        }
    });
});