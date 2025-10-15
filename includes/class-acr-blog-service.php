<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ACR_Blog_Service (Server-backed)
 *
 * Adapter for the "AI Blog Post Creator" tab.
 * - Now delegates preview building to your FastAPI endpoint /v1/blog/preview.
 *
 * Filters (unchanged):
 *  - 'acr/blog/generate_preview_html'     : (string) $html, array $args
 *  - 'acr/blog/elementor_data_from_html'  : (array) $elementor_data_array, array $args
 */
class ACR_Blog_Service {

    /** @var ACR_Settings */
    private $settings;

    /** @var ACR_Remote_Client */
    private $remote;

    public function __construct( ACR_Settings $settings, $remote_client = null ) {
        $this->settings = $settings;

        if ( $remote_client instanceof ACR_Remote_Client ) {
            $this->remote = $remote_client;
        } else {
            $api_base = get_option( 'cg_api_base' );
            $plugin_version = defined('ACR_VERSION') ? ACR_VERSION : '1.0.0';
            $this->remote = new ACR_Remote_Client( $api_base, $plugin_version, true );
        }
    }

    /* ============================================================
     * Public API
     * ============================================================ */

    /**
     * Build an HTML preview for the blog body.
     * NEW: Offloads to the server’s /v1/blog/preview.
     *
     * $args:
     *  - title         (string)
     *  - prompt        (string)       REQUIRED for AI
     *  - template_id   (int)          optional
     *  - category_id   (int)          optional
     *  - reference_ids (array<int>)   optional (style-matching)
     *  - sitemap_url   (string)       optional (server should fetch/parse)
     *  - preferred_api (string)       optional ('gemini'|'openai')
     *  - model         (string)       optional (forwarded to server)
     *  - temperature   (float)        optional (forwarded to server)
     *
     * @param array $args
     * @return array{html:string,title:string}|WP_Error
     */
    public function generate_preview_html( array $args ) {
        $title         = isset( $args['title'] )        ? sanitize_text_field( wp_unslash( $args['title'] ) ) : '';
        $prompt        = isset( $args['prompt'] )       ? sanitize_textarea_field( wp_unslash( $args['prompt'] ) ) : '';
        $reference_ids = isset( $args['reference_ids'] ) && is_array( $args['reference_ids'] ) ? array_map( 'intval', $args['reference_ids'] ) : array();
        $sitemap_url   = isset( $args['sitemap_url'] )  ? esc_url_raw( $args['sitemap_url'] ) : '';
        $preferred_api = isset( $args['preferred_api'] ) ? sanitize_key( $args['preferred_api'] ) : '';
        $model         = isset( $args['model'] )        ? sanitize_text_field( $args['model'] ) : '';
        $temperature   = isset( $args['temperature'] )  ? floatval( $args['temperature'] ) : null;

        // (0) require key
        $api_key = get_option('acr_api_key');
        if ( empty( $api_key ) ) {
            return new WP_Error( 'acr_key_missing', __( 'Connect your API key in plugin settings.', 'ai-content-replacer' ) );
        }

        // (1) Allow complete override via filter (kept intact)
        $html_override = apply_filters( 'acr/blog/generate_preview_html', '', $args );
        if ( is_string( $html_override ) && '' !== trim( $html_override ) ) {
            $final_title = ( '' !== $title ) ? $title : __( 'New Blog Post', 'ai-content-replacer' );
            return array( 'html' => $html_override, 'title' => $final_title );
        }

        // (2) Must have a prompt to ask the server for AI preview
        if ( '' === $prompt ) {
            $final_title = ( '' !== $title ) ? $title : __( 'New Blog Post', 'ai-content-replacer' );
            $html        = $this->fallback_preview_html( $final_title, '' );
            return array( 'html' => $html, 'title' => $final_title );
        }

        // (3) Optional: collect style references locally and pass as plain text for the server to use
        $reference_content = '';
        if ( ! empty( $reference_ids ) ) {
            foreach ( $reference_ids as $pid ) {
                $page = get_post( $pid );
                if ( $page ) {
                    $reference_content .= "Example Title: " . get_the_title( $pid ) . "\n";
                    $reference_content .= wp_strip_all_tags( (string) $page->post_content ) . "\n\n---\n\n";
                }
            }
        }

        // (4) Build payload for server
        $options = array(
            'provider'        => $preferred_api ?: ( method_exists( $this->settings, 'get_preferred_api' ) ? $this->settings->get_preferred_api() : '' ),
            'model'           => $model ?: ( method_exists( $this->settings, 'get_default_model' ) ? $this->settings->get_default_model() : '' ),
            'temperature'     => ( $temperature !== null ) ? $temperature : ( method_exists( $this->settings, 'get_default_temperature' ) ? $this->settings->get_default_temperature() : 0.7 ),
            'sitemap_url'     => $sitemap_url,         // let the server fetch/parse
            'reference_text'  => $reference_content,   // optional; helps style matching
            // you can forward any other knobs here (tone, max_words, etc.)
        );

        $payload = array(
            'prompt'  => (string) $prompt,
            'options' => $options,
            'site'    => home_url(),
        );

        // (5) Call server: /v1/blog/preview → { html: "...", (optional) title: "..." }
        $res = $this->remote->blog_preview( $api_key, $payload );
        if ( is_wp_error( $res ) ) return $res;

        $html_from_server  = isset( $res['html'] )  ? (string) $res['html']  : '';
        $title_from_server = isset( $res['title'] ) ? (string) $res['title'] : '';

        if ( $html_from_server === '' ) {
            return new WP_Error( 'acr_server_shape', __( 'Server did not return preview HTML.', 'ai-content-replacer' ) );
        }

        // Prefer server title; otherwise fall back to provided title or default.
        $final_title = $title_from_server !== '' ? $title_from_server : ( $title !== '' ? $title : __( 'New Blog Post', 'ai-content-replacer' ) );

        return array( 'html' => $html_from_server, 'title' => $final_title );
    }

