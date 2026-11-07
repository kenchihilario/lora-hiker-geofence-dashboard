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

console.log('PASS session UI state, error, and safety copy contract');
