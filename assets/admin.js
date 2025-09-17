jQuery(function($){
  if(!window.PB_MGR){ window.PB_MGR = {}; }
  if(!PB_MGR.ajax_url && typeof ajaxurl!=='undefined'){ PB_MGR.ajax_url = ajaxurl; }
  console.log('[PB_MGR] admin.js loaded');

  const $pat = $('#pb-mgr-pattern');
  const $useTitle = $('#pb-mgr-use-title');
  const $maxLen = $('#pb-mgr-max-len');
  const $batch = $('#pb-mgr-batch');
  const $sleepFile = $('#pb-mgr-sleep-file');
  const $replace = $('#pb-mgr-replace');

  const $start = $('#pb-mgr-start');
  const $stop = $('#pb-mgr-stop');
  const $reset = $('#pb-mgr-reset');
  const $togglePause = $('#pb-mgr-toggle-pause');
  const $statusBtn = $('#pb-mgr-status-btn');
  const $status = $('#pb-mgr-status');
  const $progress = $('#pb-mgr-index-progress');
  const $bar = $progress.find('.bar');
  const $text = $progress.find('.text');
  const $log = $('#pb-mgr-live-log');

  const $prefix = $('#pb-mgr-prefix');
  const $buildIndex = $('#pb-mgr-build-index-prefix');
  const $dedupe = $('#pb-mgr-index-dedupe');

  function pct(scanned, batch){
    if(!batch) return 0;
    return Math.min(100, Math.round((scanned % batch)/batch*100));
  }

  async function refreshLog(){
    try{
      const r = await $.post(PB_MGR.ajax_url,{action:'pb_mgr_get_log',_wpnonce:PB_MGR.nonce});
      if(r && r.success){
        const lines = r.data.lines||[];
        $log.text(lines.join("\n"));
      }
    }catch(e){}
  }
  setInterval(refreshLog, 5000);
  refreshLog();

  async function status(){
    try{
      const r = await $.post(PB_MGR.ajax_url,{action:'pb_mgr_bg_status',_wpnonce:PB_MGR.nonce});
      if(r && r.success){
        const st = r.data.state||{};
        $status.text(`running: ${!!st.running} | last_id: ${st.last_id||0} | scanned: ${st.scanned||0} | renamed: ${st.renamed||0}`);
        const p = pct(st.scanned||0, st.batch||0);
        $bar.css('width', p+'%');
        $text.text(`scanned: ${st.scanned||0} | renamed: ${st.renamed||0}`);
        $togglePause.text((window._pb_paused? 'Resume Background (Paused)' : 'Pause Background (Running)'));
      }
    }catch(e){}
  }
  setInterval(status, 5000);
  status();

  $start.on('click', async function(e){
    e.preventDefault();
    const payload = {
      action:'pb_mgr_bg_start', _wpnonce:PB_MGR.nonce,
      pattern:$pat.val(),
      use_title:$useTitle.is(':checked')?1:0,
      max_len:parseInt($maxLen.val()||100,10),
      batch:parseInt($batch.val()||500,10),
      sleep_file_ms:parseInt($sleepFile.val()||3,10),
      replace:$replace.is(':checked')?1:0
    };
    const r = await $.post(PB_MGR.ajax_url, payload);
    if(!r || !r.success){ alert('Start failed'); return; }
    status(); refreshLog();
  });

  $stop.on('click', async function(e){
    e.preventDefault();
    await $.post(PB_MGR.ajax_url,{action:'pb_mgr_bg_stop',_wpnonce:PB_MGR.nonce});
    status();
  });

  $togglePause.on('click', async function(e){
    e.preventDefault();
    const r = await $.post(PB_MGR.ajax_url,{action:'pb_mgr_toggle_pause',_wpnonce:PB_MGR.nonce});
    if(r && r.success){
      window._pb_paused = !!r.data.paused;
      $togglePause.text(window._pb_paused? 'Resume Background (Paused)' : 'Pause Background (Running)');
    }
  });

  $statusBtn.on('click', function(e){ e.preventDefault(); status(); refreshLog(); });

  $buildIndex.on('click', async function(e){
    e.preventDefault();
    const r = await $.post(PB_MGR.ajax_url,{action:'pb_mgr_build_index_prefix',_wpnonce:PB_MGR.nonce, prefix:$prefix.val()||''});
    if(!r || !r.success){ alert('Index build failed'); return; }
    alert('Indexed: '+(r.data.indexed||0));
  });

  $dedupe.on('click', async function(e){
    e.preventDefault();
    const r = await $.post(PB_MGR.ajax_url,{action:'pb_mgr_index_dedupe',_wpnonce:PB_MGR.nonce});
    if(!r || !r.success){ alert('Index dedupe failed'); return; }
    alert('Index dedupe removed: '+(r.data.removed||0));
  });
});

  $reset && $reset.on('click', async function(e){
    e.preventDefault();
    if(!confirm('Reset progress? This will start from the beginning.')) return;
    try{
      const r = await $.post(PB_MGR.ajax_url || ajaxurl, {action:'pb_mgr_reset_progress', _wpnonce:PB_MGR.nonce});
      if(!r || !r.success){ alert('Reset failed. Try the "Reset progress (non‑AJAX)" button below.'); return; }
      if(window.reset_ok_dom_notice){ reset_ok_dom_notice('✅ Progress reset. You can press Start again.'); } else { alert('Progress reset. You can press Start again.'); }
    }catch(err){ console.error(err); alert('Reset failed (AJAX error)'); }
  });



