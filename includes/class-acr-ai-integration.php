<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ACR_AI_Integration (Server-backed)
 *
 * This version moves all AI calls to your FastAPI server via ACR_Remote_Client.
 * - Rewrites (AI Content Replacer) go through /v1/generate/content with mode=replacer -> { text }
 * - Blog (AI Blog Post Creator) uses /v1/generate/content with mode=blog -> { title, sections[], faq[] }
 *
 * Public API (kept the same):
 *   - generate_content( $user_prompt ) → string|WP_Error
 *   - generate_blog_markdown( $prompt, $reference_content = '', $sitemap_urls = array(), $preferred_api = null ) → string|WP_Error
 *
 * Notes:
 * - We still accept $preferred_api, $sitemap_urls, etc., and pass them along in "options" to the server.
 * - The server is responsible for provider/model selection and the actual AI generation.
 */
class ACR_AI_Integration {

    /** @var ACR_Settings */
    private $settings;

    /** @var ACR_Remote_Client */
    private $remote;

    /**
     * @param ACR_Settings      $settings
     * @param ACR_Remote_Client $remote_client  (optional) inject a ready client; if omitted, one is built from settings
     */
    public function __construct( ACR_Settings $settings, $remote_client = null ) {
        $this->settings = $settings;

        // Build a remote client if one not injected
        if ( $remote_client instanceof ACR_Remote_Client ) {
            $this->remote = $remote_client;
        } else {
            $api_base = get_option( 'cg_api_base' ); // your plugin stores server base here
            $plugin_version = defined('ACR_VERSION') ? ACR_VERSION : '1.0.0';
            $this->remote = new ACR_Remote_Client( $api_base, $plugin_version, true );
        }
    }

    /* =========================================================================
     * REWRITES (AI Content Replacer) — unchanged public API
     * ========================================================================= */

    /**
     * Calls the server to generate rewritten content (single text string).
     * Kept signature intact.
     *
     * @param string $user_prompt
     * @return string|WP_Error
     */
    public function generate_content( $user_prompt ) {
        $api_key = get_option('acr_api_key');
        if ( empty( $api_key ) ) {
            return new WP_Error( 'acr_key_missing', __( 'Connect your API key in plugin settings.', 'ai-content-replacer' ) );
        }

        // Forward to server using the "replacer" mode.
        $options = array(
            // Keep forwarding knobs you previously had; the server decides provider/model, etc.
            'mode'        => 'replacer',
            'expect'      => 'text',
            'provider'    => $this->settings->get_preferred_api(), // 'gemini' or 'openai' (server may override)
            'model'       => $this->settings->get_default_model(), // if you store one; otherwise leave blank
            'temperature' => $this->settings->get_default_temperature(), // if you store one
        );

        $payload = array(
            'prompt'  => (string) $user_prompt,
            'options' => $options,
            'site'    => home_url(),
        );

        $res = $this->remote->generate( $api_key, $payload );
        if ( is_wp_error( $res ) ) return $res;

        // Expect { text: "..." } from server in replacer mode
        if ( isset( $res['text'] ) && is_string( $res['text'] ) ) {
            return (string) $res['text'];
        }

        return new WP_Error( 'acr_server_shape', __( 'Server did not return rewritten text.', 'ai-content-replacer' ) );
    }

    /* =========================================================================
     * BLOG (AI Blog Post Creator) — unchanged public API
     * ========================================================================= */

    /**
     * Generate a full blog article in markdown-like text where FIRST LINE is the title.
     * Public signature is preserved. Internally, we call the server in "blog" mode and
     * convert its document shape to markdown-like text (title on first line).
     *
     * @param string       $prompt
     * @param string       $reference_content
     * @param array<string>$sitemap_urls
     * @param string|null  $preferred_api
     * @return string|WP_Error
     */
    public function generate_blog_markdown( $prompt, $reference_content = '', $sitemap_urls = array(), $preferred_api = null ) {
        $api_key = get_option('acr_api_key');
        if ( empty( $api_key ) ) {
            return new WP_Error( 'acr_key_missing', __( 'Connect your API key in plugin settings.', 'ai-content-replacer' ) );
        }

        $provider = $preferred_api ? strtolower( (string) $preferred_api ) : strtolower( (string) $this->settings->get_preferred_api() );

        // options passed to server so it can reproduce your old behavior (provider/model/tone/link restrictions/etc.)
        $options = array(
            'mode'            => 'blog',
            'expect'          => 'document',         // ask server to return {title,sections[],faq[]}
            'provider'        => $provider,          // 'gemini' or 'openai' (server may decide)
            'model'           => $this->settings->get_default_model(),
            'temperature'     => $this->settings->get_default_temperature(),
            'sitemap_urls'    => array_values( array_filter( array_map( 'strval', (array) $sitemap_urls ) ) ),
            'reference_text'  => (string) $reference_content,
            // any other knobs you used to influence the blog output can go here:
            // 'tone' => 'friendly', 'max_words' => 1500, etc.
        );

        $payload = array(
            'prompt'  => (string) $prompt,
            'options' => $options,
            'site'    => home_url(),
        );

        $res = $this->remote->generate( $api_key, $payload );
        if ( is_wp_error( $res ) ) return $res;

        // Expect server doc: { title: string, sections: [{heading,text}], faq: [{q,a}] }
        if ( ! isset( $res['title'] ) || ! is_array( $res['sections'] ) ) {
            return new WP_Error( 'acr_server_shape', __( 'Server did not return a valid blog document.', 'ai-content-replacer' ) );
        }

        // Convert the returned JSON document to your original "markdown-like" string
        $markdown = $this->document_to_markdown_like( $res );
        return $markdown;
    }

    /**
     * Turn {title, sections[], faq[]} into the markdown-like string you previously used:
     * - FIRST LINE: '# Title'
     * - Sections: '## Heading' + body text (as-is, may contain HTML)
     * - FAQ: '## FAQ' + Q/A pairs
     *
     * @param array $doc
     * @return string
     */
    private function document_to_markdown_like( array $doc ) {
        $out = array();

        $title = isset( $doc['title'] ) ? (string) $doc['title'] : 'Draft';
        $out[] = '# ' . $title;

        if ( ! empty( $doc['sections'] ) && is_array( $doc['sections'] ) ) {
            foreach ( $doc['sections'] as $s ) {
                $h = isset( $s['heading'] ) ? trim( (string) $s['heading'] ) : '';
                $t = isset( $s['text'] )    ? (string) $s['text']          : '';

                if ( $h !== '' ) {
                    $out[] = '';
                    $out[] = '## ' . $h;
                }
                if ( $t !== '' ) {
                    $out[] = $t;
                }
            }
        }

        if ( ! empty( $doc['faq'] ) && is_array( $doc['faq'] ) ) {
            $out[] = '';
            $out[] = '## FAQ';
            foreach ( $doc['faq'] as $f ) {
                $q = isset( $f['q'] ) ? trim( (string) $f['q'] ) : '';
                $a = isset( $f['a'] ) ? (string) $f['a']         : '';
                if ( $q !== '' ) {
                    $out[] = '';
                    $out[] = '### ' . $q;
                    if ( $a !== '' ) {
                        $out[] = $a;
                    }
                }
            }
        }

        return implode( "\n", $out );
    }
}
