# Trailwatch Dashboard — Project Status

**Reviewed:** 2026-11-07
**Project:** `D:\Projects for PC\Dashboard`
**Branch:** `codex/session-scoped-ingest` (based on `main`; changes are uncommitted)
**Scope:** Dashboard, PHP API/backend, MariaDB schema/migrations, local tooling, and dashboard-relevant research proposal requirements. This report is based on the current checked-out source and test runner; it does not claim the hardware has been tested. No credentials or tokens are included.

## Status labels

- **IMPLEMENTED AND TESTED** — present and exercised by a passing test in the latest recorded scratch-suite run.
- **IMPLEMENTED BUT UNTESTED** — code exists, but a test was not found or was not run for that behavior.
- **PARTIAL** — some behavior exists but has a known limitation or missing element.
- **NOT IMPLEMENTED** — not found in the project.
- **BLOCKED (waiting on hardware/firmware/site data)** — needs hardware, firmware specification, surveyed locations, or field observations.

## 1. Overview

**IMPLEMENTED AND TESTED — application foundation.** Trailwatch is a local PHP 8/MariaDB application with PDO, JSON API routes, browser JavaScript and CSS, and a plain SVG map. It does not use a frontend framework or package manager. PDO uses exceptions, associative fetches, and native prepared statements (`src/db.php:57-65`). The active mode file is `data/mode.json`; absent or invalid content selects DEMO (`src/db.php:18-31`). The project includes `Launch Dashboard.bat`, `Restart Dashboard.bat`, and `Stop Dashboard.bat`, with PowerShell server management (`server-control.ps1`).

**IMPLEMENTED AND TESTED — local setup and test workflow.** `sql/schema.sql` is database-name agnostic and has no demo seed; the seed is separate in `sql/demo_seed.sql`. `tools/setup_db.php` sets up a configured profile, and `tools/migrate_db.php` applies ordered migrations and records checksums in `schema_migrations` (`sql/schema.sql:233-240`; `tools/setup_db.php`; `tools/migrate_db.php`; `tests/migration_scratch_test.php`). README documents setup and the loopback-only server requirement (`README.md:7-18`). Tests use generated disposable scratch DB names and drop them afterward (`tests/run_scratch_suite.php:4-5,28-31`).

### Important project files

| Path | Purpose |
|---|---|
| `config.example.php`, ignored `config.php` | Demo/live database profiles, separate bridge tokens, and local-only setting. Secrets stay local (`src/db.php:2-16,53-73`). |
| `config/sites.php` | Site registry defaults, supplied reference pins, and explicit unset coordinates (`config/sites.php:1-42`). |
| `src/db.php` | Active mode/profile selection, test guard, PDO connection, and local operator guard (`src/db.php:18-79`). |
| `src/geofence.php` | Point-in-polygon and edge classification (`src/geofence.php:1-16`). |
| `src/Services/ApiService.php` | Validation, source flags, ingest, geofence orchestration, downlink selection/routing, research summary, and demo clearing. |
| `public/api/index.php` | API route dispatch, authentication/local guard, JSON responses, logs, CSV export, and settings. |
| `public/index.php`, `public/settings.php`, `public/safe-zone.php` | Dashboard, settings/readiness and site registry, and safe-zone/GPX editor pages. |
| `public/assets/js/dashboard.js` | Dashboard polling and rendering, SVG map, events, packet log, and controls. |
| `public/assets/js/dashboard-label-layout.js` | SVG label placement, clipping bounds, truncation and CSS-pixel sizing. |
| `public/assets/js/safe-zone-geometry.js`, `safe-zone.js` | Local geometry, Douglas–Peucker simplification, GPX preview, polygon editing/saving. |
| `public/assets/css/dashboard.css` | Local dashboard/editor styles. |
| `sql/schema.sql`, `sql/demo_seed.sql`, `sql/migrations/` | Schema-only initialization, demo seed, and ten ordered migrations; migration 008 stores safe-zone ring boundaries, 009 adds BENCH classification, and 010 adds test sessions. |
| `tools/setup_db.php`, `migrate_db.php`, `backup_db.php`, `live_check.php` | DB setup, migration, timestamped backup, and read-only LIVE readiness check. |
| `tools/simulate_hikers.php`, `tools/scenarios/` | DEMO-only logical-packet simulator and scenarios; it does not simulate radio hardware. |
| `tests/` | PHP, JavaScript, PowerShell, API smoke, and isolated scratch-LIVE integration tests. |
| `docs/CODEX_WEBSITE_SPEC.md`, `docs/CHAPTER4_DATA.md`, `docs/PHONE_LINK_OPTIONS.md` | Requirements copy and data/phone planning notes. The paper remains unchanged. |

