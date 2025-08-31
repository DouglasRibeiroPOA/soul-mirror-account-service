<?php
if (!defined('ABSPATH')) exit;

class SM_Register_Shortcode
{
    public static function init()
    {
        add_shortcode('sm_register_form', [__CLASS__, 'render_shortcode']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    public static function enqueue_assets()
    {
        // Only enqueue on pages that contain the shortcode
        global $post;
        if (!is_a($post, 'WP_Post') || !has_shortcode($post->post_content, 'sm_register_form')) {
            return;
        }

        // Enqueue CSS
        wp_enqueue_style(
            'sm-register-style',
            plugin_dir_url(__FILE__) . '../../assets/css/sm-register.css',
            array(),
            '1.0.0'
        );

        // Enqueue JS
        wp_enqueue_script(
            'sm-register-script',
            plugin_dir_url(__FILE__) . '../../assets/js/sm-register.js',
            array(),
            '1.0.0',
            true
        );

        // Localize script with AJAX URL and other parameters
        wp_localize_script('sm-register-script', 'sm_register_params', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'rest_url' => rest_url('soulmirror/v1/register'),
            'redirect_url' => home_url('/login') // Change to your login page URL
        ));
    }

    public static function render_shortcode($atts)
    {
        // Buffer output to return it
        ob_start();
        include plugin_dir_path(__FILE__) . '../../templates/sm-register-form.php';
        return ob_get_clean();
    }
}

SM_Register_Shortcode::init();