require('./test-preflight.js');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const api = fs.readFileSync(path.join(root, 'public/api/index.php'), 'utf8');
const dashboard = fs.readFileSync(path.join(root, 'public/assets/js/dashboard.js'), 'utf8');
const page = fs.readFileSync(path.join(root, 'public/index.php'), 'utf8');
const labelLayout = fs.readFileSync(path.join(root, 'public/assets/js/dashboard-label-layout.js'), 'utf8');
const settingsPage = fs.readFileSync(path.join(root, 'public/settings.php'), 'utf8');
const settingsJs = fs.readFileSync(path.join(root, 'public/assets/js/site-settings.js'), 'utf8');
const benchTime = fs.readFileSync(path.join(root, 'public/assets/js/bench-time.js'), 'utf8');
const sourceFlags = require('../public/assets/js/source-flags.js');
const checks = [
  ['API packet-log query includes reception and route fields', ['r.received_by_node', 'r.relay_hop_delay_ms', 'r.is_duplicate', 'c.route_type'].every(x => api.includes(x))],
  ['dashboard requests packet-log API', dashboard.includes("api('logs/packets?limit=20')")],
  ['dashboard displays all reception fields', ['p.received_by_node', 'p.relay_hop_delay_ms', 'p.route_type', 'p.is_duplicate'].every(x => dashboard.includes(x))],
  ['dashboard has packet-log table columns', ['Heard by', 'Relay hop', 'Route', 'Duplicate'].every(x => page.includes(x))],
  ['dashboard requests and labels active study phase', dashboard.includes("api('settings/phase-mode')") && dashboard.includes('Phase 2') && page.includes('dashboard-phase-mode')],
  ['dashboard shows an explicit empty safe-zone state', page.includes('id="no-safe-zone"') && page.includes('No safe zone set') && dashboard.includes('noZone.hidden=!!z.safe_zone')],
  ['dashboard includes mode labels in the title and top banner', page.includes('main-mode-banner') && page.includes('top-mode-indicator') && dashboard.includes('document.title=`${st.active_mode}')],
  ['alert and packet rows display DEMO, REHEARSAL, TEST, and BENCH badges', ['source-badge','REHEARSAL','TEST','BENCH'].every(x=>dashboard.includes(x)) && dashboard.includes('rowBadge(e)') && dashboard.includes('rowBadge(p)')],
  ['dashboard banner derives unique visible source labels from record flags', dashboard.includes('TrailwatchSourceFlags.labels([...hikers') && page.includes('source-flags.js') && sourceFlags.labels([{is_demo:1,is_rehearsal:1,test_mode:1,is_bench:1},{is_demo:1},{is_demo:0}]).join(',')==='DEMO,REHEARSAL,TEST,BENCH'],
  ['dashboard has a visible banner when a LIVE bench session is active', page.includes('id="bench-session-banner"') && dashboard.includes("st.active_mode==='LIVE'&&st.bench_session_enabled") && page.includes('marked BENCH')],
  ['Bench range form converts browser-local times to explicit UTC before saving', settingsPage.includes('assets/js/bench-time.js') && settingsJs.includes('localDateTimeToUtcIso') && benchTime.includes('date.toISOString()')],
  ['dashboard has a visible red LIVE flagged-record database check', page.includes('id="database-check-inline"') && dashboard.includes('st.live_demo_count_error') && dashboard.includes("classList.toggle('api-error',statusHasError)")],
  ['dashboard includes pixel-scaled label layout and resize redraw', page.includes('dashboard-label-layout.js') && dashboard.includes('TrailwatchMapLabels.layout') && dashboard.includes('--svg-css-scale') && dashboard.includes("addEventListener('resize'") && labelLayout.includes('fontSize:fontPx/safeScale')],
];
let failed = false;
for (const [label, passed] of checks) {
  console.log(`${passed ? 'PASS' : 'FAIL'} ${label}`);
  failed ||= !passed;
}
process.exitCode = failed ? 1 : 0;
