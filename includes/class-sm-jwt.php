<?php
if (!defined('ABSPATH')) exit;

final class SM_JWT {
    // Prefer a wp-config.php constant:
    // define('SOULMIRROR_JWT_SECRET', 'a-super-long-random-secret');
    public static function secret(): string {
        if (defined('SOULMIRROR_JWT_SECRET') && SOULMIRROR_JWT_SECRET) return SOULMIRROR_JWT_SECRET;
        $s = get_option('soulmirror_jwt_secret', '');
        if (!$s) { $s = wp_generate_password(64, true, true); update_option('soulmirror_jwt_secret', $s); }
        return $s;
    }

    private static function b64u($data){ return rtrim(strtr(base64_encode($data), '+/', '-_'), '='); }
    private static function b64u_dec($data){ return base64_decode(strtr($data, '-_', '+/')); }

    public static function encode(array $payload, int $ttlSeconds = 600): string {
        $now = time();
        $payload = array_merge([
            'iat' => $now,
            'nbf' => $now - 5,
            'exp' => $now + $ttlSeconds,
            'iss' => site_url(),
        ], $payload);

        $header  = ['alg' => 'HS256', 'typ' => 'JWT'];
        $h = self::b64u(json_encode($header));
        $p = self::b64u(json_encode($payload));
        $sig = hash_hmac('sha256', "$h.$p", self::secret(), true);
        $s = self::b64u($sig);
        return "$h.$p.$s";
    }

    public static function decode_verify(string $jwt) {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) return new WP_Error('jwt', 'Malformed token');
        [$h64,$p64,$s64] = $parts;

        $header  = json_decode(self::b64u_dec($h64), true);
        $payload = json_decode(self::b64u_dec($p64), true);
        $sig     = self::b64u_dec($s64);

        if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256') {
            return new WP_Error('jwt', 'Unsupported alg');
        }
        $check = hash_hmac('sha256', "$h64.$p64", self::secret(), true);
        if (!hash_equals($check, $sig)) return new WP_Error('jwt', 'Bad signature');

        $now = time();
        if (($payload['nbf'] ?? 0) > $now) return new WP_Error('jwt', 'Not yet valid');
        if (($payload['exp'] ?? 0) < $now) return new WP_Error('jwt', 'Expired');

        return $payload;
    }

    /** Extract Bearer from a REST request and set current user (if sub is your SM user id). */
    public static function user_from_bearer(\WP_REST_Request $req) {
        $auth = $req->get_header('authorization') ?: $req->get_header('Authorization') ?: '';
        if (stripos($auth, 'Bearer ') !== 0) return new WP_Error('jwt','Missing bearer');
        $jwt = trim(substr($auth, 7));
        $pld = self::decode_verify($jwt);
        if (is_wp_error($pld)) return $pld;

        $sub = intval($pld['sub'] ?? 0);
        if ($sub <= 0) return new WP_Error('jwt','Invalid subject');

        // Note: this DOES NOT tie into WP users (you’re on your own sm_users table),
        // so we won't call wp_set_current_user(). We just return the payload.
        return $pld;
    }
}
