(async()=>{
  const q=s=>document.querySelector(s);
  const siteSelect=q('#settings-site');
  const phaseSelect=q('#phase-mode');
  const modeSelect=q('#mode-select');
  const request=async(path,options={})=>{
    const response=await fetch(`api/${path}`,{cache:'no-store',...options});
    const data=await response.json();
    if(!response.ok)throw Error(data.error||'Request failed');
    return data;
  };
  const refreshMode=async()=>{const s=await request('status');const label=s.active_mode==='LIVE'?'LIVE DATABASE':'DEMO / SIMULATION DATABASE';q('#mode-large').textContent=label;q('#global-mode-banner').textContent=label;q('#global-mode-banner').className=`global-mode-banner ${s.active_mode==='LIVE'?'mode-live':'mode-demo'}`;document.title=`${s.active_mode} · Settings · Trailwatch`;q('#database-check').textContent=`Active database: ${s.active_database} · rows flagged demo/rehearsal/test: ${s.demo_row_count}`;if(s.live_demo_count_error)q('#database-check').classList.add('database-error');q('#clear-demo-panel').hidden=s.active_mode==='LIVE';modeSelect.value=s.active_mode;};
  refreshMode().catch(e=>q('#database-check').textContent=`Database check unavailable: ${e.message}`);
  q('#apply-mode').addEventListener('click',async()=>{const next=modeSelect.value;const message=next==='LIVE'?'Switch to LIVE? The dashboard will use the separate hiker_dashboard_live database, it contains no demo data, and demo/rehearsal/test packets will be rejected. DEMO data stays in its own database.':'Switch to DEMO / SIMULATION? The LIVE database and all its data stay untouched in their own database.';if(!confirm(message))return;try{await request('settings/mode',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({mode:next,confirmed:true})});location.reload();}catch(e){alert(`Could not switch mode: ${e.message}`);}});
  q('#clear-demo').addEventListener('click',async()=>{const confirmation=q('#clear-demo-confirmation').value;if(confirmation!=='CLEAR'){alert('Type CLEAR exactly before continuing.');return;}if(!confirm('A fresh backup will be saved under backups/<timestamp> before demo rows are cleared. Unclassified legacy rows will be kept. Continue?'))return;try{const r=await request('settings/clear-demo',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({confirmation})});const kept=r.legacy_rows_kept??0;alert(`Demo rows cleared. ${kept} legacy rows kept (positions: ${r.legacy_rows_kept_detail?.positions??0}, alerts: ${r.legacy_rows_kept_detail?.alerts??0}). Backup: ${r.backup}`);location.reload();}catch(e){alert(`Could not clear demo data: ${e.message}`);}});
  try{
    const data=await request('sites');
    siteSelect.innerHTML=data.sites.map(site=>`<option value="${site.id}" ${site.id===data.selected_site?'selected':''}>${site.display_name}${site.is_rehearsal?' · UNSCORED REHEARSAL':''}</option>`).join('');
    const showSite=()=>{
      const site=data.sites.find(item=>item.id===siteSelect.value);
      q('#site-description').textContent=`${site.center_status}. Default zoom ${site.zoom}.`;
      q('#site-config-detail').textContent=site.center?`Map center: ${site.center.latitude}, ${site.center.longitude}`:'Center is unset; configure it in config/sites.php after confirmation.';
    };
    showSite();
    siteSelect.addEventListener('change',async()=>{
      try{await request('settings/site',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({site_id:siteSelect.value})});showSite();}
      catch(error){q('#site-description').textContent=`Could not save site: ${error.message}`;}
    });
  }catch(error){q('#site-description').textContent=`Settings unavailable: ${error.message}`;}
  try{
    const phase=await request('settings/phase-mode');phaseSelect.value=phase.phase_mode;
    const showPhase=()=>{q('#phase-mode-description').textContent=phaseSelect.value==='PHASE2'?'Storm turn-back campaigns are disabled in Phase 2. Active campaigns are disabled and will not resume automatically.':`Storm turn-back campaigns are enabled in ${phaseSelect.value.replace('PHASE','Phase ')}.`;};
    showPhase();
    phaseSelect.addEventListener('change',async()=>{
      phaseSelect.disabled=true;
      try{const result=await request('settings/phase-mode',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({phase_mode:phaseSelect.value})});phase.phase_mode=result.phase_mode;showPhase();}
      catch(error){q('#phase-mode-description').textContent=`Could not save phase: ${error.message}`;}
      finally{phaseSelect.disabled=false;}
    });
  }catch(error){q('#phase-mode-description').textContent=`Phase setting unavailable: ${error.message}`;}
})();
