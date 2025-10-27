<?php
if (!defined('ABSPATH')) exit;

class SM_Account
{
  /** @var wpdb */
  protected static $db;
  protected static $table;

  /* ------------------------------------------------------------
   * Bootstrap
   * ---------------------------------------------------------- */
  protected static function boot()
  {
    if (self::$db) return;
    global $wpdb;
    self::$db    = $wpdb;
    self::$table = $wpdb->prefix . 'sm_users';
  }

  /* ============================================================
   * ==============  PUBLIC HANDLERS (USED BY ROUTER) ===========
   * ============================================================ */

  /**
   * GET /session
   * Reads current WP cookie session; if logged in, returns {account, token}
   * (token is minted on the fly with sub = account_id).
   */
  public static function handle_session(WP_REST_Request $req)
  {
    self::boot();

    if (!is_user_logged_in()) {
      return self::ok(['logged_in' => false]);
    }

    $user = wp_get_current_user();
    if (!$user || !$user->ID) {
      return self::ok(['logged_in' => false]);
    }

    $row = self::get_by_wp_user_id((int)$user->ID);
    if (!$row) {
      // Create/link an sm_users row if missing
      $row = self::ensure_account_row(
        (int)$user->ID,
        $user->user_email ?: '',
        $user->display_name ?: ''
      );
      if (is_wp_error($row)) {
        return self::error('account_link_failed', $row->get_error_message(), 500);
      }
    }

    $token = self::issue_jwt($row->account_id, [
      'wp_user_id' => (int)$row->wp_user_id,
      'email'      => $row->email,
    ]);

    return new WP_REST_Response([
      'success'   => true,
      'logged_in' => true,
      'token'     => $token,
      'account'   => self::row_to_array($row),
      'data'      => [
        'logged_in' => true,
        'account'   => self::row_to_array($row),
        'token'     => $token,
      ],
    ], 200);
  }

  /**
   * POST /register
   * Body: { email, full_name?, password?, gender?, date_of_birth? }
   * Creates (or reuses) a WP user, ensures sm_users with account_id, returns JWT.
   */
  public static function handle_register(WP_REST_Request $req)
  {
    self::boot();

    $email         = sanitize_email($req->get_param('email'));
    $full_name     = sanitize_text_field((string) $req->get_param('full_name'));
    $password      = (string) $req->get_param('password');
    $gender        = self::null_text($req->get_param('gender'), 20);
    $date_of_birth = self::null_date($req->get_param('date_of_birth'));

    if (empty($email)) {
      return self::error('missing_email', 'Email is required.', 400);
    }

    // Reuse or create WP user
    $user = get_user_by('email', $email);
    if (!$user) {
      if (empty($password)) {
        $password = wp_generate_password(20, true, true);
      }
      $username = self::username_from_email($email);
      $wp_uid   = wp_create_user($username, $password, $email);
      if (is_wp_error($wp_uid)) {
        return self::error('wp_user_create_failed', $wp_uid->get_error_message(), 400);
      }
      $user = get_user_by('id', $wp_uid);
      if (!empty($full_name)) {
        wp_update_user([
          'ID'           => $user->ID,
          'display_name' => $full_name,
          'first_name'   => $full_name,
        ]);
      }
      $user->set_role('subscriber');
    }

    // Ensure sm_users row with canonical account_id
    $row = self::ensure_account_row((int)$user->ID, $email, $full_name, $gender, $date_of_birth);
    if (is_wp_error($row)) {
      return self::error('account_create_failed', $row->get_error_message(), 500);
    }

    // JWT with sub = account_id
    $token = self::issue_jwt($row->account_id, [
      'wp_user_id' => (int)$row->wp_user_id,
      'account_id' => (int)$row->account_id,
      'email'      => $row->email,
    ]);

    return new WP_REST_Response([
      'success' => true,
      'token'   => $token,
      'account' => self::row_to_array($row),
      'data'    => [
        'account' => self::row_to_array($row),
        'token'   => $token,
      ],
    ], 200);
  }

  /**
   * POST /login
   * Body: { usernameOrEmail, password }
   * Authenticates against WP, then returns JWT bound to account_id.
   */
  public static function handle_login(WP_REST_Request $req)
  {
    self::boot();

    // Accept any of these keys from the front-end
    $user_field = (string) (
      $req->get_param('usernameOrEmail') ??
      $req->get_param('email') ??
      $req->get_param('username') ?? ''
    );
    $password = (string) (
      $req->get_param('password') ??
      $req->get_param('pass') ?? ''
    );

    if ($user_field === '' || $password === '') {
      // Return a clear, front-end-friendly error payload
      return self::error(
        'missing_credentials',
        'Please provide email/username and password.',
        400
      );
    }

    // Allow email or username
    if (strpos($user_field, '@') !== false) {
      $user_obj = get_user_by('email', sanitize_email($user_field));
      if (!$user_obj) return self::error('invalid_login', 'Invalid credentials.', 401);
      $username = $user_obj->user_login;
    } else {
      $username = sanitize_user($user_field);
    }

    $user = wp_authenticate($username, $password);
    if (is_wp_error($user)) {
      return self::error('invalid_login', 'Invalid credentials.', 401);
    }

    // Ensure sm_users row and issue JWT with sub = account_id
    $row = self::ensure_account_row(
      (int)$user->ID,
      $user->user_email ?: '',
      $user->display_name ?: ''
    );
    if (is_wp_error($row)) {
      return self::error('account_link_failed', $row->get_error_message(), 500);
    }

    $token = self::issue_jwt($row->account_id, [
      'wp_user_id' => (int)$row->wp_user_id,
      'email'      => $row->email,
    ]);

    return new WP_REST_Response([
      'success' => true,
      // top-level (legacy) fields your JS is looking for:
      'token'   => $token,
      'account' => self::row_to_array($row),

      // keep your structured envelope too:
      'data'    => [
        'account' => self::row_to_array($row),
        'token'   => $token,
      ],
    ], 200);
  }


