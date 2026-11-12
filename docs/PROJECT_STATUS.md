# Trailwatch Dashboard — Project Status

**Reviewed:** 2026-11-12
**Project:** `D:\Projects for PC\Thesis\Dashboard-work`
**Branch:** `mydays/session-fix-merge` (pushed to GitHub; `main` was fast-forwarded to `3397bd3` on 2026-11-12, so `main` and this branch are the same commit; the serial bridge is `5fed06d`, the docs update `e910fa5`, and position error checks (migration 015) `dfd8f88`)
**Scope:** Dashboard, PHP API/backend, MariaDB schema/migrations, local tooling, and dashboard-relevant research proposal requirements. This report is based on the current checked-out source and test runner; it does not claim the hardware has been tested. No credentials or tokens are included. Facts about the LIVE database come from output the operator pasted; this report did not connect to LIVE.

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
| `sql/schema.sql`, `sql/demo_seed.sql`, `sql/migrations/` | Schema-only initialization, demo seed, and fifteen ordered migrations; 008 adds safe-zone rings, 009 BENCH classification, 010 sessions, 011 safe-zone metadata, 012 packet annotations, 013 crossing records, 014 marker checks, and 015 position error checks. |
| `tools/setup_db.php`, `migrate_db.php`, `backup_db.php`, `live_check.php` | DB setup, migration, timestamped backup, and read-only LIVE readiness check. |
| `tools/simulate_hikers.php`, `tools/scenarios/` | DEMO-only logical-packet simulator and scenarios (JSON scenarios plus built-in `phase1_replay` and `return_to_safe`); it does not simulate radio hardware. |
| `tools/serial_bridge.py`, `tools/fake_esp32.py` | Laptop-side ASCII line bridge (U lines in, D lines out) that posts to the bridge API, and a fake ESP32 line generator for DEMO tests without hardware. Not firmware. |
| `tests/` | PHP, JavaScript, PowerShell, Python (`serial_bridge_test.py`), API smoke, and isolated scratch-LIVE integration tests. |
| `docs/CODEX_WEBSITE_SPEC.md`, `docs/CHAPTER4_DATA.md`, `docs/PHONE_LINK_OPTIONS.md` | Requirements copy and data/phone planning notes. The paper remains unchanged. |

## 2. Architecture and completed behavior

**IMPLEMENTED AND TESTED — logical ingest and event path.** A bridge POST supplies an `uplink` and separate `reception` metadata. The service validates logical fields, finds the hiker and site polygon, applies the existing point-in-polygon function, persists packet/reception history, derives events, selects one reply, and queues a command (`public/api/index.php:13-21`; `src/Services/ApiService.php:125-170`; `src/geofence.php:1-16`). These are logical API fields; this is not a wire or serial specification.

**IMPLEMENTED AND TESTED — sequence/reception behavior and priority.** One logical packet is keyed by site, hiker, and sequence; receptions are separately retained and later receptions are marked duplicate (schema `sql/schema.sql:78-125`; service and `tests/api_smoke.php`). Out-of-order unique sequences remain separate packets. Each uplink yields at most one downlink. The single priority constant is **SOS/emergency reply > off-trail alert > storm turn-back > ACK** (`src/Services/ApiService.php:115-123`; `tests/api_smoke.php:24,66-77`).

**IMPLEMENTED AND TESTED — route and timing logs.** If any direct reception exists, the command route is `DIRECT`; if the packet was heard only via a Checkpoint, the route is `CHECKPOINT_RELAY` through that Checkpoint (`src/Services/ApiService.php:58-63`). Packet/event logs expose heard-by node, path, RSSI/SNR, relay hop delay, duplicate marker, command route, and available command timestamps/elapsed milliseconds (`public/api/index.php:81-88,109-111`; `tests/api_smoke.php:77`). The times describe command creation/fetch/sent reporting; the app does not infer hiker receive-window success.

**IMPLEMENTED AND TESTED — Phase 2/3 crossing records and packet-window calculations.** Operator routes start a crossing, mark re-entry, end it, and edit remarks/manual fields (`POST /api/crossings/start`, `POST /api/crossings/{id}/reentry`, `POST /api/crossings/{id}/end`, `PUT /api/crossings/{id}`). The table enforces one crossing number per session/hiker. Phase 2 uses crossing numbers 1–6 and requires a gate and side; PHASE3 is treated as Part B, numbers 1–5 with gate fields null. An open crossing blocks another for the same session/hiker; repeated gate use returns a warning but is allowed. Reads expose the reference OUTSIDE packet, GPS valid-fix fields, outside count, inside/outside flips, reports after re-entry, return-to-safe time, and reference-packet command lifecycle times. Computations use packet `received_at` only. Alert latency, alarm screen, noticed and understood fields remain operator-entered; no 2 s or 15 s outcome is inferred (`sql/migrations/013_crossings.sql`; `src/Services/ApiService.php`; `public/api/index.php`; `tests/crossings_scratch_test.php`).

