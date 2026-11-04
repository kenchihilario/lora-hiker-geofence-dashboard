# Trailwatch checkup audit

Read-only audit of the previous checkup deliverables. No source/config changes were intentionally made; the only file created is this report. Tests wrote only DEMO/TEST fixtures to `hiker_dashboard_test_codex` and created/dropped scratch databases. No request switched to LIVE, and no rows were written to `hiker_dashboard_live`. At the end, Git showed a tracked empty-file deletion, `tools/New Text Document.txt`, which was not shown as deleted in the initial status; it was not intentionally targeted and was left untouched under the audit-only rule.

## 1. Checklist

| # | Item | Status | Evidence |
|---:|---|---|---|
| 1 | Tile cleanup and offline references | PASS | `Test-Path public/tiles` → `False`; recursive scan of `public src config sql tools` found no external URL, CDN, tile layer, `/tiles`, or `@import` match. |
| 2 | Schema has no fixed database selection | PASS | `sql/schema.sql:1-2,222-237`; no `CREATE DATABASE` or `USE`. |
| 3 | Setup profile name validation | PASS | `tools/setup_db.php:3-11`; checks the selected profile name before connecting/creating. |
| 4 | Ordered/idempotent migration ledger; historic 001–005 record | UNVERIFIED | `tools/migrate_db.php:6-18` sorts, checks checksums and skips recorded files. `tests/migration_scratch_test.php` PASS; demo ledger query returned 001–006. But `sql/migrations/001_milestone2.sql` was edited after the historical migration, and no original ledger/checksum exists to prove its earlier execution/history. |
| 5 | Demo seed separated from live schema | PASS | `sql/schema.sql` contains no hiker/zone seed; `sql/demo_seed.sql:1-14`; `tools/setup_db.php:18` imports seed only for DEMO. Live database query showed zero hikers, network nodes, zones and flagged rows. |
| 6 | Separate config profiles and bridge tokens | PASS | `config.example.php:4-11`; local `config.php` keys were inspected without printing values and contains demo/live profiles and separate token entries. |
| 7 | Local mode file/default | PASS | `src/db.php:11-20`; no `data/mode.json` currently exists, so active mode is DEMO. `tests/mode_safety_test.php` passes missing, invalid and valid mode cases. |
| 8 | Profile selected per request | PASS | `src/db.php:17-19,22-28,42-50`; each `db()` call reads active mode and selects its profile. |
| 9 | Live DB schema and data isolation | PASS | Read-only live query: database exists, 18 tables; hiker/network-node counts 0 and demo/rehearsal/test-flagged rows are 0 in uplinks, events, commands, receptions, zones, campaigns, actions and references. No live row contents were read. |
| 10 | LIVE ingest rejects and logs flagged packets | UNVERIFIED | `public/api/index.php:12-19` rejects before `db()` and appends a generic local rejection log; `tests/mode_safety_test.php` tests predicate/order. Actual LIVE endpoint was not called, as required. |
| 11 | Simulator refuses LIVE | PASS | `tools/simulate_hikers.php:4-5`; `tests/mode_safety_test.php` passes DEMO/LIVE simulator gate. |
| 12 | No-zone status and geofence behavior | PASS | Before: `active_zone()` returns null; `src/Services/ApiService.php:109-110,118` yields UNCLASSIFIED and only creates OFF_TRAIL when `$inside===false`. Now: `public/index.php:58`, `public/assets/js/dashboard.js:49` show “No safe zone set.” API smoke passed no-zone ingest as UNCLASSIFIED with no off-trail command/event. LIVE UI was not opened. |
| 13 | Site/phase settings per database | PASS | Both profiles connect to separate schemas; `sql/schema.sql:54-58` stores `selected_site` and `phase_mode` in `app_settings`; `src/db.php:42-50` selects one DB per active mode. |
| 14 | Mode switch, labels, database check | PASS | `public/settings.php:1-3`, `public/index.php:23-45`, `public/safe-zone.php:1-2`; titles/banner/mode badges on all three pages. `public/assets/js/site-settings.js:11` displays active DB and flagged count and adds red error styling for nonzero LIVE count. Static/UI test passed. |
| 15 | Clear demo action safety | FAIL | Typed `CLEAR`, DEMO gate and backup-before-delete are at `public/api/index.php:29,38-53`; UI is in `public/settings.php:3`. However lines 52 unconditionally delete all legacy `alerts` and `positions`, which have no demo flags. Thus the action can delete unclassified legacy rows, not only rows proven demo. It was not executed. |
| 16 | Local-only switching and two-DB audit | UNVERIFIED | Local guard `src/db.php:52-58`; switch inserts audit records into both selected DB profiles at `public/api/index.php:21-27`; `sql/schema.sql:227-237` defines audit table. No switch was invoked; LIVE audit table remains empty. |
| 17 | Unique XAMPP live backup | UNVERIFIED | `tools/backup_db.php:15-20,34-52` creates a unique timestamp directory and uses XAMPP `mysqldump.exe`; two non-overwritten DEMO test DB dumps exist under ignored `backups/`. A LIVE backup was not run. |
| 18 | Test DB/LIVE safety gates | PASS | `src/db.php:29-35`; PHP tests and JS `tests/test-preflight.js` enforce DEMO test profile, and API/PowerShell smoke tests check server status before writes. Tests ran only against the test DB and disposable scratch DBs. |
| 19 | Row badges and banner flag awareness | FAIL | Row badges exist in `public/assets/js/dashboard.js:12-14`. But the dashboard banner chooses DEMO/LIVE only from `st.active_mode` at line 53; it does not consider `is_demo`, `is_rehearsal`, or `test_mode` rows. The live count warning is targeted at `#database-check-inline`, which is absent from the dashboard page. |
| 20 | Central research eligibility and bad denormalized event test | FAIL | `research_flags_eligible_sql()` is in `src/Services/ApiService.php:8`; event/command filters use it, and `tests/research_summary_test.php:17-27` passes the wrong-denormalized-flag source-packet case. Packet summary queries instead duplicate a separate literal `$packetEligibleBare` at lines 142-148, so eligibility is not centralized for all counts. |
| 21 | Loopback guard | PASS | `src/db.php:51-58`; `public/api/index.php:8-11` applies guard outside bridge routes and pages call it. `tests/mode_safety_test.php` passes loopback/non-loopback predicate checks; config default is true. |
| 22 | Requested API/filter/static tests | PASS | `tests/api_smoke.php` passes event resolve/idempotence/404, heartbeat, ingest validation, event/packet filters and duplicate/out-of-order cases. `tests/offline_static_test.js` passes offline scan. |
| 23 | Demo simulator/scenarios | PASS | `tools/simulate_hikers.php:5-13` uses the logical JSON API, marks envelopes demo, supports GPX speed and JSON scenarios. Eight scenario JSON files exist in `tools/scenarios/`. Simulator was not run to avoid adding extra demo packets. |
| 24 | CSV and source label | PASS | `public/api/index.php:31-36` exports event/packet rows with source; `src/Services/ApiService.php:17`; operator route is loopback-guarded. API smoke passed CSV field checks. |
| 25 | Derived GPS valid fix | PASS | `src/Services/ApiService.php:16`; applied to hiker/log output and CSV (`public/api/index.php:34,68,83`); unit assertions in `tests/mode_safety_test.php` pass, including null. |
| 26 | Chapter 4 and phone options docs | PASS | `docs/CHAPTER4_DATA.md` and `docs/PHONE_LINK_OPTIONS.md` exist; phone choices explicitly say UNVERIFIED for both named phones. |
| 27 | Balagbag references | PASS | `config/sites.php:24-32`: original peak preserved; extra pin is labelled user-provided/unsurveyed. |
| 28 | PUP/Mapalad/node coordinates | PASS | `config/sites.php:7-11,37-41`: PUP pin is user-provided/unverified; Mapalad and Base/Checkpoint are null/empty. Other configured deployment coordinates are only the provided pins/peak. Additional **synthetic DEMO** coordinates exist in `sql/demo_seed.sql:9-13` (14.8000–14.8060, 121.0000–121.0006) and fallback dashboard data `public/index.php:4-6` (14.8031/121.0003 and 14.8057/121.0010); these are not surveyed site data. |
| 29 | Reference labels and UNSURVEYED note | PASS | `public/assets/js/dashboard.js:34` adds UNSURVEYED to map marker labels; `public/assets/js/safe-zone.js:15` adds it to editor reference list. |
| 30 | No invented protocol or serial bridge | PASS | Diff covers DB/config/API/dashboard/tools only; `tools/simulate_hikers.php` sends logical JSON. No serial framing, binary packet sizes or hardware bridge was added. |
| 31 | Recursive external dependency scan | PASS | Command `rg -n -i -P 'https?://(?!127\.0\.0\.1|localhost|\[::1\])|tile\.openstreetmap\.org|L\.tileLayer|/tiles|@import|fonts\.googleapis|cdnjs|unpkg' public src config sql tools` returned no matches. `public/tiles` absent. |
| 32 | README required topics | PASS | `README.md:7-76` covers setup/ledger, tests, simulator, CSV, local-only rule, live backup, mode selection, GPX workflow and edge-inside rule. Diff removes the previous endpoint-by-endpoint API list and detailed logical bridge field/route/timing notes. Existing Launch/Restart/Stop batch files remain, but the README does not document them; it has a direct PHP launch command at lines 21-26. |
| 33 | Post-smoke restoration / leftover polygon state | FAIL | Before and after both smoke suites, direct test-DB query was identical: `selected_site=pup_mountain_prep`, `phase_mode=PHASE1`, active PUP polygon `id=46/version=27/name=DEMO TEST polygon`. Current run added no active version (before/after equal), but DB already contains 26 `DEMO TEST` PUP polygon rows, including that active test polygon; it is not a clean original sample boundary. |
| 34 | Repository file state | FAIL | Initial `git status --short` did not show this path deleted; final status shows `D "tools/New Text Document.txt"`, and `git diff --stat` shows a zero-line deletion. No audit command intentionally targeted this file; cause is unknown. |

