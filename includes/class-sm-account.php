<?php
if (!defined('ABSPATH')) exit;

/**
 * Handles authentication & persistence for SoulMirror Account Service.
 */
class SM_Account
{
    /** Lightweight logger to PHP error_log */
    private static function log(string $msg, array $ctx = []): void {
        // Keep context compact & safe (no passwords/tokens)
        if (!empty($ctx)) {
            // best-effort redaction
            unset($ctx['password'], $ctx['pass'], $ctx['token'], $ctx['jwt']);
            $msg .= ' ' . json_encode($ctx);
        }
        error_log('[SM_Account] ' . $msg);
    }

    /** Prefer loading from wp-config.php: define('SOULMIRROR_JWT_SECRET', '...'); */
    private static function jwt_key() {
        if (defined('SOULMIRROR_JWT_SECRET') && SOULMIRROR_JWT_SECRET) return SOULMIRROR_JWT_SECRET;
        return AUTH_KEY; // fallback so dev doesn’t break
    }

    /** Table helper */
    private static function t_users() {
        global $wpdb; return $wpdb->prefix . 'sm_users';
    }

    /* ============================================================
       PUBLIC REST HANDLERS
       ============================================================ */

    /**
     * POST /wp-json/soulmirror/v1/register
     * Body JSON: { email, password, full_name, date_of_birth? (YYYY-MM-DD) }
     */
    public static function handle_register(WP_REST_Request $req)
    {
        $p   = $req->get_json_params();
        $email = sanitize_email($p['email'] ?? '');
        $pass  = (string)($p['password'] ?? '');
        $name  = sanitize_text_field($p['full_name'] ?? '');
        $dob   = sanitize_text_field($p['date_of_birth'] ?? ''); // optional here

        self::log('handle_register: received', ['email'=>$email, 'name'=>$name, 'dob_raw'=>$dob !== '' ? $dob : null]);

        // If DOB empty in REST call, allow null; shortcode can enforce it.
        $dob_norm = null;
        if ($dob !== '') {
            $dob_norm = self::normalize_dob($dob);
            if (!$dob_norm) {
                self::log('handle_register: invalid DOB', ['email'=>$email, 'dob'=>$dob]);
                return new WP_REST_Response(['error'=>'Please choose a valid birth date'], 400);
            }
        }

        $res = self::create_user($name, $email, $dob_norm, $pass);
        if (is_wp_error($res)) {
            self::log('handle_register: create_user error', ['email'=>$email, 'code'=>$res->get_error_code(), 'msg'=>$res->get_error_message()]);
            return new WP_REST_Response(['error'=>$res->get_error_message()], self::http_status_from_error($res));
        }

        $user_id = (int)$res;
        $jwt = self::issue_jwt_for_user($user_id, $email, WEEK_IN_SECONDS);

        self::log('handle_register: success', ['email'=>$email, 'user_id'=>$user_id]);
        return rest_ensure_response(['token' => $jwt, 'user_id' => $user_id]);
    }

    /**
     * POST /wp-json/soulmirror/v1/login
     * Body JSON: { email, password }
     */
    public static function handle_login(WP_REST_Request $req)
    {
        $p        = $req->get_json_params();
        $email    = sanitize_email($p['email'] ?? '');
        $password = (string)($p['password'] ?? '');

        self::log('handle_login: received', ['email'=>$email]);

        if (!$email || !$password) {
            self::log('handle_login: missing credentials', ['email'=>$email]);
            return new WP_REST_Response(['error' => 'Email and password required'], 400);
        }

        global $wpdb; $t = self::t_users();
        $user = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE email=%s", $email));
        if ($wpdb->last_error) self::log('handle_login: SELECT error', ['email'=>$email, 'dberr'=>$wpdb->last_error]);

        if (!$user || empty($user->password_hash) || !password_verify($password, $user->password_hash)) {
            self::log('handle_login: invalid credentials', ['email'=>$email]);
            return new WP_REST_Response(['error' => 'Invalid credentials'], 401);
        }

        $jwt = self::issue_jwt_for_user((int)$user->id, $user->email, 900);
        self::log('handle_login: success', ['email'=>$email, 'user_id'=>(int)$user->id]);
        return rest_ensure_response(['token' => $jwt, 'user_id' => (int)$user->id]);
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
            return new WP_REST_Response(['error'=>'Google token required'], 400);
        }

