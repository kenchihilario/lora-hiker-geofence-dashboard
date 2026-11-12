const fs = require('fs');
const assert = require('assert');

const settings = fs.readFileSync('public/assets/js/site-settings.js', 'utf8');
const dashboard = fs.readFileSync('public/assets/js/dashboard.js', 'utf8');
const markup = fs.readFileSync('public/settings.php', 'utf8');
const index = fs.readFileSync('public/index.php', 'utf8');
const css = fs.readFileSync('public/assets/css/dashboard.css', 'utf8');
function check(condition, label) {
  if (!condition) {
    console.error(`FAIL ${label}`);
    process.exit(1);
  }
  console.log(`PASS ${label}`);
}

check(/await loadRegistry\(\);\s*await loadTestSession\(\);/.test(settings), 'changing the selected site refreshes the active-session status');
check(settings.includes('${s.site_id}') && settings.includes('s.phase?'), 'active-session status includes site and phase');
check(settings.includes('const sessionSummary=') && settings.includes('sessionLabel.replaceChildren(message)') && settings.includes('window.TrailwatchUtcTime?.parseDatabaseUtc(String(s.started_at))') && settings.includes('startedTime.title=`UTC: ${s.started_at}`') && settings.includes('startedTime.textContent=date.toLocaleString()') && settings.includes('startedTime.textContent=String(s.started_at)'), 'active-session start time renders locally with UTC title and a safe raw-value fallback');
check(settings.includes('setSessionStatus(`Could not start session: ${error.message}`,true)'), 'session start failures appear in the session status panel');
check(/NO ACTIVE SESSION[^`]*HTTP 409/.test(settings), 'empty session status explains that uplinks are rejected with HTTP 409');
check(settings.includes('startButton.disabled=!data.session_schema_available'), 'session start control is disabled when migration 010 is absent');
check(/id="no-active-session"/.test(index), 'dashboard markup has a no-active-session banner');
check(/last reported[^`]*heartbeat/.test(dashboard), 'bridge tile wording distinguishes reported state from heartbeat age');
check(/id="test-session-active"/.test(markup), 'Settings has an existing status element for session errors and success details');
check(/<option value="BENCH">BENCH · Bench session<\/option>/.test(markup), 'Settings offers BENCH as a session phase');
check(settings.includes('differs from dashboard phase') && settings.includes('phaseLabel(phaseMode)'), 'session phase mismatch warning names both phase values');
check(settings.includes('Bench session switch is OFF') && settings.includes("activeMode==='LIVE'"), 'LIVE bench switch warning is non-blocking and phase-aware');
check(settings.includes("value==='BENCH'?'BENCH'") && dashboard.includes('p.session_phase'), 'BENCH phase is formatted and exposed in packet rows');
for (const id of ['packet-annotation-panel','packet-annotation-summary','packet-annotation-mismatches','packet-annotation-range-form','annotation-session-id','annotation-node-id','annotation-sequence-from','annotation-sequence-to','annotation-intended-class','annotation-point-source','annotation-set-button','annotation-clear-button','packet-annotation-status']) {
  check(markup.includes(`id="${id}"`), `Settings annotation form includes #${id}`);
}
check(settings.includes("request(`annotations/summary?session_id=") && settings.includes("request('annotations/range'") && settings.includes('annotationSessionId.value=sessionId'), 'annotation summary and range actions use the active session by default');
check(settings.includes("'No active test session. Start a session to annotate its packets.'") && settings.includes('Packet annotations require migration 012'), 'annotation UI disables writes without a session or migration 012');

for (const id of ['crossings-panel','crossings-status','crossing-node','crossing-number','crossing-gate','crossing-side','crossing-start','crossing-reentry','crossing-end','crossings-table','crossings-rows','crossings-summary','crossing-export']) {
  check(markup.includes(`id="${id}"`), `Settings crossing panel includes #${id}`);
}
check(settings.includes('Crossings need migration 013') && settings.includes('schema_available!==false'), 'pre-013 crossing reads show a plain migration message and disable controls');
check(settings.includes("request(`crossings?session_id=") && settings.includes("request('crossings/start'") && settings.includes("crossings/${current.id}/${action}") && settings.includes("request(`crossings/${form.dataset.id}`"), 'crossing panel uses the existing list, start, re-entry/end, and edit routes');
check(settings.includes('TrailwatchUtcTime?.parseDatabaseUtc') && /utc-time\.js\?v=\d+/.test(markup), 'crossing UTC timestamps use the shared local-time formatter with a cache-busted script');
check(settings.includes('duplicate_gate_warning') && settings.includes('error.message') && settings.includes('sample SD'), 'crossing UI surfaces server warnings/errors and labels manual latency statistics');
check(settings.includes("crossingGateWrap.hidden=isPartB;crossingSideWrap.hidden=isPartB") && settings.includes("const isPartB=activeSessionPhase==='PHASE3'"), 'crossing gate visibility follows the active session phase');
check(css.includes('.settings-card label[hidden]{display:none!important}'), 'hidden crossing labels remain hidden despite settings label layout styles');
check(settings.includes("crossingNumber.max=isPartB?'5':'6'") && settings.includes("const isPartB=activeSessionPhase==='PHASE3',max=isPartB?5:6"), 'Part B crossing numbers are limited to 1–5 while other phases retain the existing 1–6 limit');
check(settings.includes('return isPartB?null:max;') && settings.includes("All five Part B crossing numbers (1–5) are used") && settings.includes('next===null'), 'Part B with all five numbers used shows a message and disables Start');
check(settings.includes("if(activeSessionPhase!=='PHASE3'){payload.gate_no=") && settings.includes('Crossing number must be 1–${max}'), 'Part B omits gate fields and validates its crossing number before sending');

for (const id of ['marker-checks-panel','marker-check-status','marker-check-node','marker-check-gate','marker-check-type','marker-check-window','marker-check-run','marker-check-export','marker-checks-table','marker-checks-rows']) {
  check(markup.includes(`id="${id}"`), `Settings marker-check panel includes #${id}`);
}
check(markup.includes('averages valid fixes received in the last N seconds') && markup.includes('window ends when you click Check marker') && markup.includes('Moved count means failed checks followed by a later check of the same marker'), 'marker-check panel explains its sample window and moved-count meaning');
check(settings.includes("request('marker-checks'") && settings.includes('marker-checks?session_id=') && settings.includes('type=marker_checks&session_id='), 'marker-check panel uses authenticated POST, session GET, and CSV routes');
check(settings.includes('Marker checks need migration 014') && settings.includes("schema_available!==false") && settings.includes('markerChecksEnabled') && settings.includes("markerExport?.setAttribute('aria-disabled','true')"), 'marker-check panel reports missing migration and disables writes and export without migration');
check(settings.includes('moved_count') && settings.includes('first_packet_at') && settings.includes('last_packet_at'), 'marker-check table renders moved count and averaged-window packet times');
check(['position-error-panel','position-error-status','position-error-node','position-error-gate','position-error-tape-distance','position-error-stamp-inside','position-error-stamp-outside','position-error-inside-stamped','position-error-outside-stamped','position-error-save','position-error-table','position-error-rows','position-error-mean-signed','position-error-mean-absolute','position-error-largest-absolute','position-error-export'].every(id=>markup.includes(`id="${id}"`)) && settings.includes("request('clock-check')") && settings.includes('positionInsideStamp=clock.server_utc_now') && settings.includes("request('position-errors'") && settings.includes('type=position_errors&session_id='), 'Position error panel uses server-UTC stamps, Phase 2 API routes, export, table, and three labeled summaries');

console.log('PASS session UI state, error, and safety copy contract');