## 2. Architecture and completed behavior

**IMPLEMENTED AND TESTED — logical ingest and event path.** A bridge POST supplies an `uplink` and separate `reception` metadata. The service validates logical fields, finds the hiker and site polygon, applies the existing point-in-polygon function, persists packet/reception history, derives events, selects one reply, and queues a command (`public/api/index.php:13-21`; `src/Services/ApiService.php:125-170`; `src/geofence.php:1-16`). These are logical API fields; this is not a wire or serial specification.

**IMPLEMENTED AND TESTED — sequence/reception behavior and priority.** One logical packet is keyed by site, hiker, and sequence; receptions are separately retained and later receptions are marked duplicate (schema `sql/schema.sql:78-125`; service and `tests/api_smoke.php`). Out-of-order unique sequences remain separate packets. Each uplink yields at most one downlink. The single priority constant is **SOS/emergency reply > off-trail alert > storm turn-back > ACK** (`src/Services/ApiService.php:115-123`; `tests/api_smoke.php:24,66-77`).

**IMPLEMENTED AND TESTED — route and timing logs.** If any direct reception exists, the command route is `DIRECT`; if the packet was heard only via a Checkpoint, the route is `CHECKPOINT_RELAY` through that Checkpoint (`src/Services/ApiService.php:58-63`). Packet/event logs expose heard-by node, path, RSSI/SNR, relay hop delay, duplicate marker, command route, and available command timestamps/elapsed milliseconds (`public/api/index.php:81-88,109-111`; `tests/api_smoke.php:77`). The times describe command creation/fetch/sent reporting; the app does not infer hiker receive-window success.

**IMPLEMENTED AND TESTED — link loss, SOS, and storm controls.** API reads mark a hiker `LINK_LOST` after 15 seconds without an uplink and retain its last packet position and receptions; dashboard renders state, battery, report age and last heard by (`public/api/index.php:63,73-75`; `public/assets/js/dashboard.js`). Emergency uplinks create an emergency event and SOS reply; an outside-zone SOS also retains an OFF_TRAIL event (`src/Services/ApiService.php:125-170`; `tests/api_smoke.php:66`). Storm campaigns repeat on eligible reply slots until receipt flag or operator cancellation; Phase 2 disables them (`public/api/index.php:94-101`; service; `tests/api_smoke.php:52-58,71-74`). The “alarm” is an event/alert shown in the app; no sounder or physical alarm has been verified.

**BLOCKED (waiting on hardware/firmware/site data) — physical bridge.** No ESP32 firmware, actual LoRa radio driver, Android application, or USB serial bridge is implemented. Packet byte sizes, radio encoding, serial framing, and real receive behavior are intentionally unspecified and remain firmware-owned. No real device delivery is claimed.

## 3. API and access control

**IMPLEMENTED AND TESTED — local operator access.** `local_only` defaults ON and only `127.0.0.1` / `::1` are accepted for operator/settings routes (`src/db.php:67-73`). Operator pages and APIs also require a local login/session and CSRF tokens for writes (`src/operator_auth.php`; `tests/operator_auth_scratch_test.php`). Bridge routes use the active profile's `X-Bridge-Token` (`public/api/index.php`). Keep the PHP server on loopback; README says never expose it to a LAN/public interface.

Current route families in `public/api/index.php` include:

