jQuery(document).ready(function($) {

  /* ============================================================
   * GLOBALS / SHARED UTILITIES
   * ============================================================ */
  var $statusMessage  = $('#acr-status-message');

  function showStatus(message, type) {
    type = type || 'info';
    $statusMessage
      .removeClass('notice notice-success notice-error notice-info')
      .addClass('notice notice-' + type)
      .html('<p>' + message + '</p>')
      .show();
  }
  function hideStatus(){ $statusMessage.hide(); }

  // Full-screen overlay (each tab has its own; we’ll pick active one)
  function activeOverlay() {
    var $visibleTab = $('.acr-flow:not([hidden])');
    var $ov = $visibleTab.find('.acr-overlay');
    if ($ov.length) return $ov;
    return $('#acr-overlay');
  }
  function showOverlay(msg){
    var $ov = activeOverlay();
    if (msg) { $ov.find('.acr-msg').text(msg); }
    $('body').addClass('acr-no-scroll');
    $ov.addClass('show');
  }
  function hideOverlay(){
    var $ov = activeOverlay();
    $ov.removeClass('show');
    $('body').removeClass('acr-no-scroll');
  }

  // Success popup (works for both flows)
  var $successOverlay  = $('#acr-success-popup-overlay');
  var $successTitle    = $('.acr-success-title');
  var $successMsg      = $('.acr-success-message');
  var $btnPrimary      = $('.acr-success-btn-primary');
  var $btnSecondary    = $('.acr-success-btn-secondary');

  window.acrLastDraftId = null;
  window.acrLastKind    = null; // 'page' | 'blog'

  function openSuccessPopup(opts){
    if (!$successOverlay.length) return;
    if (opts && opts.title)         $successTitle.text(opts.title);
    if (opts && opts.message)       $successMsg.text(opts.message);
    if (opts && opts.primaryText)   $btnPrimary.text(opts.primaryText);
    if (opts && opts.secondaryText) $btnSecondary.text(opts.secondaryText);
    $successOverlay.removeAttr('hidden').addClass('is-visible');
  }
  function closeSuccessPopup(){
    $successOverlay.removeClass('is-visible');
    setTimeout(function(){ $successOverlay.attr('hidden','hidden'); }, 250);
  }
  $(document).on('click', '.acr-close-x', closeSuccessPopup);

  // PRIMARY = "Create Another"
  $(document).on('click', '.acr-success-btn-primary', function(){
    closeSuccessPopup();
    if (window.acrLastKind === 'page') {
      switchTopTab('genie');
      Genie.setStep(2);
      Genie.loadElementorPages();
    } else {
      switchTopTab('blog');
      Blog.setStep('b1');
      if (typeof Blog.loadInitial === 'function') Blog.loadInitial();
    }
  });

  // SECONDARY = "View Draft" (Elementor)
  $(document).on('click', '.acr-success-btn-secondary', function(){
    if (window.acrLastDraftId) {
      window.location.href = 'post.php?post=' + window.acrLastDraftId + '&action=elementor';
    } else {
      closeSuccessPopup();
    }
  });

  function showPopupAfterLoader(payload){
    var opts  = window.acrSuccessPopupOpts || {};
    var delay = opts.showDelayMs || 900;

    if (payload && payload.draftId) window.acrLastDraftId = payload.draftId;
    if (payload && payload.kind)    window.acrLastKind    = payload.kind;

    var poll = setInterval(function(){
      var visible = activeOverlay().hasClass('show');
      if (!visible) {
        clearInterval(poll);
        setTimeout(function(){ openSuccessPopup(opts); }, delay);
      }
    }, 100);
  }

  // TinyMCE helper
  function getTiny(id){ return (typeof tinymce!=='undefined') ? tinymce.get(id) : null; }

  // AJAX helper
  function postAjax(action, data){
    data = data || {};
    data.action = action;
    data.nonce  = (window.acr_ajax_object && acr_ajax_object.nonce) ? acr_ajax_object.nonce : '';
    return $.post((window.acr_ajax_object && acr_ajax_object.ajax_url) ? acr_ajax_object.ajax_url : ajaxurl, data);
  }


  /* ============================================================
   * TOP-LEVEL TABS (Genie / Blog Creator)
   * ============================================================ */
  var $topTabs = $('.acr-tabs .acr-tab');
  var $flows   = $('.acr-flow'); // #acr-tab-genie, #acr-tab-blog

  function switchTopTab(target){ // 'genie' | 'blog'
    $topTabs.removeClass('active').attr('aria-selected','false');
    $topTabs.filter('[data-target="'+target+'"]').addClass('active').attr('aria-selected','true');
    $flows.attr('hidden', true);
    $('.acr-flow[data-tab="'+target+'"]').removeAttr('hidden');
    $('html,body').animate({scrollTop:0},150);
  }

  $topTabs.on('click', function(e){
    e.preventDefault();
    var target = $(this).data('target');
    switchTopTab(target);
    if (target === 'blog' && typeof Blog !== 'undefined' && Blog && typeof Blog.loadInitial === 'function') {
      Blog.setStep('b1');
      Blog.loadInitial();
    }
  });


  /* ============================================================
   * TAB 1: ContentAISEO (existing flow, scoped)
   * ============================================================ */
  var Genie = (function(){
    var $root           = $('#acr-tab-genie');

    // Existing refs (scoped to Genie tab)
    var $loadingSpinner = $root.find('#acr-loading-spinner');
    var $pageSelect     = $root.find('#acr-page-select');
    var $blogSelect     = $root.find('#acr-blog-select');
    var $newTitle       = $root.find('#acr-new-title');

    var $pageFlow       = $root.find('#acr-page-flow');
    var $pagePrompt     = $root.find('#acr-ai-prompt');
    var $pageProcessBtn = $root.find('#acr-process-button');

    var $blogFlow       = $root.find('#acr-blog-flow');
    var $aiTab          = $root.find('#acr-btn-ai-mode');
    var $simpleTab      = $root.find('#acr-btn-simple-mode');
    var $aiPanel        = $root.find('#acr-ai-mode');
    var $simplePanel    = $root.find('#acr-simple-mode');

    var $aiPromptBlog   = $root.find('#acr-ai-prompt-blog');
    var $btnPreviewAI   = $root.find('#acr-generate-preview');
    var $btnApplyAI     = $root.find('#acr-apply-ai');
    var $blogSpinner    = $root.find('#acr-loading-spinner-blog');

    var $btnUploadDocx  = $root.find('#acr-upload-docx');
    var $btnApplySimple = $root.find('#acr-apply-simple');

    // state
    var contentType = null; // 'page' | 'blog'
    var selectedId  = null; // duplicated draft id

    function setStep(n){
      $root.find('.acr-step-panel').attr('hidden', true);
      $root.find('.acr-step-panel[data-step="'+n+'"]').attr('hidden', false);
      $root.find('.acr-step').removeClass('active done').each(function(){
        var s = parseInt($(this).data('step'),10);
        if (s < n) $(this).addClass('done');
        if (s === n) $(this).addClass('active');
      });
      $('html,body').animate({scrollTop:0},150);
    }

    function toggleLoading(isLoading, overlayMsg){
      if (isLoading) { $loadingSpinner.show(); showOverlay(overlayMsg || 'Processing…'); hideStatus(); }
      else { $loadingSpinner.hide(); hideOverlay(); }
    }

    function loadElementorPages() {
      $loadingSpinner.show(); showStatus('Loading Elementor pages...', 'info');
      return postAjax('acr_get_elementor_pages', {})
        .done(function(res){
          $loadingSpinner.hide();
          if (res && res.success) {
            $pageSelect.empty().append('<option value="">Select a Page or Template</option>');
            res.data.pages.forEach(function(p){ $pageSelect.append('<option value="'+p.id+'">'+p.title+'</option>'); });
            showStatus('Pages loaded.', 'success');
          } else {
            showStatus('Error loading pages: ' + (res && res.data ? res.data.message : 'Failed'), 'error');
          }
        })
        .fail(function(xhr){
          $loadingSpinner.hide();
          showStatus('AJAX Error loading pages.', 'error');
          if (xhr && xhr.responseText) console.error(xhr.responseText);
        });
    }
    function loadBlogPosts() {
      $loadingSpinner.show(); showStatus('Loading blog posts...', 'info');
      return postAjax('acr_get_blog_posts', {})
        .done(function(res){
          $loadingSpinner.hide();
          if (res && res.success) {
            $blogSelect.empty().append('<option value="">Select a Blog Post</option>');
            res.data.posts.forEach(function(p){
              $blogSelect.append('<option value="'+p.id+'">'+p.title+'</option>');
            });
            showStatus('Posts loaded.', 'success');
          } else {
            showStatus('Error loading posts: ' + (res && res.data ? res.data.message : 'Failed'), 'error');
          }
        })
        .fail(function(xhr){
          $loadingSpinner.hide();
          showStatus('AJAX Error loading posts.', 'error');
          if (xhr && xhr.responseText) console.error(xhr.responseText);
        });
    }

    // Step 1
    $root.on('change', 'input[name="acr_content_type"]', function(){
      contentType = $(this).val();
      $root.find('#acr-next-1').prop('disabled', !contentType);
    });

    $root.find('#acr-next-1').on('click', function(){
      $root.find('#acr-pick-page').prop('hidden', contentType !== 'page');
      $root.find('#acr-pick-post').prop('hidden', contentType !== 'blog');
      if (contentType === 'page') loadElementorPages();
      else loadBlogPosts();

      selectedId = null;
      $pageSelect.val(''); $blogSelect.val(''); $newTitle.val('');
      validateStep2();
      setStep(2);
    });

    // Step 2
    function chosenId(){ return (contentType === 'page') ? $pageSelect.val() : $blogSelect.val(); }
    function validateStep2(){
      var ok = !!chosenId() && !!($newTitle.val() || '').trim();
      $root.find('#acr-next-2').prop('disabled', !ok);
    }
    $pageSelect.on('change', validateStep2);
    $blogSelect.on('change', validateStep2);
    $newTitle.on('input', validateStep2);
    $root.find('#acr-back-2').on('click', function(){ setStep(1); });

    $root.find('#acr-next-2').on('click', function(e){
      e.preventDefault();
      var originalId = chosenId();
      var newTitle   = ($newTitle.val() || '').trim();
      if (!originalId) return showStatus('Please select an item.', 'error');
      if (!newTitle)   return showStatus('Please enter a new title for the copy.', 'error');

      var postType = (contentType === 'page') ? 'page' : 'post';
      showStatus('Duplicating and renaming...', 'info'); showOverlay('Duplicating and renaming…');

      postAjax('acr_duplicate_and_rename', {
        post_id: originalId,
        new_title: newTitle,
        post_type: postType
      })
      .done(function(resp){
        if (!resp || !resp.success) {
          showStatus('Failed to duplicate: ' + (resp && resp.data ? resp.data.message : 'Unknown error'), 'error');
          return;
        }
        selectedId = resp.data.new_id;
        showStatus('Created draft #' + selectedId + '. Proceeding…', 'success');

        setStep(3);
        // >>> Keep Page flow for unified UX
        $root.find('#acr-context-label').text('Step 3: Provide AI Prompt');
        $pageFlow.prop('hidden', false);
        $blogFlow.prop('hidden', true);
      })
      .fail(function(){
        showStatus('AJAX error while duplicating.', 'error');
      })
      .always(hideOverlay);
    });

    // Inner tabs (kept)
    function switchInnerTab(which){
      if (which === 'ai') {
        $aiTab.addClass('active').attr('aria-selected','true');
        $simpleTab.removeClass('active').attr('aria-selected','false');
        $aiPanel.removeClass('hidden').addClass('block');
        $simplePanel.addClass('hidden').removeClass('block');
      } else {
        $simpleTab.addClass('active').attr('aria-selected','true');
        $aiTab.removeClass('active').attr('aria-selected','false');
        $simplePanel.removeClass('hidden').addClass('block');
        $aiPanel.addClass('hidden').removeClass('block');
      }
    }
    $aiTab.on('click', function(e){ e.preventDefault(); switchInnerTab('ai'); });
    $simpleTab.on('click', function(e){ e.preventDefault(); switchInnerTab('simple'); });
    $root.find('#acr-back-3').on('click', function(){ setStep(2); });

    // ===== PAGE FLOW processing (reused) =====
    $pageProcessBtn.on('click', function(e){
      e.preventDefault();
      if (contentType !== 'page' && contentType !== 'blog') return;
      var pid = selectedId;
      var prompt = ($pagePrompt.val() || '').trim();
      if (!pid)    return showStatus('Please complete Step 2 first.', 'error');
      if (!prompt) return showStatus('Please enter your AI prompt.', 'error');

      toggleLoading(true, 'Processing with AI…');
      postAjax('acr_process_page_content', { page_id: pid, user_prompt: prompt })
        .done(function(resp){
          toggleLoading(false);
          if (resp && resp.success) {
            showStatus('Content processed successfully!', 'success');
            showPopupAfterLoader({ draftId: selectedId, kind: 'page' });
          } else {
            showStatus('Error: ' + (resp && resp.data ? resp.data.message : 'Failed'), 'error');
          }
        })
        .fail(function(xhr){
          toggleLoading(false);
          showStatus('AJAX error while processing.', 'error');
          if (xhr && xhr.responseText) console.error(xhr.responseText);
        });
    });

    // Disable old blog preview/apply (unified flow)
    $btnPreviewAI.on('click', function(e){ e.preventDefault(); return; });
    $btnApplyAI.on('click',   function(e){ e.preventDefault(); return; });

    // Simple Mode (.docx) remains (hidden by default in unified flow)
    var file_frame = null;
    $btnUploadDocx.on('click', function(e){
      e.preventDefault();
      if (typeof wp === 'undefined' || !wp.media) { alert('WordPress media library not available.'); return; }
      if (file_frame) { file_frame.open(); return; }

      file_frame = wp.media({ title: 'Upload .docx', button: { text: 'Use this document' }, multiple: false });

      file_frame.on('select', function(){
        var att = file_frame.state().get('selection').first().toJSON();
        if (!att || !att.url) return;

        if (!window.mammoth || !window.mammoth.convertToHtml) {
          alert('Mammoth.js not loaded. Please refresh.');
          return;
        }

        showOverlay('Converting document…'); hideStatus();
        fetch(att.url, { credentials:'include' })
          .then(function(res){ return res.arrayBuffer(); })
          .then(function(buf){ return window.mammoth.convertToHtml({ arrayBuffer: buf }); })
          .then(function(result){
            hideOverlay();
            var html = result && result.value ? result.value : '';
            var ed = getTiny('acr-simple-editor'); if (ed) ed.setContent(html); else $('#acr-simple-editor').val(html);
            showStatus('Document loaded into WordPad. Review & edit, then Apply.', 'success');
          })
          .catch(function(err){ hideOverlay(); console.error(err); alert('Error converting .docx'); });
      });

      file_frame.open();
    });

    $btnApplySimple.on('click', function(e){
      e.preventDefault();
      if (contentType !== 'blog') return;
      var pid = selectedId;
      if (!pid) return showStatus('Please complete Step 2 first.', 'error');

      var ed = getTiny('acr-simple-editor');
      var html = ed ? ed.getContent() : ($('#acr-simple-editor').val() || '');
      if (!html.trim()) return showStatus('WordPad is empty.', 'error');

      toggleLoading(true, 'Applying content…');
      postAjax('acr_apply_simple_update', { post_id: pid, html: html })
        .done(function(resp){
          toggleLoading(false);
          if (resp && resp.success) {
            showStatus('Content applied to Elementor.', 'success');
            showPopupAfterLoader({ draftId: selectedId, kind: 'blog' });
          } else {
            showStatus((resp && resp.data ? resp.data.message : 'Failed to apply content.'), 'error');
          }
        })
        .fail(function(xhr){
          toggleLoading(false);
          showStatus('AJAX error while applying content.', 'error');
          if (xhr && xhr.responseText) console.error(xhr.responseText);
        });
    });

    return {
      setStep: setStep,
      loadElementorPages: loadElementorPages,
      loadBlogPosts: loadBlogPosts
    };
  })();


  /* ============================================================
   * TAB 2: AI BLOG POST CREATOR (new 3-step flow, with Simple Mode)
   * ============================================================ */
  var Blog = (function(){
    var $root = $('#acr-tab-blog');

    // Stepper in this tab uses data-step=b1,b2,b3
    function setStep(stepKey){ // 'b1' | 'b2' | 'b3'
      $root.find('.acr-step-panel').attr('hidden', true);
      $root.find('.acr-step-panel[data-step="'+stepKey+'"]').removeAttr('hidden');

      // Visual stepper: map b1->1, b2->2, b3->3 for active/done classes
      var map = { b1:1, b2:2, b3:3 };
      var n = map[stepKey] || 1;
      $root.find('.acr-step').removeClass('active done').each(function(){
        var s = parseInt($(this).data('step').toString().replace('b',''),10);
        if (s < n) $(this).addClass('done');
        if (s === n) $(this).addClass('active');
      });

      $('html,body').animate({scrollTop:0},150);
    }

    // Elements
    var $templateSel = $root.find('#acr2-template');
    var $titleInput  = $root.find('#acr2-title');
    var $catSel      = $root.find('#acr2-category');
    var $references  = $root.find('#acr2-references');

    var $next1       = $root.find('#acr2-next-1');

    // Mode (AI vs Simple)
    var $modeRadios     = $root.find('input[name="acr2-mode"]');
    var $aiPanel        = $root.find('#acr2-ai-panel');
    var $simplePanel    = $root.find('#acr2-simple-panel');
    var $b3DescAI       = $root.find('#acr2-b3-desc-ai');
    var $b3DescSimple   = $root.find('#acr2-b3-desc-simple');
    var $createBtnAIb3  = $root.find('#acr2-create');
    var $createBtnSMb3  = $root.find('#acr2-create-simple');

    // AI panel controls
    var $back2       = $root.find('#acr2-back-2');
    var $genBtn      = $root.find('#acr2-generate');
    var $spinner2    = $root.find('#acr2-spinner');
    var editorIdAI   = 'acr2-editor';

    // Inline Create in AI panel
    var $createInlineAI = $root.find('#acr2-create-ai-inline');

    // Create Draft (AI) – Step b3 main button
    var $createBtn   = $root.find('#acr2-create');
    var $createSpin  = $root.find('#acr2-create-spinner');

    // Simple mode controls
    var $simpleTitle     = $root.find('#acr2-simple-title');
    var editorIdSM       = 'acr2-simple-editor';
    var $simpleCreateB2  = $root.find('#acr2-simple-create');
    var $simpleSpinB2    = $root.find('#acr2-simple-spinner');

    // DOCX controls
    var $docxBtn   = $root.find('#acr2-docx-btn');
    var $docxInput = $root.find('#acr2-docx');
    var $docxName  = $root.find('#acr2-docx-name');

    // Load template posts (optional)
    function loadTemplates(){
      return postAjax('acr2_get_templates', {})
        .done(function(res){
          $templateSel.empty()
            .append($('<option>', {
              value: '',
              text: (window.acr_strings && acr_strings.none_start_fresh) || 'None (start fresh)…'
            }));
          if (res && res.success && Array.isArray(res.data && res.data.posts)) {
            res.data.posts.forEach(function(p){
              $templateSel.append($('<option>', { value: p.id, text: p.title }));
            });
          }
        });
    }

    // Load categories (uses WP default category; server can be extended to pick project tax if present)
    function loadCategories(){
      return postAjax('acr2_get_categories', {})
        .done(function(res){
          $catSel.empty()
            .append($('<option>', {
              value: '',
              text: (window.acr_strings && acr_strings.select_category) || 'Select category…'
            }));
          if (res && res.success && Array.isArray(res.data && res.data.terms)) {
            res.data.terms.forEach(function(t){
              $catSel.append($('<option>', { value: t.id, text: t.name }));
            });
          }
        });
    }

    // Load reference posts (refresh client-side so it always stays current)
    function loadReferences(){
      if (!$references || !$references.length) return $.Deferred().resolve();
      return postAjax('acr_get_blog_posts', {})
        .done(function(res){
          $references.empty();
          if (res && res.success && Array.isArray(res.data && res.data.posts)) {
            res.data.posts.forEach(function(p){
              $references.append($('<option>', { value: p.id, text: p.title }));
            });
          }
        });
    }

    // Validate Step b1
    function validateB1(){
      var ok = !!($titleInput.val() || '').trim();
      $next1.prop('disabled', !ok);
    }
    $titleInput.on('input', validateB1);
    $templateSel.on('change', validateB1);
    $catSel.on('change', validateB1);

    // Mode switch handler
    function applyModeUI(){
      var mode = ($modeRadios.filter(':checked').val() || 'ai');
      if (mode === 'simple') {
        $aiPanel.addClass('hidden');
        $simplePanel.removeClass('hidden');
        $b3DescAI.addClass('hidden');
        $createBtnAIb3.addClass('hidden');
        $b3DescSimple.removeClass('hidden');
        $createBtnSMb3.removeClass('hidden');
      } else {
        $aiPanel.removeClass('hidden');
        $simplePanel.addClass('hidden');
        $b3DescSimple.addClass('hidden');
        $createBtnSMb3.addClass('hidden');
        $b3DescAI.removeClass('hidden');
        $createBtnAIb3.removeClass('hidden');
      }
    }
    $modeRadios.on('change', applyModeUI);

    // Step b1 → b2
    $next1.on('click', function(e){
      e.preventDefault();
      if (!($titleInput.val() || '').trim()) {
        return showStatus('Please enter a title to continue.', 'error');
      }
      setStep('b2');
      applyModeUI();
    });

    // Back b2 → b1 (AI panel back button)
    $back2 && $back2.on('click', function(e){
      e.preventDefault();
      setStep('b1');
    });

    /* ---------------------------
     * AI Mode – Generate Preview
     * --------------------------- */
    $genBtn.on('click', function(e){
      e.preventDefault();
      var title     = ($titleInput.val() || '').trim();
      var prompt    = ($root.find('#acr2-prompt').val() || '').trim();
      var template  = $templateSel.val() || '';
      var category  = $catSel.val() || '';

      if (!title)  return showStatus('Please enter a title.', 'error');
      if (!prompt) return showStatus('Please provide an AI prompt.', 'error');

      // Optional: collect reference IDs
      var referenceIds = [];
      if ($references && $references.length) {
        $references.find('option:selected').each(function(){
          var v = $(this).val();
          if (v) referenceIds.push(v);
        });
      }

      $spinner2.show(); showOverlay('Generating preview…'); hideStatus();
      var payload = {
        title: title,
        prompt: prompt,
        template_id: template,
        category_id: category
      };
      if (referenceIds.length) payload.reference_ids = referenceIds;

      postAjax('acr_blog_generate_ai', payload)
      .done(function(res){
        $spinner2.hide(); hideOverlay();
        if (!res || !res.success) {
          showStatus((res && res.data ? res.data.message : 'Failed to generate preview.'), 'error');
          return;
        }
        var html  = (res.data && res.data.html)  ? res.data.html  : '';
        var tBack = (res.data && res.data.title) ? res.data.title : '';

        var ed = getTiny(editorIdAI);
        if (ed) ed.setContent(html); else $('#'+editorIdAI).val(html);

        if (!($titleInput.val() || '').trim() && tBack) {
          $titleInput.val(tBack);
        }

        if ($createInlineAI && $createInlineAI.length) {
          $createInlineAI.prop('disabled', !html.trim());
        }

        showStatus('Preview generated. Review & edit, then Create Draft.', 'success');
      })
      .fail(function(xhr){
        $spinner2.hide(); hideOverlay();
        showStatus('AJAX error while generating preview.', 'error');
        if (xhr && xhr.responseText) console.error(xhr.responseText);
      });
    });

    /* -----------------------------------------
     * Shared function: create AI-mode draft
     * ----------------------------------------- */
    function createDraftAI() {
      var title    = ($titleInput.val() || '').trim();
      var template = $templateSel.val() || '';
      var category = $catSel.val() || '';
      var ed = getTiny(editorIdAI);
      var html = ed ? ed.getContent() : ($('#'+editorIdAI).val() || '');

      if (!title) return showStatus('Title is required.', 'error');
      if (!html.trim()) return showStatus('Preview/editor is empty. Generate or paste content first.', 'error');

      $createSpin.show(); showOverlay('Creating draft…'); hideStatus();
      return postAjax('acr_blog_create_post', {
        mode: 'ai',
        title: title,
        html: html,
        template_id: template,
        category_id: category
      })
      .done(function(res){
        $createSpin.hide(); hideOverlay();
        if (!res || !res.success) {
          showStatus((res && res.data ? res.data.message : 'Failed to create draft.'), 'error');
          return;
        }
        var postId = res.data && res.data.post_id ? res.data.post_id : null;
        if (postId) {
          window.acrLastDraftId = postId;
          window.acrLastKind    = 'blog';
          showStatus('Draft created successfully!', 'success');
          showPopupAfterLoader({ draftId: postId, kind: 'blog' });
          setStep('b3');
        } else {
          showStatus('Could not get the created draft ID.', 'error');
        }
      })
      .fail(function(xhr){
        $createSpin.hide(); hideOverlay();
        showStatus('AJAX error while creating draft.', 'error');
        if (xhr && xhr.responseText) console.error(xhr.responseText);
      });
    }

    // Step b3 “Create Draft” (AI Mode)
    $createBtn.on('click', function(e){
      e.preventDefault();
      createDraftAI();
    });

    // Inline Create (AI Mode) in Step b2 panel
    if ($createInlineAI && $createInlineAI.length) {
      $createInlineAI.on('click', function(e){
        e.preventDefault();
        createDraftAI();
      });
    }

    /* -----------------------------------------
     * Simple Mode — create directly from Step b2
     * ----------------------------------------- */
    $simpleCreateB2.on('click', function(e){
      e.preventDefault();
      var title = ($simpleTitle.val() || $titleInput.val() || '').trim();
      var category = $catSel.val() || '';
      var ed = getTiny(editorIdSM);
      var content = ed ? ed.getContent() : ($('#'+editorIdSM).val() || '');

      if (!title)   return showStatus('Please provide a Post Title for Simple Mode.', 'error');
      if (!content) return showStatus('Content is empty. Please add content.', 'error');

      $simpleSpinB2.show(); showOverlay('Creating draft…'); hideStatus();
      postAjax('acr_blog_create_post', {
        mode: 'simple',
        title: title,
        content: content,
        category_id: category
      })
      .done(function(res){
        $simpleSpinB2.hide(); hideOverlay();
        if (!res || !res.success) {
          showStatus((res && res.data ? res.data.message : 'Failed to create draft.'), 'error');
          return;
        }
        var postId = res.data && res.data.post_id ? res.data.post_id : null;
        if (postId) {
          window.acrLastDraftId = postId;
          window.acrLastKind    = 'blog';
          showStatus('Draft created successfully (Simple Mode)!', 'success');
          showPopupAfterLoader({ draftId: postId, kind: 'blog' });
          setStep('b3');
        } else {
          showStatus('Could not get the created draft ID.', 'error');
        }
      })
      .fail(function(xhr){
        $simpleSpinB2.hide(); hideOverlay();
        showStatus('AJAX error while creating draft.', 'error');
        if (xhr && xhr.responseText) console.error(xhr.responseText);
      });
    });

    // Simple Mode — create from Step b3 (alternative flow)
    $createBtnSMb3.on('click', function(e){
      e.preventDefault();
      var title = ($simpleTitle.val() || $titleInput.val() || '').trim();
      var category = $catSel.val() || '';
      var ed = getTiny(editorIdSM);
      var content = ed ? ed.getContent() : ($('#'+editorIdSM).val() || '');

      if (!title)   return showStatus('Please provide a Post Title for Simple Mode.', 'error');
      if (!content) return showStatus('Content is empty. Please add content.', 'error');

      $createSpin.show(); showOverlay('Creating draft…'); hideStatus();
      postAjax('acr_blog_create_post', {
        mode: 'simple',
        title: title,
        content: content,
        category_id: category
      })
      .done(function(res){
        $createSpin.hide(); hideOverlay();
        if (!res || !res.success) {
          showStatus((res && res.data ? res.data.message : 'Failed to create draft.'), 'error');
          return;
        }
        var postId = res.data && res.data.post_id ? res.data.post_id : null;
        if (postId) {
          window.acrLastDraftId = postId;
          window.acrLastKind    = 'blog';
          showStatus('Draft created successfully (Simple Mode)!', 'success');
          showPopupAfterLoader({ draftId: postId, kind: 'blog' });
          setStep('b3');
        } else {
          showStatus('Could not get the created draft ID.', 'error');
        }
      })
      .fail(function(xhr){
        $createSpin.hide(); hideOverlay();
        showStatus('AJAX error while creating draft.', 'error');
        if (xhr && xhr.responseText) console.error(xhr.responseText);
      });
    });

    // DOCX import (client-side)
    if ($docxBtn && $docxBtn.length && $docxInput && $docxInput.length) {
      $docxBtn.on('click', function(e){
        e.preventDefault();
        $docxInput.trigger('click');
      });

      $docxInput.on('change', function(){
        var file = this.files && this.files[0] ? this.files[0] : null;
        if (!file) return;

        if ($docxName && $docxName.length) {
          $docxName.text(file.name);
        }

        if (!window.mammoth || !window.mammoth.convertToHtml) {
          alert('Mammoth.js not loaded. Please refresh.');
          return;
        }

        var reader = new FileReader();
        reader.onload = function(ev){
          var arrayBuffer = ev.target.result;
          showOverlay('Converting document…'); hideStatus();
          window.mammoth.convertToHtml({ arrayBuffer: arrayBuffer })
            .then(function(result){
              hideOverlay();
              var html = result && result.value ? result.value : '';
              var ed = getTiny(editorIdSM);
              if (ed) ed.setContent(html);
              else $('#'+editorIdSM).val(html);
              showStatus('Document loaded into editor. Review & edit, then Create Draft.', 'success');
            })
            .catch(function(err){
              hideOverlay();
              console.error(err);
              alert('Error converting .docx');
            });
        };
        reader.onerror = function(err){
          console.error(err);
          alert('Could not read the selected file.');
        };
        reader.readAsArrayBuffer(file);
      });
    }

    // ---------- NEW: Initializer that loads all three lists ----------
    function loadInitial(){
      // Fire in parallel; don’t block UI
      try { loadTemplates(); }  catch(e){ console.error('loadTemplates error', e); }
      try { loadCategories(); } catch(e){ console.error('loadCategories error', e); }
      try { loadReferences(); } catch(e){ console.error('loadReferences error', e); }
    }

    // Public API (FIXED)
    return {
      setStep: setStep,
      loadInitial: loadInitial
    };
  })();


  /* ============================================================
   * INIT
   * ============================================================ */
  // Default to Genie tab at load
  switchTopTab('genie');
  // Initialize Genie
  Genie.setStep(1);

  // Prepare Blog tab lists so it feels instant when switched
  if (typeof Blog !== 'undefined' && Blog && typeof Blog.loadInitial === 'function') {
    Blog.loadInitial();
  }

});
