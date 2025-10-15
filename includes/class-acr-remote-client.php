<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class ACR_Remote_Client {
  private $base;
  private $version;
  private $debug;

  public function __construct( $api_base, $plugin_version, $debug = true ) {
    $this->base    = untrailingslashit( $api_base );
    $this->version = $plugin_version;
    $this->debug   = (bool) $debug;
  }

  /* ------------------------
   * Internals / helpers
   * ------------------------ */
  private function log( $msg ) {
    if ( $this->debug ) error_log( '[ACR Remote] ' . $msg );
  }

  private function base_url() {
    return untrailingslashit( $this->base ?: ( defined('ACR_API_BASE') ? ACR_API_BASE : '' ) );
  }

  /**
   * Read provider API keys from plugin settings (or constants) and
   * attach them as headers so the server can use them.
   */
  private function provider_headers() {
    // Try options first (your settings page should save to these).
    // If you already store them under different option names, adjust here.
    $openai = get_option('acr_openai_api_key');
    $gemini = get_option('acr_gemini_api_key');

    // Allow hardcoding via constants if you want
    if ( empty( $openai ) && defined('ACR_OPENAI_API_KEY') ) {
      $openai = ACR_OPENAI_API_KEY;
    }
    if ( empty( $gemini ) && defined('ACR_GEMINI_API_KEY') ) {
      $gemini = ACR_GEMINI_API_KEY;
    }

    $h = array();
    if ( ! empty( $openai ) ) {
      $h['X-OpenAI-Key'] = trim( (string) $openai );
    }
    if ( ! empty( $gemini ) ) {
      $h['X-Gemini-Key'] = trim( (string) $gemini );
    }
    return $h;
  }

  private function do_post( $url, $api_key, $payload, $timeout = 60 ) {
    $headers = array_merge(
      [
        'Authorization' => 'Bearer ' . trim( (string) $api_key ),
        'Content-Type'  => 'application/json',
        'Accept'        => 'application/json',
      ],
      $this->provider_headers()
    );

    $this->log( 'POST ' . $url );
    $res = wp_remote_post( $url, [
      'timeout' => (int) $timeout,
      'headers' => $headers,
      'body'    => wp_json_encode( (array) $payload ),
    ] );

    if ( is_wp_error( $res ) ) {
      $this->log( 'WP_Error: ' . $res->get_error_message() );
      return $res;
    }

    $code     = (int) wp_remote_retrieve_response_code( $res );
    $body_raw = (string) wp_remote_retrieve_body( $res );

    $this->log( 'HTTP ' . $code );
    $this->log( 'Body: ' . $body_raw );

    if ( $code === 401 || $code === 403 ) {
      return new WP_Error( 'acr_invalid_key', 'API key invalid (HTTP ' . $code . ')' );
    }
    // if ( $code !== 200 ) {
    //   return new WP_Error( 'acr_server', 'Server error (HTTP ' . $code . ')' );
    // }


    if ( $code !== 200 ) {
      // Try to surface server error details
      $err = 'Server error (HTTP ' . $code . ')';
      $decoded = json_decode( $body_raw, true );
      if ( is_array( $decoded ) ) {
        if ( ! empty( $decoded['detail'] ) && is_string( $decoded['detail'] ) ) {
          $err .= ': ' . $decoded['detail'];
        } elseif ( ! empty( $decoded['message'] ) && is_string( $decoded['message'] ) ) {
          $err .= ': ' . $decoded['message'];
        }
      } elseif ( is_string( $body_raw ) && $body_raw !== '' ) {
        $err .= ': ' . $body_raw;
      }
      return new WP_Error( 'acr_server', $err );
    }
    $json = json_decode( $body_raw, true );
    if ( ! is_array( $json ) ) {
      return new WP_Error( 'acr_parse', 'Invalid JSON from server.' );
    }
    return $json;
  }

  /* ------------------------
   * Public API
   * ------------------------ */

   public function verify_key( $raw_key ) {
    $raw_key = trim( (string) $raw_key );

    // Build endpoint URL
    if ( defined('ACR_API_VERIFY_URL') && ACR_API_VERIFY_URL ) {
        $url = ACR_API_VERIFY_URL;
    } else {
        $base = $this->base_url();
        if ( empty( $base ) ) {
            return new WP_Error( 'acr_config', 'API base URL not configured.' );
        }
        $url = untrailingslashit( $base ) . '/api/key/verify/';
    }

    // The verify endpoint expects the key in the body (no Authorization header)
    $res = wp_remote_post( $url, array(
        'timeout'   => 15,
        'headers'   => array(
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ),
        'body'      => wp_json_encode( array( 'key' => $raw_key ) ),
        'sslverify' => true,
    ) );

    if ( is_wp_error( $res ) ) {
        return $res;
    }

    $code = (int) wp_remote_retrieve_response_code( $res );
    $body = (string) wp_remote_retrieve_body( $res );

    if ( 200 !== $code ) {
        return new WP_Error( 'acr_verify_failed', 'Verification failed (HTTP ' . $code . ')' );
    }

    $json = json_decode( $body, true );
    if ( ! is_array( $json ) ) {
        return new WP_Error( 'acr_parse', 'Invalid JSON from verify endpoint.' );
    }

    // Expect: { ok: true/false, plan: "...", key_prefix: "..." }
    return $json;
}



  public function generate( $api_key, array $payload ) {
    $api_key = trim( (string) $api_key );
    $base = $this->base_url();
    if ( empty( $base ) ) {
      return new WP_Error( 'acr_config', 'API base URL not configured.' );
    }
    $url = $base . '/v1/generate/content';
    return $this->do_post( $url, $api_key, (array) $payload, 120 );
  }



  public function blog_preview( $api_key, array $payload ) {
    $api_key = trim( (string) $api_key );
    $base = $this->base_url();
    if ( empty( $base ) ) {
      return new WP_Error( 'acr_config', 'API base URL not configured.' );
    }
    $url = $base . '/v1/blog/preview';
    $json = $this->do_post( $url, $api_key, $payload, 120 );
    if ( is_wp_error( $json ) ) return $json;
    if ( ! isset( $json['html'] ) ) {
      return new WP_Error( 'acr_server_shape', 'Server did not return preview HTML.' );
    }
    return $json;
  }

  public function process_elementor( $api_key, array $payload ) {
    $api_key = trim( (string) $api_key );
    $base = $this->base_url();
    if ( empty( $base ) ) {
      return new WP_Error( 'acr_config', 'API base URL not configured.' );
    }
    $url = $base . '/v1/generate/content';
    $json = $this->do_post( $url, $api_key, $payload, 180 );
    if ( is_wp_error( $json ) ) return $json;
    if ( ! isset( $json['elementor'] ) || ! is_array( $json['elementor'] ) ) {
      return new WP_Error( 'acr_server_shape', 'Server did not return updated Elementor JSON.' );
    }
    return $json;
  }
}