False-alert output is explicitly a **candidate only**: an OFF_TRAIL event whose source packet's receive time falls outside every matching session/hiker `started_at..reentry_at` window. It is not stored or presented as a study result. Crossing timestamps are `DATETIME(3)`, while packet `received_at` is `DATETIME` at second precision, so sub-second boundary ordering cannot be recovered from existing packet timestamps.

**IMPLEMENTED AND TESTED — UTC clock.** The MariaDB session time zone is +00:00 and PHP runs in UTC; ingest converts a supplied `received_at` to UTC. An operator-only `GET /api/clock-check` returns `server_utc_now` (no milliseconds), `db_now` and the time zones, and a Settings clock panel shows them with the laptop offset for Sheet 0. API and CSV timestamps are naive UTC strings. Rows written before this change (legacy DEMO rows) can mix clocks.

**IMPLEMENTED AND TESTED — packet annotations (migration 012).** The operator can set or clear an intended class (INSIDE or OUTSIDE) and a point source (FROM_TRACK or MOVED_BEYOND_MARGIN) for one packet or a range of sequences; actions are audited. The packet log and packet CSV gain `intended_class`, `point_source` and `classification_matched`. Nothing is added to the uplink or bridge payload.

**IMPLEMENTED AND TESTED — marker checks (migration 014, PHASE2 sessions only).** `POST /api/marker-checks` averages the valid fixes (accuracy 10 m or better, 5 or more satellites, duplicates excluded) inside a window that ends at server UTC now (default 120 s, allowed 30 to 600 s), classifies the mean position with the existing geofence against the active zone, and stores classification, intended class and whether they match. No valid fix returns HTTP 422 and saves nothing. With no active polygon the row is saved as UNCLASSIFIED with a warning. Warnings (never blocks) appear under 100 s or fewer than 10 valid fixes. `GET /api/marker-checks?session_id=` and the `marker_checks` CSV export read it back. Seen on DEMO through the session 13 CSV; the CSV now prints Y/N for `matches_intended` (covered by a test; the fixed CSV was not re-downloaded).

**IMPLEMENTED AND TESTED — position error checks (migration 015, PHASE2 sessions only).** One row per gate check holds the inside and outside 10-second windows, valid fix counts, mean positions, the typed tape distance, the phone distance and the signed error. The operator presses two stamp buttons (each stores `server_utc_now` unchanged); each window is the 10 seconds before its stamp, both ends inclusive, and only non-duplicate valid fixes count. Phone distance is a PHP haversine (radius 6371008.8 m) between the two window means; error is phone distance minus tape distance, signed, 3 decimals. Stamps must be `YYYY-MM-DD HH:MM:SS` UTC, not more than 2 s in the future and not older than 60 minutes. Responses: HTTP 409 outside PHASE2, 404 for an unknown session or hiker, 422 when a window has no valid fix, 503 without migration 015. Saves are audited as `POSITION_ERROR_CREATED` in a transaction. `GET /api/position-errors?session_id=` returns rows plus a read-time summary (count, mean signed, mean absolute, largest absolute); the CSV is `GET /api/export.csv?type=position_errors&session_id=N` (17 columns). Browser test on DEMO (session 23): phone distance 10.008 m against a typed 10 m gave an error of 0.008 m, matching the expected value. The panel's three labelled summary lines were not confirmed on screen. Files: `sql/migrations/015_position_error_checks.sql`, `public/api/index.php`, `src/Services/ApiService.php`, `public/assets/js/site-settings.js`, `tests/position_error_scratch_test.php`. Not built: the optional absolute error at a known point.

**Difference from the paper (decision D5).** The paper says the app logs the pause average. The dashboard instead computes the marker and pause averages from packets inside an operator-stamped window. Worth a note in the paper.

**IMPLEMENTED AND TESTED — link loss, SOS, and storm controls.** API reads mark a hiker `LINK_LOST` after 15 seconds without an uplink and retain its last packet position and receptions; dashboard renders state, battery, report age and last heard by (`public/api/index.php:63,73-75`; `public/assets/js/dashboard.js`). Emergency uplinks create an emergency event and SOS reply; an outside-zone SOS also retains an OFF_TRAIL event (`src/Services/ApiService.php:125-170`; `tests/api_smoke.php:66`). Storm campaigns repeat on eligible reply slots until receipt flag or operator cancellation; Phase 2 disables them (`public/api/index.php:94-101`; service; `tests/api_smoke.php:52-58,71-74`). The “alarm” is an event/alert shown in the app; no sounder or physical alarm has been verified.

