const assert = require('node:assert/strict');
const { parseDatabaseUtc } = require('../public/assets/js/utc-time.js');

const eventCreatedAt = '2026-11-10 16:30:00';
const parsed = parseDatabaseUtc(eventCreatedAt);
assert.ok(parsed instanceof Date);
assert.equal(parsed.toISOString(), '2026-11-10T16:30:00.000Z');
console.log('PASS naive event created_at plus Z represents the intended UTC instant');
