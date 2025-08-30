<?php
// File: includes/shortcodes/class-sm-login-shortcode.php
if (! defined('ABSPATH')) {
    exit;
}

class SM_Login_Shortcode
{
    /**
     * Hosts we allow in the redirect URL (for safety).
     */
    private static $allowed_hosts = [
        'palmreading.vitalguideshop.com',
        'aura.vitalguideshop.com',
        'soul-mirror.local',
        // …add any other dev/prod domains here…
    ];

    /**
     * Hook up our shortcode, form handler, and styles.
     */
    public static function init()
    {
        // Register [sm_login_form]
        add_shortcode('sm_login_form', [__CLASS__, 'render']);
        // Catch the POST before WP renders templates
        add_action('template_redirect', [__CLASS__, 'maybe_handle_form']);
        // Enqueue our CSS only when the shortcode is present
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_styles']);
    }

    /**
     * Only enqueue CSS on pages that use [sm_login_form].
     */
    public static function enqueue_styles()
{
    global $post;
    if (! $post || ! has_shortcode($post->post_content, 'sm_login_form')) {
        return;
    }
    
    // Calculate the correct path to assets
    $plugin_url = plugin_dir_url(dirname(__FILE__)) . '../assets/';
    
    wp_enqueue_style(
        'sm-login-css', 
        $plugin_url . 'css/sm-login.css'
    );
    
    // Enqueue script with dependencies
    wp_enqueue_script(
        'sm-login-js', 
        $plugin_url . 'js/sm-login.js', 
        array('jquery'), // Add jQuery as dependency
        false, 
        true
    );
    
    // Localize the script - must happen AFTER enqueue
    wp_localize_script('sm-login-js', 'sm_login_vars', array(
        'redirect' => isset($_GET['redirect']) ? esc_url($_GET['redirect']) : home_url('/'),
        'ajax_url' => admin_url('admin-ajax.php'),
        'home_url' => home_url('/'),
        'lost_password_url' => wp_lostpassword_url()
    ));
}

    /**
     * Output the login form + inline JS to capture the JWT.
     */
    public static function render($atts)
    {
        $redirect = isset($_GET['redirect']) ? esc_url($_GET['redirect']) : home_url('/');
        $error = isset($_GET['error']) ? sanitize_text_field($_GET['error']) : '';

        // Pass PHP variables to JavaScript
        wp_localize_script('sm-login-js', 'sm_login_vars', array(
            'redirect' => $redirect,
            'ajax_url' => admin_url('admin-ajax.php'),
            'home_url' => home_url('/'),
            'lost_password_url' => wp_lostpassword_url()
        ));

        ob_start();
?>
        <div class="sm-login-container">
            <div class="sm-login-card">
                <div class="sm-login-header">
                    <h2>Sign In</h2>
                    <?php if ($error) : ?>
                        <div class="sm-alert sm-alert-error"><?php echo esc_html($error); ?></div>
                    <?php endif; ?>
                </div>

                <form method="post" class="sm-login-form" novalidate>
                    <div class="sm-form-group">
                        <label for="sm_email" class="sm-form-label">Email Address</label>
                        <input type="email" id="sm_email" name="sm_email" class="sm-form-input" required placeholder="Enter your email" />
                    </div>

                    <div class="sm-form-group">
                        <label for="sm_password" class="sm-form-label">Password</label>
                        <input type="password" id="sm_password" name="sm_password" class="sm-form-input" required placeholder="Enter your password" />
                    </div>

                    <div class="sm-form-group sm-form-actions">
                        <button type="submit" class="sm-btn sm-btn-primary sm-btn-block">Login</button>
                    </div>

                    <input type="hidden" name="sm_redirect" value="<?php echo esc_attr($redirect); ?>" />
                    <input type="hidden" name="sm_nonce" value="<?php echo wp_create_nonce('sm_login_nonce'); ?>" />
                </form>

                <div class="sm-login-footer">
                    <div class="sm-footer-links">
                        <a href="<?php echo esc_url(wp_lostpassword_url()); ?>" class="sm-footer-link">Forgot Password?</a>
                        <span class="sm-footer-separator">·</span>
                        <a href="<?php echo esc_url(home_url('/register')); ?>" class="sm-footer-link">Create Account</a>
                    </div>
                </div>
            </div>
        </div>
<?php
        return ob_get_clean();
    }

    /**
     * Handle the POST from our login form, issue a JWT, and redirect back.
     */
    public static function maybe_handle_form()
    {
        if (
            $_SERVER['REQUEST_METHOD'] !== 'POST' ||
            empty($_POST['sm_email']) ||
            empty($_POST['sm_password']) ||
            empty($_POST['sm_redirect'])
        ) {
            return;
        }

        // Only intercept pages that actually use our shortcode
        if (! is_singular()) {
            return;
        }
        global $post;
        if (! has_shortcode($post->post_content, 'sm_login_form')) {
            return;
        }

        global $wpdb;
        $email        = sanitize_email($_POST['sm_email']);
        $password     = $_POST['sm_password'];
        $raw_redirect = $_POST['sm_redirect'];

        // Look up the user
        $tbl  = $wpdb->prefix . 'sm_users';
        $user = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$tbl} WHERE email = %s", $email)
        );

        // Invalid credentials?
        if (! $user || ! password_verify($password, $user->password_hash)) {
            wp_redirect(add_query_arg(
                'error',
                'Invalid email or password',
                wp_get_referer()
            ));
            exit;
        }

        // Issue JWT
        $payload = [
            'sub'   => intval($user->id),
            'email' => $user->email,
            'exp'   => time() + WEEK_IN_SECONDS,
        ];
        $token = SM_Account::jwt_encode($payload);

        // Whitelist the redirect host
        $parts = @parse_url($raw_redirect);
        $host  = $parts['host'] ?? '';
        if (in_array($host, self::$allowed_hosts, true)) {
            $approved = esc_url_raw($raw_redirect);
        } else {
            $approved = home_url('/');
        }

        // Redirect back with `token=…`
        wp_redirect(add_query_arg('token', $token, $approved));
        exit;
    }
}

// Initialize the shortcode
SM_Login_Shortcode::init();