    /**
     * Create an Elementor "post" draft and set its content from HTML.
     * (Unchanged)
     *
     * @param array $args
     * @return array{post_id:int}|WP_Error
     */
    public function create_elementor_post( array $args ) {
        $title       = isset( $args['title'] )       ? sanitize_text_field( wp_unslash( $args['title'] ) ) : '';
        $html        = isset( $args['html'] )        ? wp_kses_post( wp_unslash( $args['html'] ) ) : '';
        $template_id = isset( $args['template_id'] ) ? (int) $args['template_id'] : 0;
        $category_id = isset( $args['category_id'] ) ? (int) $args['category_id'] : 0;

        if ( '' === $title || '' === $html ) {
            return new WP_Error( 'acr_missing_fields', __( 'Both title and HTML are required.', 'ai-content-replacer' ) );
        }

        if ( ! class_exists( '\Elementor\Plugin' ) ) {
            return new WP_Error( 'acr_no_elementor', __( 'Elementor is required to create the post.', 'ai-content-replacer' ) );
        }

        // Create draft
        $postarr = array(
            'post_type'      => 'post',
            'post_status'    => 'draft',
            'post_title'     => $title,
            'post_name'      => sanitize_title( $title ),
            'post_content'   => '',
            'post_author'    => get_current_user_id(),
            'comment_status' => get_default_comment_status( 'post' ),
            'ping_status'    => get_default_comment_status( 'post', 'pingback' ),
        );
        $post_id = wp_insert_post( $postarr, true );
        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        if ( $category_id > 0 && term_exists( (int) $category_id, 'category' ) ) {
            wp_set_post_terms( $post_id, array( (int) $category_id ), 'category', false );
        }

        // Build Elementor data (from template or from HTML)
        if ( $template_id && $this->is_elementor_document( $template_id ) ) {
            $elementor_data = $this->load_elementor_data( $template_id );
            if ( empty( $elementor_data ) ) {
                $elementor_data = $this->build_elementor_data_from_html( $html, $args );
            } else {
                $elementor_data = $this->replace_first_text_widget_html( $elementor_data, $html );
            }
        } else {
            $elementor_data = $this->build_elementor_data_from_html( $html, $args );
        }

        $elementor_data = apply_filters( 'acr/blog/elementor_data_from_html', $elementor_data, $args );

        // Save
        $json = wp_json_encode( $elementor_data );
        update_post_meta( $post_id, '_elementor_data', wp_slash( $json ) );
        update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );

