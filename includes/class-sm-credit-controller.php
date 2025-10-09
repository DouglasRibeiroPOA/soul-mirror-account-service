<?php
if (!defined('ABSPATH')) exit;

/**
 * Handles credit management and WooCommerce integration.
 */
class SM_Credit_Controller
{
    /**
     * Hook WooCommerce and anything else you need at init.
     */
    public static function init()
    {
        // Credit the user when an order is marked "completed"
        add_action('woocommerce_order_status_completed', [__CLASS__, 'handle_order_completed']);
    }

    /**
     * WooCommerce order-completed callback.
     *
     * @param int $order_id
     */
    public static function handle_order_completed($order_id)
    {
        if (!function_exists('wc_get_order')) return;

        $order = wc_get_order($order_id);
        if (!$order) return;

        global $wpdb;
        $p = $wpdb->prefix;

        // Lookup SM user by billing email
        $email   = $order->get_billing_email();
        $sm_user = $wpdb->get_row(
            $wpdb->prepare("SELECT id, email FROM {$p}sm_users WHERE email = %s", $email)
        );
        if (!$sm_user) {
            // Optional: auto-create the user or log for later reconciliation
            return;
        }

        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $qty        = (int) $item->get_quantity();

            // Credits & module can come from product meta (recommended)
            $credits_per_unit = self::get_credits_for_product($product_id);
            $module           = self::get_module_for_product($product_id); // default 'global'

            if ($credits_per_unit <= 0) continue;

            $total_credits = $credits_per_unit * max(1, $qty);
            self::add_credits(
                (int) $sm_user->id,
                $sm_user->email,
                $total_credits,
                $module,
                'order_completed',
                'order_' . (int) $order_id
            );
        }
    }

    /**
     * Map a Woo product to number of credits (pull from product meta if set).
     *
     * @param int $product_id
     * @return int
     */
    public static function get_credits_for_product($product_id)
    {
        // Try meta first: _sm_credits (int)
        $meta = get_post_meta($product_id, '_sm_credits', true);
        if ($meta !== '' && $meta !== null) {
            return max(0, (int) $meta);
        }

        // Fallback hard-coded map (optional)
        $map = [
            // product_id => credits
            // 123 => 5,
            // 124 => 10,
            // 125 => 20,
        ];
        return isset($map[$product_id]) ? (int) $map[$product_id] : 0;
    }

    /**
     * Map a Woo product to a credits "module" (pull from product meta if set).
     *
     * @param int $product_id
     * @return string
     */
    public static function get_module_for_product($product_id)
    {
        // Try meta first: _sm_module (string)
        $module = get_post_meta($product_id, '_sm_module', true);
        $module = is_string($module) ? trim($module) : '';
        return $module !== '' ? sanitize_key($module) : 'global';
    }

    /**
     * Insert credits (+) row into sm_credits and log it.
     *
     * @param int         $user_id
     * @param string      $email
     * @param int         $credits_added
     * @param string      $module
     * @param string|null $source
     * @param string|null $reference
     * @return bool
     */
    public static function add_credits($user_id, $email, $credits_added, $module = 'global', $source = null, $reference = null)
    {
        global $wpdb;
        $p = $wpdb->prefix;

        $ok = $wpdb->insert("{$p}sm_credits", [
            'user_id'       => (int) $user_id,
            'email'         => $email,
            'module'        => sanitize_key($module ?: 'global'),
            'credits_added' => max(0, (int) $credits_added),
            'credits_used'  => 0,
            'source'        => $source ? sanitize_text_field($source) : null,
            'created_at'    => current_time('mysql'),
        ]);

        // Optional: also record a usage log entry for grants (as "grant")
        self::record_usage_log($user_id, $module, 'grant', -1 * absint($credits_added) * -1, $reference); // normalize

        return $ok !== false;
    }

    /**
     * Compute balance for a user (per module or all).
     *
     * @param int $user_id
     * @param string|null $module If null, returns ['module' => balance...] map
     * @return int|array
     */
    public static function get_balance($user_id, $module = null)
    {
        global $wpdb;
        $p = $wpdb->prefix;

        if ($module !== null && $module !== '') {
            $module = sanitize_key($module);
            $added = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(credits_added),0) FROM {$p}sm_credits WHERE user_id = %d AND module = %s",
                (int) $user_id,
                $module
            ));
            $used = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(credits_used),0) FROM {$p}sm_credits WHERE user_id = %d AND module = %s",
                (int) $user_id,
                $module
            ));
            return max(0, $added - $used);
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT module,
                    COALESCE(SUM(credits_added),0) AS added,
                    COALESCE(SUM(credits_used),0)  AS used
             FROM {$p}sm_credits
             WHERE user_id = %d
             GROUP BY module",
            (int) $user_id
        ), ARRAY_A);

        $out = [];
        foreach ($rows as $r) {
            $out[$r['module']] = max(0, (int) $r['added'] - (int) $r['used']);
        }
        return $out;
    }


    /**
     * REST: POST /credits/use
     * Body: { module: string, amount?: int = 1, reference?: string }
     *
     * Deducts from specific module first; if insufficient, falls back to 'global' bucket.
     * All wrapped in a DB transaction with row-level locking to prevent races.
     */
    private static function log($rid, $message, array $ctx = [])
    {
        // Keep the context small to avoid logging PII; user_id and module are fine.
        if (!empty($ctx)) {
            $message .= ' ' . wp_json_encode($ctx);
        }
        error_log('[SM_Credits][' . $rid . '] ' . $message);
    }

    /**
     * POST /wp-json/{NS}/credits/use
     * Requires Authorization: Bearer <JWT> via permission_callback.
     */
    public static function rest_use_credit(WP_REST_Request $req)
    {
        $rid = substr(bin2hex(random_bytes(6)), 0, 8); // short request id for log correlation

        // 1) Authenticate via your existing account helper
        try {
            $user = SM_Account::get_sm_user_from_jwt($req);
        } catch (\Throwable $e) {
            self::log($rid, 'get_sm_user_from_jwt threw', ['err' => $e->getMessage()]);
            return new WP_Error('invalid_token', 'Invalid token', ['status' => 401]);
        }
        if (!$user || empty($user->id)) {
            self::log($rid, 'invalid/expired token');
            return new WP_Error('invalid_token', 'Invalid token', ['status' => 401]);
        }

        global $wpdb;
        $p   = $wpdb->prefix;
        $tbl = "{$p}sm_credits";

        // 2) Validate inputs
        $module    = sanitize_key($req->get_param('module'));
        $amount    = max(1, (int) ($req->get_param('amount') ?? 1));
        $reference = sanitize_text_field($req->get_param('reference') ?? '');

        if ($module === '') {
            self::log($rid, 'bad_request: missing module', ['user_id' => (int)$user->id]);
            return new WP_Error('bad_request', 'Module is required', ['status' => 400]);
        }

        // 3) Start atomic section + advisory lock
        $lock_name = 'sm_credits_user_' . (int) $user->id;
        $lock_acquired = false;

        try {
            $wpdb->query('START TRANSACTION');
            self::log($rid, 'transaction started', ['user_id' => (int)$user->id, 'module' => $module, 'amount' => $amount]);

            $lock = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, 5));
            $lock_acquired = ($lock === '1' || $lock === 1);
            if (!$lock_acquired) {
                self::log($rid, 'GET_LOCK timeout', ['lock_name' => $lock_name]);
                $wpdb->query('ROLLBACK');
                return new WP_Error('lock_timeout', 'Please retry', ['status' => 503]);
            }

            // 4) Read balances
            $specific = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(credits_added)-SUM(credits_used),0)
                 FROM {$tbl}
                 WHERE user_id = %d AND module = %s",
                (int) $user->id,
                $module
            ));
            $global = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(credits_added)-SUM(credits_used),0)
                 FROM {$tbl}
                 WHERE user_id = %d AND module = %s",
                (int) $user->id,
                'global'
            ));
            $available = $specific + $global;

            self::log($rid, 'balances read', [
                'user_id'   => (int)$user->id,
                'module'    => $module,
                'specific'  => $specific,
                'global'    => $global,
                'available' => $available,
                'amount'    => $amount,
            ]);

            if ($available < $amount) {
                // Not enough credits: 402
                $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
                $lock_acquired = false;
                $wpdb->query('ROLLBACK');
                self::log($rid, 'insufficient_credits', [
                    'user_id'   => (int)$user->id,
                    'available' => $available,
                    'needed'    => $amount
                ]);
                return new WP_Error(
                    'insufficient_credits',
                    'Not enough credits',
                    ['status' => 402, 'available' => $available]
                );
            }

            // 5) Choose bucket & insert usage row
            $bucket = ($specific >= $amount) ? $module : 'global';

            $ok = $wpdb->insert($tbl, [
                'user_id'       => (int) $user->id,
                'email'         => $user->email ?? '',
                'module'        => $bucket,
                'credits_added' => 0,
                'credits_used'  => $amount,
                'source'        => 'api_use',
                'created_at'    => current_time('mysql'),
            ], [
                '%d',
                '%s',
                '%s',
                '%d',
                '%d',
                '%s',
                '%s'
            ]);

            if ($ok === false) {
                $dberr = $wpdb->last_error;
                $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
                $lock_acquired = false;
                $wpdb->query('ROLLBACK');
                self::log($rid, 'db_insert_failed', ['error' => $dberr]);
                return new WP_Error('db_error', 'Failed to deduct credits', ['status' => 500]);
            }

            // 6) Optional usage log
            if (method_exists(__CLASS__, 'record_usage_log')) {
                try {
                    self::record_usage_log((int) $user->id, $module, 'usage', $amount, $reference);
                } catch (\Throwable $e) {
                    self::log($rid, 'record_usage_log failed', ['err' => $e->getMessage()]);
                }
            }

            // 7) Commit & release lock
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
            $lock_acquired = false;
            $wpdb->query('COMMIT');

            $remaining = (int) ($available - $amount);
            self::log($rid, 'credit_deducted', [
                'user_id'           => (int)$user->id,
                'module_requested'  => $module,
                'module_used'       => $bucket,
                'amount'            => $amount,
                'credits_remaining' => $remaining,
                'reference'         => $reference
            ]);

            return rest_ensure_response([
                'success'           => true,
                'module_requested'  => $module,
                'module_used'       => $bucket,
                'amount'            => $amount,
                'credits_remaining' => $remaining,
            ]);
        } catch (\Throwable $e) {
            // Safety cleanup
            if ($lock_acquired) {
                $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
            }
            $wpdb->query('ROLLBACK');
            self::log($rid, 'server_error', ['err' => $e->getMessage()]);
            return new WP_Error('server_error', 'Unexpected error', ['status' => 500]);
        }
    }

    /**
     * GET /wp-json/{NS}/credits/balance
     * (Optional) Useful for debugging. Requires Authorization via permission_callback.
     */
    public static function rest_get_balance(WP_REST_Request $req)
    {
        $rid = substr(bin2hex(random_bytes(6)), 0, 8);

        try {
            $user = SM_Account::get_sm_user_from_jwt($req);
        } catch (\Throwable $e) {
            self::log($rid, 'get_sm_user_from_jwt threw', ['err' => $e->getMessage()]);
            return new WP_Error('invalid_token', 'Invalid token', ['status' => 401]);
        }
        if (!$user || empty($user->id)) {
            self::log($rid, 'invalid/expired token');
            return new WP_Error('invalid_token', 'Invalid token', ['status' => 401]);
        }

        global $wpdb;
        $p   = $wpdb->prefix;
        $tbl = "{$p}sm_credits";

        $module = sanitize_key($req->get_param('module')); // optional filter

        if ($module) {
            $specific = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(credits_added)-SUM(credits_used),0)
                 FROM {$tbl} WHERE user_id = %d AND module = %s",
                (int)$user->id,
                $module
            ));
            $global = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(credits_added)-SUM(credits_used),0)
                 FROM {$tbl} WHERE user_id = %d AND module = %s",
                (int)$user->id,
                'global'
            ));
            $resp = [
                'success'   => true,
                'user_id'   => (int)$user->id,
                'module'    => $module,
                'specific'  => $specific,
                'global'    => $global,
                'available' => $specific + $global,
            ];
            self::log($rid, 'balance', $resp);
            return rest_ensure_response($resp);
        } else {
            // All modules grouped
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT module,
                        COALESCE(SUM(credits_added)-SUM(credits_used),0) AS balance
                 FROM {$tbl} WHERE user_id = %d GROUP BY module",
                (int)$user->id
            ), ARRAY_A);

            $by_module = [];
            $available = 0;
            foreach ($rows as $r) {
                $m = $r['module'];
                $b = (int)$r['balance'];
                $by_module[$m] = $b;
                $available += ($m === 'global') ? 0 : $b; // convenience, but total by module is available + global
            }
            $resp = [
                'success'   => true,
                'user_id'   => (int)$user->id,
                'balances'  => $by_module,
            ];
            self::log($rid, 'balance_all', $resp);
            return rest_ensure_response($resp);
        }
    }


    /**
     * Insert a record into sm_credit_usage_log.
     *
     * @param int         $user_id
     * @param string      $module
     * @param string      $credit_type  e.g., 'usage' | 'grant'
     * @param int         $credits_used positive integer for usage; for grants you can pass 0 or omit
     * @param string|null $reference
     * @return bool
     */
    public static function record_usage_log($user_id, $module, $credit_type, $credits_used = 1, $reference = null)
    {
        global $wpdb;
        $p = $wpdb->prefix;

        $ok = $wpdb->insert("{$p}sm_credit_usage_log", [
            'user_id'      => (int) $user_id,
            'module'       => sanitize_key($module ?: 'global'),
            'credit_type'  => sanitize_key($credit_type ?: 'usage'),
            'credits_used' => max(0, (int) $credits_used),
            'reference'    => $reference ? sanitize_text_field($reference) : null,
            'used_at'      => current_time('mysql'),
        ]);

        return $ok !== false;
    }
}