**IMPLEMENTED AND TESTED (software only, DEMO) — laptop serial line bridge.** `tools/serial_bridge.py` reads one ASCII `U` line per heard uplink (from `--stdin`, or from a serial port with `--port` and pyserial, which is untested), posts it to `POST /api/bridge/ingest` as an `uplink` plus a `reception` object, and, when the response holds a command, writes one `D` line (`D,node_id,sequence_answered,command_type`) back before reporting `SENT_TO_BASE` to `POST /api/commands/{id}/status`. The `U` line is `U,node_id,sequence,latitude,longitude,accuracy_m,satellites,battery,flags,path[,rssi_dbm[,snr_db[,checkpoint_id[,checkpoint_rssi_dbm[,relay_hop_delay_ms]]]]]`, with `flags` as five 0/1 characters in the order link_lost, low_battery, emergency, turn_back_received, test_mode. The command types are the dashboard's own names: `ACK`, `OFF_TRAIL_ALERT`, `SOS_ACK`, `STORM_TURN_BACK`. The bridge accepts only loopback `http://` URLs, reads the bridge token from the environment variable `TRAILWATCH_BRIDGE_TOKEN` and never prints it, logs one line per packet to stderr (including the milliseconds from line read to `D` line written), and keeps going after HTTP 400, 401, 409, 422 and 500. `tools/fake_esp32.py` prints fake `U` lines. `tests/serial_bridge_test.py` gave 27 PASS / 0 FAIL against a local stub HTTP server (it is not part of the PHP suite count). A real DEMO run (sequences 1 to 4 and 200 to 201) returned HTTP 201 for every packet with the expected `D` lines and 84 to 120 ms from line read to `D` line; this is localhost and the laptop side only, so the serial link, radio and relay hop will add time on the real system. The line format is a proposal for the USB link only. It is not the over-the-air format. The operator writes the firmware themselves, accepted the format in principle, and has not yet fixed the baud rate (115200 is a placeholder) or tested the `--port` path. Radio settings come from proposal Table 9 (915 MHz, SF9, 125 kHz bandwidth, coding rate 4/5, 14 dBm) and are applied by the firmware, not by this bridge.

**BLOCKED (waiting on hardware/firmware/site data) — physical path.** No ESP32 firmware, actual LoRa radio driver, or Android application is implemented, and the laptop bridge above has not been run with a real serial port. Over-the-air packet sizes and radio encoding remain firmware-owned (Table 8 sizes are planned values). No real device delivery is claimed.

## 3. API and access control

**IMPLEMENTED AND TESTED — local operator access.** `local_only` defaults ON and only `127.0.0.1` / `::1` are accepted for operator/settings routes (`src/db.php:67-73`). Operator pages and APIs also require a local login/session and CSRF tokens for writes (`src/operator_auth.php`; `tests/operator_auth_scratch_test.php`). Bridge routes use the active profile's `X-Bridge-Token` (`public/api/index.php`). Keep the PHP server on loopback; README says never expose it to a LAN/public interface.

Current route families in `public/api/index.php` include:

- **Read dashboard:** `GET /api/status`, `/api/hikers`, `/api/hikers/{node_id}/latest`, `/api/positions/latest`, `/api/safe-zone`, `/api/events`, `/api/logs/packets`, `/api/research/summary`, `/api/settings/phase-mode`, `/api/test-sessions/active`, `/api/sites`, `/api/network-nodes`, `/api/reference-points`, `/api/commands/storm-turn-back/active`, `/api/crossings?session_id={id}`, `/api/crossings/{id}`, `/api/marker-checks?session_id={id}`, `/api/position-errors?session_id={id}`, and `/api/clock-check`. Packet-log filters include `session_id` and `node_id`.
- **Operator writes:** `POST /api/settings/site`, `/api/settings/phase-mode`, `/api/test-sessions/start`, `PUT /api/safe-zone`, `POST /api/events/{id}/resolve`, `POST /api/crossings/start`, `POST /api/crossings/{id}/reentry`, `POST /api/crossings/{id}/end`, `PUT /api/crossings/{id}`, `POST /api/marker-checks`, `POST /api/position-errors`, storm campaign arm/cancel endpoints, demo clearing, hiker registry create/update, site-location and network-node/reference-point CRUD and verification actions.
- **Bridge:** `POST /api/bridge/ingest`, `/api/bridge/validate`, `/api/bridge/status`, `GET /api/commands/pending`, and `POST /api/commands/{id}/status`.
- **Export and readiness:** `GET /api/export.csv` (types include `crossings`, `marker_checks` and `position_errors`, each with `session_id`), `GET /api/live-check`.
- **Bridge token on a read route:** `GET /api/safe-zone` also accepts a valid bridge token. On LIVE, anyone holding the token can read the polygon.