- **Read dashboard:** `GET /api/status`, `/api/hikers`, `/api/hikers/{node_id}/latest`, `/api/positions/latest`, `/api/safe-zone`, `/api/events`, `/api/logs/packets`, `/api/research/summary`, `/api/settings/phase-mode`, `/api/test-sessions/active`, `/api/sites`, `/api/network-nodes`, `/api/reference-points`, `/api/commands/storm-turn-back/active`.
- **Operator writes:** `POST /api/settings/site`, `/api/settings/phase-mode`, `/api/test-sessions/start`, `PUT /api/safe-zone`, `POST /api/events/{id}/resolve`, storm campaign arm/cancel endpoints, demo clearing, hiker registry create/update, site-location and network-node/reference-point CRUD and verification actions.
- **Bridge:** `POST /api/bridge/ingest`, `/api/bridge/validate`, `/api/bridge/status`, `GET /api/commands/pending`, and `POST /api/commands/{id}/status`.
- **Export and readiness:** `GET /api/export.csv`, `GET /api/live-check`.

Inputs are JSON or query filters as appropriate; validation returns HTTP errors for malformed fields/ranges and unknown records. Operator APIs require the local operator session; mutations require `X-CSRF-Token`, and loopback remains enforced. Bridge ingest/status/command routes require the profile bridge token; validation also requires loopback and performs a dry run. LIVE-check is local and read-only. Ingest returns the created logical command object (or `null` for dry-run/no command) while preserving `command_queued`. See route dispatch at `public/api/index.php`; tests include `tests/api_smoke.php`, `tests/operator_auth_scratch_test.php`, and `tests/live_first_run_scratch_test.php`.

**IMPLEMENTED AND TESTED — dry-run validation.** `POST /api/bridge/validate` authenticates, validates without committing, and is guarded to loopback. Scratch integration verifies packet/reception/command tables remain unchanged (`public/api/index.php:23-24`; `tests/live_first_run_scratch_test.php:33-35`).

## 4. Database and migrations

**IMPLEMENTED AND TESTED — schema.** Core tables include `hiker_nodes`, `network_nodes`, `trail_reference_points`, `safe_zones`, `safe_zone_vertices`, `site_locations`, `app_settings`, `test_sessions`, `uplink_packets`, `packet_receptions`, `downlink_commands`, `storm_turnback_campaigns`, `system_events`, `operator_actions`, `system_status`, legacy `nodes`/`positions`/`alerts`, `schema_migrations`, and `mode_switch_audit` (`sql/schema.sql`). Session IDs on packet, reception, command, and event rows point to the site-scoped test session; packet uniqueness includes site, hiker, session, and sequence.

**IMPLEMENTED AND TESTED — migration runner.** The ordered set is:

1. `001_milestone2.sql` — initial backend tables and packet/event/command logging.
2. `002_local_map_references.sql` — local map reference points.
3. `003_site_aware_safe_zones.sql` — site-scoped polygons and reference points.
4. `004_bridge_fields_research_rehearsal.sql` — logical bridge fields and demo/rehearsal/test classification.
5. `005_downlink_routing_timing_storm_campaigns.sql` — route, timestamps, duplicate/reception and campaign support.
6. `006_mode_safety.sql` — migration ledger/mode audit safety structures.
7. `007_site_registry_editing.sql` — editable site, node, and reference registry.
8. `008_safe_zone_rings.sql` — ring-aware safe-zone vertices for loop corridors.
9. `009_bench_classification.sql` — BENCH flags for packets, receptions, events, commands, and operator actions.
10. `010_test_sessions.sql` — site-scoped sessions, legacy-row backfill, and session-bounded sequence uniqueness.

The runner uses checksums and skips already applied migrations; a scratch test checks repeated application and ten ledger rows (`tools/migrate_db.php`; `tests/migration_scratch_test.php:4-8`). A historical LIVE readiness note reported migration 007 was pending in its 2026-11-04 snapshot (`docs/LIVE_FIRST_RUN.md:20-29,57-59`). The operator later reported running the LIVE backup, migration, and readiness-check commands. **This update did not inspect the LIVE database or see those commands' output, so the current applied migration set, backup path, and readiness result remain unverified.**

## 5. Frontend, map, and safe-zone editor

