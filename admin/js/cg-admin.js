console.log('cg-admin.js loaded');

jQuery(function($){
  $('#cg_generate_btn').on('click', function(e){
    e.preventDefault();
    const prompt = $('#cg_prompt').val().trim();
    if (!prompt) { alert('Please enter a prompt'); return; }

    const options = { model: 'demo', max_tokens: 512 };

    $(this).prop('disabled', true).text('Generating...');

    $.post(cg.ajax_url, {
      action: 'cg_generate',
      _ajax_nonce: cg.nonce,
      prompt: prompt,
      options: JSON.stringify(options)
    })
    .done(function(res){
      if (res && res.success) {
        const data = res.data;
        const html = `
          <p><strong>Title:</strong> ${data.title ?? '(no title)'}</p>
          ${(data.sections||[]).map(s => `
            <h3>${s.heading||''}</h3>
            <p>${s.text||''}</p>
          `).join('')}
          ${(data.faq||[]).map(f => `
            <p><strong>Q:</strong> ${f.q||''}<br><strong>A:</strong> ${f.a||''}</p>
          `).join('')}
        `;
        $('#cg_result').html(html);
      } else {
        alert(res?.data?.message || 'Unknown error');
      }
    })
    .fail(function(xhr){
      const msg = xhr?.responseJSON?.data?.message || xhr?.statusText || 'Request failed';
      alert('Error: ' + msg);
    })
    .always(()=>{
      $('#cg_generate_btn').prop('disabled', false).text('Generate');
    });
  });
});