Inputs are JSON or query filters as appropriate; validation returns HTTP errors for malformed fields/ranges and unknown records. Operator APIs require the local operator session; mutations require `X-CSRF-Token`, and loopback remains enforced. Bridge ingest/status/command routes require the profile bridge token; validation also requires loopback and performs a dry run. LIVE-check is local and read-only. Ingest returns the created logical command object (or `null` for dry-run/no command) while preserving `command_queued`. See route dispatch at `public/api/index.php`; tests include `tests/api_smoke.php`, `tests/operator_auth_scratch_test.php`, and `tests/live_first_run_scratch_test.php`.

**IMPLEMENTED AND TESTED — dry-run validation.** `POST /api/bridge/validate` authenticates, validates without committing, and is guarded to loopback. Scratch integration verifies packet/reception/command tables remain unchanged (`public/api/index.php:23-24`; `tests/live_first_run_scratch_test.php:33-35`).

## 4. Database and migrations

**IMPLEMENTED AND TESTED — schema.** Core tables include `hiker_nodes`, `network_nodes`, `trail_reference_points`, `safe_zones`, `safe_zone_vertices`, `site_locations`, `app_settings`, `test_sessions`, `uplink_packets`, `packet_receptions`, `packet_annotations`, `crossings`, `marker_checks`, `position_error_checks`, `downlink_commands`, `storm_turnback_campaigns`, `system_events`, `operator_actions`, `system_status`, legacy `nodes`/`positions`/`alerts`, `schema_migrations`, and `mode_switch_audit` (`sql/schema.sql`). Session IDs on packet, reception, command, and event rows point to the site-scoped test session; packet uniqueness includes site, hiker, session, and sequence. Crossing rows are unique by session, node, and crossing number.

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
11. `011_safe_zone_version_metadata.sql` — nullable metadata for GPX-derived safe-zone versions.
12. `012_packet_annotations.sql` — operator-side intended classification and point-source annotations.
13. `013_crossings.sql` — Phase 2/3 crossing records and optional operator-entered alert fields.
14. `014_marker_checks.sql` — gate marker acceptance checks (averaged window, classification, intended class).
15. `015_position_error_checks.sql` — position error checks (two 10 s windows, tape distance, phone distance, signed error).

The runner uses checksums and skips already applied migrations; the scratch migration test checks repeated application and fifteen ledger rows (`tools/migrate_db.php`; `tests/migration_scratch_test.php`). Migrations 001 to 009 have mixed CRLF/LF line endings and their checksums are of the raw bytes, so never normalize them. Migrations 011 and later are stored with LF.

- **DEMO** (`hiker_dashboard_test_codex`): on 015. A DEMO backup was taken first (`backups/20261112_014205`), and the table exists with ledger count 15.
- **LIVE** (`hiker_dashboard_live`): on 015 since 2026-11-12. Before that `live_check` had reported 14 recorded migrations with matching checksums and CHECK TABLE OK on 23 tables, from output the operator pasted. After a fresh LIVE backup, migration 015 was applied; `SHOW TABLES LIKE 'position_error_checks'` listed the table and `SELECT COUNT(*) FROM schema_migrations` returned 15 (output pasted by the operator). `live_check` has not been rerun since.
- Migration 014 SHA-256: `83B64DC283FD44ECAC31A72BF2955FB7329992115E8228A5F7D692D2871F5225`. Migration 015 SHA-256: `E09F4D836FEE9B91BA41DA13BE0282970DCBA8791463A92ABD0CAC62392EA76E` (1374 bytes, LF only, one trailing newline).
- **Applying a migration to LIVE (how 015 was done, 2026-11-12):** `C:\xampp\php\php.exe tools\backup_db.php --profile=live` (it prints `Backup complete: backups/<UTC folder>/hiker_dashboard_live.sql`; this one was `backups/20261112_032832`), then `C:\xampp\php\php.exe tools\migrate_db.php --profile=live --allow-live-schema`. Without `--allow-live-schema` the runner refuses with "Refusing LIVE migration unless explicitly invoked with --allow-live-schema." The line "Migration ledger is current for profile X" is printed at the end of every run and does not prove that anything was applied, so check the table and the ledger count afterwards. Always pass `--profile`; `backup_db.php` defaults to LIVE without it.

## 5. Frontend, map, and safe-zone editor

**IMPLEMENTED AND TESTED — offline map.** The dashboard map is plain SVG with a background/grid, equirectangular local projection, site nodes/references, safe-zone geometry, hiker markers, scale and north direction; it does not use a tile layer (`public/assets/js/dashboard.js`; `public/index.php:56-60`). Map layout has defaults for empty/single-marker cases. Labels counter-scale to CSS-pixel size, fit inside SVG bounds, truncate with full text retained in an SVG title, and resize with the viewport (`public/assets/js/dashboard-label-layout.js`; `tests/dashboard_map_labels_test.js:5-15`). Static offline checks cover the dashboard, safe-zone and local assets (`tests/offline_static_test.js`). Static scans do not prove zero network activity at runtime.