**IMPLEMENTED AND TESTED — offline map.** The dashboard map is plain SVG with a background/grid, equirectangular local projection, site nodes/references, safe-zone geometry, hiker markers, scale and north direction; it does not use a tile layer (`public/assets/js/dashboard.js`; `public/index.php:56-60`). Map layout has defaults for empty/single-marker cases. Labels counter-scale to CSS-pixel size, fit inside SVG bounds, truncate with full text retained in an SVG title, and resize with the viewport (`public/assets/js/dashboard-label-layout.js`; `tests/dashboard_map_labels_test.js:5-15`). Static offline checks cover the dashboard, safe-zone and local assets (`tests/offline_static_test.js`). Static scans do not prove zero network activity at runtime.

**IMPLEMENTED AND TESTED — GPX/polygon editing.** `/safe-zone` locally imports a GPX track/route, previews a corridor, and saves only after operator confirmation. Tracks over 250 points use browser Douglas–Peucker simplification with configurable tolerance and previewed point count/maximum deviation. Corridor half-width remains 5–50 m (`public/safe-zone.php`; `public/assets/js/safe-zone.js`; `public/assets/js/safe-zone-geometry.js`; `tests/gpx_corridor_test.js:14-34`). Existing geometry keeps edge points INSIDE (`src/geofence.php:1-16`; polygon/geofence tests). This is not survey validation and does not establish a real boundary. Closed loops now produce outer and inner rings, with the center of the loop outside the safe zone (`tests/gpx_ring_test.js`; `tests/ring_zone_scratch_test.php`).

**IMPLEMENTED AND TESTED — empty LIVE dashboard.** With no safe zone, the page displays “No safe zone set”; packets remain unclassified rather than being marked OFF TRAIL. The LIVE dashboard does not silently fall back to demo records on API errors (`public/index.php:56-60`; `public/assets/js/dashboard.js`; `tests/live_empty_dashboard_test.js`).

## 6. Modes, sites, and data classification

**IMPLEMENTED AND TESTED — separate DEMO and LIVE profiles.** The local mode file defaults safely to DEMO; each mode selects its own DB profile and bridge token (`src/db.php:18-55`). LIVE ingest rejects demo, rehearsal, and test-mode packets; LIVE selection rejects rehearsal/demo sites; the simulator refuses LIVE mode (`src/Services/ApiService.php:6-15`; `src/db.php:49`; `public/api/index.php:13-24`; `tests/live_first_run_scratch_test.php:21-24,41`). The scratch LIVE test uses a disposable DB and does not connect to the actual LIVE database.

**IMPLEMENTED AND TESTED — mode actions and visibility.** The settings page supports mode switching and audit, a demo-only clear action with typed confirmation and prior backup, and a LIVE readiness checklist. The dashboard shows the active mode and active DB/flagged-row check; flagged LIVE records display an error. Clearing preserves unflagged legacy position/alert rows and reports how many were kept (`src/Services/ApiService.php:30-47`; `public/index.php:56`; `public/api/index.php`; `tests/clear_demo_scratch_test.php`).

Configured site data (`config/sites.php:4-42`):

- **PUP Sta. Mesa:** no site center; PUP Oval pin at 14.598126, 121.0120373 is user-provided/unverified; BASE and CHK coordinates unset.
- **PUP Mountain Prep (Demo):** explicitly DEMO and REHEARSAL, unscored mountain rehearsal; coordinates unset.
- **Mt. Balagbag:** peak reference 14.8243087, 121.1804131 (770 m); user-provided unsurveyed pin 14.8239931, 121.1795801; BASE and CHK unset.
- **Mt. Mapalad (backup):** blank center and references; BASE/CHK unset.

**PARTIAL — site verification.** Registry records can be marked verified and that action is audited; unknown coordinates remain null/UNSURVEYED (`public/api/index.php` site/node/reference registry write routes; `tests/live_first_run_scratch_test.php:30-32`). Actual survey/verification is still absent. Confirm the PUP sample boundary remains excluded from research if using the general PUP site in LIVE; the configured `pup` entry is not marked demo/rehearsal (`config/sites.php:4-12`).