        if ( $template_id ) {
            $this->copy_basic_elementor_meta( $template_id, $post_id );
        } else {
            // Version-safe way to set _elementor_version meta
            $ver = defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '';
            if ( ! $ver && class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance ) && method_exists( \Elementor\Plugin::$instance, 'get_version' ) ) {
                $ver = \Elementor\Plugin::$instance->get_version();
            }
            if ( $ver ) {
                update_post_meta( $post_id, '_elementor_version', $ver );
            }
        }

        $this->regenerate_elementor_css( $post_id );

        return array( 'post_id' => (int) $post_id );
    }

    /**
     * Simple Mode: create a plain WP blog post (no Elementor).
     * (Unchanged)
     *
     * @param array $args keys: title, content, category_id
     * @return array{post_id:int}|WP_Error
     */
    public function create_post_simple( array $args ) {
        $title       = isset( $args['title'] )       ? sanitize_text_field( wp_unslash( $args['title'] ) ) : '';
        $content     = isset( $args['content'] )     ? wp_kses_post( wp_unslash( $args['content'] ) ) : '';
        $category_id = isset( $args['category_id'] ) ? (int) $args['category_id'] : 0;

        if ( '' === $title || '' === $content ) {
            return new WP_Error( 'acr_missing_fields', __( 'Both title and content are required.', 'ai-content-replacer' ) );
        }

        $post_id = wp_insert_post( array(
            'post_type'      => 'post',
            'post_status'    => 'draft',
            'post_title'     => $title,
            'post_name'      => sanitize_title( $title ),
            'post_content'   => $content,
            'post_author'    => get_current_user_id(),
            'comment_status' => get_default_comment_status( 'post' ),
            'ping_status'    => get_default_comment_status( 'post', 'pingback' ),
            'post_category'  => $category_id ? array( $category_id ) : array(),
        ), true );

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        return array( 'post_id' => (int) $post_id );
    }

    /* ============================================================
     * Fallback + Elementor helpers (Unchanged)
     * ============================================================ */

    private function fallback_preview_html( $title, $prompt ) {
        $title  = $title ?: __( 'New Blog Post', 'ai-content-replacer' );
        $prompt = $prompt ? '<p><em>' . esc_html( $prompt ) . '</em></p>' : '';
        return '<article><h1>' . esc_html( $title ) . "</h1>\n"
             . $prompt
             . "<h2>" . esc_html__( 'Introduction', 'ai-content-replacer' ) . "</h2>\n<p>"
             . esc_html__( 'Write your introduction here…', 'ai-content-replacer' ) . "</p>\n"
             . "<h2>" . esc_html__( 'Main Points', 'ai-content-replacer' ) . "</h2>\n<ul><li>"
             . esc_html__( 'Point 1', 'ai-content-replacer' ) . "</li><li>"
             . esc_html__( 'Point 2', 'ai-content-replacer' ) . "</li></ul>\n"
             . "<h2>" . esc_html__( 'Conclusion', 'ai-content-replacer' ) . "</h2>\n<p>"
             . esc_html__( 'Wrap up your article…', 'ai-content-replacer' ) . "</p></article>";
    }

    private function is_elementor_document( $post_id ) {
        return (bool) get_post_meta( (int) $post_id, '_elementor_edit_mode', true );
    }

    private function load_elementor_data( $post_id ) {
        $raw = get_post_meta( (int) $post_id, '_elementor_data', true );
        if ( empty( $raw ) ) { return array(); }
        if ( is_array( $raw ) ) { return $raw; }
        $maybe = maybe_unserialize( $raw );
        if ( is_array( $maybe ) ) { return $maybe; }
        $decoded = json_decode( (string) $raw, true );
        return is_array( $decoded ) ? $decoded : array();
    }

    private function replace_first_text_widget_html( array $data, $html ) {
        $found = false;

        $walk = function( &$nodes ) use ( &$walk, $html, &$found ) {
            if ( ! is_array( $nodes ) ) return;
            foreach ( $nodes as &$node ) {
                if ( isset( $node['elType'], $node['widgetType'] )
                     && 'widget' === $node['elType']
                     && 'text-editor' === $node['widgetType'] ) {
                    if ( ! isset( $node['settings'] ) || ! is_array( $node['settings'] ) ) {
                        $node['settings'] = array();
                    }
                    $node['settings']['editor'] = $html;
                    $found = true;
                    return;
                }
                if ( isset( $node['elements'] ) && is_array( $node['elements'] ) ) {
                    $walk( $node['elements'] );
                    if ( $found ) return;
                }
            }
        };

        $walk( $data );

        if ( ! $found ) {
            $data[] = $this->elementor_section_with_text_widget( $html );
        }

        return $data;
    }

    private function build_elementor_data_from_html( $html, array $args ) {
        return array( $this->elementor_section_with_text_widget( $html ) );
    }

    private function elementor_section_with_text_widget( $html ) {
        $section_id = $this->uuid();
        $column_id  = $this->uuid();
        $widget_id  = $this->uuid();

        return array(
            'id'       => $section_id,
            'elType'   => 'section',
            'settings' => array(),
            'elements' => array(
                array(
                    'id'       => $column_id,
                    'elType'   => 'column',
                    'settings' => array( '_column_size' => 100 ),
                    'elements' => array(
                        array(
                            'id'         => $widget_id,
                            'elType'     => 'widget',
                            'widgetType' => 'text-editor',
                            'settings'   => array( 'editor' => $html ),
                            'elements'   => array(),
                            'isInner'    => false,
                        ),
                    ),
                    'isInner'  => false,
                ),
            ),
            'isInner'  => false,
        );
    }

    private function copy_basic_elementor_meta( $from_id, $to_id ) {
        $keys = array(
            '_elementor_version',
            '_wp_page_template',
            '_elementor_template_type',
        );
        foreach ( $keys as $k ) {
            $v = get_post_meta( (int) $from_id, $k, true );
            if ( '' !== $v && null !== $v ) {
                update_post_meta( (int) $to_id, $k, $v );
            }
        }
    }

    private function regenerate_elementor_css( $post_id ) {
        if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance ) ) {
            try {
                \Elementor\Plugin::$instance->files_manager->clear_cache();
                \Elementor\Plugin::$instance->files_manager->clear_css( (int) $post_id );
                \Elementor\Plugin::$instance->files_manager->regenerate_css( (int) $post_id );

                $doc_obj = \Elementor\Plugin::$instance->documents->get( (int) $post_id );
                if ( $doc_obj ) { $doc_obj->save( array() ); }
            } catch ( \Throwable $e ) { /* non-fatal */ }
        }
    }

    /** Simple random-ish id for Elementor nodes. */
    private function uuid() {
        return substr( wp_hash( uniqid( (string) mt_rand(), true ) ), 0, 12 );
    }
}