**IMPLEMENTED AND TESTED — GPX/polygon editing.** `/safe-zone` locally imports a GPX track/route, previews a corridor, and saves only after operator confirmation. Tracks over 250 points use browser Douglas–Peucker simplification with configurable tolerance and previewed point count/maximum deviation. Corridor half-width remains 5–50 m (`public/safe-zone.php`; `public/assets/js/safe-zone.js`; `public/assets/js/safe-zone-geometry.js`; `tests/gpx_corridor_test.js:14-34`). Existing geometry keeps edge points INSIDE (`src/geofence.php:1-16`; polygon/geofence tests). This is not survey validation and does not establish a real boundary. Closed loops now produce outer and inner rings, with the center of the loop outside the safe zone (`tests/gpx_ring_test.js`; `tests/ring_zone_scratch_test.php`).

**IMPLEMENTED AND TESTED — safe-zone version metadata (migration 011).** Each saved safe-zone version can store `margin_m`, GPX file name, raw and simplified point counts, simplification tolerance, largest simplification deviation (`simplify_max_deviation_m`), recorded track length (haversine over raw points), optional ground length and a recording note; the API derives corridor width and the recorded-minus-ground difference. The editor defaults the margin to 10 m and warns (does not block) below 10 m. The preview shows "Not applied" for tolerance when a track has 250 points or fewer. A two-ring save whose inner-ring probe point does not classify OUTSIDE is rejected by the server with HTTP 422, and the editor disables Save for the same case. The 2 m simplification has not yet been checked on a real track over 250 points.

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

**PARTIAL — LIVE setup state (from operator-pasted output; this report did not connect to LIVE).** The selected LIVE site is `pup`. The hiker registry has two enabled rows: `node_id` N1 (display name H1) and `node_id` N2 (display name H2). The `node_id`, not the display name, is what the bridge and hardware must send. After the `live_check` hiker-count fix, `live_check` gave 10 PASS and 2 FAIL: no active test session (expected until a test starts) and Base Camp and Checkpoint nodes missing with coordinates unset. The active LIVE safe zone "PUP oval Phase 2" (v2) has 13 points and the operator says it is not the real walked lap, so it must be replaced before any real data is trusted.

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
| Session-scoped sequence identity | **IMPLEMENTED AND TESTED** | Migration 010 adds sessions to packets, receptions, commands, and events; dedupe compares site, hiker, sequence, and the server-stamped active session. Settings can start and audit sessions; packet log and CSV expose session phase and metadata (`sql/migrations/010_test_sessions.sql`; `tests/test_sessions_scratch_test.php`). |
| Phase 2/3 crossing records (Sheets 2/3/6) | **IMPLEMENTED AND TESTED** | Migration 013 stores crossings and manual phone/alarm observations. Operator APIs provide start/re-entry/end/edit; reads compute packet-window reference, flips, outside counts, return-to-safe and reports-after-re-entry. No receive-window judgement or alert latency is computed (`sql/migrations/013_crossings.sql`; `tests/crossings_scratch_test.php`). |
| Marker acceptance (Phase 2 gates) | **IMPLEMENTED AND TESTED** | Migration 014. The mean of valid fixes in an operator-chosen window is classified against the stored polygon and compared with the intended class. Tested in `tests/marker_checks_scratch_test.php`; seen on DEMO. Not yet run on LIVE. |
| Position error (Phase 2 gates) | **IMPLEMENTED AND TESTED** | Migration 015. Phone distance between two 10 s window means minus the typed tape or wheel distance; the summary shows mean signed, mean absolute and largest absolute error, each labelled, because the paper wording is ambiguous. Browser-tested on DEMO. The operator has a tape measure; each gate still has to be measured on the ground once. |
| Phase 1 classification match | **IMPLEMENTED AND TESTED** | Migration 012 annotations plus the `phase1_replay` scenario. Replay outside points are 5 m beyond the outer edge only, so the field side is not exercised. |
| BENCH session phase and warnings | **IMPLEMENTED AND TESTED** | `BENCH` is a fourth `test_sessions.phase` value; the distinct `app_settings.phase_mode` is not synchronized. Settings warns on mismatch and, in LIVE, warns when starting BENCH/PHASE1 while Bench session is OFF. These warnings do not block starting a session (`public/api/index.php`; `public/settings.php`; `public/assets/js/site-settings.js`; session tests). |
| Immediate command in ingest result | **IMPLEMENTED AND TESTED** | Non-dry-run ingest includes the created command object; simulator fetches it and reports SENT_TO_BASE to exercise lifecycle timestamps (`src/Services/ApiService.php`; `tools/simulate_hikers.php`; `tests/simulator_reply_lifecycle_test.php`). |
| Research classification of bench packets | **IMPLEMENTED AND TESTED** | LIVE Bench session and manual range marking set BENCH; packet/event/command summary counts exclude it; labels appear in packet log and CSV (`sql/migrations/009_bench_classification.sql`; `tests/bench_scratch_test.php`; `tests/research_summary_test.php`). |

