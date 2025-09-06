<?php
if (!defined('ABSPATH')) exit;

/**
 * Handles authentication & persistence for SoulMirror Account Service.
 */
class SM_Account
{
    /** Lightweight logger to PHP error_log */
    private static function log(string $msg, array $ctx = []): void
    {
        // Keep context compact & safe (no passwords/tokens)
        if (!empty($ctx)) {
            // best-effort redaction
            unset($ctx['password'], $ctx['pass'], $ctx['token'], $ctx['jwt']);
            $msg .= ' ' . json_encode($ctx);
        }
        error_log('[SM_Account] ' . $msg);
    }

    /** Prefer loading from wp-config.php: define('SOULMIRROR_JWT_SECRET', '...'); */
    private static function jwt_key()
    {
        if (defined('SOULMIRROR_JWT_SECRET') && SOULMIRROR_JWT_SECRET) return SOULMIRROR_JWT_SECRET;
        return AUTH_KEY; // fallback so dev doesn’t break
    }

    /** Table helper */
    private static function t_users()
    {
        global $wpdb;
        return $wpdb->prefix . 'sm_users';
    }

    /* ============================================================
       PUBLIC REST HANDLERS
       ============================================================ */

    /**
     * POST /wp-json/soulmirror/v1/register
     * Body JSON: { email, password, full_name, date_of_birth? (YYYY-MM-DD) }
     */
    /**
     * POST /wp-json/soulmirror/v1/register
     * Body JSON: { email, password, full_name, date_of_birth? (YYYY-MM-DD) }
     */
    public static function handle_register(WP_REST_Request $req)
    {
        // ---- Parse & sanitize body
        $p      = $req->get_json_params();
        $email  = sanitize_email($p['email'] ?? '');
        $pass   = (string)($p['password'] ?? '');
        $name   = sanitize_text_field($p['full_name'] ?? '');
        $dob_in = sanitize_text_field($p['date_of_birth'] ?? ''); // optional

        self::log('handle_register: received', ['email' => $email, 'name' => $name, 'dob_raw' => $dob_in ?: null]);

        // ---- Optional: DOB normalization
        $dob_norm = null;
        if ($dob_in !== '') {
            $dob_norm = self::normalize_dob($dob_in);
            if (!$dob_norm) {
                self::log('handle_register: invalid DOB', ['email' => $email, 'dob' => $dob_in]);
                return new WP_REST_Response(['error' => 'Please choose a valid birth date'], 400);
            }
        }

        // ---- Create user (now uses WordPress as canonical)
        $res = self::create_user($name, $email, $dob_norm, $pass);
        if (is_wp_error($res)) {
            self::log('handle_register: create_user error', [
                'email' => $email,
                'code'  => $res->get_error_code(),
                'msg'   => $res->get_error_message()
            ]);
            return new WP_REST_Response(['error' => $res->get_error_message()], self::http_status_from_error($res));
        }

        // $res is the WP user id (by design in create_user)
        $wp_user_id = (int)$res;

        // Optionally create a logged-in WP session cookie
        wp_set_current_user($wp_user_id);
        wp_set_auth_cookie($wp_user_id, true);

        // ---- Load your SM profile row (linked by wp_user_id)
        global $wpdb;
        $t  = self::t_users(); // e.g., "{$wpdb->prefix}sm_users"
        $sm = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE wp_user_id = %d", $wp_user_id));

        // ---- Issue JWT (HS256 for now) with sub = wp_user_id
        $jwt = self::issue_jwt_for_user($wp_user_id, $email, WEEK_IN_SECONDS, [
            'sm_user_id' => (int)($sm->id ?? 0),
        ]);

        self::log('handle_register: success', ['email' => $email, 'wp_user_id' => $wp_user_id, 'sm_user_id' => (int)($sm->id ?? 0)]);

        // ---- Return token + ids
        return rest_ensure_response([
            'token'      => $jwt,
            'wp_user_id' => $wp_user_id,
            'sm_user_id' => (int)($sm->id ?? 0),
        ]);
    }


    /**
     * POST /wp-json/soulmirror/v1/login
     * Body JSON: { email, password }
     */
    public static function handle_login(WP_REST_Request $req)
    {
        $p = $req->get_json_params();
        $email = sanitize_email($p['email'] ?? '');
        $password = (string)($p['password'] ?? '');

        if (!$email || !$password) {
            return new WP_REST_Response(['error' => 'Email and password required'], 400);
        }

        $creds = ['user_login' => $email, 'user_password' => $password, 'remember' => true];
        $user = wp_signon($creds);
        if (is_wp_error($user)) {
            return new WP_REST_Response(['error' => 'Invalid credentials'], 401);
        }

        wp_set_current_user($user->ID); // optional

        global $wpdb;
        $t = self::t_users();
        $sm = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE wp_user_id=%d", (int)$user->ID));

        $jwt = self::issue_jwt_for_user((int)$user->ID, $user->user_email, 900, [
            'sm_user_id' => (int)($sm->id ?? 0),
        ]);

        // if you’re doing SSO, include the next authorize URL here
        return rest_ensure_response([
            'token' => $jwt,
            'wp_user_id' => (int)$user->ID,
            'sm_user_id' => (int)($sm->id ?? 0),
        ]);
    }


