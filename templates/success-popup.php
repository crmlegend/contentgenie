<?php
/**
 * Success Popup (two-button) – shared by both tabs.
 * Path: templates/success-popup.php
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div id="acr-success-popup-overlay" class="acr-success-overlay" hidden>
  <div class="acr-success-modal" role="dialog" aria-modal="true" aria-live="polite" aria-labelledby="acr-success-title">
    <!-- Close -->
    <button type="button" class="acr-close-x" aria-label="<?php esc_attr_e( 'Close', 'ai-content-replacer' ); ?>">×</button>

    <div class="acr-success-header">
      <span class="acr-success-icon" aria-hidden="true">✓</span>
      <h3 id="acr-success-title" class="acr-success-title">
        <?php esc_html_e( 'Success', 'ai-content-replacer' ); ?>
      </h3>
    </div>

    <p class="acr-success-message">
      <?php esc_html_e( 'Content applied successfully!', 'ai-content-replacer' ); ?>
    </p>

    <div class="acr-success-actions">
      <!-- PRIMARY: reusable “Create Another” / “Create New” (text overridden by JS if localized) -->
      <button
        type="button"
        class="button button-primary acr-success-btn-primary"
        data-action="primary"
        aria-label="<?php esc_attr_e( 'Create another item', 'ai-content-replacer' ); ?>"
      >
        <?php esc_html_e( 'Create New', 'ai-content-replacer' ); ?>
      </button>

      <!-- SECONDARY: go to Elementor edit of the created/updated draft -->
      <button
        type="button"
        class="button acr-success-btn-secondary"
        data-action="secondary"
        aria-label="<?php esc_attr_e( 'View the created draft in Elementor', 'ai-content-replacer' ); ?>"
      >
        <?php esc_html_e( 'View Draft', 'ai-content-replacer' ); ?>
      </button>
    </div>
  </div>
</div>