The proposal DOCX is not modified by this project work. Table 8 physical sizes and actual acceptance windows are not inferred here. Safe zones now support one open-track outline or a two-ring closed-loop corridor; vertices are grouped by ring and legacy single-ring payloads remain supported (`sql/migrations/008_safe_zone_rings.sql`; `src/Services/ApiService.php`).

## 8. Tests and verification

**IMPLEMENTED AND TESTED — latest scratch suite (2026-11-12).** `C:\xampp\php\php.exe tests\run_scratch_suite.php 2>&1` gave **542 PASS lines and 0 FAIL lines** on the tree committed as `dfd8f88`, run once on the operator's machine with the dashboard on DEMO. The `2>&1` matters because FAIL lines go to stderr. A case-sensitive `FAILED` search of the output printed nothing (a case-insensitive search wrongly matches PASS lines whose test names contain "failed"). Earlier recorded runs: 419 PASS on 2026-11-10 and 528 PASS at `a4afdfa`. The runner creates and drops disposable scratch databases; its isolated LIVE-mode integration uses only a scratch `live` profile, never `hiker_dashboard_live` (`tests/run_scratch_suite.php`). Run the suite once, only while the badge says DEMO and MariaDB is healthy.

Included test files cover:

- PHP logic: `geofence_test.php`, `polygon_validation_test.php`, `research_summary_test.php`, `mode_safety_test.php`, `live_check_test.php`, `registry_service_test.php`.
- DB safety/isolated behavior: `clear_demo_scratch_test.php`, `live_schema_scratch_test.php`, `migration_scratch_test.php`, `bench_scratch_test.php`, `live_first_run_scratch_test.php`.
- API and pages: `api_smoke.php`, `crossings_scratch_test.php`, `marker_checks_scratch_test.php`, `position_error_scratch_test.php`, `packet_annotations_scratch_test.php`, `test_sessions_scratch_test.php`, `simulator_reply_lifecycle_test.php`, `site_safe_zone_smoke.ps1`.
- Browser logic/static contracts: `gpx_corridor_test.js`, `gpx_ring_test.js`, `bench_time_test.js`, `dashboard_map_labels_test.js`, `dashboard_packet_log_contract_test.js`, `live_empty_dashboard_test.js`, `session_ui_contract_test.js`, `offline_static_test.js`.
- Python (run separately with `python tests\serial_bridge_test.py`, not part of `run_scratch_suite.php`): `serial_bridge_test.py`, 27 PASS / 0 FAIL, local stub HTTP server only.
- Ring persistence/geofence integration: `ring_zone_scratch_test.php` creates and drops its own ring and pre-008 scratch databases.

The suite creates/drops scratch databases and launches only loopback test servers. Use `docs/LIVE_FIRST_RUN.md` for the read-only readiness workflow; back up before any schema migration.

