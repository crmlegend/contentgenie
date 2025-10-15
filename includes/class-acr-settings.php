<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ACR_Settings {

    /**
     * Constructor.
     */
    public function __construct() {
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    /**
     * Register plugin settings.
     */
    public function register_settings() {
        // OpenAI API key.
        register_setting(
            'acr_settings_group',
            'acr_openai_api_key',
            array(
                'type'              => 'string',
                'sanitize_callback' => array( $this, 'sanitize_api_key' ),
                'default'           => '',
                'show_in_rest'      => false,
            )
        );

        // Google Gemini API key.
        register_setting(
            'acr_settings_group',
            'acr_gemini_api_key',
            array(
                'type'              => 'string',
                'sanitize_callback' => array( $this, 'sanitize_api_key' ),
                'default'           => '',
                'show_in_rest'      => false,
            )
        );

        // Preferred AI provider (strictly allow 'openai' or 'gemini').
        register_setting(
            'acr_settings_group',
            'acr_preferred_api',
            array(
                'type'              => 'string',
                'sanitize_callback' => array( $this, 'sanitize_preferred_api' ),
                'default'           => 'openai',
                'show_in_rest'      => false,
            )
        );

        // Sitemap URL (optional).
        register_setting(
            'acr_settings_group',
            'acr_sitemap_url',
            array(
                'type'              => 'string',
                'sanitize_callback' => array( $this, 'sanitize_sitemap_url' ),
                'default'           => '',
                'show_in_rest'      => false,
            )
        );

        // ✅ NEW: Server API Base URL (FastAPI)
        register_setting(
            'acr_settings_group',
            'cg_api_base',
            array(
                'type'              => 'string',
                'sanitize_callback' => array( $this, 'sanitize_sitemap_url' ), // reuse URL sanitizer
                'default'           => '',
                'show_in_rest'      => false,
            )
        );

        // Settings section.
        add_settings_section(
            'acr_api_settings_section',
            __( 'AI API Settings', 'ai-content-replacer' ),
            array( $this, 'api_settings_section_callback' ),
            'ai-content-replacer'
        );

        // OpenAI key field.
        add_settings_field(
            'acr_openai_api_key_field',
            __( 'OpenAI API Key', 'ai-content-replacer' ),
            array( $this, 'openai_api_key_field_callback' ),
            'ai-content-replacer',
            'acr_api_settings_section'
        );

        // Gemini key field.
        add_settings_field(
            'acr_gemini_api_key_field',
            __( 'Google Gemini API Key', 'ai-content-replacer' ),
            array( $this, 'gemini_api_key_field_callback' ),
            'ai-content-replacer',
            'acr_api_settings_section'
        );

        // Preferred provider select.
        add_settings_field(
            'acr_preferred_api_field',
            __( 'Preferred AI Provider', 'ai-content-replacer' ),
            array( $this, 'preferred_api_field_callback' ),
            'ai-content-replacer',
            'acr_api_settings_section'
        );

        // Sitemap URL field.
        add_settings_field(
            'acr_sitemap_url_field',
            __( 'Sitemap URL', 'ai-content-replacer' ),
            array( $this, 'sitemap_url_field_callback' ),
            'ai-content-replacer',
            'acr_api_settings_section'
        );

        // ✅ NEW: API Base URL field.
        add_settings_field(
            'cg_api_base_field',
            __( 'API Base URL (Server)', 'ai-content-replacer' ),
            array( $this, 'api_base_field_callback' ),
            'ai-content-replacer',
            'acr_api_settings_section'
        );
    }

    /* ----------------------------
     * Sanitizers
     * ---------------------------- */

    /**
     * Sanitize the API key.
     *
     * @param string $input The API key input.
     * @return string Sanitized API key.
     */
    public function sanitize_api_key( $input ) {
        $input = trim( (string) $input );
        return sanitize_text_field( $input );
    }

    /**
     * Sanitize the preferred provider value.
     * Only allow 'openai' or 'gemini'. Defaults to 'openai'.
     *
     * @param string $input
     * @return string
     */
    public function sanitize_preferred_api( $input ) {
        $input = strtolower( trim( (string) $input ) );
        $input = sanitize_text_field( $input );
        return in_array( $input, array( 'openai', 'gemini' ), true ) ? $input : 'openai';
    }

    /**
     * Sanitize the Sitemap URL or general URL.
     *
     * - Trims whitespace
     * - Ensures a valid http/https URL
     * - Returns empty string if invalid
     *
     * @param string $input The URL input.
     * @return string Sanitized URL or empty string.
     */
    public function sanitize_sitemap_url( $input ) {
        $input = trim( (string) $input );
        if ( $input === '' ) {
            return '';
        }
        // Force http/https only
        $url = esc_url_raw( $input, array( 'http', 'https' ) );
        if ( empty( $url ) ) {
            return '';
        }
        return $url;
    }

    /* ----------------------------
     * Settings UI callbacks
     * ---------------------------- */

    /**
     * Callback for the API settings section.
     */
    public function api_settings_section_callback() {
        echo '<p>' . esc_html__( 'Enter your OpenAI and/or Google Gemini API keys here to enable AI content generation.', 'ai-content-replacer' ) . '</p>';
        echo '<p>' . esc_html__( 'The Preferred AI Provider and Sitemap URL set here are used globally, including by the AI Blog Post Creator (Tab 2).', 'ai-content-replacer' ) . '</p>';
        echo '<p>' . esc_html__( 'Optionally provide your website’s Sitemap URL so the AI can understand site structure and create internal links only to valid URLs.', 'ai-content-replacer' ) . '</p>';
        echo '<p>' . esc_html__( 'Also provide the API Base URL where your ContentAISEO server is running.', 'ai-content-replacer' ) . '</p>';
    }

    /**
     * Callback for the OpenAI API key field.
     */
    public function openai_api_key_field_callback() {
        $api_key = get_option( 'acr_openai_api_key', '' );
        echo '<input type="password" id="acr_openai_api_key_field" name="acr_openai_api_key" value="' . esc_attr( $api_key ) . '" class="regular-text" placeholder="' . esc_attr__( 'sk-xxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'ai-content-replacer' ) . '">';
        echo '<p class="description">' . esc_html__( 'Your API key will be stored securely.', 'ai-content-replacer' ) . '</p>';
    }

    /**
     * Callback for the Gemini API key field.
     */
    public function gemini_api_key_field_callback() {
        $api_key = get_option( 'acr_gemini_api_key', '' );
        echo '<input type="password" id="acr_gemini_api_key_field" name="acr_gemini_api_key" value="' . esc_attr( $api_key ) . '" class="regular-text" placeholder="' . esc_attr__( 'AIzaSyXXXXXXXXXXXXXXXXXXXXXXXXX', 'ai-content-replacer' ) . '">';
        echo '<p class="description">' . esc_html__( 'Your API key will be stored securely.', 'ai-content-replacer' ) . '</p>';
    }

    /**
     * Callback for the preferred API selection field.
     */
    public function preferred_api_field_callback() {
        $preferred_api = $this->get_preferred_api();
        ?>
        <select name="acr_preferred_api" id="acr_preferred_api_field">
            <option value="openai" <?php selected( $preferred_api, 'openai' ); ?>><?php esc_html_e( 'OpenAI (ChatGPT)', 'ai-content-replacer' ); ?></option>
            <option value="gemini" <?php selected( $preferred_api, 'gemini' ); ?>><?php esc_html_e( 'Google Gemini', 'ai-content-replacer' ); ?></option>
        </select>
        <p class="description">
            <?php esc_html_e( 'This provider is used across the plugin, including the AI Blog Post Creator. Default is ChatGPT.', 'ai-content-replacer' ); ?>
        </p>
        <?php
    }

    /**
     * Callback for the Sitemap URL field.
     */
    public function sitemap_url_field_callback() {
        $sitemap_url = $this->get_sitemap_url();
        echo '<input type="url" id="acr_sitemap_url_field" name="acr_sitemap_url" value="' . esc_attr( $sitemap_url ) . '" class="regular-text" placeholder="' . esc_attr__( 'https://example.com/sitemap.xml', 'ai-content-replacer' ) . '">';
        echo '<p class="description">' . esc_html__( 'Used by the AI Blog Post Creator to build internal links to valid URLs on your site.', 'ai-content-replacer' ) . '</p>';
    }

    /**
     * ✅ Callback for the API Base URL field.
     */
    public function api_base_field_callback() {
        $val = get_option( 'cg_api_base', '' );
        echo '<input type="url" id="cg_api_base_field" name="cg_api_base" value="' . esc_attr( $val ) . '" class="regular-text code" placeholder="' . esc_attr__( 'http://127.0.0.1:8000', 'ai-content-replacer' ) . '">';
        echo '<p class="description">' . esc_html__( 'Where your ContentAISEO Server is running. Example: http://127.0.0.1:8000 (local) or your deployed server URL.', 'ai-content-replacer' ) . '</p>';
    }

    /* ----------------------------
     * Getters (used elsewhere)
     * ---------------------------- */

    /**
     * Get the stored OpenAI API key.
     *
     * @return string The OpenAI API key.
     */
    public function get_openai_api_key() {
        return get_option( 'acr_openai_api_key', '' );
    }

    /**
     * Get the stored Google Gemini API key.
     *
     * @return string The Gemini API key.
     */
    public function get_gemini_api_key() {
        return get_option( 'acr_gemini_api_key', '' );
    }

    /**
     * Get the stored preferred API.
     *
     * @return string The preferred API ('openai' or 'gemini').
     */
    public function get_preferred_api() {
        $val = get_option( 'acr_preferred_api', 'openai' );
        return in_array( $val, array( 'openai', 'gemini' ), true ) ? $val : 'openai';
    }

    /**
     * Get the stored Sitemap URL.
     *
     * @return string The Sitemap URL.
     */
    public function get_sitemap_url() {
        return get_option( 'acr_sitemap_url', '' );
    }
}