        $payload = self::verify_google_token($id_token);
        if (empty($payload['email'])) {
            self::log('handle_google_login: invalid token');
            return new WP_REST_Response(['error'=>'Invalid Google token'], 401);
        }

        global $wpdb; $t = self::t_users();
        $email = sanitize_email($payload['email']);
        $user  = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE email=%s", $email));
        if ($wpdb->last_error) self::log('handle_google_login: SELECT error', ['email'=>$email, 'dberr'=>$wpdb->last_error]);

        if (!$user) {
            $ok = $wpdb->insert($t, [
                'email'         => $email,
                'full_name'     => sanitize_text_field($payload['name'] ?? ''),
                'google_id'     => sanitize_text_field($payload['sub'] ?? ''),
                'password_hash' => null,
                'created_at'    => current_time('mysql'),
            ], ['%s','%s','%s','%s','%s']);

            if (!$ok || !$wpdb->insert_id) {
                self::log('handle_google_login: INSERT failed', ['email'=>$email, 'dberr'=>$wpdb->last_error]);
                return new WP_REST_Response(['error'=>'Could not create user'], 500);
            }
            $user = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id=%d", (int)$wpdb->insert_id));
        }

        $jwt = self::issue_jwt_for_user((int)$user->id, $user->email, 900);
        self::log('handle_google_login: success', ['email'=>$email, 'user_id'=>(int)$user->id]);
        return rest_ensure_response(['token' => $jwt, 'user_id' => (int)$user->id]);
    }

    /**
     * Permission callback for protected routes.
     */
    public static function verify_jwt(WP_REST_Request $req)
    {
        $row = self::get_sm_user_from_jwt($req);
        $ok  = (bool) $row;
        self::log('verify_jwt', ['ok'=>$ok, 'user_id'=>$row->id ?? null]);
        return $ok;
    }

    /**
     * Decode Bearer token and return the sm_users row or null.
     */
    public static function get_sm_user_from_jwt(WP_REST_Request $req)
    {
        $auth = $req->get_header('authorization') ?: $req->get_header('Authorization') ?: '';
        if (stripos($auth, 'Bearer ') !== 0) {
            self::log('get_sm_user_from_jwt: missing bearer');
            return null;
        }

        $token  = trim(substr($auth, 7));
        $claims = self::jwt_decode($token);
        if (!$claims || empty($claims['sub'])) {
            self::log('get_sm_user_from_jwt: bad token');
            return null;
        }

        // exp check (defense-in-depth if someone bypassed jwt_decode guard)
        if (!empty($claims['exp']) && time() >= (int)$claims['exp']) {
            self::log('get_sm_user_from_jwt: token expired', ['sub'=>$claims['sub']]);
            return null;
        }

        global $wpdb; $t = self::t_users();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id=%d", (int)$claims['sub']));
        if ($wpdb->last_error) self::log('get_sm_user_from_jwt: SELECT error', ['dberr'=>$wpdb->last_error, 'sub'=>$claims['sub'] ?? null]);
        self::log('get_sm_user_from_jwt: ok', ['sub'=>$claims['sub'], 'found'=> (bool)$row]);
        return $row;
    }

    /* ============================================================
       PERSISTENCE (used by REST and by your shortcode)
       ============================================================ */

    /**
     * Create a new user in sm_users. Returns user_id or WP_Error.
     */
    public static function create_user(string $full_name, string $email, ?string $date_of_birth, string $password) {
        global $wpdb; $t = self::t_users();

        self::log('create_user: start', [
            'email'=>$email,
            'name'=>$full_name,
            'dob'=>$date_of_birth
        ]);

        // Validate
        if (!is_email($email)) {
            self::log('create_user: bad_email', ['email'=>$email]);
            return new WP_Error('bad_email', 'Please enter a valid email address');
        }
        if (mb_strlen(trim($full_name)) < 3) {
            self::log('create_user: bad_name', ['email'=>$email, 'name'=>$full_name]);
            return new WP_Error('bad_name',  'Full name must be at least 3 characters');
        }
        if (!preg_match('/^(?=.*[A-Za-z])(?=.*\d).{8,}$/', $password)) {
            self::log('create_user: bad_pw', ['email'=>$email]);
            return new WP_Error('bad_pw', 'Password must have at least 8 characters, including a letter and a number');
        }

        // If DOB provided, ensure YYYY-MM-DD
        if ($date_of_birth !== null) {
            $dob = self::normalize_dob($date_of_birth);
            if (!$dob) {
                self::log('create_user: bad_dob', ['email'=>$email, 'dob'=>$date_of_birth]);
                return new WP_Error('bad_dob', 'Please choose a valid birth date');
            }
            $date_of_birth = $dob;
        }

        // Uniqueness
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE email=%s", $email));
        if ($wpdb->last_error) self::log('create_user: SELECT error', ['email'=>$email, 'dberr'=>$wpdb->last_error]);
        if ($exists) {
            self::log('create_user: exists', ['email'=>$email, 'existing_id'=>$exists]);
            return new WP_Error('exists', 'Email already registered');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $data = [
            'email'         => $email,
            'full_name'     => $full_name,
            'password_hash' => $hash,
            'created_at'    => current_time('mysql'),
        ];
        $fmt  = ['%s','%s','%s','%s'];

        if ($date_of_birth !== null) {
            $data['date_of_birth'] = $date_of_birth;
            $fmt[] = '%s';
        }

        $ok = $wpdb->insert($t, $data, $fmt);
        if (!$ok) {
            self::log('create_user: INSERT failed', ['email'=>$email, 'dberr'=>$wpdb->last_error, 'data'=>$data]);
            return new WP_Error('db', 'Could not create user. Please try again.');
        }

        $new_id = (int)$wpdb->insert_id;
        self::log('create_user: INSERT ok', ['email'=>$email, 'user_id'=>$new_id]);
        return $new_id;
    }

    /** Normalize YYYY-MM-DD within range 1900-01-01..today */
    private static function normalize_dob(string $raw) {
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
    private static function issue_jwt_for_user(int $user_id, string $email, int $ttl) {
        $now = time();
        $payload = [
            'sub' => $user_id,
            'email' => $email,
            'iat' => $now,
            'exp' => $now + $ttl,
            'iss' => 'soul-mirror',
        ];
        $jwt = self::jwt_encode($payload);
        self::log('issue_jwt_for_user', ['user_id'=>$user_id, 'ttl'=>$ttl]);
        return $jwt;
    }

    /** Encode payload as JWT */
    public static function jwt_encode(array $payload): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $b64 = static function ($d) { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); };

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

        $b64d = static function ($d) { return base64_decode(strtr($d, '-_', '+/')); };
        $header  = json_decode($b64d($h64), true);
        $payload = json_decode($b64d($p64), true);
        $sig     = $b64d($s64);

        if (!is_array($header) || !is_array($payload)) {
            self::log('jwt_decode: bad header/payload');
            return null;
        }
        if (($header['alg'] ?? '') !== 'HS256') {
            self::log('jwt_decode: unexpected alg', ['alg'=>$header['alg'] ?? null]);
            return null;
        }

        $check = hash_hmac('sha256', "$h64.$p64", self::jwt_key(), true);
        if (!hash_equals($check, $sig)) {
            self::log('jwt_decode: signature mismatch');
            return null;
        }

        if (!empty($payload['exp']) && time() >= (int)$payload['exp']) {
            self::log('jwt_decode: expired', ['exp'=>$payload['exp']]);
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
            self::log('verify_google_token: transport error', ['err'=>$resp->get_error_message()]);
            return null;
        }
        $code = wp_remote_retrieve_response_code($resp);
        if ($code !== 200) {
            self::log('verify_google_token: bad http', ['code'=>$code]);
            return null;
        }
        $body = wp_remote_retrieve_body($resp);
        $json = json_decode($body, true);
        self::log('verify_google_token: ok', ['has_email'=>!empty($json['email'])]);
        return $json;
    }

    /* ============================================================
       UTILS
       ============================================================ */
    private static function http_status_from_error(WP_Error $e): int {
        $map = [
            'bad_email' => 400, 'bad_name' => 400, 'bad_pw' => 400, 'bad_dob'=>400,
            'exists' => 409, 'db' => 500,
        ];
        foreach ($e->get_error_codes() as $code) {
            if (isset($map[$code])) return $map[$code];
        }
        return 400;
    }
}