**Operator-reported LIVE state (2026-11-11 and 2026-11-12):** LIVE backups exist in `backups\` (UTC folder names): before and after migrations 010 to 013, and one taken just before migration 014 was applied to LIVE (its "Dump completed" line was not seen). A fresh LIVE backup was taken on 2026-11-12 just before migration 015 (`backups\20261112_032832`, the line `Backup complete: backups/20261112_032832/hiker_dashboard_live.sql` was seen). Take another one before any later LIVE schema change or large LIVE setup change.

## 9. Data integrity and security

**IMPLEMENTED AND TESTED — mode isolation controls.** Research eligibility uses demo/rehearsal/test/BENCH flags and source packet metadata for related rows. LIVE mode blocks demo/rehearsal/test ingest; the live empty-schema scratch test confirms rejection writes no packet. Bench session marks unflagged LIVE uplinks; demo clearing deletes only flagged demo/rehearsal/test rows (and proven linked rows) and preserves legacy unflagged positions/alerts, reporting the retained count (`src/Services/ApiService.php`; `tests/bench_scratch_test.php`; `tests/clear_demo_scratch_test.php`).

**IMPLEMENTED AND TESTED — web security.** Operator pages and APIs require local login, PHP session authorization, and CSRF tokens; loopback guard remains enabled and bridge routes continue to require the separate bridge token. Binding the development server to another interface would expose operator actions and data to reachable clients; keep it on 127.0.0.1. Do not publish the local bridge token.

## 10. Offline guarantee

**IMPLEMENTED AND TESTED — no map tile/CDN dependency in static assets.** The app uses inline SVG and local scripts/styles; `tests/offline_static_test.js` scans the dashboard, safe-zone, settings and relevant assets for external URLs. No tile folder is needed by the map. This is a source scan, not a browser network-capture proof; repeat a runtime request inspection if that assurance is required.

## 11. Known issues and open decisions

1. **High — bench workflow depends on the operator:** the Bench session defaults OFF. Turn it on before bench trials and off before scored field collection; otherwise unflagged LIVE records are REAL.
2. **High — no physical system verification:** firmware, radios, serial link, phone app, and hiker-side response have not been built/tested.
3. **High — site geometry:** the PUP safe zone now on LIVE (13 points) is not the real walked lap according to the operator; Balagbag and Mapalad route geometry, and Base Camp and Checkpoint coordinates, still need real survey or operator data.
4. **Medium — timing evidence:** command timestamps measure base/bridge lifecycle only; 2 s / 3 s receive windows and 15 s hiker warning behavior need hardware/record-sheet measurements.
5. **Medium — security:** single local operator account with session and CSRF protection; intended deployment remains one operator on loopback.
6. **Medium — offline runtime proof:** static external-reference scan passed; no runtime browser network capture recorded here.
7. **Medium — packet/crossing timestamp precision:** crossing boundaries retain milliseconds, but packet `received_at` has second precision, so same-second packet ordering around a button press is not recoverable.
8. **Low — Phase 3 interpretation:** the API treats every PHASE3 session as Part B for crossing-number and null-gate validation; the session model has no separate sub-phase field.
9. **Low — version drift:** README and old historical reports may describe prior states; use current code and this report's cited evidence.
10. **Resolved 2026-11-12 — LIVE is on the final schema:** migration 015 was applied to LIVE after a fresh backup (section 4). `live_check` has not been rerun since.
11. **Low — tape distance:** position error needs a distance measured on the ground with a tape or measuring wheel (proposal v80, materials list). The operator has a tape measure and will use it, so position error stays in. Each of the 6 gates is measured once when the markers are placed and the number is reused for every tester. A tape is not practical for a full lap, so the optional ground length stays empty unless a measuring wheel is borrowed.
12. **Low — wording differences between the paper and the dashboard (not fixed here):** (a) the paper says the app logs the pause average, while the dashboard computes it from packets in an operator-stamped window; (b) "mean and largest absolute position error" is ambiguous, so three labelled summary lines are shown; (c) Table 9 says the largest simplification deviation is recorded on Sheet 0, but no sheet has a row for it (stored as `simplify_max_deviation_m`); (d) Table 8 gives the downlink type as "acknowledgement or alert" but the text lists four types and the dashboard issues four; (e) Part B corridor is about 150 m in v80 while the sample Balagbag GPX is 817.96 m.
13. **Low — mixed clocks in old rows:** rows written before the UTC change can mix clocks. Use packet times, not event `created_at`, for windows (the return-to-safe event time is the database `created_at`).
14. **Low — test data for averaged checks:** `phase1_replay` spreads INSIDE points around the ring edge, so their average falls in the middle of the oval (OUTSIDE) and an INSIDE marker check fails by design. Position error needs packets at one location (for example a small GPX with identical points). The simulator restarts sequence numbers at 1 on every run, so use one run per session; a rerun in the same session is flagged duplicate and excluded from the windows.
15. **Low — not built:** D9 freeze warning when saving a safe-zone version during an active PHASE2/PHASE3 session, D11 "link lost since" (last reception plus 15 s, derived), P9 GPS fix stability, the optional absolute position error at a known point, and field-side replay points. Build only what the paper needs.
16. **Medium — local MariaDB incident (2026-11-12):** `mysqld` hung on corrupted replication metadata files (`master*.info`, `relay-log*.info`, `mysql-relay-bin*`) in `C:\xampp\mysql\data`; cause unverified, probably from the earlier Aria recovery and forced kills. The files were moved, not deleted, to `D:\mysql-replication-junk-20261112` and `D:\mysql-replication-junk-20261112b` (keep both). If it happens again, read the `mysqld` console output and check which `php.exe` processes hold connections before killing anything, and never run the suite while MariaDB is unhealthy.
17. **Medium — USB line format not final:** the `U`/`D` line format is a proposal for the laptop-to-ESP32 link. The operator writes the firmware, accepted the format in principle, and still has to fix the baud rate (115200 is only a placeholder) and test the `--port` path with pyserial.
18. **Low — bridge timing is partial:** 84 to 120 ms is the laptop side on localhost only. The Hiker Node listens 2 s for a reply (Table 9), and that window also has to cover the serial link, the radio and, through a Checkpoint, the relay hop. Measure it with the real boards.
19. **Low — PowerShell pipe BOM:** Windows PowerShell puts a UTF-8 BOM at the start of piped text; the bridge reads stdin as `utf-8-sig` so the first line is not rejected. A real serial port does not send a BOM.

## 12. Not finished / blocked

**BLOCKED (waiting on hardware/firmware/site data)**

- ESP32 firmware, actual LoRa delivery, relay forwarding, and real serial-port testing of the laptop bridge (the bridge exists and is DEMO-tested with a fake generator only).
- Final packet sizes and binary encoding, which the operator decides when writing the firmware; the USB line format is a proposal until the firmware exists.
- Phone-to-hiker GPS transfer, hiker SOS/inactivity detection, screen/vibration/audio warning, turn-back receipt, and hiker receive-window timings.
- Surveyed PUP boundary, Balagbag GPX/corridor, checkpoint/base locations, and Mapalad route/reference locations.
- Field measurements for delivery denominator, position error ground truth, battery life, wrong-node receptions, and phone alert timestamps.
- Hardware acceptance testing and any claim of outdoor/radio readiness.
- The Android phone app (`HikerPhoneApp`) is not touched by this work.
- Operator-side LIVE setup: a real walked PUP lap (not yet recorded), the 6 gate markers placed and tape-measured, real Base Camp and Checkpoint coordinates (read from the phone at the real spot), and the node ID the hardware sends (N1 or N2).

## 13. Suggested next steps

1. Finish the LIVE setup: load the real walked PUP lap in the editor, check the loop rule, save it and reload; add real Base Camp and Checkpoint coordinates (the Base Camp code must match the `base_id` the bridge sends, `BASE` is the config fallback); confirm the node ID the hardware sends.
2. Done 2026-11-12: fresh LIVE backup and migration 015 on LIVE (section 4). Switch the dashboard back to DEMO if the badge still says LIVE.
3. Start a LIVE test session only when real testing begins. Use a BENCH session with the Bench switch for bench work, and verify the switch is OFF before scored field collection.
4. Tape measure: decided, it will be used at each gate (known issue 11). Decide which of the items in known issue 15 the paper really needs.
5. Write the firmware's USB part (the operator does this), fix the baud rate, and check that the SX1276 module is the 915 MHz version. When the boards arrive, run the radio self-test, then test the bridge with a real serial port (needs pyserial) and measure the real reply time against the 2 s window.
6. Capture the manual measurement fields from the proposal sheets (timing, alerts, battery, wrong-node observations).
7. Done 2026-11-12: `main` was fast-forwarded to `3397bd3` with `git push origin mydays/session-fix-merge:main` (no force). Never force-push or rewrite history on `main`.

## One-page status summary

| Area | Status | Note |
|---|---|---|
| PHP/MariaDB dashboard foundation | **IMPLEMENTED AND TESTED** | Local app, API, settings, map, logs, and controls. |
| Schema and migration system | **IMPLEMENTED AND TESTED** | Schema-only setup, separate demo seed, checksum ledger through migration 015 on DEMO and LIVE. |
| DEMO/LIVE mode separation | **IMPLEMENTED AND TESTED** | Local mode file, separate profiles/tokens, LIVE packet rejection and readiness checks. |
| Uplink ingest/research/event/command logic | **IMPLEMENTED AND TESTED** | Logical fields only; dedupe, reply priority, route, timestamps and campaign behavior. |
| Offline SVG map and GPX editor | **IMPLEMENTED AND TESTED** | No tiles, local projection, bounded labels, simplification preview. |
| Test suite | **IMPLEMENTED AND TESTED** | Full scratch suite: 542 PASS, 0 FAIL at `dfd8f88`; disposable DBs dropped. |
| Security | **IMPLEMENTED AND TESTED** | Local operator account, session auth, CSRF on mutating operator APIs, loopback guard, and separate bridge-token routes (`src/operator_auth.php`; `tests/operator_auth_scratch_test.php`). |
| LIVE DB state | **PARTIAL** | On migration 015 (table and ledger count 15 seen in output the operator pasted); `live_check` last gave 10 PASS / 2 FAIL before 015 (no active session; Base Camp and Checkpoint coordinates unset) and has not been rerun. |
| Bench-data research classification | **IMPLEMENTED AND TESTED** | BENCH is stored per packet and excluded from research counts; switch/range actions are audited. |
| Phase 2/3 crossing records | **IMPLEMENTED AND TESTED** | Migration 013, audited operator APIs, packet-time computed crossing fields, manual alert observations, and candidate-only false-alert field. |
| Marker checks and position error (Phase 2) | **IMPLEMENTED AND TESTED** | Migrations 014 and 015, operator-stamped windows, labelled error summary, CSV exports. Both are on DEMO and LIVE; each gate needs a tape measurement. |
| USB line bridge (laptop side) | **IMPLEMENTED AND TESTED** | DEMO only, with a fake generator and a stub server (27 PASS) and one real DEMO run. Real serial port, firmware and radio timing are untested; the line format and baud rate wait for the operator's firmware. |
| Real radio/phone/hardware path | **BLOCKED (waiting on hardware/firmware/site data)** | Firmware, physical hardware and field observations pending. |
| Surveyed trail/site configuration | **BLOCKED (waiting on hardware/firmware/site data)** | Needed coordinates and verified boundaries are incomplete. |