jQuery(function($){
  if(!window.PB_MGR){ window.PB_MGR = {}; }
  if(!PB_MGR.ajax_url && typeof ajaxurl!=='undefined'){ PB_MGR.ajax_url = ajaxurl; }
  if($('#pb-mgr-reset').length===0){
    const $stop = $('#pb-mgr-stop');
    if($stop.length){
      const $btn = $('<button/>', {id:'pb-mgr-reset', class:'button', type:'button', text:'Reset progress'});
      $btn.insertAfter($stop);
    }else{
      // fallback: append at bottom of wrap
      const $wrap = $('.wrap').first();
      if($wrap.length){
        $wrap.append('<p class="submit"><button id="pb-mgr-reset" class="button" type="button">Reset progress</button></p>');
      }
    }
  }
  // Click handler
  $(document).on('click', '#pb-mgr-reset', async function(e){
    e.preventDefault();
    if(!confirm('Reset progress? This will start from the beginning.')) return;
    try{
      const r = await $.post(PB_MGR.ajax_url || ajaxurl, {action:'pb_mgr_reset_progress', _wpnonce:PB_MGR.nonce});
      if(!r || !r.success){ alert('Reset failed. Try the "Reset progress (non‑AJAX)" button below.'); return; }
      if(window.reset_ok_dom_notice){ reset_ok_dom_notice('✅ Progress reset. You can press Start again.'); } else { alert('Progress reset. You can press Start again.'); }
    }catch(err){ console.error(err); alert('Reset failed (AJAX error)'); }
  });
});


jQuery(function($){
  function pb_mgr_status(){
    return $.post(PB_MGR.ajax_url || ajaxurl, {action:'pb_mgr_bg_status', _wpnonce:PB_MGR.nonce});
  }
  $(document).on('click','#pb-mgr-reset',async function(e){
    e.preventDefault();
    if(!confirm('Reset progress? This will start from the beginning.')) return;
    try{
      const r = await $.post(PB_MGR.ajax_url || ajaxurl, {action:'pb_mgr_reset_progress', _wpnonce:PB_MGR.nonce});
      console.log('[PB_MGR] reset response:', r);
      if(!r || !r.success){ alert('Reset failed. Try the "Reset progress (non‑AJAX)" button below.'); return; }
      try{ const s = await pb_mgr_status(); console.log('[PB_MGR] status after reset:', s);}catch(e){console.warn('status fetch failed', e);}
      if(window.reset_ok_dom_notice){ reset_ok_dom_notice('✅ Progress reset. You can press Start again.'); } else { alert('Progress reset. You can press Start again.'); }
    }catch(err){ console.error(err); alert('Reset failed (AJAX error)'); }
  });
});

jQuery(function($){
  $(document).on('click','#pb-mgr-reset',async function(e){
    // If this handler already exists, don't duplicate logic; rely on previous success flow
  });
  function pb_mgr_dom_notice_ok(msg){
    var $wrap = $('.wrap').first();
    if(!$wrap.length) return;
    var $n = $('<div class="notice notice-success is-dismissible"><p>'+ (msg||'Progress reset.') +'</p></div>');
    $wrap.prepend($n);
  }
  window.reset_ok_dom_notice = pb_mgr_dom_notice_ok;
});

jQuery(function($){
  const $btnMissing = $('#pb-mgr-list-missing');
  const $report = $('#pb-mgr-missing-report');
  $btnMissing.on('click', async function(e){
    e.preventDefault();
    $btnMissing.prop('disabled', true).text('Scanning...');
    try{
      const r = await $.post(PB_MGR.ajax_url || ajaxurl, {action:'pb_mgr_list_missing', _wpnonce:PB_MGR.nonce, limit:500});
      if(r && r.success){
        $report.html(r.data.html).show();
      }else{
        $report.html('<p>Failed to fetch report.</p>').show();
      }
    }catch(err){
      console.error(err);
      $report.html('<p>AJAX error while fetching missing files.</p>').show();
    }finally{
      $btnMissing.prop('disabled', false).text('List missing files');
    }
  });
});
