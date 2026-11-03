(async()=>{
  const q=s=>document.querySelector(s);
  const siteSelect=q('#settings-site');
  const phaseSelect=q('#phase-mode');
  const request=async(path,options={})=>{
    const response=await fetch(`api/${path}`,{cache:'no-store',...options});
    const data=await response.json();
    if(!response.ok)throw Error(data.error||'Request failed');
    return data;
  };
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