  /**
   * POST /google-login
   * Disabled (kept only to avoid breaking callers).
   */
  public static function handle_google_login(WP_REST_Request $req)
  {
    return self::error('google_login_disabled', 'Google login is not enabled in this build.', 410);
  }

  /**
   * GET /me (permission: verify_jwt)
   * Returns the account linked to the JWT subject (account_id).
   */
  public static function get_user_info(WP_REST_Request $req)
  {
    self::boot();

    $claims = $req->get_attribute('sm_jwt_claims');
    if (!is_array($claims) || empty($claims['sub'])) {
      return self::error('invalid_token', 'Missing subject.', 401);
    }

    $row = self::get_by_account_id((string)$claims['sub']);
    if (!$row) return self::error('not_found', 'Account not found.', 404);

    return self::ok(['account' => self::row_to_array($row)]);
  }

  /**
   * Permission callback for protected routes.
   * - Validates Bearer token
   * - Attaches claims to request as 'sm_jwt_claims'
   */
  public static function verify_jwt(WP_REST_Request $req)
  {
    self::boot();

    $claims = self::authorize_bearer($req);
    if (is_wp_error($claims)) return $claims;

    // Attach claims so handlers can read without re-decoding
    $req->set_attribute('sm_jwt_claims', $claims);
    return true;
  }

  /* ============================================================
   * =====================  INTERNAL HELPERS  ====================
   * ============================================================ */

  protected static function ensure_account_row(
    int $wp_user_id,
    string $email,
    string $full_name = '',
    ?string $gender = null,
    ?string $date_of_birth = null
  ) {
    // Try by wp_user_id first (fast path)
    $row = self::get_by_wp_user_id($wp_user_id);
    if ($row) {
      // Backfill email/full_name if missing
      $needsUpdate = false;
      $update = [];
      $fmt    = [];
      if ($email && strtolower($row->email) !== strtolower($email)) {
        $update['email'] = sanitize_email($email);
        $fmt[] = '%s';
        $needsUpdate = true;
      }
      if ($full_name && $row->full_name !== $full_name) {
        $update['full_name'] = sanitize_text_field($full_name);
        $fmt[] = '%s';
        $needsUpdate = true;
      }
      if (!is_null($gender) && $row->gender !== $gender) {
        $update['gender'] = $gender;
        $fmt[] = '%s';
        $needsUpdate = true;
      }
      if (!is_null($date_of_birth) && $row->date_of_birth !== $date_of_birth) {
        $update['date_of_birth'] = $date_of_birth;
        $fmt[] = '%s';
        $needsUpdate = true;
      }
      if ($needsUpdate) {
        $update['updated_at'] = current_time('mysql');
        $fmt[] = '%s';
        self::$db->update(self::$table, $update, ['account_id' => $row->account_id], $fmt, ['%s']);
        $row = self::get_by_account_id($row->account_id);
      }
      return $row;
    }

    // Try by email
    if (!empty($email)) {
      $byEmail = self::get_by_email($email);
      if ($byEmail) {
        // link wp_user_id if not set or different
        if ((int)$byEmail->wp_user_id !== $wp_user_id) {
          self::$db->update(
            self::$table,
            ['wp_user_id' => $wp_user_id, 'updated_at' => current_time('mysql')],
            ['account_id' => $byEmail->account_id],
            ['%d', '%s'],
            ['%s']
          );
          $byEmail = self::get_by_account_id($byEmail->account_id);
        }
        // Backfill optional fields
        $needsUpdate = false;
        $update = [];
        $fmt    = [];
        if ($full_name && $byEmail->full_name !== $full_name) {
          $update['full_name'] = sanitize_text_field($full_name);
          $fmt[] = '%s';
          $needsUpdate = true;
        }
        if (!is_null($gender) && $byEmail->gender !== $gender) {
          $update['gender'] = $gender;
          $fmt[] = '%s';
          $needsUpdate = true;
        }
        if (!is_null($date_of_birth) && $byEmail->date_of_birth !== $date_of_birth) {
          $update['date_of_birth'] = $date_of_birth;
          $fmt[] = '%s';
          $needsUpdate = true;
        }
        if ($needsUpdate) {
          $update['updated_at'] = current_time('mysql');
          $fmt[] = '%s';
          self::$db->update(self::$table, $update, ['account_id' => $byEmail->account_id], $fmt, ['%s']);
          $byEmail = self::get_by_account_id($byEmail->account_id);
        }
        return $byEmail;
      }
    }

    // Create new row with new account_id (UUID v4)
    $account_id = self::uuidv4();
    $insert = [
      'wp_user_id'    => $wp_user_id,
      'account_id'    => $account_id,
      'email'         => sanitize_email($email),
      'full_name'     => sanitize_text_field($full_name),
      'gender'        => $gender,
      'date_of_birth' => $date_of_birth,
      'created_at'    => current_time('mysql'),
      'updated_at'    => current_time('mysql'),
    ];
    $ok = self::$db->insert(self::$table, $insert, ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);
    if (!$ok) {
      return new WP_Error('db_insert_failed', self::$db->last_error ?: 'Insert failed');
    }
    return self::get_by_account_id($account_id);
  }

