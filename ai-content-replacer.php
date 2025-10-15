<?php

/**
 * Plugin Name: ContentAISEO – AI Content Replacer
 * Description: Rewrite existing Elementor pages/posts and generate new AI blog posts. Connects to your Django backend.
 * Version: 1.0.0
 * Author: Ahmad Rohullah
 * Text Domain: ai-content-replacer
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */



if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Constants
 */
if ( ! defined( 'ACR_VERSION' ) )    define( 'ACR_VERSION', '1.1.0' );
if ( ! defined( 'ACR_PLUGIN_DIR' ) ) define( 'ACR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
if ( ! defined( 'ACR_PLUGIN_URL' ) ) define( 'ACR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * IMPORTANT: point the plugin to your Azure webapp (production server)
 * Example: https://djangosubscriptionpanel-app-e8cxfagthcf5emga.canadacentral-01.azurewebsites.net
 * Use HTTPS and no trailing slash.
 */
if ( ! defined( 'ACR_WEBAPP_URL' ) )
    // define( 'ACR_WEBAPP_URL', 'https://djangosubscriptionpanel-app-e8cxfagthcf5emga.canadacentral-01.azurewebsites.net');
    define( 'ACR_WEBAPP_URL', 'https://193edd64fb8d.ngrok-free.app/');

/**
 * Base API for your server (used by the remote client for content ops, if needed).
 * Usually same as webapp URL; keep it separate for flexibility.
 */
if ( ! defined( 'ACR_API_BASE' ) )
    define( 'ACR_API_BASE', ACR_WEBAPP_URL );

/**
 * Endpoint the Connect button will call to verify the API key.
 * This must match the Django route you added: path("api/key/verify/", verify_key, ...)
 */
if ( ! defined( 'ACR_API_VERIFY_URL' ) )
    define( 'ACR_API_VERIFY_URL', ACR_WEBAPP_URL . '/api/key/verify/' );

/**
 * Where the Subscribe button should send users (dashboard or signup on your server)
 * Change to '/accounts/signup/' if you prefer the signup page.
 */
if ( ! defined( 'ACR_SUBSCRIBE_URL' ) )
    define( 'ACR_SUBSCRIBE_URL', ACR_WEBAPP_URL . '/' );

/**
 * (Optional) If some older code uses CG_API_BASE, keep it aligned.
 */
if ( ! defined( 'CG_API_BASE' ) )
    define( 'CG_API_BASE', ACR_API_BASE );

/**
 * Includes (order matters)
 */
require_once ACR_PLUGIN_DIR . 'includes/class-acr-settings.php';
require_once ACR_PLUGIN_DIR . 'includes/class-acr-ai-integration.php';
require_once ACR_PLUGIN_DIR . 'includes/class-acr-elementor-content-processor.php';
require_once ACR_PLUGIN_DIR . 'includes/class-acr-blog-service.php';
require_once ACR_PLUGIN_DIR . 'includes/class-acr-remote-client.php';
require_once ACR_PLUGIN_DIR . 'class-acr-connect-screen.php';
require_once ACR_PLUGIN_DIR . 'admin/class-acr-admin.php';
require_once ACR_PLUGIN_DIR . 'includes/cg-server.php';

/**
 * Admin JS (if you need it)
 */
add_action('admin_enqueue_scripts', function($hook){
    wp_enqueue_script(
        'cg-admin',
        ACR_PLUGIN_URL . 'admin/js/cg-admin.js',
        ['jquery'],
        ACR_VERSION,
        true
    );
    wp_localize_script('cg-admin', 'cg', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('cg_nonce'),
    ]);
});

/**
 * Bootstrap
 */
function acr_run_plugin() {
    load_plugin_textdomain( 'ai-content-replacer', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

    $settings            = new ACR_Settings();
    $ai_integration      = new ACR_AI_Integration( $settings );
    $elementor_processor = new ACR_Elementor_Content_Processor( $ai_integration );
    $blog_service        = new ACR_Blog_Service( $settings );
    $remote_client       = new ACR_Remote_Client( ACR_API_BASE, ACR_VERSION );

    $admin = new ACR_Admin( $elementor_processor, $settings, $blog_service, $remote_client );
    $admin->run();
}
add_action( 'plugins_loaded', 'acr_run_plugin' );

/**
 * Activation / Deactivation
 */
function acr_activate_plugin() {
    add_option( 'acr_api_key', '' );
    add_option( 'acr_key_status', 'unknown' ); // active | invalid | revoked | unknown
    add_option( 'acr_tenant_id', '' );
    add_option( 'acr_last_check', 0 );
}
register_activation_hook( __FILE__, 'acr_activate_plugin' );

function acr_deactivate_plugin() { /* no-op */ }
register_deactivation_hook( __FILE__, 'acr_deactivate_plugin' );
