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
                (int) $user_id, $module
            ));
            $used = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(credits_used),0) FROM {$p}sm_credits WHERE user_id = %d AND module = %s",
                (int) $user_id, $module
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
     * REST: GET /credits/balance
     * Query param (optional): module
     */
    public static function rest_get_balance(WP_REST_Request $req)
    {
        $user = SM_Account::get_sm_user_from_jwt($req);
        if (!$user) {
            return new WP_Error('invalid_token', 'Invalid token', ['status' => 401]);
        }

        $module = $req->get_param('module');
        if (is_string($module) && $module !== '') {
            $balance = self::get_balance((int) $user->id, $module);
            return rest_ensure_response([
                'module'  => sanitize_key($module),
                'balance' => (int) $balance,
            ]);
        }

        return rest_ensure_response([
            'balances' => self::get_balance((int) $user->id, null),
        ]);
    }

    /**
     * REST: POST /credits/use
     * Body: { module: string, amount?: int = 1, reference?: string }
     *
     * Deducts from specific module first; if insufficient, falls back to 'global' bucket.
     * All wrapped in a DB transaction with row-level locking to prevent races.
     */
    public static function rest_use_credit(WP_REST_Request $req)
    {
        $user = SM_Account::get_sm_user_from_jwt($req);
        if (!$user) {
            return new WP_Error('invalid_token', 'Invalid token', ['status' => 401]);
        }

        global $wpdb;
        $p   = $wpdb->prefix;
        $tbl = "{$p}sm_credits";

        $module    = sanitize_key($req->get_param('module'));
        $amount    = max(1, (int) ($req->get_param('amount') ?? 1));
        $reference = sanitize_text_field($req->get_param('reference') ?? '');

        if ($module === '') {
            return new WP_Error('bad_request', 'Module is required', ['status' => 400]);
        }

        try {
            // Start atomic section
            $wpdb->query('START TRANSACTION');

            // Advisory lock (prevents race even when user has no rows yet)
            // If unavailable in your MySQL, you can omit this safely.
            $lock_name = $wpdb->prepare('sm_credits_user_%d', (int) $user->id);
            $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, 5));

            // Current specific balance
            $specific = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(credits_added)-SUM(credits_used),0)
                 FROM {$tbl}
                 WHERE user_id = %d AND module = %s",
                (int) $user->id, $module
            ));

            // Current global balance
            $global = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(credits_added)-SUM(credits_used),0)
                 FROM {$tbl}
                 WHERE user_id = %d AND module = %s",
                (int) $user->id, 'global'
            ));

            $available = $specific + $global;
            if ($available < $amount) {
                // Release advisory lock
                $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
                $wpdb->query('ROLLBACK');
                return new WP_Error(
                    'insufficient_credits',
                    'Not enough credits',
                    ['status' => 402, 'available' => $available]
                );
            }

            // Choose bucket: prefer specific, otherwise take from global
            $bucket = ($specific >= $amount) ? $module : 'global';

            $ok = $wpdb->insert($tbl, [
                'user_id'       => (int) $user->id,
                'email'         => $user->email,
                'module'        => $bucket,
                'credits_added' => 0,
                'credits_used'  => $amount,
                'source'        => 'api_use',
                'created_at'    => current_time('mysql'),
            ]);

            if ($ok === false) {
                // Release advisory lock
                $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
                $wpdb->query('ROLLBACK');
                return new WP_Error('db_error', 'Failed to deduct credits', ['status' => 500]);
            }

            // Log usage
            self::record_usage_log((int) $user->id, $module, 'usage', $amount, $reference);

            // Release advisory lock, commit
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
            $wpdb->query('COMMIT');

            return rest_ensure_response([
                'success'           => true,
                'module_requested'  => $module,
                'module_used'       => $bucket,
                'amount'            => $amount,
                'credits_remaining' => (int) ($available - $amount),
            ]);
        } catch (\Throwable $e) {
            // Best-effort cleanup
            $wpdb->query('ROLLBACK');
            return new WP_Error('server_error', 'Unexpected error: '.$e->getMessage(), ['status' => 500]);
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
