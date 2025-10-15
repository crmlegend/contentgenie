<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AJAX controller: bridges admin UI to the new server-backed classes.
 * Requires:
 * - ACR_Settings
 * - ACR_Remote_Client
 * - ACR_AI_Integration
 * - ACR_Blog_Service
 * - ACR_Elementor_Content_Processor
 */

// ========== Verify key from settings ==========
add_action( 'wp_ajax_cg_verify_key', function () {
  if ( ! current_user_can('manage_options') ) wp_send_json_error('forbidden', 403);

  $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
  if ( ! $api_key ) wp_send_json_error('missing_key', 400);

  $client = new ACR_Remote_Client( get_option('cg_api_base'), defined('ACR_VERSION') ? ACR_VERSION : '1.0.0', true );
  $res = $client->verify_key( $api_key );
  if ( is_wp_error( $res ) ) wp_send_json_error( $res->get_error_message(), 400 );

  update_option('acr_api_key', $api_key);
  update_option('acr_key_status', 'active');

  wp_send_json_success( $res );
} );

// ========== BLOG TAB: generate preview HTML ==========
add_action( 'wp_ajax_cg_blog_preview', function () {
  if ( ! current_user_can('edit_posts') ) wp_send_json_error('forbidden', 403);

  $args = array(
    'title'         => isset($_POST['title']) ? wp_unslash($_POST['title']) : '',
    'prompt'        => isset($_POST['prompt']) ? wp_unslash($_POST['prompt']) : '',
    'template_id'   => isset($_POST['template_id']) ? intval($_POST['template_id']) : 0,
    'category_id'   => isset($_POST['category_id']) ? intval($_POST['category_id']) : 0,
    'reference_ids' => isset($_POST['reference_ids']) ? array_map('intval', (array) $_POST['reference_ids']) : array(),
    'sitemap_url'   => isset($_POST['sitemap_url']) ? esc_url_raw($_POST['sitemap_url']) : '',
    'preferred_api' => isset($_POST['preferred_api']) ? sanitize_key($_POST['preferred_api']) : '',
    'model'         => isset($_POST['model']) ? sanitize_text_field($_POST['model']) : '',
    'temperature'   => isset($_POST['temperature']) ? floatval($_POST['temperature']) : null,
  );

  $settings = new ACR_Settings();
  $blog     = new ACR_Blog_Service( $settings );

  $out = $blog->generate_preview_html( $args );
  if ( is_wp_error( $out ) ) wp_send_json_error( $out->get_error_message(), 400 );

  wp_send_json_success( $out );
} );

// ========== BLOG TAB: create Elementor draft from preview HTML ==========
add_action( 'wp_ajax_cg_blog_create_post', function () {
  if ( ! current_user_can('edit_posts') ) wp_send_json_error('forbidden', 403);

  $args = array(
    'title'       => isset($_POST['title']) ? wp_unslash($_POST['title']) : '',
    'html'        => isset($_POST['html']) ? wp_kses_post( wp_unslash($_POST['html']) ) : '',
    'template_id' => isset($_POST['template_id']) ? intval($_POST['template_id']) : 0,
    'category_id' => isset($_POST['category_id']) ? intval($_POST['category_id']) : 0,
  );

  $settings = new ACR_Settings();
  $blog     = new ACR_Blog_Service( $settings );

  $res = $blog->create_elementor_post( $args );
  if ( is_wp_error( $res ) ) wp_send_json_error( $res->get_error_message(), 400 );

  wp_send_json_success( $res );
} );

// ========== REPLACER: preview (dry run) ==========
add_action( 'wp_ajax_cg_elementor_preview', function () {
  if ( ! current_user_can('edit_posts') ) wp_send_json_error('forbidden', 403);

  $post_id     = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
  $user_prompt = isset($_POST['prompt']) ? wp_unslash($_POST['prompt']) : '';
  $opts        = isset($_POST['options']) ? json_decode( wp_unslash($_POST['options']), true ) : array();

  $processor = new ACR_Elementor_Content_Processor();
  $out = $processor->generate_preview( $post_id, $user_prompt, is_array($opts) ? $opts : array() );
  if ( is_wp_error( $out ) ) wp_send_json_error( $out->get_error_message(), 400 );

  wp_send_json_success( $out );
} );

// ========== REPLACER: apply (save) ==========
add_action( 'wp_ajax_cg_elementor_apply', function () {
  if ( ! current_user_can('edit_posts') ) wp_send_json_error('forbidden', 403);

  $post_id     = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
  $user_prompt = isset($_POST['prompt']) ? wp_unslash($_POST['prompt']) : '';
  $opts        = isset($_POST['options']) ? json_decode( wp_unslash($_POST['options']), true ) : array();

  $processor = new ACR_Elementor_Content_Processor();
  $out = $processor->process_elementor_content( $post_id, $user_prompt, is_array($opts) ? $opts : array() );
  if ( is_wp_error( $out ) ) wp_send_json_error( $out->get_error_message(), 400 );

  wp_send_json_success( $out );
} );

// ========== BLOG TAB: optional simple mode (no Elementor) ==========
add_action( 'wp_ajax_cg_blog_create_simple', function () {
  if ( ! current_user_can('edit_posts') ) wp_send_json_error('forbidden', 403);

  $args = array(
    'title'       => isset($_POST['title']) ? wp_unslash($_POST['title']) : '',
    'content'     => isset($_POST['content']) ? wp_kses_post( wp_unslash($_POST['content']) ) : '',
    'category_id' => isset($_POST['category_id']) ? intval($_POST['category_id']) : 0,
  );

  $settings = new ACR_Settings();
  $blog     = new ACR_Blog_Service( $settings );

  $res = $blog->create_post_simple( $args );
  if ( is_wp_error( $res ) ) wp_send_json_error( $res->get_error_message(), 400 );

  wp_send_json_success( $res );
} );
// ========== QUICK GENERATE (connect screen) ==========
add_action('wp_ajax_cg_generate', function () {
  // Only logged-in users who can edit posts
  if ( ! current_user_can('edit_posts') ) {
      wp_send_json_error(['message' => 'Forbidden'], 403);
  }

  // (Optional) nonce check if your JS sends one
  // if ( isset($_POST['_wpnonce']) ) {
  //     check_ajax_referer('cg_nonce', '_wpnonce');
  // }

  // Read prompt from JS
  $prompt = isset($_POST['prompt']) ? wp_unslash($_POST['prompt']) : '';
  if ( $prompt === '' ) {
      wp_send_json_error(['message' => 'Prompt is required'], 400);
  }

  // Use your existing classes to call the server
  $settings = new ACR_Settings();
  $client   = new ACR_Remote_Client(
      defined('ACR_API_BASE') ? ACR_API_BASE : '',
      defined('ACR_VERSION') ? ACR_VERSION : '1.0.0'
  );
  $ai = new ACR_AI_Integration( $settings, $client );

  // Quick generate behaves like "replacer" mode: return plain text
  $text = $ai->generate_content( $prompt, [ 'mode' => 'replacer' ] );
  if ( is_wp_error($text) ) {
      wp_send_json_error(['message' => $text->get_error_message()], 500);
  }

  // Send back both a simple HTML preview and the raw text
  wp_send_json_success([
      'html' => nl2br( esc_html( $text ) ),
      'raw'  => $text,
  ]);
});