    /**
     * POST /wp-json/soulmirror/v1/google-login
     * Body JSON: { token: <Google ID token> }
     */
    public static function handle_google_login(WP_REST_Request $req)
    {
        $p = $req->get_json_params();
        $id_token = sanitize_text_field($p['token'] ?? '');
        if (!$id_token) {
            self::log('handle_google_login: missing token');
            return new WP_REST_Response(['error' => 'Google token required'], 400);
        }

        $payload = self::verify_google_token($id_token);
        if (empty($payload['email'])) {
            self::log('handle_google_login: invalid token');
            return new WP_REST_Response(['error' => 'Invalid Google token'], 401);
        }

        global $wpdb;
        $t = self::t_users();
        $email = sanitize_email($payload['email']);
        $user  = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE email=%s", $email));
        if ($wpdb->last_error) self::log('handle_google_login: SELECT error', ['email' => $email, 'dberr' => $wpdb->last_error]);

        if (!$user) {
            $ok = $wpdb->insert($t, [
                'email'         => $email,
                'full_name'     => sanitize_text_field($payload['name'] ?? ''),
                'google_id'     => sanitize_text_field($payload['sub'] ?? ''),
                'password_hash' => null,
                'created_at'    => current_time('mysql'),
            ], ['%s', '%s', '%s', '%s', '%s']);

            if (!$ok || !$wpdb->insert_id) {
                self::log('handle_google_login: INSERT failed', ['email' => $email, 'dberr' => $wpdb->last_error]);
                return new WP_REST_Response(['error' => 'Could not create user'], 500);
            }
            $user = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id=%d", (int)$wpdb->insert_id));
        }

        $jwt = self::issue_jwt_for_user((int)$user->id, $user->email, 900);
        self::log('handle_google_login: success', ['email' => $email, 'user_id' => (int)$user->id]);
        return rest_ensure_response(['token' => $jwt, 'user_id' => (int)$user->id]);
    }

    /**
     * Permission callback for protected routes.
     */
    public static function verify_jwt(WP_REST_Request $req)
    {
        $row = self::get_sm_user_from_jwt($req);
        $ok  = (bool) $row;
        self::log('verify_jwt', ['ok' => $ok, 'user_id' => $row->id ?? null]);
        return $ok;
    }

