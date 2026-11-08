require('./test-preflight.js');
const assert=require('node:assert/strict');
process.env.TZ='Asia/Manila';
const {localDateTimeToUtcIso}=require('../public/assets/js/bench-time.js');
assert.equal(localDateTimeToUtcIso('2026-11-04T10:00'),'2026-11-04T02:00:00.000Z');
assert.throws(()=>localDateTimeToUtcIso('not-a-date'),/valid local date and time/);
console.log('PASS local Manila date-time values are sent as UTC ISO timestamps');
console.log('PASS malformed local date-time values are rejected');
