<?php
if (!defined('ABSPATH')) exit;

class SM_SSO_Controller
{

    public static function register_routes()
    {
        register_rest_route(SM_API_NAMESPACE, '/sso/start', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => [__CLASS__, 'start'],
            'args'                => [
                'redirect_uri' => ['required' => true, 'type' => 'string'],
                'state'        => ['required' => false, 'type' => 'string'],
                'ttl'          => ['required' => false, 'type' => 'integer', 'default' => 3600], // 60m
            ],
        ]);
    }

    public static function start(WP_REST_Request $req)
    {
        $redirect = esc_url_raw($req->get_param('redirect_uri'));
        $state    = sanitize_text_field($req->get_param('state') ?: '');
        $ttl      = max(300, intval($req->get_param('ttl') ?? 3600)); // min 5m, default 60m

        if (!$redirect) {
            return new WP_REST_Response(['error' => 'missing redirect_uri'], 400);
        }

        // --- IMPORTANT: restore user from the normal WP "logged_in" cookie for REST ---
        // WP REST does not honor cookies w/out a nonce by default. This explicitly validates it.
        if (!is_user_logged_in()) {
            $uid = wp_validate_auth_cookie('', 'logged_in'); // reads LOGGED_IN_COOKIE when '' is passed
            if ($uid) {
                wp_set_current_user($uid);
                error_log('[SSO] cookie-auth restored user_id=' . $uid);
            }
        }

        // If still not logged in to WP, send to wp-login.php with a bounce and an SSO flag.
        if (!is_user_logged_in()) {
            $sso_start = rest_url(SM_API_NAMESPACE . '/sso/start');
            $bounce = add_query_arg(
                [
                    'redirect_uri' => $redirect,
                    'state'        => $state,
                    'ttl'          => $ttl,
                    'sm_sso'       => 1, // marks SSO-initiated login
                ],
                $sso_start
            );
            error_log('[SSO] not logged in → sending to wp-login.php with bounce back');
            wp_redirect(wp_login_url($bounce));
            exit;
        }

        // Map WP user → SM user (by email). Auto-create SM user if missing.
        $wpuser = wp_get_current_user();
        $email  = $wpuser->user_email;

        if (empty($email)) {
            error_log('[SSO] ERROR: current WP user has no email; cannot map to SM user.');
            return new WP_REST_Response(['error' => 'current WP user has no email'], 500);
        }

        global $wpdb;
        $p = $wpdb->prefix;

        $sm_user = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$p}sm_users WHERE email = %s",
            $email
        ));

        if (!$sm_user) {
            $wpdb->insert("{$p}sm_users", [
                'email'         => $email,
                'full_name'     => $wpuser->display_name ?: $wpuser->user_login,
                'password_hash' => null,
                'created_at'    => current_time('mysql'),
            ]);
            $sm_user = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$p}sm_users WHERE id = %d",
                $wpdb->insert_id
            ));
            error_log('[SSO] auto-created SM user id=' . $sm_user->id . ' email=' . $email);
        } else {
            error_log('[SSO] found existing SM user id=' . $sm_user->id . ' email=' . $email);
        }

        // Issue JWT
        if (!class_exists('SM_JWT')) {
            require_once HAS_PLUGIN_DIR . 'includes/class-sm-jwt.php';
        }
        $payload = [
            'sub'   => (int) $sm_user->id,
            'email' => $sm_user->email,
            'aud'   => $redirect,
        ];
        $jwt = SM_JWT::encode($payload, $ttl);
        error_log('[SSO] issuing JWT user=' . $sm_user->id . ' ttl=' . $ttl . ' aud=' . $redirect);

        // Redirect back to callback with token + state
        $to = add_query_arg(['token' => $jwt, 'state' => $state], $redirect);
        wp_redirect($to);
        exit;
    }
}
