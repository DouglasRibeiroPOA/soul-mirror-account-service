<?php

/**
 * Handles authentication via JWT for the SoulMirror Account Service.
 */
class SM_Account
{
    // Ideally load this from wp-config.php or an environment variable:
    private static $jwt_secret = 'your_super_secret_key_here';

    /**
     * Handle POST /wp-json/soulmirror/v1/login
     * Expects JSON { email, password }
     */
    public static function handle_login(WP_REST_Request $req)
    {
        $params   = $req->get_json_params();
        $email    = sanitize_email($params['email'] ?? '');
        $password = $params['password'] ?? '';

        if (empty($email) || empty($password)) {
            return new WP_REST_Response(['error' => 'Email and password required'], 400);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sm_users';
        $user  = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE email = %s", $email)
        );

        if (! $user || ! password_verify($password, $user->password_hash)) {
            return new WP_REST_Response(['error' => 'Invalid credentials'], 401);
        }

        return self::issue_token($user);
    }

    /**
     * Handle POST /wp-json/soulmirror/v1/google-login
     * Expects JSON { token: <Google ID token> }
     */
    public static function handle_google_login(WP_REST_Request $req)
    {
        $params  = $req->get_json_params();
        $id_token = sanitize_text_field($params['token'] ?? '');

        if (! $id_token) {
            return new WP_REST_Response(['error' => 'Google token required'], 400);
        }

        // Verify with Google's tokeninfo endpoint
        $payload = self::verify_google_token($id_token);
        if (empty($payload['email'])) {
            return new WP_REST_Response(['error' => 'Invalid Google token'], 401);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sm_users';

        // Try to find existing
        $user = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE email = %s", $payload['email'])
        );

        if (! $user) {
            // Create new SM user
            $wpdb->insert($table, [
                'email'         => $payload['email'],
                'full_name'     => sanitize_text_field($payload['name'] ?? ''),
                'google_id'     => sanitize_text_field($payload['sub'] ?? ''),
                'password_hash' => null,  // no local password
            ]);
            $user_id = $wpdb->insert_id;
            $user = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $user_id)
            );
        }

        return self::issue_token($user);
    }


    /**
     * Permission callback for protected routes.
     */
    public static function verify_jwt(WP_REST_Request $req)
    {
        return (bool) self::get_sm_user_from_jwt($req);
    }

    /**
     * Issue a JWT for a given SM user row.
     */
    private static function issue_token($user, $ttl = 900 /* 15 minutes */)
    {
        $payload = [
            'sub'   => intval($user->id),
            'email' => $user->email,
            // Add audience if you will validate it on Site B (recommended in SSO flow)
            // 'aud'   => 'https://app.example.com/auth/callback',
            // Optional extra context:
            // 'name' => $user->full_name,
        ];
        $jwt = SM_JWT::encode($payload, $ttl);
        return rest_ensure_response(['token' => $jwt]);
    }

    /**
     * Decode the Bearer token from the request and return the SM user row.
     */
    // make sure this file (or your main plugin) already loaded the helper:
    // require_once HAS_PLUGIN_DIR . 'includes/class-sm-jwt.php';

    public static function get_sm_user_from_jwt(WP_REST_Request $req)
    {
        // Accept either header casing
        $auth = $req->get_header('authorization') ?: $req->get_header('Authorization') ?: '';
        if (stripos($auth, 'Bearer ') !== 0) {
            return null;
        }

        $token = trim(substr($auth, 7));
        $decoded = SM_JWT::decode_verify($token); // <-- single source of truth (checks signature + exp/nbf)
        if (is_wp_error($decoded) || empty($decoded['sub'])) {
            return null;
        }

        // (Optional) enforce audience if you set one when issuing:
        // if (!empty($decoded['aud']) && $decoded['aud'] !== 'https://your-site-b-callback') {
        //     return null;
        // }

        global $wpdb;
        $table = $wpdb->prefix . 'sm_users';

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", (int) $decoded['sub'])
        );
    }


    /*** JWT helpers ***/
    public static function jwt_encode($payload)
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $segments = [
            rtrim(strtr(base64_encode(json_encode($header)), '+/', '-_'), '='),
            rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '='),
        ];
        $signing_input = implode('.', $segments);
        $signature = hash_hmac('sha256', $signing_input, self::$jwt_secret, true);
        $segments[] = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
        return implode('.', $segments);
    }

    private static function jwt_decode($jwt)
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }
        list($h64, $p64, $s64) = $parts;
        $sig = hash_hmac('sha256', "$h64.$p64", self::$jwt_secret, true);
        $decoded_sig = base64_decode(strtr($s64, '-_', '+/'));
        if (! hash_equals($sig, $decoded_sig)) {
            return null;
        }
        return json_decode(base64_decode(strtr($p64, '-_', '+/')), true);
    }

    private static function verify_google_token($id_token)
    {
        $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($id_token);
        $resp = wp_remote_get($url);
        if (is_wp_error($resp)) {
            return null;
        }
        return json_decode(wp_remote_retrieve_body($resp), true);
    }
    /**
     * Handle POST /wp-json/soulmirror/v1/register
     * Body: { email, password, full_name }
     */
    public static function handle_register(WP_REST_Request $req)
    {
        $params    = $req->get_json_params();
        $email     = sanitize_email($params['email']     ?? '');
        $password  =              $params['password']  ?? '';
        $full_name = sanitize_text_field($params['full_name'] ?? '');

        if (empty($email) || empty($password) || empty($full_name)) {
            return new WP_REST_Response(
                ['error' => 'Email, name & password required'],
                400
            );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sm_users';

        // Prevent duplicates
        if ($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE email = %s",
            $email
        )) > 0) {
            return new WP_REST_Response(['error' => 'Email already registered'], 409);
        }

        // Insert new user
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $wpdb->insert($table, [
            'email'         => $email,
            'full_name'     => $full_name,
            'password_hash' => $hash,
            'created_at'    => current_time('mysql'),
        ]);
        $user_id = $wpdb->insert_id;

        // Issue JWT
        $payload = [
            'sub'   => $user_id,
            'email' => $email,
            'exp'   => time() + WEEK_IN_SECONDS,
        ];
        $jwt = self::jwt_encode($payload);

        return rest_ensure_response(['token' => $jwt]);
    }
}