## 7. Research summary and proposal requirements

**IMPLEMENTED AND TESTED — research exclusion.** `research_flags_eligible_sql()` centralizes demo/rehearsal/test/BENCH predicates. Summary counts exclude flagged packets and apply source-packet eligibility to associated events/commands; tests include wrongly denormalized event flags, JSON test-mode source flags, and BENCH rows (`src/Services/ApiService.php:21-23,241-254`; `tests/research_summary_test.php`; `tests/bench_scratch_test.php`). Dashboard/CSV source labels distinguish DEMO, REHEARSAL, TEST, BENCH, and REAL. A LIVE-only Bench session switch marks new uplinks and their derived rows; Settings also supports auditing an existing eligible packet range as BENCH (`public/settings.php`; `public/api/index.php:43-54`). GPS valid-fix is derived for display/export only: accuracy <=10 m and satellite count >=5; null inputs are invalid (`src/Services/ApiService.php:19-20`; packet export in `public/api/index.php`).

| Proposal area | Status | Evidence / remaining gap |
|---|---|---|
| Table 8 logical uplink fields and downlink fields | **PARTIAL** | Logical JSON fields and validation exist (`src/Services/ApiService.php:125-170`); packet byte sizes/encoding/serial framing are explicitly unknown and firmware-owned. |
| One stored logical copy per sequence; reception duplicates marked | **IMPLEMENTED AND TESTED** | Schema uniqueness and reception duplicate assertion in API smoke (`sql/schema.sql:78-125`; `tests/api_smoke.php`). |
| 15-second link loss, last position and last-heard node | **IMPLEMENTED AND TESTED** | API derives 15-second state and includes latest reception (`public/api/index.php:63,73-75`; API smoke). Actual node timing/display behavior awaits hardware. |
| Battery level displayed/logged | **IMPLEMENTED AND TESTED** | API hiker and packet data include battery; dashboard renders it (`public/api/index.php:73`; `public/assets/js/dashboard.js`; API smoke). |
| Emergency/SOS and outside-zone event | **IMPLEMENTED AND TESTED** | Service creates SOS and OFF_TRAIL independently; API smoke includes SOS outside zone. Physical alarm and hiker SOS trigger are untested. |
| One ACK/reply slot and reply priority | **IMPLEMENTED AND TESTED** | One priority constant and one command per packet; SOS outranks outside alert, storm, ACK (`src/Services/ApiService.php:115-123`; API smoke). |
| DIRECT vs Checkpoint downlink route; relay hop delay | **IMPLEMENTED AND TESTED** | Route selection and packet logging (`src/Services/ApiService.php:58-63`; `public/api/index.php:81-88`). Physical forwarding is absent. |
| Command created/fetched/sent timing | **IMPLEMENTED AND TESTED** | Timestamps and elapsed milliseconds are logged/exposed; does not measure radio/hiker arrival (`src/Services/ApiService.php:64-73`; bridge API and packet log). |
| Storm repetition and Phase 2 switch-off | **IMPLEMENTED AND TESTED** | Campaign state/repetition/cancel plus Phase 2 behavior; tests exercise them (`public/api/index.php:94-101`; `tests/api_smoke.php:52-58,71-74`). |
| Sheet 1 / Sheet 4 dashboard data | **PARTIAL** | Available packet, reception, position, command timing and event exports exist (`public/api/index.php` CSV route). Wrong-node observation and actual hiker receive windows/phone warning timing remain manual; no device receipt measurement. |
| Session-scoped sequence identity | **IMPLEMENTED AND TESTED** | Migration 010 adds sessions to packets, receptions, commands, and events; dedupe compares site, hiker, sequence, and the server-stamped active session. Settings can start and audit sessions; packet logs and CSV expose session metadata (`sql/migrations/010_test_sessions.sql`; `tests/test_sessions_scratch_test.php`). |
| Immediate command in ingest result | **IMPLEMENTED AND TESTED** | Non-dry-run ingest includes the created command object; simulator fetches it and reports SENT_TO_BASE to exercise lifecycle timestamps (`src/Services/ApiService.php`; `tools/simulate_hikers.php`; `tests/simulator_reply_lifecycle_test.php`). |
| Research classification of bench packets | **IMPLEMENTED AND TESTED** | LIVE Bench session and manual range marking set BENCH; packet/event/command summary counts exclude it; labels appear in packet log and CSV (`sql/migrations/009_bench_classification.sql`; `tests/bench_scratch_test.php`; `tests/research_summary_test.php`). |

