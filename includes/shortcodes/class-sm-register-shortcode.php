<?php
if (! defined('ABSPATH')) exit;

class SM_Register_Shortcode
{

    public static function init()
    {
        add_shortcode('sm_register_form', [__CLASS__, 'render']);
        add_action('init',               [__CLASS__, 'maybe_handle_form']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_scripts']);
    }

    public static function enqueue_scripts()
    {
        global $post;
        if (! $post || ! has_shortcode($post->post_content, 'sm_register_form')) {
            return;
        }
        wp_enqueue_style(
            'sm-auth-style',
            HAS_PLUGIN_URL . 'assets/css/sm-auth.css'
        );
        // Re-use your existing validation JS
        wp_enqueue_script(
            'sm-pw-check',
            HAS_PLUGIN_URL . 'assets/js/sm-pw-check.js',
            ['jquery'],
            null,
            true
        );
    }

    public static function render($atts)
    {
        $redirect     = isset($_GET['redirect'])
            ? esc_url($_GET['redirect'])
            : home_url('/');
        $server_error = isset($_GET['error'])
            ? sanitize_text_field($_GET['error'])
            : '';
        ob_start(); ?>

        <div class="sm-auth-container">
            <form id="sm-registration-form" class="sm-auth-form">
                <h2>Create Your Account</h2>

                <div class="sm-form-group">
                    <label for="fullname">Full Name</label>
                    <input type="text" id="fullname" name="fullname" required>
                    <div class="sm-error-message" id="fullname-error"></div>
                </div>

                <div class="sm-form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" required>
                    <div class="sm-error-message" id="email-error"></div>
                </div>

                <div class="sm-form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>
                    <div class="sm-error-message" id="password-error"></div>
                </div>

                <div class="sm-form-group">
                    <label for="confirm-password">Confirm Password</label>
                    <input type="password" id="confirm-password" name="confirm-password" required>
                    <div class="sm-error-message" id="confirm-password-error"></div>
   

                <button type="submit" class="sm-auth-button">Create Account</button>

                <div class="sm-auth-alt-option">
                    <p>Already have an account?</p>
                    <a href="/login" class="sm-auth-link-button">Login</a>
                </div>
            </form>
        </div>
    <?php
        return ob_get_clean();
    }

    public static function maybe_handle_form()
    {
        if (
            $_SERVER['REQUEST_METHOD'] !== 'POST' ||
            ! isset(
                $_POST['sm_email'],
                $_POST['sm_full_name'],
                $_POST['sm_password'],
                $_POST['sm_password_confirm']
            )
        ) {
            return;
        }

        $email            = sanitize_email($_POST['sm_email']);
        $full_name        = sanitize_text_field($_POST['sm_full_name']);
        $password         = $_POST['sm_password'];
        $confirm          = $_POST['sm_password_confirm'];
        $redirect         = esc_url_raw($_POST['sm_redirect'] ?? home_url('/'));

        // server‐side recheck
        if (! is_email($email)) {
            $error = 'Please enter a valid email address';
        } elseif (mb_strlen(trim($full_name)) < 3) {
            $error = 'Full name must be at least 3 characters';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match';
        } else {
            global $wpdb;
            $table = $wpdb->prefix . 'sm_users';
            if ($wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE email = %s",
                    $email
                )
            ) > 0) {
                $error = 'Email already registered';
            }
        }

        if (! empty($error)) {
            wp_redirect(
                add_query_arg(
                    'error',
                    rawurlencode($error),
                    wp_get_referer()
                )
            );
            exit;
        }

        // create account
        global $wpdb;
        $pass_hash = password_hash($password, PASSWORD_DEFAULT);
        $wpdb->insert(
            $wpdb->prefix . 'sm_users',
            [
                'email'         => $email,
                'full_name'     => $full_name,
                'password_hash' => $pass_hash,
            ]
        );
        $user_id = $wpdb->insert_id;

        // issue JWT & redirect
        $payload = [
            'sub'   => $user_id,
            'email' => $email,
            'exp'   => time() + (7 * DAY_IN_SECONDS),
        ];
        $token = SM_Account::jwt_encode($payload);

        wp_redirect(add_query_arg('token', $token, $redirect));
        exit;
    }
}

SM_Register_Shortcode::init();
