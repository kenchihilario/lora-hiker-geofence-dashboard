const fs = require('fs');
const assert = require('assert');

const settings = fs.readFileSync('public/assets/js/site-settings.js', 'utf8');
const dashboard = fs.readFileSync('public/assets/js/dashboard.js', 'utf8');
const markup = fs.readFileSync('public/settings.php', 'utf8');
const index = fs.readFileSync('public/index.php', 'utf8');
function check(condition, label) {
  if (!condition) {
    console.error(`FAIL ${label}`);
    process.exit(1);
  }
  console.log(`PASS ${label}`);
}

check(/await loadRegistry\(\);\s*await loadTestSession\(\);/.test(settings), 'changing the selected site refreshes the active-session status');
check(settings.includes('${s.site_id}') && settings.includes('s.phase?'), 'active-session status includes site and phase');
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

console.log('PASS session UI state, error, and safety copy contract');