## 2. Test results

| Command | Result |
|---|---|
| `C:\xampp\php\php.exe tests\geofence_test.php` | PASS (5 assertions) |
| `C:\xampp\php\php.exe tests\polygon_validation_test.php` | PASS (2 assertions) |
| `C:\xampp\php\php.exe tests\research_summary_test.php` | PASS (demo/rehearsal/test packet, event, command and source-packet exclusion) |
| `C:\xampp\php\php.exe tests\mode_safety_test.php` | PASS (mode/profile, guards, source labels, GPS and schema checks) |
| `C:\xampp\php\php.exe tests\live_schema_scratch_test.php` | PASS; created/dropped scratch DB, no seed rows |
| `C:\xampp\php\php.exe tests\migration_scratch_test.php` | PASS; rerun skipped recorded migrations |
| `node tests\gpx_corridor_test.js` | PASS (4 assertions) |
| `node tests\dashboard_packet_log_contract_test.js` | PASS (8 assertions) |
| `node tests\offline_static_test.js` | PASS (7 files scanned) |
| `C:\xampp\php\php.exe tests\api_smoke.php http://127.0.0.1:8002` | PASS (all endpoint assertions; local server was already running) |
| `powershell -NoProfile -ExecutionPolicy Bypass -File tests\site_safe_zone_smoke.ps1 -BaseUrl http://127.0.0.1:8002/api -Database hiker_dashboard_test_codex` | PASS; cleanup ran |
| PHP lint (`rg --files -g '*.php'`, plus ignored `config.php`) | PASS: 26 discovered files plus `config.php` |
| `node --check` for every `*.js` file | PASS: 8 files |

