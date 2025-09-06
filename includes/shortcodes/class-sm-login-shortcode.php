<?php
// File: includes/shortcodes/class-sm-login-shortcode.php
if (!defined('ABSPATH')) exit;

class SM_Login_Shortcode
{
    /**
     * Whitelisted redirect hosts. Only these are allowed for the ?redirect / ?redirect_uri.
     */
    private static $allowed_hosts = [
        'palmreading.vitalguideshop.com',
        'aura.vitalguideshop.com',
        'soul-mirror.local',
        'palm-reading.local',
    ];

    public static function init()
    {
        add_shortcode('sm_login_form', [__CLASS__, 'render_shortcode']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    /**
     * Enqueue assets ONLY when the page contains [sm_login_form]
     */
    public static function enqueue_assets()
    {
        global $post;
        
        // Check if we're on a page with the shortcode
        if (!is_a($post, 'WP_Post') || !has_shortcode($post->post_content, 'sm_login_form')) {
            return;
        }

        // Get the correct base URL for assets (go up 2 levels from includes/shortcodes/)
        $plugin_url = plugin_dir_url(dirname(__FILE__, 2)) . 'assets/';

        // Login CSS
        wp_enqueue_style(
            'sm-login-css', 
            $plugin_url . 'css/sm-login.css', 
            [], 
            '1.0.1'
        );

        // Login JavaScript - CRITICAL!
        wp_enqueue_script(
            'sm-login-js',
            $plugin_url . 'js/sm-login.js',
            [], // No dependencies
            '1.0.1',
            true // Load in footer
        );

        // Localize script with data for JavaScript
        wp_localize_script('sm-login-js', 'smLoginParams', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'rest_url' => rest_url('soulmirror/v1/login'),
            'home_url' => home_url('/'),
            'nonce'    => wp_create_nonce('sm_login_nonce')
        ]);
    }

    /**
     * Render the login form
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
                return esc_url_raw($url);
            }
        }

        return home_url('/');
    }
}

SM_Login_Shortcode::init();