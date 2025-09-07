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
            'nonce'    => wp_create_nonce('sm_login_nonce'),
            'redirect'  => isset($template_vars['approved_redirect']) ? $template_vars['approved_redirect'] : '',
            'state'     => isset($template_vars['state']) ? $template_vars['state'] : '',
        ]);
    }

    /**
     * Render the login form
     */
    public static function render_shortcode($atts)
    {
        // Prefer ?redirect, otherwise ?redirect_uri; fallback to home
        // Prefer ?redirect, otherwise ?redirect_uri; fallback to home
        $raw_redirect = isset($_GET['redirect'])
            ? $_GET['redirect']
            : (isset($_GET['redirect_uri']) ? $_GET['redirect_uri'] : '');

        // Whitelist enforcement (handles decoding internally)
        $approved_redirect = self::sanitize_redirect_host($raw_redirect);

        // State parameter (leave as-is)
        $state = isset($_GET['state']) ? sanitize_text_field($_GET['state']) : '';


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
    /**
     * Returns a safe redirect URL if host is whitelisted; otherwise site home.
     * Accepts encoded or plain URLs and supports relative paths (same-origin).
     */
    /**
     * Returns a safe redirect URL if host is whitelisted; otherwise site home.
     * Accepts encoded or plain URLs, supports relative paths, and avoids regex.
     */
    private static function sanitize_redirect_host(string $url): string
    {
        // Normalize/Decode once
        $decoded = rawurldecode(trim((string)$url));

        // Empty → home
        if ($decoded === '') {
            return home_url('/');
        }

        // Allow relative paths like "/some/page" but not protocol-relative "//host"
        if ($decoded[0] === '/' && (!isset($decoded[1]) || $decoded[1] !== '/')) {
            return esc_url_raw(home_url($decoded));
        }

        // Parse absolute URL
        $parts = wp_parse_url($decoded);
        if (!$parts || empty($parts['host'])) {
            return home_url('/');
        }

        $host = strtolower($parts['host']);

        // Whitelist check (exact host match)
        foreach (self::$allowed_hosts as $allowed) {
            if ($host === strtolower($allowed)) {
                // Return sanitized decoded URL (preserves scheme/path/query/fragment)
                return esc_url_raw($decoded);
            }
        }

        // Fallback: home
        return home_url('/');
    }
}

SM_Login_Shortcode::init();
