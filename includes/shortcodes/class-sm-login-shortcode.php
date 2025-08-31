<?php
// File: includes/shortcodes/class-sm-login-shortcode.php
if (!defined('ABSPATH')) exit;

class SM_Login_Shortcode
{
    /**
     * Whitelisted redirect hosts. Only these are allowed for the ?redirect / ?redirect_uri.
     * ⚠️ Edit this list to match your environments.
     */
    private static $allowed_hosts = [
        'palmreading.vitalguideshop.com',
        'aura.vitalguideshop.com',
        'soul-mirror.local',
        'palm-reading.local',  // add dev hosts as needed
    ];

    public static function init()
    {
        add_shortcode('sm_login_form', [__CLASS__, 'render_shortcode']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    /**
     * Enqueue ONLY when the page contains [sm_login_form].
     * (CSS only for now; we’ll add JS later.)
     */
    public static function enqueue_assets()
    {
        global $post;
        if (!is_a($post, 'WP_Post') || !has_shortcode($post->post_content, 'sm_login_form')) {
            return;
        }

        // Base plugin assets folder — adjust if you keep a different structure
        $plugin_url = plugin_dir_url(__FILE__) . '../../assets/';

        // Login CSS
        wp_enqueue_style('sm-login-css', $plugin_url . 'css/sm-login.css', [], '1.0.0');
    }

    /**
     * Render the login form by including the template.
     * We also pre-sanitize the redirect target against the whitelist and
     * pass it to the template via scoped variables.
     */
    public static function render_shortcode($atts)
    {
        // Prefer ?redirect, otherwise ?redirect_uri; fallback to home
        $raw_redirect = isset($_GET['redirect'])
            ? esc_url_raw($_GET['redirect'])
            : (isset($_GET['redirect_uri']) ? esc_url_raw($_GET['redirect_uri']) : home_url('/'));

        $state = isset($_GET['state']) ? sanitize_text_field($_GET['state']) : '';

        // Whitelist enforcement
        $approved_redirect = self::sanitize_redirect_host($raw_redirect);

        // Expose vars to the template scope
        $template_vars = [
            'approved_redirect' => $approved_redirect,
            'state'             => $state,
            'lost_pw_url'       => wp_lostpassword_url(),
            'register_url'      => home_url('/register'),
        ];

        ob_start();
        // Make $template_vars keys available as variables in the template
        extract($template_vars, EXTR_SKIP);
        include plugin_dir_path(__FILE__) . '../../templates/sm-login-form.php';
        return ob_get_clean();
    }

    /**
     * Returns a safe redirect URL if host is whitelisted; otherwise site home.
     */
    private static function sanitize_redirect_host(string $url): string
    {
        if (empty($url)) {
            return home_url('/');
        }

        $parts = @parse_url($url);
        $host  = $parts['host'] ?? '';
        if (!$host) {
            // Relative URLs are allowed; anchor to site origin
            return home_url($url);
        }

        // Compare lowercase hostnames
        $host = strtolower($host);
        foreach (self::$allowed_hosts as $allowed) {
            if ($host === strtolower($allowed)) {
                // Return the original URL if host is approved
                return esc_url_raw($url);
            }
        }

        // Not approved → send to site home
        return home_url('/');
    }
}

SM_Login_Shortcode::init();