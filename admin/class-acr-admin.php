<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ACR_Admin {

    private $elementor_processor;
    private $settings;

    /**
     * Adapter that wraps the second plugin’s blog-creation logic.
     * Should expose:
     *  - generate_preview_html( [ title, prompt, template_id, category_id, sitemap_url, preferred_api ] )
     *  - create_elementor_post( [ title, html, template_id, category_id ] )
     *  - (optional) create_post_simple( [ title, content, category_id ] )
     */
    private $blog_service; // instance of ACR_Blog_Service (or compatible)

    /** NEW: Remote client (talks to FastAPI: /v1/keys/verify, /v1/generate/content) */
    private $remote;

    public function __construct(
        ACR_Elementor_Content_Processor $elementor_processor,
        ACR_Settings $settings,
        $blog_service = null,
        $remote_client = null
    )  {
        $this->elementor_processor = $elementor_processor;
        $this->settings            = $settings;
        $this->blog_service        = $blog_service; // can be null until wired in bootstrap
        $this->remote              = $remote_client; // NEW
    }

    public function run() {
        add_action( 'admin_menu', array( $this, 'add_plugin_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

        // Handle Connect form submit (API key entry)
        add_action( 'admin_init', array( $this, 'maybe_handle_connect' ) ); // NEW
        add_action( 'admin_init', array( $this, 'maybe_handle_connect' ) ); // NEW
        add_action('load-toplevel_page_ai-content-replacer', array($this,'maybe_reverify'));
        add_action('load-ai-content-replacer_page_ai-content-replacer-settings', array($this,'maybe_reverify'));

        // Success popup HTML in footer (used by both tabs)
        add_action( 'admin_footer', array( $this, 'print_success_popup' ) );

        // ===== Existing Genie / rewrite routes (Tab #1) =====
        add_action( 'wp_ajax_acr_get_elementor_pages', array( $this, 'ajax_get_elementor_pages' ) );
        add_action( 'wp_ajax_acr_process_page_content', array( $this, 'ajax_process_page_content' ) );

        add_action( 'wp_ajax_acr_get_blog_posts', array( $this, 'ajax_get_blog_posts' ) );
        add_action( 'wp_ajax_acr_process_blog_content', array( $this, 'ajax_process_blog_content' ) );

        add_action( 'wp_ajax_acr_duplicate_and_rename', array( $this, 'ajax_duplicate_and_rename' ) );

        // Blog rewrite tab (AI preview + apply WordPad)
        add_action( 'wp_ajax_acr_generate_ai_preview', array( $this, 'ajax_generate_ai_preview' ) );
        add_action( 'wp_ajax_acr_apply_simple_update', array( $this, 'ajax_apply_simple_update' ) );

        // ===== Blog Creator routes (Tab #2) =====
        // Helpers
        add_action( 'wp_ajax_acr2_get_templates',  array( $this, 'ajax_blog_get_templates' ) );
        add_action( 'wp_ajax_acr2_get_categories', array( $this, 'ajax_blog_get_categories' ) );

        // Preview & Create — register BOTH “v1” and “v2” aliases to avoid JS mismatch errors.
        add_action( 'wp_ajax_acr_blog_generate_ai', array( $this, 'ajax_blog_generate_ai' ) );
        add_action( 'wp_ajax_acr2_blog_generate_ai', array( $this, 'ajax_blog_generate_ai' ) );

        add_action( 'wp_ajax_acr_blog_create_post', array( $this, 'ajax_blog_create_post' ) );
        add_action( 'wp_ajax_acr2_blog_create_post', array( $this, 'ajax_blog_create_post' ) );
    }

    /** ---------------- Admin Menu ---------------- */
    public function add_plugin_admin_menu() {
        // SVG icon
        $icon_svg = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20"><path d="M14.46,15.14C14.32,15.63,14,16.3,14,17a2,2,0,0,1-2,2,2,2,0,0,1-2-2c0-0.72.33-1.39.46-1.86C8.28,14.5,6,12.44,6,9.19,6,5.22,9.14,2,13,2s7,3.22,7,7.19C20,12.44,17.72,14.5,15.54,15.14M13,20a2,2,0,0,0,2-2,2,2,0,0,0-2-2,2,2,0,0,0-2,2,2,2,0,0,0,2,2M4,19.5a2.5,2.5,0,0,1,0-5,2.5,2.5,0,0,1,0,5M13,0C8,0,4,4,4,9.19c0,1.5.41,2.88,1.13,4.06C3.33,14.34,2,16.5,2,19a4.5,4.5,0,0,0,4.5,4.5A4.5,4.5,0,0,0,11,19c0-2.5-1.33-4.66-3.13-5.75A9.7,9.7,0,0,0,9,9.19C9,6.33,10.79,4,13,4s4,2.33,4,5.19a9.7,9.7,0,0,0-1.13,4.06C14.33,14.34,13,16.5,13,19a4.5,4.5,0,0,0,4.5,4.5A4.5,4.5,0,0,0,22,19c0-2.5-1.33-4.66-3.13-5.75C19.59,12.07,20,10.7,20,9.19,20,4,18,0,13,0Z"/></svg>');
        add_menu_page(
            __( 'ContentAISEO', 'content-genie' ),
            __( 'ContentAISEO', 'content-genie' ),
            'manage_options',
            'ai-content-replacer',
            array( $this, 'display_admin_page' ),
            $icon_svg,
            6
        );

        add_submenu_page(
            'ai-content-replacer',
            __( 'AI Settings', 'content-genie' ),
            __( 'AI Settings', 'content-genie' ),
            'manage_options',
            'ai-content-replacer-settings',
            array( $this, 'display_settings_page' )
        );
    }

    /** ---------------- Gating helpers ---------------- */
    private function key_is_active() {
        return get_option( 'acr_key_status' ) === 'active' && get_option( 'acr_api_key' );
    }

    /** Re-verify once per day on page open */
    public function maybe_reverify() {
        // Always re-check when a plugin screen is opened
        $key = get_option('acr_api_key', '');
        if ( ! $key || ! $this->remote ) {
            // no key or no client → treat as locked
            update_option('acr_key_status', 'invalid');
            return;
        }
    
        $result = $this->remote->verify_key( $key ); // key only
    
        if ( is_wp_error( $result ) || empty( $result['ok'] ) ) {
            update_option( 'acr_key_status', 'invalid' );
        } else {
            update_option( 'acr_key_status', 'active' );
            if ( ! empty( $result['plan'] ) ) {
                update_option( 'acr_plan', sanitize_text_field( $result['plan'] ) );
            }
            if ( ! empty( $result['tenant_id'] ) ) {
                update_option( 'acr_tenant_id', sanitize_text_field( $result['tenant_id'] ) );
            }
        }
    }
    
    

    /** Handle Connect form (API key submit) */
    public function maybe_handle_connect() {
        if ( empty( $_POST['acr_connect'] ) ) { return; }
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        if ( ! check_admin_referer( 'acr_connect' ) ) { return; }
    
        $key = sanitize_text_field( wp_unslash( $_POST['acr_api_key'] ?? '' ) );
        if ( ! $key ) {
            add_action( 'admin_notices', function() {
                echo '<div class="notice notice-error"><p>Please enter your API key.</p></div>';
            } );
            return;
        }
    
        if ( ! $this->remote ) {
            add_action( 'admin_notices', function() {
                echo '<div class="notice notice-error"><p>Remote client not initialized.</p></div>';
            } );
            return;
        }
    
        // ✅ Key-only verification against your Django endpoint /api/key/verify/
        $verify = $this->remote->verify_key( $key );
    
        if ( is_wp_error( $verify ) || empty( $verify['ok'] ) ) {
            update_option( 'acr_key_status', 'invalid' );
            // Do NOT save bad key
            add_action( 'admin_notices', function() {
                echo '<div class="notice notice-error"><p>API key invalid or could not be verified. Please check your subscription.</p></div>';
            } );
            return;
        }
    
        // ✅ Only now we save the key and mark active
        update_option( 'acr_api_key', $key );
        update_option( 'acr_key_status', 'active' );
        update_option( 'acr_last_check', time() );
    
        if ( ! empty( $verify['plan'] ) ) {
            update_option( 'acr_plan', sanitize_text_field( $verify['plan'] ) );
        }
        if ( ! empty( $verify['tenant_id'] ) ) {
            update_option( 'acr_tenant_id', sanitize_text_field( $verify['tenant_id'] ) );
        }
    
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-success"><p>Connected!</p></div>';
        } );
    
        wp_safe_redirect( admin_url( 'admin.php?page=ai-content-replacer' ) );
        exit;
    }
        
    /** ---------------- Page renders (GATED) ---------------- */
    public function display_admin_page() {
        $this->maybe_reverify();
        if ( ! $this->key_is_active() ) {
            // Render the Connect screen if key is not active
            if ( class_exists( 'ACR_Connect_Screen' ) ) {
                ACR_Connect_Screen::render();
            } else {
                // Minimal inline fallback (if you didn’t include class-acr-connect-screen.php)
                ?>
                <div class="wrap">
                  <h1>ContentAISEO</h1>
                  <div class="notice notice-error inline"><p>Status: <strong><?php echo esc_html( strtoupper( get_option('acr_key_status','unknown') ) ); ?></strong></p></div>
                  <form method="post">
                    <?php wp_nonce_field('acr_connect'); ?>
                    <table class="form-table">
                      <tr>
                        <th scope="row">API Key</th>
                        <td><input type="password" name="acr_api_key" class="regular-text" placeholder="Enter your API key" value="<?php echo esc_attr( get_option('acr_api_key','') ); ?>"></td>
                      </tr>
                    </table>
                    <p class="submit">
                      <button class="button button-primary" name="acr_connect" value="1">Connect</button>
                      <a class="button" href="#" onclick="alert('Subscribe flow comes later. Use cg_test_123 for local test.');return false;">Subscribe</a>
                    </p>
                  </form>
                </div>
                <?php
            }
            return;
        }

        // Key is active → show your normal ContentAISEO UI
        include_once ACR_PLUGIN_DIR . 'templates/admin-page.php';
    }

    public function display_settings_page() {
        $this->maybe_reverify();
        if ( ! $this->key_is_active() ) {
            if ( class_exists( 'ACR_Connect_Screen' ) ) {
                ACR_Connect_Screen::render();
            } else {
                // Fallback UI:
                ?>
                <div class="wrap">
                  <h1>ContentAISEO</h1>
                  <div class="notice notice-error inline"><p>Status: <strong><?php echo esc_html( strtoupper( get_option('acr_key_status','unknown') ) ); ?></strong></p></div>
                  <form method="post">
                    <?php wp_nonce_field('acr_connect'); ?>
                    <table class="form-table">
                      <tr>
                        <th scope="row">API Key</th>
                        <td><input type="password" name="acr_api_key" class="regular-text" placeholder="Enter your API key" value="<?php echo esc_attr( get_option('acr_api_key','') ); ?>"></td>
                      </tr>
                    </table>
                    <p class="submit">
                      <button class="button button-primary" name="acr_connect" value="1">Connect</button>
                    </p>
                  </form>
                </div>
                <?php
            }
            return;
        }

        // Key is active → show Settings
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'ContentAISEO Settings', 'content-genie' ); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'acr_settings_group' );
                do_settings_sections( 'ai-content-replacer' );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /** ---------------- Assets ---------------- */
    public function enqueue_admin_scripts( $hook ) {
        if ( 'toplevel_page_ai-content-replacer' !== $hook && 'ai-content-replacer_page_ai-content-replacer-settings' !== $hook ) {
            return;
        }

        // Media library (.docx upload)
        if ( function_exists( 'wp_enqueue_media' ) ) {
            wp_enqueue_media();
        }

        // Mammoth.js for .docx → HTML (client-side conversion used in Tab-2 Simple Mode)
        $local_mammoth_path = ACR_PLUGIN_DIR . 'libs/mammoth/1.6.0/mammoth.browser.min.js';
        $local_mammoth_url  = ACR_PLUGIN_URL . 'libs/mammoth/1.6.0/mammoth.browser.min.js';
        $mammoth_src        = file_exists( $local_mammoth_path ) ? $local_mammoth_url : 'https://unpkg.com/mammoth@1.6.0/mammoth.browser.min.js';
        wp_enqueue_script( 'acr-mammoth', $mammoth_src, array(), '1.6.0', true );

        // Admin CSS/JS
        $css_path = ACR_PLUGIN_DIR . 'admin/css/acr-admin.css';
        $js_path  = ACR_PLUGIN_DIR . 'admin/js/acr-admin.js';

        wp_enqueue_style(
            'acr-admin-css',
            ACR_PLUGIN_URL . 'admin/css/acr-admin.css',
            array(),
            file_exists( $css_path ) ? filemtime( $css_path ) : ( defined( 'ACR_VERSION' ) ? ACR_VERSION : '1.0.0' )
        );

        wp_enqueue_script(
            'acr-admin-js',
            ACR_PLUGIN_URL . 'admin/js/acr-admin.js',
            array( 'jquery' ),
            file_exists( $js_path ) ? filemtime( $js_path ) : ( defined( 'ACR_VERSION' ) ? ACR_VERSION : '1.0.0' ),
            true
        );

        // Core AJAX config (keep these keys; JS expects them)
        wp_localize_script(
            'acr-admin-js',
            'acr_ajax_object',
            array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'acr_ajax_nonce' ),
            )
        );

        // Success popup strings/timing (used by both tabs)
        wp_localize_script(
            'acr-admin-js',
            'acrSuccessPopupOpts',
            array(
                'showDelayMs'    => 1200,
                'title'          => __( 'Success', 'content-genie' ),
                'message'        => __( 'Content applied successfully!', 'content-genie' ),
                'primaryText'    => __( 'Create New', 'content-genie' ),
                'secondaryText'  => __( 'View Draft', 'content-genie' ),
                'createPostUrl'  => admin_url( 'post-new.php?post_type=post' ),
                'createPageUrl'  => admin_url( 'post-new.php?post_type=page' ),
            )
        );

        // Generic strings
        wp_localize_script(
            'acr-admin-js',
            'acr_strings',
            array(
                'none_start_fresh' => __( 'None (start fresh)…', 'ai-content-replacer' ),
                'select_category'  => __( 'Select category…', 'ai-content-replacer' ),
            )
        );

        // Expose settings to JS (optional UX). Backend is authoritative.
        wp_localize_script(
            'acr-admin-js',
            'acr_settings_js',
            array(
                'sitemap_url'   => method_exists( $this->settings, 'get_sitemap_url' ) ? (string) $this->settings->get_sitemap_url() : '',
                'preferred_api' => method_exists( $this->settings, 'get_preferred_api' ) ? (string) $this->settings->get_preferred_api() : 'openai',
            )
        );
    }

    /* ---------- Pages/Templates (Tab #1) ---------- */
    public function ajax_get_elementor_pages() {
        check_ajax_referer( 'acr_ajax_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_pages' ) ) {
            wp_send_json_error( array( 'message' => 'No permission to edit pages.' ) );
        }
        $ids = get_posts( array(
            'post_type'   => array( 'page', 'elementor_library' ),
            'post_status' => array( 'publish', 'draft' ),
            'numberposts' => -1,
            'fields'      => 'ids',
        ) );
        $pages = array_map( function( $id ) {
            return array( 'id' => $id, 'title' => get_the_title( $id ) );
        }, $ids );
        wp_send_json_success( array( 'pages' => $pages ) );
    }

    public function ajax_process_page_content() {
        check_ajax_referer( 'acr_ajax_nonce', 'nonce' );
        $page_id     = isset( $_POST['page_id'] ) ? intval( $_POST['page_id'] ) : 0;
        $user_prompt = isset( $_POST['user_prompt'] ) ? sanitize_textarea_field( $_POST['user_prompt'] ) : '';

        if ( empty( $page_id ) || empty( $user_prompt ) ) {
            wp_send_json_error( array( 'message' => __( 'Page ID and AI prompt are required.', 'ai-content-replacer' ) ) );
        }
        if ( ! current_user_can( 'edit_post', $page_id ) ) {
            wp_send_json_error( array( 'message' => __( 'You do not have permission to edit this document.', 'ai-content-replacer' ) ) );
        }
        $post = get_post( $page_id );
        if ( ! $post || ! get_post_meta( $page_id, '_elementor_edit_mode', true ) ) {
            wp_send_json_error( array( 'message' => __( 'Selected item is not an Elementor document.', 'ai-content-replacer' ) ) );
        }

        $result = $this->elementor_processor->process_elementor_content( $page_id, $user_prompt );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }
        wp_send_json_success( array( 'message' => __( 'Content successfully replaced!', 'ai-content-replacer' ) ) );
    }

    /* ---------- Blog Posts (rewrite, Tab #1) ---------- */
    public function ajax_get_blog_posts() {
        check_ajax_referer( 'acr_ajax_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => 'No permission to edit posts.' ) );
        }

        $q = new WP_Query( array(
            'post_type'      => 'post',
            'post_status'    => array( 'publish', 'draft' ),
            'posts_per_page' => 200,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
            'meta_query'     => array(
                array(
                    'key'     => '_elementor_edit_mode',
                    'compare' => 'EXISTS',
                ),
            ),
        ) );

        $posts = array();
        foreach ( $q->posts as $p ) {
            $posts[] = array( 'id' => $p->ID, 'title' => get_the_title( $p ) );
        }
        wp_send_json_success( array( 'posts' => $posts ) );
    }

    public function ajax_process_blog_content() {
        check_ajax_referer( 'acr_ajax_nonce', 'nonce' );
        $post_id     = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
        $user_prompt = isset( $_POST['user_prompt'] ) ? sanitize_textarea_field( $_POST['user_prompt'] ) : '';

        if ( empty( $post_id ) || empty( $user_prompt ) ) {
            wp_send_json_error( array( 'message' => __( 'Post ID and AI prompt are required.', 'ai-content-replacer' ) ) );
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'You do not have permission to edit this post.', 'ai-content-replacer' ) ) );
        }
        if ( ! get_post_meta( $post_id, '_elementor_edit_mode', true ) ) {
            wp_send_json_error( array( 'message' => __( 'This post is not edited with Elementor. Convert it to Elementor and try again.', 'ai-content-replacer' ) ) );
        }

        $result = $this->elementor_processor->process_elementor_content( $post_id, $user_prompt );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }
        wp_send_json_success( array( 'message' => __( 'Content successfully replaced!', 'ai-content-replacer' ) ) );
    }

    /* ---------- Duplicate & rename (used by Genie flow Step 2) ---------- */
    public function ajax_duplicate_and_rename() {
        check_ajax_referer( 'acr_ajax_nonce', 'nonce' );

        $orig_id   = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
        $new_title = isset( $_POST['new_title'] ) ? sanitize_text_field( wp_unslash( $_POST['new_title'] ) ) : '';
        $post_type = isset( $_POST['post_type'] ) ? sanitize_key( $_POST['post_type'] ) : '';

        if ( ! $orig_id || '' === $new_title ) {
            wp_send_json_error( array( 'message' => 'Post and new title are required.' ) );
        }

        $orig = get_post( $orig_id );
        if ( ! $orig || 'trash' === $orig->post_status ) {
            wp_send_json_error( array( 'message' => 'Original item not found or is in trash.' ) );
        }

        if ( ! current_user_can( 'edit_post', $orig_id ) ) {
            wp_send_json_error( array( 'message' => 'You are not allowed to duplicate this item.' ) );
        }

        $target_type = in_array( $post_type, array( 'post', 'page' ), true ) ? $post_type : $orig->post_type;

        // Create the new draft shell
        $new_postarr = array(
            'post_type'      => $target_type,
            'post_status'    => 'draft',
            'post_title'     => $new_title,
            'post_name'      => sanitize_title( $new_title ),
            'post_content'   => '',
            'post_excerpt'   => $orig->post_excerpt,
            'post_author'    => get_current_user_id(),
            'post_password'  => $orig->post_password,
            'post_parent'    => $orig->post_parent,
            'menu_order'     => $orig->menu_order,
            'comment_status' => $orig->comment_status,
            'ping_status'    => $orig->ping_status,
        );

        $new_id = wp_insert_post( $new_postarr, true );
        if ( is_wp_error( $new_id ) ) {
            wp_send_json_error( array( 'message' => $new_id->get_error_message() ) );
        }

        // Copy taxonomies
        $taxes = get_object_taxonomies( $orig->post_type );
        if ( is_array( $taxes ) ) {
            foreach ( $taxes as $tax ) {
                $terms = wp_get_object_terms( $orig_id, $tax, array( 'fields' => 'ids' ) );
                if ( ! is_wp_error( $terms ) ) {
                    wp_set_object_terms( $new_id, $terms, $tax, false );
                }
            }
        }

        // Copy meta (skip keys regenerated by Elementor)
        $all_meta = get_post_meta( $orig_id );
        foreach ( $all_meta as $key => $values ) {
            if ( in_array( $key, array(
                '_edit_lock',
                '_edit_last',
                '_wp_trash_meta_status',
                '_wp_trash_meta_time',
                '_elementor_css',
                '_elementor_data',
            ), true ) ) {
                continue;
            }
            foreach ( $values as $v ) {
                add_post_meta( $new_id, $key, maybe_unserialize( $v ) );
            }
        }

        // Elementor data
        $el_data = get_post_meta( $orig_id, '_elementor_data', true );
        if ( ! empty( $el_data ) ) {
            if ( is_array( $el_data ) ) {
                $el_json = wp_json_encode( $el_data );
            } else {
                $maybe   = maybe_unserialize( $el_data );
                $el_json = is_array( $maybe ) ? wp_json_encode( $maybe ) : (string) $el_data;
            }
            update_post_meta( $new_id, '_elementor_data', wp_slash( $el_json ) );
            update_post_meta( $new_id, '_elementor_edit_mode', 'builder' );

            if ( $ver = get_post_meta( $orig_id, '_elementor_version', true ) ) {
                update_post_meta( $new_id, '_elementor_version', $ver );
            }
            if ( $tpl = get_post_meta( $orig_id, '_wp_page_template', true ) ) {
                update_post_meta( $new_id, '_wp_page_template', $tpl );
            }
            if ( $doc = get_post_meta( $orig_id, '_elementor_template_type', true ) ) {
                update_post_meta( $new_id, '_elementor_template_type', $doc );
            }

            // Regenerate CSS & clear caches
            if ( class_exists( '\Elementor\Plugin' ) ) {
                try {
                    \Elementor\Plugin::$instance->files_manager->clear_cache();
                    \Elementor\Plugin::$instance->files_manager->clear_css( $new_id );
                    \Elementor\Plugin::$instance->files_manager->regenerate_css( $new_id );
                    $doc_obj = \Elementor\Plugin::$instance->documents->get( $new_id );
                    if ( $doc_obj ) {
                        $doc_obj->save( array() );
                    }
                } catch ( \Throwable $e ) { /* non-fatal */ }
            }
        }

        // Ensure unique slug
        $unique_slug = wp_unique_post_slug( sanitize_title( $new_title ), $new_id, 'draft', $target_type, 0 );
        if ( $unique_slug ) {
            wp_update_post( array( 'ID' => $new_id, 'post_name' => $unique_slug ) );
        }

        wp_send_json_success( array(
            'new_id'   => $new_id,
            'edit_url' => admin_url( 'post.php?action=elementor&post=' . $new_id ),
        ) );
    }

    /* ---------- BLOG TABS: AI preview & apply edited HTML (Tab #1) ---------- */
    public function ajax_generate_ai_preview() {
        check_ajax_referer( 'acr_ajax_nonce', 'nonce' );
        $post_id     = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
        $user_prompt = isset( $_POST['user_prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['user_prompt'] ) ) : '';

        if ( ! $post_id || '' === $user_prompt ) {
            wp_send_json_error( array( 'message' => 'Post and prompt are required.' ) );
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
        }

        $preview = $this->elementor_processor->generate_preview( $post_id, $user_prompt );
        if ( is_wp_error( $preview ) ) {
            wp_send_json_error( array( 'message' => $preview->get_error_message() ) );
        }
        wp_send_json_success( $preview ); // { html, parts }
    }

    public function ajax_apply_simple_update() {
        check_ajax_referer( 'acr_ajax_nonce', 'nonce' );
        $post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
        $html    = isset( $_POST['html'] ) ? wp_kses_post( wp_unslash( $_POST['html'] ) ) : '';

        if ( ! $post_id || '' === $html ) {
            wp_send_json_error( array( 'message' => 'Post and HTML are required.' ) );
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
        }

        $result = $this->elementor_processor->apply_simple_content( $post_id, $html );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }
        wp_send_json_success( array( 'message' => 'Content applied successfully.' ) );
    }

    /* ---------- Blog Creator (Tab #2, UI same; logic from second plugin) ---------- */

    // Step b1 helpers: templates (optional)
    public function ajax_blog_get_templates() {
        check_ajax_referer( 'acr_ajax_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => 'No permission.' ) );
        }

        // Use posts as selectable templates (adjust if you maintain a custom template type)
        $q = new WP_Query( array(
            'post_type'      => 'post',
            'post_status'    => array( 'publish', 'draft' ),
            'posts_per_page' => 50,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ) );

        $items = array();
        foreach ( $q->posts as $p ) {
            $items[] = array( 'id' => $p->ID, 'title' => get_the_title( $p ) );
        }

        wp_send_json_success( array( 'posts' => $items ) );
    }

    // Step b1 helpers: categories
    public function ajax_blog_get_categories() {
        check_ajax_referer( 'acr_ajax_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => 'No permission.' ) );
        }

        $terms = get_terms( array(
            'taxonomy'   => 'category',
            'hide_empty' => false,
            'number'     => 200,
        ) );

        if ( is_wp_error( $terms ) ) {
            wp_send_json_error( array( 'message' => $terms->get_error_message() ) );
        }

        $out = array();
        foreach ( $terms as $t ) {
            $out[] = array( 'id' => (int) $t->term_id, 'name' => $t->name );
        }

        wp_send_json_success( array( 'terms' => $out ) );
    }

    // Step b2: generate blog HTML preview (backed by sitemap-aware AI)
    public function ajax_blog_generate_ai() {
        check_ajax_referer( 'acr_ajax_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => 'No permission.' ) );
        }

        $title       = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
        $prompt      = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
        $templateID  = isset( $_POST['template_id'] ) ? (int) $_POST['template_id'] : 0;
        $categoryID  = isset( $_POST['category_id'] ) ? (int) $_POST['category_id'] : 0;

        if ( '' === $title || '' === $prompt ) {
            wp_send_json_error( array( 'message' => 'Title and prompt are required.' ) );
        }

        if ( ! $this->blog_service || ! method_exists( $this->blog_service, 'generate_preview_html' ) ) {
            wp_send_json_error( array( 'message' => 'Blog service not configured.' ) );
        }

        // IMPORTANT CHANGE:
        // Always use AI Settings for sitemap and provider (ignore any POST overrides).
        $sitemap_url   = method_exists( $this->settings, 'get_sitemap_url' ) ? (string) $this->settings->get_sitemap_url() : '';
        $preferred_api = method_exists( $this->settings, 'get_preferred_api' ) ? (string) $this->settings->get_preferred_api() : 'openai';

        $res = $this->blog_service->generate_preview_html( array(
            'title'         => $title,
            'prompt'        => $prompt,
            'template_id'   => $templateID,
            'category_id'   => $categoryID,
            'sitemap_url'   => $sitemap_url,
            'preferred_api' => $preferred_api,
        ) );

        if ( is_wp_error( $res ) ) {
            wp_send_json_error( array( 'message' => $res->get_error_message() ) );
        }

        // Expect: [ 'html' => '<p>...</p>', (optional 'title' => '...') ]
        $html  = isset( $res['html'] ) ? $res['html'] : '';
        $title = isset( $res['title'] ) && $res['title'] ? $res['title'] : $title;

        wp_send_json_success( array(
            'html'  => $html,
            'title' => $title,
        ) );
    }

    // Step b2/b3: create the new post draft with AI HTML or Simple Mode content
    public function ajax_blog_create_post() {
        check_ajax_referer( 'acr_ajax_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => 'No permission.' ) );
        }

        $mode       = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'ai'; // 'ai' | 'simple'
        $title      = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
        $html       = isset( $_POST['html'] ) ? wp_kses_post( wp_unslash( $_POST['html'] ) ) : '';
        $content    = isset( $_POST['content'] ) ? wp_kses_post( wp_unslash( $_POST['content'] ) ) : '';
        $templateID = isset( $_POST['template_id'] ) ? (int) $_POST['template_id'] : 0;
        $categoryID = isset( $_POST['category_id'] ) ? (int) $_POST['category_id'] : 0;

        if ( '' === $title ) {
            wp_send_json_error( array( 'message' => 'Title is required.' ) );
        }

        if ( 'simple' === $mode ) {
            if ( '' === $content ) {
                wp_send_json_error( array( 'message' => 'Content is required for Simple Mode.' ) );
            }
        } else {
            if ( '' === $html ) {
                wp_send_json_error( array( 'message' => 'HTML is required for AI Mode.' ) );
            }
        }

        // Prefer service methods; fallback locally for Simple Mode if not available.
        try {
            if ( 'simple' === $mode ) {
                if ( $this->blog_service && method_exists( $this->blog_service, 'create_post_simple' ) ) {
                    $res = $this->blog_service->create_post_simple( array(
                        'title'       => $title,
                        'content'     => $content,
                        'category_id' => $categoryID,
                    ) );
                    if ( is_wp_error( $res ) ) {
                        wp_send_json_error( array( 'message' => $res->get_error_message() ) );
                    }
                    $post_id = is_array( $res ) && isset( $res['post_id'] ) ? (int) $res['post_id'] : (int) $res;
                } else {
                    // Local minimal fallback
                    $post_id = wp_insert_post( array(
                        'post_type'     => 'post',
                        'post_status'   => 'draft',
                        'post_title'    => $title,
                        'post_content'  => $content,
                        'post_category' => $categoryID ? array( $categoryID ) : array(),
                    ), true );
                    if ( is_wp_error( $post_id ) ) {
                        wp_send_json_error( array( 'message' => $post_id->get_error_message() ) );
                    }
                }
            } else {
                if ( ! $this->blog_service || ! method_exists( $this->blog_service, 'create_elementor_post' ) ) {
                    wp_send_json_error( array( 'message' => 'Blog service not configured.' ) );
                }
                $res = $this->blog_service->create_elementor_post( array(
                    'title'       => $title,
                    'html'        => $html,
                    'template_id' => $templateID,
                    'category_id' => $categoryID,
                ) );
                if ( is_wp_error( $res ) ) {
                    wp_send_json_error( array( 'message' => $res->get_error_message() ) );
                }
                $post_id = isset( $res['post_id'] ) ? (int) $res['post_id'] : (int) $res;
            }

            if ( ! $post_id ) {
                wp_send_json_error( array( 'message' => 'Draft creation failed.' ) );
            }

            wp_send_json_success( array(
                'post_id'   => $post_id,
                'edit_link' => get_edit_post_link( $post_id, '' ),
                'view_link' => get_permalink( $post_id ),
            ) );
        } catch ( \Throwable $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ) );
        }
    }

    /* ---------- Success popup HTML ---------- */
    public function print_success_popup() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ( ! $screen ) { return; }

        $allowed = array(
            'toplevel_page_ai-content-replacer',
            'ai-content-replacer_page_ai-content-replacer-settings',
        );
        if ( ! in_array( $screen->id, $allowed, true ) ) {
            return;
        }

        $view = ACR_PLUGIN_DIR . 'templates/success-popup.php';
        if ( file_exists( $view ) ) {
            include $view;
        }
    }
}