  /* ---------- JWT helpers ---------- */

  protected static function authorize_bearer(WP_REST_Request $req)
  {
    $hdr = $req->get_header('authorization');
    if (!$hdr || stripos($hdr, 'bearer ') !== 0) {
      return new WP_Error('missing_auth', 'Missing Authorization header.');
    }
    $jwt = trim(substr($hdr, 7));
    if ($jwt === '') return new WP_Error('missing_auth', 'Missing token.');

    try {
      if (method_exists('SM_JWT', 'decode')) {
        $claims = SM_JWT::decode($jwt);
      } else {
        // Some builds expose verify(); accept either
        $claims = SM_JWT::verify($jwt);
      }
    } catch (\Throwable $e) {
      return new WP_Error('invalid_token', 'Invalid token: ' . $e->getMessage());
    }

    if (!is_array($claims) || empty($claims['sub'])) {
      return new WP_Error('invalid_token', 'Token missing subject.');
    }
    return $claims;
  }

  protected static function issue_jwt(string $account_id, array $extra = [])
  {
    $claims = array_merge([
      'sub' => $account_id,
      'iat' => time(),
      'nbf' => time(),
      'exp' => time() + 60 * 60 * 24 * 7, // 7 days
    ], $extra);

    if (method_exists('SM_JWT', 'issue')) {
      return SM_JWT::issue($claims);
    }
    if (method_exists('SM_JWT', 'encode')) {
      return SM_JWT::encode($claims);
    }
    // Fallback: throw clear error in dev
    throw new \RuntimeException('SM_JWT::issue/encode not found.');
  }

  /* ---------- DB helpers ---------- */

  protected static function get_by_account_id(string $account_id)
  {
    return self::$db->get_row(
      self::$db->prepare("SELECT * FROM " . self::$table . " WHERE account_id = %s LIMIT 1", $account_id)
    );
  }

  protected static function get_by_wp_user_id(int $wp_user_id)
  {
    return self::$db->get_row(
      self::$db->prepare("SELECT * FROM " . self::$table . " WHERE wp_user_id = %d LIMIT 1", $wp_user_id)
    );
  }

  protected static function get_by_email(string $email)
  {
    $email = sanitize_email($email);
    if ($email === '') return null;
    return self::$db->get_row(
      self::$db->prepare("SELECT * FROM " . self::$table . " WHERE email = %s LIMIT 1", $email)
    );
  }

  /* ---------- utilities ---------- */

  protected static function row_to_array($row)
  {
    if (!$row) return null;
    return [
      'account_id'    => $row->account_id,
      'wp_user_id'    => (int) $row->wp_user_id,
      'email'         => $row->email,
      'full_name'     => $row->full_name,
      'gender'        => $row->gender,
      'date_of_birth' => $row->date_of_birth,
      'created_at'    => $row->created_at,
      'updated_at'    => $row->updated_at,
    ];
  }

  protected static function username_from_email(string $email)
  {
    $base = sanitize_user(current(explode('@', $email)));
    if ($base === '') $base = 'user';
    $candidate = $base;
    $i = 1;
    while (username_exists($candidate)) {
      $candidate = $base . $i;
      $i++;
    }
    return $candidate;
  }

  protected static function uuidv4()
  {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
  }

  protected static function null_text($val, int $maxLen = 255): ?string
  {
    if ($val === null || $val === '') return null;
    $s = sanitize_text_field((string)$val);
    return mb_substr($s, 0, $maxLen);
  }

  protected static function null_date($val): ?string
  {
    if (!$val) return null;
    // accept YYYY-MM-DD only
    $s = preg_replace('/[^0-9\-]/', '', (string)$val);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return null;
    return $s;
  }

  protected static function ok($data = [])
  {
    return new WP_REST_Response(['success' => true, 'data' => $data], 200);
  }
  protected static function error(string $code, string $message, int $status = 400)
  {
    return new WP_REST_Response(['success' => false, 'error' => ['code' => $code, 'message' => $message]], $status);
  }
}
