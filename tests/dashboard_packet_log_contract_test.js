const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const api = fs.readFileSync(path.join(root, 'public/api/index.php'), 'utf8');
const dashboard = fs.readFileSync(path.join(root, 'public/assets/js/dashboard.js'), 'utf8');
const page = fs.readFileSync(path.join(root, 'public/index.php'), 'utf8');
const checks = [
  ['API packet-log query includes reception and route fields', ['r.received_by_node', 'r.relay_hop_delay_ms', 'r.is_duplicate', 'c.route_type'].every(x => api.includes(x))],
  ['dashboard requests packet-log API', dashboard.includes("api('logs/packets?limit=20')")],
  ['dashboard displays all reception fields', ['p.received_by_node', 'p.relay_hop_delay_ms', 'p.route_type', 'p.is_duplicate'].every(x => dashboard.includes(x))],
  ['dashboard has packet-log table columns', ['Heard by', 'Relay hop', 'Route', 'Duplicate'].every(x => page.includes(x))],
  ['dashboard requests and labels active study phase', dashboard.includes("api('settings/phase-mode')") && dashboard.includes('Phase 2') && page.includes('dashboard-phase-mode')],
];
let failed = false;
for (const [label, passed] of checks) {
  console.log(`${passed ? 'PASS' : 'FAIL'} ${label}`);
  failed ||= !passed;
}
process.exitCode = failed ? 1 : 0;
