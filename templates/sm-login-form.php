<?php

/**
 * Template: Login Form
 * Path: templates/sm-login-form.php
 */
if (!defined('ABSPATH')) exit;

// Get redirect URL with fallback - check both GET and POST
if (!empty($_GET['redirect_to'])) {
    $redirect = esc_url($_GET['redirect_to']);
} elseif (!empty($_POST['sm_redirect'])) {
    $redirect = esc_url($_POST['sm_redirect']);
} else {
    $redirect = home_url();
}

// Get state parameter
$state = '';
if (!empty($_GET['state'])) {
    $state = sanitize_text_field($_GET['state']);
} elseif (!empty($_POST['sm_state'])) {
    $state = sanitize_text_field($_POST['sm_state']);
}

// Get lost password URL
$lost_pw_url = wp_lostpassword_url();

// Get registration URL
$register_url = wp_registration_url();

// Get the correct path to the JavaScript file
$plugin_url = plugin_dir_url(__FILE__);
$js_url = $plugin_url . '../assets/js/sm-login.js';

// Pre-fill email after redirect
$email_value = '';
if (!empty($_GET['email'])) {
    $email_value = esc_attr($_GET['email']);
}

// Display error if passed via query
$error_message = '';
if (!empty($_GET['login_error'])) {
    $error_message = sanitize_text_field(urldecode($_GET['login_error']));
}
?>
<div class="sm-login-container">
    <div class="sm-login-header">
        <h2>Welcome Back</h2>
        <p>Sign in to continue your journey</p>
    </div>

    <div class="sm-login-form">
        <?php if (!empty($error_message)) : ?>
            <div class="sm-error-server sm-error-pulse" id="smServerError" role="alert" aria-live="assertive" style="display:block;">
                <span class="sm-error-icon">⚠️</span>
                <span><?php echo $error_message; ?></span>
            </div>
        <?php else : ?>
            <div class="sm-error-server" id="smServerError" role="alert" aria-live="assertive" style="display:none;"></div>
        <?php endif; ?>


        <form id="sm-login-form" method="post" novalidate>
            <div class="sm-form-group">
                <label for="sm_email">Email Address</label>
                <input type="email" id="sm_email" name="email" required value="<?php echo $email_value; ?>">
                <i class="fas fa-envelope" aria-hidden="true"></i>
                <div class="sm-error-message" id="smEmailError">Please enter a valid email address</div>
            </div>

            <div class="sm-form-group">
                <label for="sm_password">Password</label>
                <input type="password" id="sm_password" name="password" required>
                <i class="fas fa-lock" aria-hidden="true"></i>
                <div class="sm-error-message" id="smPasswordError">Please enter your password</div>
            </div>

            <div class="sm-remember-forgot">
                <div class="sm-remember-me">
                    <input type="checkbox" id="sm_remember_me" name="remember_me">
                    <label for="sm_remember_me">Remember me</label>
                </div>
                <a href="<?php echo $lost_pw_url; ?>" class="sm-forgot-password">Forgot Password?</a>
            </div>

            <!-- Hidden fields for redirect & state -->
            <input type="hidden" name="sm_redirect" value="<?php echo esc_attr($redirect); ?>">
            <?php if (!empty($state)) : ?>
                <input type="hidden" name="sm_state" value="<?php echo esc_attr($state); ?>">
            <?php endif; ?>

            <!-- Security nonce -->
            <?php wp_nonce_field('sm_login_action', 'sm_login_nonce'); ?>

            <button type="submit" class="sm-submit-btn" id="smSubmitBtn">
                <span class="sm-button-text">Sign In</span>
                <!-- Keep placeholder for loader to avoid JS errors; hidden by CSS -->
                <span class="sm-loading" aria-hidden="true"></span>
            </button>
        </form>

        <div class="sm-form-footer">
            Don't have an account? <a href="<?php echo $register_url; ?>">Create Account</a>
        </div>
    </div>
</div>

<!-- Load external JavaScript (kept intact) -->
<script>
    // Load sm-login.js once
    if (typeof window.smLoginInitialized === 'undefined') {
        var script = document.createElement('script');
        script.src = '<?php echo esc_url($js_url); ?>?v=1.0.5';
        script.onload = function() {
            // If there is a server error already rendered, add a single shake
            setTimeout(function() {
                var errorElement = document.querySelector('.sm-error-server');
                if (errorElement && errorElement.textContent.trim() !== '') {
                    errorElement.classList.add('sm-shake');
                    setTimeout(function() {
                        errorElement.classList.remove('sm-shake');
                        // keep a subtle 1x pulse already handled via CSS class
                    }, 500);
                }
            }, 100);
        };
        script.onerror = function() {
            // Fallback if JS fails to load
            var form = document.getElementById('sm-login-form');
            if (form) {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    alert('JavaScript failed to load. Please refresh the page and try again.');
                });
            }
        };
        document.head.appendChild(script);
    }
</script>

<!-- Small helper to ensure no spinner sticks on bfcache returns -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('sm-login-form');
        if (!form) return;

        // Ensure no spinner nor disabled state on bfcache/back
        const btn = document.getElementById('smSubmitBtn');
        if (btn) {
            btn.disabled = false;
            btn.classList.remove('sm-loading');
        }
    });
</script>