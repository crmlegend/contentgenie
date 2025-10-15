<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<div class="wrap acr-container acr-wizard">
  <h1><?php esc_html_e('ContentAISEO', 'ai-content-replacer'); ?></h1>

  <!-- ======= TOP TABS ======= -->
  <div class="acr-tabs" role="tablist" aria-label="<?php esc_attr_e('Choose feature', 'ai-content-replacer'); ?>">
    <button class="acr-tab active" role="tab" aria-selected="true" aria-controls="acr-tab-genie" data-target="genie">
      <?php esc_html_e('ContentAISEO', 'ai-content-replacer'); ?>
    </button>
    <button class="acr-tab" role="tab" aria-selected="false" aria-controls="acr-tab-blog" data-target="blog">
      <?php esc_html_e('AI Blog Post Creator', 'ai-content-replacer'); ?>
    </button>
  </div>

  <div id="acr-status-message" class="notice"></div>

  <!-- ===================================================== -->
  <!-- =============== TAB 1: ContentAISEO ============= -->
  <!-- ===================================================== -->
  <section id="acr-tab-genie" class="acr-flow" data-tab="genie">
    <!-- Stepper -->
    <div class="acr-steps">
      <div class="acr-step" data-step="1"><span>1</span><p><?php esc_html_e('Choose Type', 'ai-content-replacer'); ?></p></div>
      <div class="acr-step" data-step="2"><span>2</span><p><?php esc_html_e('Choose Item', 'ai-content-replacer'); ?></p></div>
      <div class="acr-step" data-step="3"><span>3</span><p><?php esc_html_e('Prompt & Process', 'ai-content-replacer'); ?></p></div>
    </div>

    <!-- ==================== STEP 1 ==================== -->
    <section class="acr-step-panel" data-step="1">
      <div class="acr-cards">
        <label class="acr-card">
          <input type="radio" name="acr_content_type" value="blog" />
          <div class="acr-card-body">
            <div class="acr-card-icon">📝</div>
            <h3><?php esc_html_e('Blog Post', 'ai-content-replacer'); ?></h3>
            <p><?php esc_html_e('Rewrite a WordPress blog post.', 'ai-content-replacer'); ?></p>
          </div>
        </label>

        <label class="acr-card">
          <input type="radio" name="acr_content_type" value="page" />
          <div class="acr-card-body">
            <div class="acr-card-icon">🧩</div>
            <h3><?php esc_html_e('Elementor Page/Template', 'ai-content-replacer'); ?></h3>
            <p><?php esc_html_e('Rewrite an Elementor page or template.', 'ai-content-replacer'); ?></p>
          </div>
        </label>
      </div>

      <div class="acr-nav">
        <button class="button button-primary" id="acr-next-1" disabled><?php esc_html_e('Next', 'ai-content-replacer'); ?></button>
      </div>
    </section>

    <!-- ==================== STEP 2 ==================== -->
    <section class="acr-step-panel" data-step="2" hidden>
      <div class="acr-form-group" id="acr-pick-page" hidden>
        <label for="acr-page-select"><?php esc_html_e('Select an Elementor Page or Template:', 'ai-content-replacer'); ?></label>
        <select id="acr-page-select" name="page_id">
          <option value=""><?php esc_html_e('Loading pages...', 'ai-content-replacer'); ?></option>
        </select>
      </div>

      <div class="acr-form-group" id="acr-pick-post" hidden>
        <label for="acr-blog-select"><?php esc_html_e('Select a Blog Post:', 'ai-content-replacer'); ?></label>
        <select id="acr-blog-select" name="post_id">
          <option value=""><?php esc_html_e('Loading posts...', 'ai-content-replacer'); ?></option>
        </select>
      </div>

      <div class="acr-form-group" id="acr-new-title-wrap">
        <label for="acr-new-title"><strong><?php esc_html_e('New Title for the Copy', 'ai-content-replacer'); ?></strong></label>
        <input type="text" id="acr-new-title" class="regular-text" placeholder="<?php esc_attr_e('Enter the new title (required)', 'ai-content-replacer'); ?>"/>
        <p class="description">
          <?php esc_html_e('We will duplicate the selected item, set this as its title, and auto-generate the slug from it.', 'ai-content-replacer'); ?>
        </p>
      </div>

      <div class="acr-nav">
        <button class="button" id="acr-back-2"><?php esc_html_e('Back', 'ai-content-replacer'); ?></button>
        <button class="button button-primary" id="acr-next-2" disabled><?php esc_html_e('Next', 'ai-content-replacer'); ?></button>
      </div>
    </section>

    <!-- ==================== STEP 3 ==================== -->
    <section class="acr-step-panel" data-step="3" hidden>
      <div class="acr-subtitle">
        <p id="acr-context-label"><?php esc_html_e('Step 3: Provide Content', 'ai-content-replacer'); ?></p>
      </div>

      <!-- ========== PAGE FLOW (unchanged) ========== -->
      <div id="acr-page-flow" hidden>
        <div class="acr-form-group">
          <label for="acr-ai-prompt"><?php esc_html_e('AI Prompt (e.g., "Rewrite to be more engaging"):', 'ai-content-replacer'); ?></label>
          <textarea id="acr-ai-prompt" name="user_prompt" placeholder="<?php esc_attr_e('Enter your instructions for the AI here...', 'ai-content-replacer'); ?>"></textarea>
        </div>
        <div class="acr-nav">
          <button class="button" id="acr-back-3"><?php esc_html_e('Back', 'ai-content-replacer'); ?></button>
          <button id="acr-process-button" class="button button-primary"><?php esc_html_e('Process Content with AI', 'ai-content-replacer'); ?></button>
          <span id="acr-loading-spinner" class="spinner acr-spinner"></span>
        </div>
        <p class="description">
          <?php esc_html_e('This will fetch the selected item, send its text-based content to AI based on your prompt, and update it while preserving structure/design.', 'ai-content-replacer'); ?>
        </p>
        <p class="description"><strong><?php esc_html_e('Important:', 'ai-content-replacer'); ?></strong> <?php esc_html_e('Back up your site first. Review AI-generated content.', 'ai-content-replacer'); ?></p>
        <p class="description">
          <?php
          printf(
            esc_html__('Make sure your %s is configured.', 'ai-content-replacer'),
            '<a href="' . esc_url( admin_url( 'admin.php?page=ai-content-replacer-settings' ) ) . '">' . esc_html__( 'AI API Key', 'ai-content-replacer' ) . '</a>'
          );
          ?>
        </p>
      </div>

      <!-- ========== BLOG FLOW (rewrite inside Genie tab; unchanged) ========== -->
      <div id="acr-blog-flow" hidden>
        <!-- Mode Tabs -->
        <div class="acr-mode-tabs" role="tablist" aria-label="<?php esc_attr_e('Choose editing mode', 'ai-content-replacer'); ?>">
          <button id="acr-btn-ai-mode" class="acr-tab active" role="tab" aria-selected="true" aria-controls="acr-ai-mode">
            <?php esc_html_e('AI Powered Mode', 'ai-content-replacer'); ?>
          </button>
          <button id="acr-btn-simple-mode" class="acr-tab" role="tab" aria-selected="false" aria-controls="acr-simple-mode">
            <?php esc_html_e('Simple Mode', 'ai-content-replacer'); ?>
          </button>
        </div>

        <!-- AI Mode -->
        <div id="acr-ai-mode" class="acr-mode-panel block" role="tabpanel" aria-labelledby="acr-btn-ai-mode">
          <div class="acr-form-group">
            <label for="acr-ai-prompt-blog" class="acr-label"><?php esc_html_e('AI Prompt:', 'ai-content-replacer'); ?></label>
            <textarea id="acr-ai-prompt-blog" name="user_prompt_blog" class="acr-textarea" placeholder="<?php esc_attr_e('Tell the AI how to rewrite the blog content…', 'ai-content-replacer'); ?>"></textarea>
          </div>
          <div class="acr-actions" style="display:flex;align-items:center;gap:.5rem;">
            <button class="button button-primary" id="acr-generate-preview"><?php esc_html_e('Generate Preview', 'ai-content-replacer'); ?></button>
            <span class="spinner acr-spinner" id="acr-loading-spinner-blog" style="float:none;"></span>
          </div>

          <div class="acr-wordpad-wrap">
            <div class="acr-wordpad-toolbar">
              <span class="font-semibold"><?php esc_html_e('WordPad Preview (editable)', 'ai-content-replacer'); ?></span>
            </div>

            <!-- Moved here: Create Draft button just below the Word/WordPad toolbar -->
            <div class="acr-actions" style="margin:.5rem 0 0;">
              <button class="button" id="acr2-create-inline" disabled><?php esc_html_e('Create Draft (AI Mode)', 'ai-content-replacer'); ?></button>
            </div>

            <?php
              wp_editor(
                '', 'acr-wordpad-editor',
                array(
                  'textarea_name'  => 'acr_wordpad_editor',
                  'media_buttons'  => false,
                  'textarea_rows'  => 14,
                  'tinymce'        => array( 'height' => 380 ),
                  'quicktags'      => true,
                )
              );
            ?>
          </div>

          <div class="acr-actions">
            <button id="acr-apply-ai" class="button button-primary"><?php esc_html_e('Apply to Elementor', 'ai-content-replacer'); ?></button>
          </div>
        </div>

        <!-- Simple Mode -->
        <div id="acr-simple-mode" class="acr-mode-panel hidden" role="tabpanel" aria-labelledby="acr-btn-simple-mode">
          <p class="description">
            <?php esc_html_e('Upload a .docx or paste content below, edit, then apply to Elementor.', 'ai-content-replacer'); ?>
          </p>

          <!-- NEW: real .docx chooser + filename display -->
          <div class="acr-actions" style="display:flex;align-items:center;gap:.5rem;">
            <input type="file" id="acr-upload-docx-input" accept=".docx" style="display:none;" />
            <button id="acr-upload-docx" class="button" type="button"><?php esc_html_e('Upload .docx', 'ai-content-replacer'); ?></button>
            <span class="text-muted" id="acr-upload-docx-name" style="opacity:.8;"></span>
            <span class="text-muted"><?php esc_html_e('Your document will be converted to HTML using Mammoth.', 'ai-content-replacer'); ?></span>
          </div>

          <div class="acr-wordpad-wrap">
            <div class="acr-wordpad-toolbar">
              <span class="font-semibold"><?php esc_html_e('WordPad Editor', 'ai-content-replacer'); ?></span>
            </div>
            <?php
              wp_editor(
                '', 'acr-simple-editor',
                array(
                  'textarea_name'  => 'acr_simple_editor',
                  'media_buttons'  => false,
                  'textarea_rows'  => 14,
                  'tinymce'        => array( 'height' => 380 ),
                  'quicktags'      => true,
                )
              );
            ?>
          </div>

          <div class="acr-actions">
            <button id="acr-apply-simple" class="button button-primary"><?php esc_html_e('Apply to Elementor', 'ai-content-replacer'); ?></button>
          </div>
        </div>
      </div>
    </section>

    <!-- === FULL-PAGE OVERLAY LOADER (Genie Tab) === -->
    <div id="acr-overlay" class="acr-overlay" aria-hidden="true">
      <div class="acr-overlay-box" role="status" aria-live="polite">
        <div class="acr-spinner" aria-hidden="true"></div>
        <div class="acr-msg"><?php esc_html_e('Processing…', 'ai-content-replacer'); ?></div>
      </div>
    </div>
  </section> <!-- /#acr-tab-genie -->

  <!-- ===================================================== -->
  <!-- ============ TAB 2: AI BLOG POST CREATOR ============ -->
  <!-- ===================================================== -->
  <section id="acr-tab-blog" class="acr-flow" data-tab="blog" hidden>
    <?php wp_nonce_field( 'acr2_nonce', 'acr2_nonce_field' ); ?>

    <!-- Stepper -->
    <div class="acr-steps">
      <div class="acr-step" data-step="b1"><span>1</span><p><?php esc_html_e('Choose Source / Options', 'ai-content-replacer'); ?></p></div>
      <div class="acr-step" data-step="b2"><span>2</span><p><?php esc_html_e('Mode, Prompt & Generate', 'ai-content-replacer'); ?></p></div>
      <div class="acr-step" data-step="b3"><span>3</span><p><?php esc_html_e('Review & Create Draft', 'ai-content-replacer'); ?></p></div>
    </div>

    <!-- ===== BLOG STEP 1 ===== -->
    <section class="acr-step-panel" data-step="b1">
      <div class="acr-form-group">
        <label for="acr2-template"><?php esc_html_e('Optional: Use template post (for structure)', 'ai-content-replacer'); ?></label>
        <select id="acr2-template" name="acr2_template">
          <option value=""><?php esc_html_e('None (start fresh)…', 'ai-content-replacer'); ?></option>
          <!-- Populate via JS -->
        </select>
      </div>

      <div class="acr-form-group">
        <label for="acr2-title"><strong><?php esc_html_e('New Blog Title', 'ai-content-replacer'); ?></strong></label>
        <input type="text" id="acr2-title" class="regular-text" placeholder="<?php esc_attr_e('e.g., 10 Tips for…', 'ai-content-replacer'); ?>">
      </div>

      <div class="acr-form-group">
        <label for="acr2-category"><?php esc_html_e('Category', 'ai-content-replacer'); ?></label>
        <select id="acr2-category" name="acr2_category">
          <option value=""><?php esc_html_e('Select category…', 'ai-content-replacer'); ?></option>
          <!-- Populate via JS -->
        </select>
      </div>

      <!-- Optional: Reference posts to guide tone/style -->
      <div class="acr-form-group">
        <label for="acr2-references"><?php esc_html_e('Optional: Reference posts for tone/style (multi-select)', 'ai-content-replacer'); ?></label>
        <select id="acr2-references" name="acr2_references[]" multiple style="min-width:320px;">
          <?php
          $ref_q = new WP_Query( array(
            'post_type'      => 'post',
            'post_status'    => array( 'publish', 'draft' ),
            'posts_per_page' => 20,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
          ) );
          if ( $ref_q->have_posts() ) {
            foreach ( $ref_q->posts as $ref_p ) {
              printf(
                '<option value="%d">%s</option>',
                absint( $ref_p->ID ),
                esc_html( get_the_title( $ref_p ) )
              );
            }
          }
          ?>
        </select>
        <p class="description">
          <?php esc_html_e('Selected posts help the AI match tone/structure. Leave empty to skip.', 'ai-content-replacer'); ?>
        </p>
      </div>

      <!-- NOTE: Sitemap URL + Provider overrides removed (now taken from AI Settings) -->

      <div class="acr-nav">
        <button class="button button-primary" id="acr2-next-1" disabled><?php esc_html_e('Next', 'ai-content-replacer'); ?></button>
      </div>
    </section>

    <!-- ===== BLOG STEP 2 ===== -->
    <section class="acr-step-panel" data-step="b2" hidden>
      <!-- Mode toggle -->
      <div class="acr-form-group">
        <label class="acr-label"><strong><?php esc_html_e('Mode', 'ai-content-replacer'); ?></strong></label>
        <label><input type="radio" name="acr2-mode" value="ai" checked> <?php esc_html_e('AI Mode', 'ai-content-replacer'); ?></label>
        <label style="margin-left:16px;"><input type="radio" name="acr2-mode" value="simple"> <?php esc_html_e('Simple Mode', 'ai-content-replacer'); ?></label>
      </div>

      <!-- AI MODE PANEL -->
      <div id="acr2-ai-panel">
        <div class="acr-form-group">
          <label for="acr2-prompt"><strong><?php esc_html_e('AI Prompt', 'ai-content-replacer'); ?></strong></label>
          <textarea id="acr2-prompt" placeholder="<?php esc_attr_e('Describe the post you want to generate…', 'ai-content-replacer'); ?>"></textarea>
          <p class="description">
            <?php
            printf(
              esc_html__( 'Tip: Configure your AI provider and (optionally) Sitemap URL in %s for better internal linking.', 'ai-content-replacer' ),
              '<a href="' . esc_url( admin_url( 'admin.php?page=ai-content-replacer-settings' ) ) . '">' . esc_html__( 'AI Settings', 'ai-content-replacer' ) . '</a>'
            );
            ?>
          </p>
        </div>

        <div class="acr-actions" style="display:flex;align-items:center;gap:.5rem;">
          <button class="button" id="acr2-back-2"><?php esc_html_e('Back', 'ai-content-replacer'); ?></button>
          <button class="button button-primary" id="acr2-generate"><?php esc_html_e('Generate Preview', 'ai-content-replacer'); ?></button>
          <span id="acr2-spinner" class="spinner acr-spinner"></span>
        </div>

        <div class="acr-wordpad-wrap" style="margin-top: 12px;">
          <div class="acr-wordpad-toolbar">
            <span class="font-semibold"><?php esc_html_e('AI Preview (editable)', 'ai-content-replacer'); ?></span>
          </div>

          <!-- Moved here: Create Draft button just below the Word/WordPad toolbar -->
          <div class="acr-actions" style="margin:.5rem 0 0;">
            <button class="button" id="acr2-create-ai-inline" disabled><?php esc_html_e('Create Draft (AI Mode)', 'ai-content-replacer'); ?></button>
          </div>

          <?php
            wp_editor(
              '', 'acr2-editor',
              array(
                'textarea_name'  => 'acr2_editor',
                'media_buttons'  => false,
                'textarea_rows'  => 16,
                'tinymce'        => array( 'height' => 420 ),
                'quicktags'      => true,
              )
            );
          ?>
        </div>
      </div>

      <!-- SIMPLE MODE PANEL -->
      <div id="acr2-simple-panel" class="hidden">
        <p class="description"><?php esc_html_e('Skip AI. Provide your own content and create the draft directly.', 'ai-content-replacer'); ?></p>

        <div class="acr-form-group">
          <label for="acr2-simple-title"><strong><?php esc_html_e('Post Title', 'ai-content-replacer'); ?></strong></label>
          <input type="text" id="acr2-simple-title" class="regular-text" placeholder="<?php esc_attr_e('e.g., My Custom Post', 'ai-content-replacer'); ?>">
        </div>

        <!-- NEW: real .docx chooser (hidden input triggered by button) -->
        <div class="acr-actions" style="display:flex;align-items:center;gap:.5rem;">
          <input type="file" id="acr2-docx" accept=".docx" style="display:none;">
          <button class="button" id="acr2-docx-btn" type="button"><?php esc_html_e('Upload .docx', 'ai-content-replacer'); ?></button>
          <span id="acr2-docx-name" class="text-muted" style="opacity:.8;"></span>
          <span class="text-muted"><?php esc_html_e('Your document will be converted to HTML using Mammoth.', 'ai-content-replacer'); ?></span>
        </div>

        <div class="acr-wordpad-wrap" style="margin-top:8px;">
          <div class="acr-wordpad-toolbar">
            <span class="font-semibold"><?php esc_html_e('Content (HTML allowed)', 'ai-content-replacer'); ?></span>
          </div>
          <?php
            wp_editor(
              '', 'acr2-simple-editor',
              array(
                'textarea_name'  => 'acr2_simple_editor',
                'media_buttons'  => false,
                'textarea_rows'  => 16,
                'tinymce'        => array( 'height' => 420 ),
                'quicktags'      => true,
              )
            );
          ?>
        </div>

        <div class="acr-actions" style="margin-top:12px;">
          <button class="button" id="acr2-simple-back-2"><?php esc_html_e('Back', 'ai-content-replacer'); ?></button>
          <button class="button button-primary" id="acr2-simple-create"><?php esc_html_e('Create Draft (Simple Mode)', 'ai-content-replacer'); ?></button>
          <span id="acr2-simple-spinner" class="spinner acr-spinner"></span>
        </div>
      </div>
    </section>

    <!-- ===== BLOG STEP 3 ===== -->
    <section class="acr-step-panel" data-step="b3" hidden>
      <p class="description" id="acr2-b3-desc-ai">
        <?php esc_html_e('Review the AI preview above. When ready, click Create Draft to build a new post (Elementor mapping applied).', 'ai-content-replacer'); ?>
      </p>
      <p class="description hidden" id="acr2-b3-desc-simple">
        <?php esc_html_e('Ready to create your draft using the Simple Mode content.', 'ai-content-replacer'); ?>
      </p>

      <div class="acr-actions">
        <button class="button" id="acr2-back-3"><?php esc_html_e('Back', 'ai-content-replacer'); ?></button>

        <!-- AI mode finalize -->
        <button class="button button-primary" id="acr2-create"><?php esc_html_e('Create Draft', 'ai-content-replacer'); ?></button>

        <!-- Simple mode finalize -->
        <button class="button button-primary hidden" id="acr2-create-simple"><?php esc_html_e('Create Draft (Simple Mode)', 'ai-content-replacer'); ?></button>

        <span id="acr2-create-spinner" class="spinner acr-spinner"></span>
      </div>
    </section>

    <!-- === FULL-PAGE OVERLAY LOADER (Blog Tab) === -->
    <div id="acr2-overlay" class="acr-overlay" aria-hidden="true">
      <div class="acr-overlay-box" role="status" aria-live="polite">
        <div class="acr-spinner" aria-hidden="true"></div>
        <div class="acr-msg"><?php esc_html_e('Processing…', 'ai-content-replacer'); ?></div>
      </div>
    </div>
  </section> <!-- /#acr-tab-blog -->
</div>