    /**
     * Decode Bearer token, resolve WP user id from sub, and return the sm_users row (or null).
     */
    public static function get_sm_user_from_jwt(WP_REST_Request $req)
    {
        // 1) Read Authorization header and extract token
        $auth = $req->get_header('authorization') ?: $req->get_header('Authorization') ?: '';
        if (stripos($auth, 'Bearer ') !== 0) {
            self::log('get_sm_user_from_jwt: missing bearer');
            return null;
        }
        $token = trim(substr($auth, 7));

        // 2) Decode + verify (HS256 in your current code)
        $claims = self::jwt_decode($token);
        if (!$claims || empty($claims['sub'])) {
            self::log('get_sm_user_from_jwt: bad token/claims');
            return null;
        }

        // 3) Expiry defense-in-depth
        if (!empty($claims['exp']) && time() >= (int)$claims['exp']) {
            self::log('get_sm_user_from_jwt: token expired', ['sub' => $claims['sub']]);
            return null;
        }

        // 4) Subject is the canonical WP user id
        $wp_user_id = (int)$claims['sub'];
        if ($wp_user_id <= 0) {
            self::log('get_sm_user_from_jwt: invalid sub');
            return null;
        }

        // 5) Load your SM profile row by wp_user_id
        global $wpdb;
        $t  = self::t_users(); // e.g., "{$wpdb->prefix}sm_users"
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$t} WHERE wp_user_id = %d", $wp_user_id)
        );

        // ---- If you have NOT added the wp_user_id column yet, use this line instead:
        // $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE email = %s", get_userdata($wp_user_id)->user_email));

        if ($wpdb->last_error) {
            self::log('get_sm_user_from_jwt: SELECT error', ['err' => $wpdb->last_error, 'wp_user_id' => $wp_user_id]);
        }

        self::log('get_sm_user_from_jwt: done', ['found' => (bool)$row, 'wp_user_id' => $wp_user_id]);
        return $row ?: null;
    }

    /* ============================================================
       PERSISTENCE (used by REST and by your shortcode)
       ============================================================ */

    /**
     * Create a new user in sm_users. Returns user_id or WP_Error.
     */
    public static function create_user(string $full_name, string $email, ?string $date_of_birth, string $password)
    {
        // keep your current validations (email/name/password strength/DOB)

        if (email_exists($email)) {
            return new WP_Error('exists', 'Email already registered');
        }

        // 1) Create canonical WP user
        $user_id = wp_create_user($email, $password, $email);
        if (is_wp_error($user_id)) return $user_id;

        wp_update_user(['ID' => $user_id, 'display_name' => $full_name]);
        (new WP_User($user_id))->set_role('sm_customer'); // create this role once

        // 2) Insert profile row (no password_hash)
        global $wpdb;
        $t = self::t_users();
        $data = [
            'wp_user_id'  => $user_id,
            'email'       => $email,
            'full_name'   => $full_name,
            'created_at'  => current_time('mysql'),
        ];
        $fmt  = ['%d', '%s', '%s', '%s'];
        if ($date_of_birth) {
            $data['date_of_birth'] = $date_of_birth;
            $fmt[] = '%s';
        }

        $ok = $wpdb->insert($t, $data, $fmt);
        if (!$ok) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user($user_id); // rollback on failure
            return new WP_Error('db', 'Could not create profile. Please try again.');
        }

        return (int)$user_id; // return WP user id (canonical)
    }


    /** Normalize YYYY-MM-DD within range 1900-01-01..today */
    private static function normalize_dob(string $raw)
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) return null;
        $ts = strtotime($raw);
        if ($ts === false) return null;
        $min = strtotime('1900-01-01');
        $today = strtotime(current_time('Y-m-d'));
        if ($ts < $min || $ts > $today) return null;
        return date('Y-m-d', $ts);
    }

    /* ============================================================
       JWT HELPERS (HS256)
       ============================================================ */

    /** Issue a token for an id/email with TTL seconds */
    private static function issue_jwt_for_user(int $wp_user_id, string $email, int $ttl, array $extra = [])
    {
        $now = time();
        $payload = array_merge([
            'sub'   => $wp_user_id,     // canonical subject
            'email' => $email,
            'iat'   => $now,
            'exp'   => $now + $ttl,
            'iss'   => 'soul-mirror',
        ], $extra);

        return self::jwt_encode($payload); // keep HS256 for now
    }


    /** Encode payload as JWT */
    public static function jwt_encode(array $payload): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $b64 = static function ($d) {
            return rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
        };

        $segments = [
            $b64(json_encode($header)),
            $b64(json_encode($payload)),
        ];
        $sig = hash_hmac('sha256', implode('.', $segments), self::jwt_key(), true);
        $segments[] = $b64($sig);
        return implode('.', $segments);
    }

    /** Decode + verify signature + exp */
    public static function jwt_decode(string $jwt)
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            self::log('jwt_decode: bad parts count');
            return null;
        }
        list($h64, $p64, $s64) = $parts;

        $b64d = static function ($d) {
            return base64_decode(strtr($d, '-_', '+/'));
        };
        $header  = json_decode($b64d($h64), true);
        $payload = json_decode($b64d($p64), true);
        $sig     = $b64d($s64);

        if (!is_array($header) || !is_array($payload)) {
            self::log('jwt_decode: bad header/payload');
            return null;
        }
        if (($header['alg'] ?? '') !== 'HS256') {
            self::log('jwt_decode: unexpected alg', ['alg' => $header['alg'] ?? null]);
            return null;
        }

        $check = hash_hmac('sha256', "$h64.$p64", self::jwt_key(), true);
        if (!hash_equals($check, $sig)) {
            self::log('jwt_decode: signature mismatch');
            return null;
        }

        if (!empty($payload['exp']) && time() >= (int)$payload['exp']) {
            self::log('jwt_decode: expired', ['exp' => $payload['exp']]);
            return null;
        }

        return $payload;
    }

    /* ============================================================
       GOOGLE TOKEN VERIFY (server-side)
       ============================================================ */
    private static function verify_google_token($id_token)
    {
        $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($id_token);
        $resp = wp_remote_get($url, ['timeout' => 8]);
        if (is_wp_error($resp)) {
            self::log('verify_google_token: transport error', ['err' => $resp->get_error_message()]);
            return null;
        }
        $code = wp_remote_retrieve_response_code($resp);
        if ($code !== 200) {
            self::log('verify_google_token: bad http', ['code' => $code]);
            return null;
        }
        $body = wp_remote_retrieve_body($resp);
        $json = json_decode($body, true);
        self::log('verify_google_token: ok', ['has_email' => !empty($json['email'])]);
        return $json;
    }

    /* ============================================================
       UTILS
       ============================================================ */
    private static function http_status_from_error(WP_Error $e): int
    {
        $map = [
            'bad_email' => 400,
            'bad_name' => 400,
            'bad_pw' => 400,
            'bad_dob' => 400,
            'exists' => 409,
            'db' => 500,
        ];
        foreach ($e->get_error_codes() as $code) {
            if (isset($map[$code])) return $map[$code];
        }
        return 400;
    }
}
