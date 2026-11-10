(()=>{
const $=s=>document.querySelector(s);
const esc=s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
// A safe zone is a list of rings. rings[0] is the outer edge; rings[1] (optional) is the inner edge of a
// loop corridor, so the middle of the loop is NOT safe. An old single-ring zone is just rings=[[...]].
let siteId='pup',site=null,zone=null,baseCamp=null,rings=[[]],view={lat0:0,lon0:0,minX:-100,maxX:100,minY:-100,maxY:100},drag=null,gpxSummary='',gpxMetadata=null;
const status=m=>{const el=$('#editor-status');el.textContent=m;el.classList.toggle('is-loading',m==='Loading site and active polygon…');},siteSelect=$('#site-select'),svg=$('#editor-svg');
const csrf=()=>document.cookie.split('; ').find(c=>c.startsWith('TRAILWATCH_CSRF='))?.split('=').slice(1).join('=')||'';
const logout=()=>{const host=document.querySelector('.editor-head');if(!host||host.querySelector('.operator-logout'))return;const form=document.createElement('form');form.className='operator-logout';form.method='post';form.action='logout.php';form.innerHTML=`<input type="hidden" name="csrf_token" value="${csrf()}"><button type="submit">Sign out</button>`;host.append(form);};logout();
const request=async(path,options={})=>{const r=await fetch(`api/${path}`,{cache:'no-store',...options,headers:{'Content-Type':'application/json','X-CSRF-Token':csrf(),...(options.headers||{})}});const d=await r.json();if(r.status===401){location.href='login.php';throw Error(d.error||'Sign in again');}if(!r.ok)throw Error(d.error||`HTTP ${r.status}`);return d;};
const project=p=>({x:(p.longitude-view.lon0)*Math.cos(view.lat0*Math.PI/180)*111320,y:(p.latitude-view.lat0)*110540});
const screen=p=>{const q=project(p),w=800,h=520,pad=48,s=Math.min((w-2*pad)/(view.maxX-view.minX||1),(h-2*pad)/(view.maxY-view.minY||1));return{x:pad+(q.x-view.minX)*s,y:h-pad-(q.y-view.minY)*s};};
const hasCoordinates=v=>Number.isFinite(Number(v?.latitude))&&Number.isFinite(Number(v?.longitude))&&v.latitude!=null&&v.longitude!=null&&v.latitude!==''&&v.longitude!=='';
const allVertices=()=>rings.flat();
const label=(r,i)=>`${r===0?'':'H'}${i+1}`; // outer vertices 1,2,3...; inner (hole) vertices H1,H2,...

function fit(){
  const points=allVertices().filter(hasCoordinates);
  if(baseCamp)points.unshift(baseCamp);
  if(!points.length&&site?.center)points.push(site.center);
  if(!points.length){view={lat0:0,lon0:0,minX:-100,maxX:100,minY:-100,maxY:100};return;}
  view.lat0=points[0].latitude;view.lon0=points[0].longitude;
  const m=points.map(project),pad=30;
  view.minX=Math.min(...m.map(p=>p.x))-pad;view.maxX=Math.max(...m.map(p=>p.x))+pad;
  view.minY=Math.min(...m.map(p=>p.y))-pad;view.maxY=Math.max(...m.map(p=>p.y))+pad;
}
function renderMap(){
  fit();
  $('#editor-map-empty').hidden=allVertices().some(hasCoordinates);
  const grid=$('#editor-grid');grid.innerHTML='';
  const w=800,h=520,step=10**Math.floor(Math.log10(Math.max(view.maxX-view.minX,view.maxY-view.minY)/5||1));
  let lines='';
  for(let x=Math.ceil(view.minX/step)*step;x<=view.maxX;x+=step){
    const a=screen({latitude:view.lat0+view.minY/110540,longitude:view.lon0+x/(Math.cos(view.lat0*Math.PI/180)*111320)});
    lines+=`<line x1="${a.x}" y1="0" x2="${a.x}" y2="${h}"/><text x="${a.x+4}" y="${h-7}">${Math.round(x)} m</text>`;
  }
  grid.innerHTML=lines;
  const ringPts=rings.map((ring,r)=>ring.map((v,i)=>hasCoordinates(v)?{r,i,p:screen(v)}:null).filter(Boolean));
  // One path with several sub-paths; fill-rule="evenodd" on the element leaves the inner ring empty.
  $('#editor-poly').setAttribute('d',ringPts.filter(rp=>rp.length>=2).map(rp=>'M'+rp.map(q=>`${q.p.x},${q.p.y}`).join(' L ')+' Z').join(' '));
  $('#editor-points').innerHTML=ringPts.flat().map(({r,i,p})=>`<g class="edit-point" data-r="${r}" data-i="${i}" transform="translate(${p.x} ${p.y})"><circle r="9"/><text y="4" text-anchor="middle">${label(r,i)}</text></g>`).join('');
  updateLoopGuard();
}
function render(){renderMap();rows();}
function rows(){
  const rows=rings.flatMap((ring,r)=>ring.map((v,i)=>`<tr><td>${label(r,i)}</td><td><input type="number" step="0.0000001" data-r="${r}" data-i="${i}" data-key="latitude" value="${v.latitude}"></td><td><input type="number" step="0.0000001" data-r="${r}" data-i="${i}" data-key="longitude" value="${v.longitude}"></td><td><button type="button" data-delete-r="${r}" data-delete="${i}" aria-label="Delete vertex ${label(r,i)}">×</button></td></tr>`)).join('');
  $('#vertex-rows').innerHTML=rows||'<tr><td class="empty" colspan="4">No vertices yet</td></tr>';
}
const validate=()=>TrailGeometry.validateRings(rings);
const meters=value=>value==null||value===''?'—':`${Number(value).toFixed(2)} m`;
const count=value=>value==null||value===''?'—':String(value);
function renderMetadataList(id,rows){const list=$(id);list.innerHTML=rows.map(([label,value])=>`<dt>${esc(label)}</dt><dd>${esc(value==null||value===''?'—':String(value))}</dd>`).join('');}
function renderSavedMetadata(saved){renderMetadataList('#saved-metadata',[
  ['GPX file name',saved?.gpx_file_name],['Raw GPX points',count(saved?.gpx_point_count)],['Simplified points',count(saved?.simplified_point_count)],
  ['Simplification tolerance',saved?.simplify_tolerance_m==null?'—':meters(saved.simplify_tolerance_m)],['Largest deviation',saved?.simplify_max_deviation_m==null?'—':meters(saved.simplify_max_deviation_m)],
  ['Recorded track length',saved?.recorded_track_length_m==null?'—':meters(saved.recorded_track_length_m)],['Ground-measured length',saved?.ground_length_m==null?'—':meters(saved.ground_length_m)],
  ['Margin per side',saved?.margin_m==null?'—':meters(saved.margin_m)],['Corridor width',saved?.corridor_width_m==null?'—':meters(saved.corridor_width_m)],
  ['Recorded minus ground',saved?.recorded_minus_ground_difference_m==null?'—':meters(saved.recorded_minus_ground_difference_m)],['Recording note',saved?.recording_note]
]);}
function updateLoopGuard(){const warning=$('#loop-guard-warning'),save=$('#save-zone'),state=TrailGeometry.loopGuardState(rings);const failed=rings.length===2&&!state.safe;warning.hidden=!failed;save.disabled=failed;return state;}
function updateMarginWarning(){const value=Number($('#gpx-width').value);$('#margin-warning').hidden=!Number.isFinite(value)||value<=0||value>=10;}
function showGpxPreview(meta,closed){const ground=$('#ground-length').value.trim(),parsedGround=ground===''?null:Number(ground),groundLength=Number.isFinite(parsedGround)?parsedGround:null,difference=groundLength===null?null:meta.recorded_track_length_m-groundLength;
  const rows=[['GPX file name',meta.gpx_file_name],['Raw point count',meta.gpx_point_count],['Simplified point count',meta.simplified_point_count],['Simplification tolerance',meta.simplify_tolerance_m==null?'Not applied':meters(meta.simplify_tolerance_m)],['Largest deviation',meters(meta.simplify_max_deviation_m)],['Raw track length',meters(meta.recorded_track_length_m)],['Margin per side',meters(meta.margin_m)],['Corridor width',meters(2*meta.margin_m)],['Ring count',rings.filter(r=>r.length>=3).length],['Recorded minus ground',difference===null?'—':meters(difference)],['Loop rule',closed?'middle of the loop: OUTSIDE':'Open track; no loop middle']];
  renderMetadataList('#gpx-preview-details',rows);$('#gpx-preview').hidden=false;
}

async function load(){
  const [sites,sz]=await Promise.all([request('sites'),request('safe-zone')]);
  siteId=sites.selected_site;site=sites.sites.find(s=>s.id===siteId);
  siteSelect.innerHTML=sites.sites.map(s=>`<option value="${esc(s.id)}" ${s.id===siteId?'selected':''}>${esc(s.display_name)}${s.is_rehearsal?' · UNSCORED REHEARSAL':''}</option>`).join('');
  zone=sz.safe_zone;
  baseCamp=(sz.network_nodes||[]).find(n=>n.node_type==='BASE_CAMP'&&n.latitude!=null&&n.longitude!=null)||null;
  const src=zone?.rings?.length?zone.rings:[zone?.vertices||[]];
  rings=src.map(r=>r.map(v=>({latitude:+v.latitude,longitude:+v.longitude})));
  $('#zone-name').value=zone?.name||'';
  renderSavedMetadata(zone);
  $('#editor-map-title').textContent=site.display_name;
  $('#polygon-version').textContent=zone?`ACTIVE · v${zone.version}`:'NO ACTIVE POLYGON';
  $('#site-reference-list').innerHTML=[...(sz.reference_points||[]),...(sz.network_nodes||[]).map(n=>({label:n.display_name,point_type:n.node_type,latitude:n.latitude,longitude:n.longitude}))].map(p=>`<span class="reference-chip">${esc(p.label)}${p.unsurveyed?" · UNSURVEYED":""}${p.latitude!=null?` · ${p.latitude}, ${p.longitude}`:' · position unset'}</span>`).join('')||'<p>No reference coordinates configured for this site.</p>';
  render();
  status(site.center_status+(zone?` · Active polygon version ${zone.version}${zone.rings?.length>1?' · loop corridor (outer + inner edge)':''}`:' · No active safe zone'));
}
siteSelect.addEventListener('change',async()=>{try{await request('settings/site',{method:'POST',body:JSON.stringify({site_id:siteSelect.value})});location.reload();}catch(e){status(e.message);}});
$('#vertex-rows').addEventListener('input',e=>{
  const r=Number(e.target.dataset.r),i=Number(e.target.dataset.i),k=e.target.dataset.key;
  if(k&&rings[r]?.[i]){rings[r][i][k]=e.target.value===''?'':Number(e.target.value);renderMap();status(validate()||'Draft updated.');}
});
$('#vertex-rows').addEventListener('click',e=>{
  if(e.target.dataset.delete===undefined)return;
  const r=Number(e.target.dataset.deleteR),i=Number(e.target.dataset.delete);
  if(Number.isInteger(r)&&Number.isInteger(i)&&rings[r]){rings[r].splice(i,1);render();status(validate()||'Vertex removed.');}
});
// New vertices go on the outer ring (ring 0).
$('#add-vertex').addEventListener('click',()=>{
  rings[0].push({latitude:baseCamp?.latitude??site?.center?.latitude??'',longitude:baseCamp?.longitude??site?.center?.longitude??''});
  render();status(site?.center?'New vertex added to the outer ring; adjust its coordinates.':'Enter coordinates for the new vertex. This site has no confirmed map center yet.');
});
function coordinateAt(e){
  if(!baseCamp&&!site?.center&&!allVertices().some(hasCoordinates))return null;
  const b=svg.getBoundingClientRect(),px=(e.clientX-b.left)*800/b.width,py=(e.clientY-b.top)*520/b.height,s=Math.min(704/(view.maxX-view.minX||1),424/(view.maxY-view.minY||1)),qx=view.minX+(px-48)/s,qy=view.minY+(472-py)/s;
  return{latitude:view.lat0+qy/110540,longitude:view.lon0+qx/(Math.cos(view.lat0*Math.PI/180)*111320)};
}
svg.addEventListener('click',e=>{
  if(e.target.closest('.edit-point'))return;
  const point=coordinateAt(e);
  if(!point){status('Enter at least one confirmed coordinate first; this site has no confirmed center.');return;}
  rings[0].push(point);render();status('Vertex added to the outer ring from local map click.');
});
svg.addEventListener('pointerdown',e=>{const p=e.target.closest('.edit-point');if(p){drag={r:Number(p.dataset.r),i:Number(p.dataset.i)};svg.setPointerCapture(e.pointerId);}});
svg.addEventListener('pointermove',e=>{if(!drag)return;const point=coordinateAt(e);if(point){rings[drag.r][drag.i]=point;renderMap();}});
svg.addEventListener('pointerup',()=>{if(drag)status(validate()||'Vertex moved.');drag=null;});

$('#preview-gpx').addEventListener('click',async()=>{
  try{
    const file=$('#gpx-file').files[0];if(!file)throw Error('Choose a GPX file first.');
    const width=Number($('#gpx-width').value);if(!Number.isFinite(width)||width<5||width>50)throw Error('Half-width must be from 5 to 50 m.');
    const xml=new DOMParser().parseFromString(await file.text(),'application/xml');
    if(xml.querySelector('parsererror'))throw Error('GPX XML could not be read.');
    const segments=[...xml.querySelectorAll('trkseg,rte')];
    if(segments.length>1)throw Error('GPX contains multiple segments or routes. Import one continuous route at a time so disconnected tracks are not joined.');
    const source=segments[0]||xml.documentElement,track=[...source.querySelectorAll('trkpt,rtept')].map(p=>({latitude:Number(p.getAttribute('lat')),longitude:Number(p.getAttribute('lon'))}));
    if(track.length<2)throw Error('GPX needs at least two track or route points.');
    const originalCount=track.length,rawTrackLength=TrailGeometry.trackLengthMeters(track);let simplified=track,deviation=0,tolerance=null;
    if(track.length>250){
      tolerance=Number($('#gpx-tolerance').value);if(!Number.isFinite(tolerance)||tolerance<=0)throw Error('Simplification tolerance must be greater than 0 meters.');
      const result=TrailGeometry.simplifyTrack(track,tolerance);simplified=result.points;deviation=result.maxDeviationMeters;
      if(simplified.length>250)throw Error(`Simplification kept ${simplified.length} points, above the 250-point limit. Increase the tolerance (currently ${tolerance} m) and preview again.`);
    }
    const corridor=TrailGeometry.bufferTrackRings(simplified,width);
    const problem=TrailGeometry.validateRings(corridor.rings);
    if(problem)throw Error(`${problem} Try a smaller half-width.`);
    gpxMetadata={gpx_file_name:file.name,gpx_point_count:originalCount,simplified_point_count:simplified.length,simplify_tolerance_m:tolerance,simplify_max_deviation_m:deviation,recorded_track_length_m:rawTrackLength,margin_m:width};
    gpxSummary=originalCount>250?`GPX simplified ${originalCount} → ${simplified.length} points using ${tolerance} m tolerance; measured maximum deviation ${deviation.toFixed(2)} m`:`GPX ${originalCount} points; no simplification needed`;
    rings=corridor.rings;render();showGpxPreview(gpxMetadata,corridor.closed);updateMarginWarning();
    const shape=corridor.closed?`closed loop: ${rings.length} rings; middle of the loop: OUTSIDE`:`open track: ${rings.length} ring`;
    const guard=updateLoopGuard();status(guard.checked&&!guard.safe?'Inner-ring check failed: the middle classifies SAFE. Edit the corridor before saving.':`${gpxSummary}. Corridor preview: ${shape}, ${width} m margin per side (${2*width} m total width). Review before saving.`);
  }catch(e){status(`GPX preview failed: ${e.message}`);}
});
$('#gpx-width').addEventListener('input',updateMarginWarning);$('#ground-length').addEventListener('input',()=>{if(gpxMetadata)showGpxPreview(gpxMetadata,rings.length===2);});updateMarginWarning();
$('#save-zone').addEventListener('click',async()=>{
  const toSave=rings.filter(r=>r.length);
  const error=TrailGeometry.validateRings(toSave);if(error){status(error);return;}
  const loop=TrailGeometry.loopGuardState(toSave);if(toSave.length===2&&!loop.safe){updateLoopGuard();status('The point inside the inner ring classifies SAFE. Edit the loop before saving.');return;}
  const name=$('#zone-name').value.trim();if(!name){status('Enter a polygon name.');return;}
  const groundText=$('#ground-length').value.trim(),groundLength=groundText===''?null:Number(groundText);if(groundLength!==null&&(!Number.isFinite(groundLength)||groundLength<0)){status('Ground-measured length must be a non-negative number.');return;}
  if(!confirm(`Save this as a new active ${site.display_name} safe-zone version?`))return;
  try{
    const payload={site_id:siteId,name,rings:toSave,created_by:'local-operator'};if(gpxMetadata)Object.assign(payload,gpxMetadata);if(groundLength!==null)payload.ground_length_m=groundLength;const note=$('#recording-note').value.trim();if(note)payload.recording_note=note;
    const d=await request('safe-zone',{method:'PUT',body:JSON.stringify(payload)});
    zone=d.safe_zone;renderSavedMetadata(zone);$('#polygon-version').textContent=`ACTIVE · v${zone.version}`;
    status(`${gpxSummary?`${gpxSummary}. `:''}Saved new active version ${zone.version}.`);
  }catch(e){status(e.message);}
});
load().catch(e=>status(`Could not load editor: ${e.message}`));
})();