API smoke wrote DEMO/TEST fixtures only to `hiker_dashboard_test_codex`; it also deletes already-queued `test_mode=1` commands in that test DB before its run. Scratch tests dropped their scratch databases. Captured selected site, phase, and active polygon were unchanged after both smoke suites. The active polygon and 26 test-zone history rows predated this audited run and remain present.

## 3. Totals

| PASS | FAIL | MISSING | UNVERIFIED |
|---:|---:|---:|---:|
| 25 | 5 | 0 | 4 |

## 4. Risks found (ranked)

1. **High — clear-demo data loss:** legacy `positions` and `alerts` are deleted without demo markers. A backup is taken first, but those rows cannot be distinguished from any non-demo legacy records.
2. **High — dirty demo fixture state:** 26 PUP `DEMO TEST polygon` versions persist, with one active. Current smoke tests preserve this pre-existing state instead of restoring a clean sample polygon.
3. **Medium — banner misses flagged live rows:** unexpected flagged records in LIVE would not change the dashboard banner; the settings page count is the only visible database error line.
4. **Medium — duplicated research predicate:** packet counts bypass the shared flag helper, allowing packet criteria to drift from event/command criteria.
5. **Medium — migration provenance:** migration 001 was edited to remove database selection and demo inserts. The current ledger has the edited checksum, but no prior ledger proves historical application of the original migration.
6. **Medium — live operations intentionally unverified:** LIVE ingest rejection, two-database mode audit, and LIVE dump were not exercised. The live DB currently has schema and zero flagged/demo seed rows.
7. **Low — README detail reduction:** the old endpoint catalog and detailed logical bridge/route/timing explanation were removed; the new README keeps run/test instructions but omits the existing batch launch/restart/stop shortcuts.
8. **Low — operator security remains local-only:** no user accounts, sessions, or CSRF protection. Keep the server bound to loopback; README says so.
9. **Low — unexplained repository deletion:** final Git status shows the tracked empty `tools/New Text Document.txt` deleted; the cause is unknown and it was not restored.

## 5. REMAINING WORK

- **#15:** Change demo clearing so it does not delete unclassified legacy `positions`/`alerts` rows.
- **#19:** Make the global banner account for record-level demo/rehearsal/test flags and surface the LIVE flagged-row error on the dashboard.
- **#20:** Use the same eligibility fragment for packet counts as for event/command counts.
- **#33:** Clean/reseed the disposable test DB's stale `DEMO TEST polygon` history so the active PUP zone is not a smoke-test fixture.
- **#34:** Determine why `tools/New Text Document.txt` was deleted; restore it only after deciding whether the tracked placeholder should remain.

## 6. Could not check

- No actual LIVE request, mode switch, live backup, or LIVE audit write was performed, per the no-live-testing rule. Their behavior is code-inspected only.
- No ESP32, LoRa, USB serial, firmware packet sizing/framing, phone, or surveyed field-site behavior is verifiable from this repository. No protocol details were inferred.
- Server was already running on loopback port 8002; this audit did not start or stop it.
