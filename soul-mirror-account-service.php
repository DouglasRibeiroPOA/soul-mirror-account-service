<?php

/**
 * Plugin Name: SoulMirror Account Service
 * Description: Centralized user and credit manager for SoulMirror platform.
 * Version: 1.0
 * Author: Simone Moreira
 */

if (! defined('ABSPATH')) exit;

define('SOULMIRROR_JWT_SECRET', 'dev-super-long-random-secret-change-me');

define('HAS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('HAS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SM_API_NAMESPACE', 'soulmirror/v1');

// — Load core classes — (order matters)
require_once HAS_PLUGIN_DIR . 'includes/class-sm-jwt.php';
require_once HAS_PLUGIN_DIR . 'includes/class-sm-account.php';
require_once HAS_PLUGIN_DIR . 'includes/class-sm-credit-controller.php';
require_once HAS_PLUGIN_DIR . 'includes/class-sm-api-router.php';
require_once HAS_PLUGIN_DIR . 'includes/class-sm-sso-controller.php';


// — Load shortcodes —
require_once HAS_PLUGIN_DIR . 'includes/shortcodes/class-sm-login-shortcode.php';
require_once HAS_PLUGIN_DIR . 'includes/shortcodes/class-sm-register-shortcode.php';

// — Register REST routes on initialization —
add_action('rest_api_init', function () {
  SM_SSO_Controller::register_routes();
  $router = new SM_API_Router();
  $router->register_routes();
});

// — Initialize shortcodes (render & form handling) —
add_action('init', ['SM_Login_Shortcode',    'init']);
add_action('init', ['SM_Register_Shortcode', 'init']);

// — Initialize credit‐related hooks (WooCommerce) —
add_action('init', ['SM_Credit_Controller', 'init']);


// === Lock wp-admin for non-admins; admins (you) still have full access ===
add_action('init', function () {
  if (is_admin() && !current_user_can('manage_options') && !(defined('DOING_AJAX') && DOING_AJAX)) {
    // send customers to your front-end dashboard page
    wp_redirect(home_url('/account-dashboard/'));
    exit;
  }
});

// Respect the SSO bounce (redirect_to) when login was initiated by /sso/start.
// If not an SSO login, keep the usual behavior: admins → /wp-admin, others → /account-dashboard/.
// Respect SSO bounce only when the login was started by /sso/start (sm_sso=1 inside redirect_to)
add_filter('login_redirect', function ($redirect_to, $request, $user) {
  if (is_wp_error($user) || empty($user) || !is_object($user)) {
    return $redirect_to;
  }

  $redirectToRaw = $_REQUEST['redirect_to'] ?? '';
  $isSso = false;

  if ($redirectToRaw) {
    // redirect_to is url-encoded; decode and inspect it
    $decoded = urldecode($redirectToRaw);
    $ssoStart = rest_url(SM_API_NAMESPACE . '/sso/start');

    if (strpos($decoded, $ssoStart) === 0 && strpos($decoded, 'sm_sso=1') !== false) {
      $isSso = true;
    }
  }

  if ($isSso) {
    error_log('[LOGIN_REDIRECT] SSO flow detected → honoring redirect_to back to /sso/start');
    return $redirectToRaw; // hand control back to SSO controller
  }

  // Normal post-login routing
  return user_can($user, 'manage_options') ? admin_url() : home_url('/account-dashboard/');
}, 10, 3);




add_action('wp_enqueue_scripts', function () {
  global $post;
  if (! $post) return;

  $content = $post->post_content;
  if (
    has_shortcode($content, 'sm_login_form')
    || has_shortcode($content, 'sm_register_form')
  ) {

    // Your auth styles
    wp_enqueue_style(
      'sm-auth-style',
      HAS_PLUGIN_URL . 'assets/css/sm-auth.css'
    );

    // jQuery (our dependency)
    wp_enqueue_script('jquery');

    // Your existing validation script
    wp_enqueue_script(
      'sm-pw-check',
      HAS_PLUGIN_URL . 'assets/js/sm-pw-check.js',
      ['jquery'],
      null,
      true
    );

    // SweetAlert2 for modals (from CDN)
    wp_enqueue_style(
      'sweetalert2',
      'https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css',
      [],
      null
    );
    wp_enqueue_script(
      'sweetalert2',
      'https://cdn.jsdelivr.net/npm/sweetalert2@11',
      [],
      null,
      true
    );
  }
});

// — Create or update database tables on plugin activation —
register_activation_hook(__FILE__, 'sm_create_database_tables');
function sm_create_database_tables()
{
  global $wpdb;
  $charset_collate = $wpdb->get_charset_collate();
  $p = $wpdb->prefix;
  require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

  $ddl = [];
  // Users table
  $ddl[] = "CREATE TABLE {$p}sm_users (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      email VARCHAR(255) NOT NULL UNIQUE,
      full_name VARCHAR(255) NOT NULL,
      gender VARCHAR(20) DEFAULT NULL,
      date_of_birth DATE DEFAULT NULL,
      password_hash VARCHAR(255) DEFAULT NULL,
      google_id VARCHAR(255) DEFAULT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) {$charset_collate};";

  // Credits table
  $ddl[] = "CREATE TABLE {$p}sm_credits (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id BIGINT UNSIGNED NOT NULL,
      email VARCHAR(255) NOT NULL,
      module VARCHAR(100) NOT NULL,
      credits_added INT DEFAULT 0,
      credits_used INT DEFAULT 0,
      source VARCHAR(255) DEFAULT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      INDEX (user_id)
    ) {$charset_collate};";

  // Usage log
  $ddl[] = "CREATE TABLE {$p}sm_credit_usage_log (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id BIGINT UNSIGNED NOT NULL,
      module VARCHAR(100) NOT NULL,
      credit_type VARCHAR(100) NOT NULL,
      credits_used INT NOT NULL DEFAULT 1,
      reference VARCHAR(255) DEFAULT NULL,
      used_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      INDEX (user_id)
    ) {$charset_collate};";

  foreach ($ddl as $sql) {
    dbDelta($sql);
  }
}
