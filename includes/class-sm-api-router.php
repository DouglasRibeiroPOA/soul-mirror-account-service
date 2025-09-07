<?php
if (! defined('ABSPATH')) exit;

class SM_API_Router
{

    public function register_routes()
    {

        // Quick register (can live in your existing rest_api_init closure)
        register_rest_route(SM_API_NAMESPACE, '/session', [
            'methods'  => 'GET',
            'callback' => ['SM_Account', 'handle_session'],
            'permission_callback' => '__return_true', // reads WP cookie only
        ]);

        // ——— User Registration ———
        register_rest_route(SM_API_NAMESPACE, '/register', [
            'methods'             => 'POST',
            'callback'            => ['SM_Account', 'handle_register'],
            'permission_callback' => '__return_true',
        ]);

        // --- AUTH ROUTES ---
        register_rest_route(SM_API_NAMESPACE, '/login', [
            'methods'             => 'POST',
            'callback'            => ['SM_Account', 'handle_login'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(SM_API_NAMESPACE, '/google-login', [
            'methods'             => 'POST',
            'callback'            => ['SM_Account', 'handle_google_login'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(SM_API_NAMESPACE, '/me', [
            'methods'             => 'GET',
            'callback'            => ['SM_Account', 'get_user_info'],
            'permission_callback' => ['SM_Account', 'verify_jwt'],
        ]);

        // --- CREDIT ROUTES ---
        register_rest_route(SM_API_NAMESPACE, '/credits/balance', [
            'methods'             => 'GET',
            'callback'            => ['SM_Credit_Controller', 'rest_get_balance'],
            'permission_callback' => ['SM_Account', 'verify_jwt'],
        ]);
        register_rest_route(SM_API_NAMESPACE, '/credits/use', [
            'methods'  => 'POST',
            'callback' => ['SM_Credit_Controller', 'rest_use_credit'],
            'permission_callback' => ['SM_Account', 'verify_jwt'],
            'args' => [
                'module' => ['required' => true, 'type' => 'string'],
                'amount' => ['required' => false, 'type' => 'integer', 'default' => 1],
            ],
        ]);
    }
}
