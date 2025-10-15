<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ACR_Elementor_Content_Processor {

    /** @var ACR_Remote_Client */
    private $remote;

    /**
     * Back-compat constructor: previously received ACR_AI_Integration.
     * We ignore that and initialize the remote client from settings,
     * unless a ready ACR_Remote_Client is passed in.
     *
     * @param mixed $maybe_remote Optional: pass an ACR_Remote_Client directly
     */
    public function __construct( $maybe_remote = null ) {
        if ( $maybe_remote instanceof ACR_Remote_Client ) {
            $this->remote = $maybe_remote;
        } else {
            $api_base = get_option( 'cg_api_base' );
            $plugin_version = defined('ACR_VERSION') ? ACR_VERSION : '1.0.0';
            $this->remote = new ACR_Remote_Client( $api_base, $plugin_version, true );
        }
    }

    /* ============================================================
     * Page & (legacy) blog direct apply path — now offloaded to server
     * ============================================================ */

    /**
     * Rewrites Elementor content for a post by sending the current tree to the server.
     * The server returns the UPDATED Elementor JSON which we save back.
     *
     * @param int    $post_id
     * @param string $user_prompt
     * @param array  $opts        (optional) e.g. ['model'=>'gpt-4o-mini','temperature'=>0.7,'allowlist'=>['heading','text-editor']]
     * @return array|WP_Error     { success: true, message: '...' }
     */
    
    
    
    




    
    
    
    
    
    
    
     public function process_elementor_content( $post_id, $user_prompt, $opts = array() ) {
        $post_id = (int) $post_id;
        $api_key = get_option('acr_api_key');
        if ( empty( $api_key ) ) {
            return new WP_Error( 'acr_key_missing', __( 'Connect your API key in plugin settings.', 'ai-content-replacer' ) );
        }

        // 1) Load current Elementor JSON
        $data = get_post_meta( $post_id, '_elementor_data', true );
        if ( is_string( $data ) ) { $data = json_decode( $data, true ); }
        if ( ! is_array( $data ) ) {
            return new WP_Error( 'invalid_elementor_data', __( 'Invalid Elementor data format.', 'ai-content-replacer' ) );
        }

        // 2) Build payload for server
        $options = is_array( $opts ) ? $opts : array();
        $options['dry_run'] = false; // explicitly apply changes
        $payload = array(
            'post_id'   => $post_id,
            'prompt'    => (string) $user_prompt,
            'options'   => $options,
            'site'      => home_url(),
            'elementor' => $data,
        );

        // 3) Send to server → returns { elementor: [...], (optional) dry_run_parts: [...] }
        $res = $this->remote->process_elementor( $api_key, $payload );




        if ( is_wp_error( $res ) ) return $res;

        if ( ! isset( $res['elementor'] ) || ! is_array( $res['elementor'] ) ) {
            return new WP_Error( 'acr_server_shape', __( 'Server did not return updated Elementor JSON.', 'ai-content-replacer' ) );
        }

        // 4) Save back
        $json = wp_json_encode( $res['elementor'] );
        if ( false === $json ) {
            return new WP_Error( 'save_error', __( 'Failed to encode updated Elementor data.', 'ai-content-replacer' ) );
        }
        update_post_meta( $post_id, '_elementor_data', wp_slash( $json ) );

        // 5) Clear Elementor cache (same as before)
        if ( class_exists( '\Elementor\Plugin' ) ) {
            try {
                \Elementor\Plugin::instance()->files_manager->clear_cache();
                \Elementor\Plugin::instance()->files_manager->clear_css( $post_id );
                \Elementor\Plugin::instance()->files_manager->regenerate_css( $post_id );
            } catch ( \Throwable $e ) { /* non-fatal */ }
        }

        return array( 'success' => true, 'message' => __( 'Content successfully replaced!', 'ai-content-replacer' ) );
    }




















    /* ============================================================
     * BLOG TABS: AI preview (NO SAVE) — offloaded to server (dry_run)
     * ============================================================ */

    /**
     * Builds a preview by asking the server to simulate rewrites (dry_run),
     * then concatenates the returned parts the same way your legacy code did.
     *
     * @param int    $post_id
     * @param string $user_prompt
     * @param array  $opts        optional: same knobs as process (model, temperature, allowlist, etc.)
     * @return array|WP_Error     { html: string, parts: array }
     */
    public function generate_preview( $post_id, $user_prompt, $opts = array() ) {
        $post_id = (int) $post_id;
        $api_key = get_option('acr_api_key');
        if ( empty( $api_key ) ) {
            return new WP_Error( 'acr_key_missing', __( 'Connect your API key in plugin settings.', 'ai-content-replacer' ) );
        }

        $data = get_post_meta( $post_id, '_elementor_data', true );
        if ( is_string( $data ) ) { $data = json_decode( $data, true ); }
        if ( ! is_array( $data ) ) {
            return new WP_Error( 'invalid_elementor_data', __( 'Elementor data is invalid.', 'ai-content-replacer' ) );
        }

        $options = is_array( $opts ) ? $opts : array();
        $options['dry_run'] = true; // preview only, do not write server-side

        $payload = array(
            'post_id'   => $post_id,
            'prompt'    => (string) $user_prompt,
            'options'   => $options,
            'site'      => home_url(),
            'elementor' => $data,
        );

        $res = $this->remote->process_elementor( $api_key, $payload );
        if ( is_wp_error( $res ) ) return $res;

        $parts = isset( $res['dry_run_parts'] ) && is_array( $res['dry_run_parts'] ) ? $res['dry_run_parts'] : array();

        // Build the same simple concatenated preview as before:
        $html = '';
        foreach ( $parts as $part ) {
            $before = isset($part['original_snippet']) ? (string) $part['original_snippet'] : ( isset($part['before']) ? (string)$part['before'] : '' );
            $after  = isset($part['after']) ? (string) $part['after'] : '';
            $candidate = $after !== '' ? $after : $before;
            if ( $candidate !== '' ) {
                $html .= $candidate . "\n\n";
            }
        }

        return array(
            'html'  => $html,
            'parts' => $parts,
        );
    }

    /* ============================================================
     * BLOG TABS: Apply edited WordPad HTML into Text Editor (unchanged)
     * ============================================================ */

    /**
     * Places provided HTML into the first matching text-editor widget and saves.
     *
     * @param int    $post_id
     * @param string $html
     * @return array|WP_Error  { success: true }
     */
    public function apply_simple_content( $post_id, $html ) {
        $data = get_post_meta( $post_id, '_elementor_data', true );
        if ( is_string( $data ) ) { $data = json_decode( $data, true ); }
        if ( ! is_array( $data ) ) {
            return new WP_Error( 'invalid_elementor_data', __( 'Elementor data is invalid.', 'ai-content-replacer' ) );
        }

        $place = function( &$elements, $html, &$placed ) use ( &$place ) {
            if ( ! is_array( $elements ) ) return;
            foreach ( $elements as &$el ) {
                if ( isset( $el['elType'] ) && $el['elType'] === 'widget' && isset( $el['widgetType'] ) ) {
                    if ( 'text-editor' === $el['widgetType'] ) {
                        $css_id = isset( $el['settings']['_css_id'] ) ? (string) $el['settings']['_css_id'] : '';
                        if ( 'text' === $css_id ) {
                            $el['settings']['editor'] = (string) $html;
                            $placed = true;
                            return;
                        }
                    }
                }
                if ( isset( $el['elements'] ) && is_array( $el['elements'] ) && ! $placed ) {
                    $place( $el['elements'], $html, $placed );
                }
                if ( $placed ) return;
            }
        };

        $placed = false;
        $place( $data, $html, $placed );

        if ( ! $placed ) {
            $fallback = function( &$elements, $html, &$placed ) use ( &$fallback ) {
                foreach ( $elements as &$el ) {
                    if ( isset( $el['elType'] ) && $el['elType'] === 'widget' && isset( $el['widgetType'] ) && 'text-editor' === $el['widgetType'] ) {
                        $el['settings']['editor'] = (string) $html;
                        $placed = true;
                        return;
                    }
                    if ( isset( $el['elements'] ) && is_array( $el['elements'] ) && ! $placed ) {
                        $fallback( $el['elements'], $html, $placed );
                    }
                    if ( $placed ) return;
                }
            };
            $fallback( $data, $html, $placed );
        }

        if ( ! $placed ) {
            return new WP_Error( 'no_text_widget', __( 'No text-editor widget found to place content.', 'ai-content-replacer' ) );
        }

        $json = wp_json_encode( $data );
        if ( false === $json ) {
            return new WP_Error( 'save_error', __( 'Failed to encode updated Elementor data.', 'ai-content-replacer' ) );
        }

        update_post_meta( $post_id, '_elementor_data', wp_slash( $json ) );
        if ( class_exists( '\Elementor\Plugin' ) ) {
            \Elementor\Plugin::instance()->files_manager->clear_cache();
        }
        return array( 'success' => true );
    }
}