The proposal DOCX is not modified by this project work. Table 8 physical sizes and actual acceptance windows are not inferred here. Safe zones now support one open-track outline or a two-ring closed-loop corridor; vertices are grouped by ring and legacy single-ring payloads remain supported (`sql/migrations/008_safe_zone_rings.sql`; `src/Services/ApiService.php`).

## 8. Tests and verification

**IMPLEMENTED AND TESTED — latest scratch suite.** `tests/run_scratch_suite.php` completed successfully after BENCH support: 247 PASS-prefixed output lines and zero FAIL lines. The suite ran PHP, Node, and PowerShell tests against disposable scratch databases, which it dropped (`tests/run_scratch_suite.php:17-31`). No configured test DB or LIVE database was used.

Included test files cover:

- PHP logic: `geofence_test.php`, `polygon_validation_test.php`, `research_summary_test.php`, `mode_safety_test.php`, `live_check_test.php`, `registry_service_test.php`.
- DB safety/isolated behavior: `clear_demo_scratch_test.php`, `live_schema_scratch_test.php`, `migration_scratch_test.php`, `bench_scratch_test.php`, `live_first_run_scratch_test.php`.
- API and pages: `api_smoke.php`, `site_safe_zone_smoke.ps1`.
- Browser logic/static contracts: `gpx_corridor_test.js`, `gpx_ring_test.js`, `dashboard_map_labels_test.js`, `dashboard_packet_log_contract_test.js`, `live_empty_dashboard_test.js`, `offline_static_test.js`.
- Ring persistence/geofence integration: `ring_zone_scratch_test.php` creates and drops its own ring and pre-008 scratch databases.

The suite creates/drops scratch databases and launches only loopback test servers. Actual LIVE database integrity/migration state was not queried during this review. Use `docs/LIVE_FIRST_RUN.md` for the read-only readiness workflow; back up before any schema migration.

**Operator-reported LIVE follow-up (2026-11-04):** The operator says they ran `tools/backup_db.php --profile=live`, `tools/migrate_db.php --profile=live --allow-live-schema`, and `tools/live_check.php`. The output and resulting backup location were not provided here, and this report did not connect to LIVE; success and current LIVE readiness are therefore not verified.

## 9. Data integrity and security

**IMPLEMENTED AND TESTED — mode isolation controls.** Research eligibility uses demo/rehearsal/test/BENCH flags and source packet metadata for related rows. LIVE mode blocks demo/rehearsal/test ingest; the live empty-schema scratch test confirms rejection writes no packet. Bench session marks unflagged LIVE uplinks; demo clearing deletes only flagged demo/rehearsal/test rows (and proven linked rows) and preserves legacy unflagged positions/alerts, reporting the retained count (`src/Services/ApiService.php`; `tests/bench_scratch_test.php`; `tests/clear_demo_scratch_test.php`).

**IMPLEMENTED AND TESTED — web security.** Operator pages and APIs require local login, PHP session authorization, and CSRF tokens; loopback guard remains enabled and bridge routes continue to require the separate bridge token. Binding the development server to another interface would expose operator actions and data to reachable clients; keep it on 127.0.0.1. Do not publish the local bridge token.

## 10. Offline guarantee

**IMPLEMENTED AND TESTED — no map tile/CDN dependency in static assets.** The app uses inline SVG and local scripts/styles; `tests/offline_static_test.js` scans the dashboard, safe-zone, settings and relevant assets for external URLs. No tile folder is needed by the map. This is a source scan, not a browser network-capture proof; repeat a runtime request inspection if that assurance is required.

## 11. Known issues and open decisions

1. **High — bench workflow depends on the operator:** the Bench session defaults OFF. Turn it on before bench trials and off before scored field collection; otherwise unflagged LIVE records are REAL.
2. **High — no physical system verification:** firmware, radios, serial link, phone app, and hiker-side response have not been built/tested.
3. **High — site geometry:** PUP boundary is demo-only; Balagbag and Mapalad route geometry, base and checkpoint coordinates need survey/operator data.
4. **Medium — timing evidence:** command timestamps measure base/bridge lifecycle only; 2 s / 3 s receive windows and 15 s hiker warning behavior need hardware/record-sheet measurements.
5. **Medium — security:** single local operator account with session and CSRF protection; intended deployment remains one operator on loopback.
6. **Medium — offline runtime proof:** static external-reference scan passed; no runtime browser network capture recorded here.
7. **Low — version drift:** README and old historical reports may describe prior states; use current code and this report's cited evidence.

## 12. Not finished / blocked

**BLOCKED (waiting on hardware/firmware/site data)**

- ESP32 firmware, actual LoRa delivery, relay forwarding, and USB serial bridge.
- Final firmware-owned packet sizes, binary encoding and serial framing.
- Phone-to-hiker GPS transfer, hiker SOS/inactivity detection, screen/vibration/audio warning, turn-back receipt, and hiker receive-window timings.
- Surveyed PUP boundary, Balagbag GPX/corridor, checkpoint/base locations, and Mapalad route/reference locations.
- Field measurements for delivery denominator, position error ground truth, battery life, wrong-node receptions, and phone alert timestamps.
- Hardware acceptance testing and any claim of outdoor/radio readiness.

## 13. Suggested next steps

1. Use the audited Bench session switch during Phase 1 trials, then verify it is OFF before scored field collection.
2. Collect and verify site data, then mark registry locations verified and import surveyed GPX corridors.
3. Review the operator's LIVE backup, migration, and readiness-check outputs and confirm the resulting migration ledger/readiness before field use; do not rerun migrations without checking current state.
4. Agree packet/serial contract with firmware owners without assuming sizes or framing; then implement and bench-test the bridge when hardware exists.
5. Capture manual measurement fields from the proposal sheets and verify physical timing, alerts, battery, and wrong-node observations.
6. Add authenticated operator accounts/CSRF if the system ever needs access beyond its single-machine loopback setup.

## One-page status summary

| Area | Status | Note |
|---|---|---|
| PHP/MariaDB dashboard foundation | **IMPLEMENTED AND TESTED** | Local app, API, settings, map, logs, and controls. |
| Schema and migration system | **IMPLEMENTED AND TESTED** | Schema-only setup, separate demo seed, checksum ledger through migration 010. |
| DEMO/LIVE mode separation | **IMPLEMENTED AND TESTED** | Local mode file, separate profiles/tokens, LIVE packet rejection and readiness checks. |
| Uplink ingest/research/event/command logic | **IMPLEMENTED AND TESTED** | Logical fields only; dedupe, reply priority, route, timestamps and campaign behavior. |
| Offline SVG map and GPX editor | **IMPLEMENTED AND TESTED** | No tiles, local projection, bounded labels, simplification preview. |
| Test suite | **IMPLEMENTED AND TESTED** | Scratch suite passed after migration 010, session auth/UI, and simulator lifecycle changes; scratch DB was dropped. |
| Security | **IMPLEMENTED AND TESTED** | Local operator account, session auth, CSRF on mutating operator APIs, loopback guard, and separate bridge-token routes (`src/operator_auth.php`; `tests/operator_auth_scratch_test.php`). |
| LIVE DB state | **PARTIAL** | Operator reports running backup, migration, and live check; outputs and current DB state were not verified in this report. |
| Bench-data research classification | **IMPLEMENTED AND TESTED** | BENCH is stored per packet and excluded from research counts; switch/range actions are audited. |
| Real radio/phone/hardware path | **BLOCKED (waiting on hardware/firmware/site data)** | Firmware, physical hardware and field observations pending. |
| Surveyed trail/site configuration | **BLOCKED (waiting on hardware/firmware/site data)** | Needed coordinates and verified boundaries are incomplete. |
