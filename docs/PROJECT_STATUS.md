# Trailwatch Dashboard Project Status

**Review date:** 2026-11-03  
**Project:** `D:\Projects for PC\Dashboard`  
**Scope:** Dashboard, PHP backend, SQL, local launch/test tooling, and requirements in the supplied proposal. This report is based on repository files opened and commands run for this review. It does not use README claims as evidence. The proposal DOCX has no stable line numbering, so proposal citations use its chapter, table, sheet, and named-section labels; code citations use file paths and line numbers.

## Status legend

- **IMPLEMENTED AND TESTED** — implementation is present and a test exercising the stated behavior passed in this review.
- **IMPLEMENTED BUT UNTESTED** — implementation is present, but no test for the stated behavior was run/found.
- **PARTIAL** — some of the requested behavior or data is present, but a material part is missing or limited.
- **NOT IMPLEMENTED** — no implementation was found in the inspected project.
- **BLOCKED (waiting on hardware/firmware/site data)** — completion or meaningful verification depends on hardware, firmware, surveyed site data, permissions, or field observations not present here.

## 1. Overview

**IMPLEMENTED AND TESTED — system and stack.** Trailwatch is a local PHP web dashboard with a MariaDB database, JSON API, plain SVG map, and browser JavaScript/CSS. PHP database access uses PDO with exceptions, associative results, and emulated prepares disabled (`src/db.php:11-20`). The project has PHP API/service code, SQL schema and migrations, browser-only JavaScript, PowerShell server control, and no package manifest for a frontend framework. The XAMPP CLI used during this review reports PHP 8.0.30 and the MariaDB client reports MariaDB 10.4.32. The proposal's Materials Table 7 instead lists XAMPP 8.2.12 / PHP 8.2.12 / MariaDB 10.4.32; PHP version therefore differs from the planned version.

**IMPLEMENTED AND TESTED — how to run the dashboard.** `Launch Dashboard.bat` delegates to `server-control.ps1` (`Launch Dashboard.bat:1-4`). The script expects `C:\xampp\php\php.exe`, binds the PHP development server to `127.0.0.1`, picks the first free port from 8000 through 8010, serves `public` through `public/router.php`, and opens the browser (`server-control.ps1:1-12,38-57`; the server launch is below line 57). MariaDB must also be running. The project requires a root `config.php`; `src/db.php:2-7` says to copy `config.example.php` when it is missing. The example file gives a database name of `hiker_dashboard` (`config.example.php:1-14`).

**PARTIAL — new database setup.** The current schema file creates and selects a database named `hiker_dashboard` itself (`sql/schema.sql:1-3`). That can disagree with `config.php` if it names another database. The configured database observed in this review is `hiker_dashboard_test_codex`; the API independently identified it as a test database. Do not assume that importing `sql/schema.sql` while targeting a different database will initialize the configured database: its `USE hiker_dashboard` statement takes precedence. No migration-history table was found, so the execution history is not recorded in the database.

### Important folders and files

| File or folder | Purpose and evidence |
|---|---|
| `config.php` | Local ignored settings used by `src/db.php`; contains database and bridge/legacy token configuration. Values are intentionally not reproduced here (`src/db.php:2-18`; `.gitignore:1-4`). |
| `config.example.php` | Template for local MariaDB and API token settings (`config.example.php:1-14`). |
| `config/sites.php` | Site labels, coordinates, node positions, references, and rehearsal markers (`config/sites.php:1-36`). |
| `public/index.php` | Main dashboard shell, cards, map SVG, packet-log table, and active-phase badge (`public/index.php:1-80`). |
| `public/settings.php` | Site and study-phase selector (`public/settings.php:1`). |
| `public/safe-zone.php` | Safe-zone and GPX editor UI (`public/safe-zone.php:1-3`). |
| `public/router.php` | Routes `/api/*`, editor pages, and static files (`public/router.php:1-5`). |
| `public/api/index.php` | API routes, bridge authentication, input dispatch, and HTTP responses (`public/api/index.php:1-63`). |
| `public/ingest.php` | Earlier form-encoded legacy ingest endpoint, separate from the current bridge JSON API (`public/ingest.php:1-30`). |
| `public/assets/js/dashboard.js` | Live dashboard polling/rendering, SVG map, packet log, phase badge, event and storm controls (`public/assets/js/dashboard.js:1-70`). |
| `public/assets/js/site-settings.js` | Site and phase-mode setting UI (`public/assets/js/site-settings.js:1-36`). |
| `public/assets/js/safe-zone.js` | Polygon editing, map projection, validation, GPX import, and save behavior (`public/assets/js/safe-zone.js:1-25`). |
| `public/assets/js/safe-zone-geometry.js` | Local track-to-corridor geometry helper (`public/assets/js/safe-zone-geometry.js:1-4`). |
| `public/assets/css/dashboard.css` | Locally stored dashboard and editor styles; system font fallbacks are declared in the stylesheet (`public/assets/css/dashboard.css:1-18`). |
| `src/db.php` | PDO configuration and connection (`src/db.php:1-22`). |
| `src/geofence.php` | Ray-casting point-in-polygon calculation (`src/geofence.php:1-16`). |
| `src/Services/ApiService.php` | Validation, site/phase settings, geofence orchestration, routing, commands, and research summary (`src/Services/ApiService.php:5-135`). |
| `src/report.php` | Backward-compatible legacy report processor (`src/report.php:1-14`). |
| `sql/schema.sql` | Full current schema and explicit synthetic seed data (`sql/schema.sql:1-235`). |
| `sql/migrations/001_milestone2.sql`–`005_downlink_routing_timing_storm_campaigns.sql` | Upgrade scripts; their purposes are summarized in Section 4. |
| `tests/` | Four PHP tests, two JavaScript tests, and one PowerShell integration test; see Section 8. |
| `Launch Dashboard.bat`, `Restart Dashboard.bat`, `Stop Dashboard.bat` | Separate start/restart/stop entry points (`Launch Dashboard.bat:1-4`; `Restart Dashboard.bat:1-4`; `Stop Dashboard.bat:1-4`). |
| `server-control.ps1` | Managed local PHP-server process, PID/port, and log handling (`server-control.ps1:1-72`). |
| `tools/mock_hike.php` | Fake positions for the legacy report path, with no radio connection (`tools/mock_hike.php:1-24`). |

## 2. Architecture

**IMPLEMENTED AND TESTED — application-level request path.** The browser polls the local API every five seconds for hikers, recent events, component status, safe zone, phase mode, and the last 20 packet-log rows (`public/assets/js/dashboard.js:39-50,69`). The bridge-facing endpoint accepts an authenticated JSON envelope containing `uplink` and `reception`; it validates logical fields, looks up the hiker/site polygon, calls the existing ray-casting function, records one logical uplink plus reception rows, creates events, chooses one reply, and stores a downlink command (`src/Services/ApiService.php:79-123`; point-in-polygon is `src/geofence.php:1-16`). The API's bridge ingest route is `public/api/index.php:58`.

**IMPLEMENTED AND TESTED — logical packet fields, deduplication, routing, and reply priority.** The bridge contract allows uplink fields `node_id`, `sequence_number`, `latitude`, `longitude`, `gps_accuracy_m`, `satellite_count`, `battery_level`, and five flags; `base_id` is rejected inside the uplink and belongs to reception metadata (`src/Services/ApiService.php:79-99`; `tests/api_smoke.php:60`). A unique database key scopes one logical uplink to site + hiker + sequence (`sql/schema.sql:68-93`). Each reception remains separately logged and the second/later reception is marked `is_duplicate`; duplicate receptions do not queue another response (`src/Services/ApiService.php:102-108,121-123`; `tests/api_smoke.php:38-43`). Routing is `DIRECT` if any direct reception exists for the logical packet, otherwise `CHECKPOINT_RELAY` via the latest Checkpoint ID (`src/Services/ApiService.php:15-19`). The one documented priority constant is **SOS/emergency reply > off-trail alert > storm turn-back > ACK** (`src/Services/ApiService.php:65-72,117-120`). Priority and one-command behavior are tested (`tests/api_smoke.php:23,40,49,54-58`).

**IMPLEMENTED AND TESTED — link-loss and last-heard display.** API status and hiker responses calculate `LINK_LOST` when the latest packet is at least 15 seconds old; the latest packet position/time remain available, and the dashboard renders position age and last-heard node (`public/api/index.php:15-23`; `public/assets/js/dashboard.js:9-11,32,41-49`). The API smoke test sends a 16-second-old received timestamp and checks that the position, time, Base Camp, and `LINK_LOST` state remain (`tests/api_smoke.php:50`). This is server-side status derived on reads; the reviewed code does not run a background timer or create a dedicated “link lost” event.

**IMPLEMENTED AND TESTED — SOS and alerts.** A new emergency flag transition creates a CRITICAL emergency event; an outside position independently creates an OFF_TRAIL warning. The selected SOS response outranks the off-trail alert, while the off-trail event is retained (`src/Services/ApiService.php:109-120`). The API smoke test verifies an SOS packet outside the zone still produces the OFF_TRAIL warning and one SOS reply (`tests/api_smoke.php:48-49`). The “alarm” here is a persisted event rendered in the dashboard alert panel, not a demonstrated audible base-camp alarm (`public/assets/js/dashboard.js:12,40-46`; `public/index.php:55-60`).

**IMPLEMENTED AND TESTED — storm campaigns.** The operator arms/cancels campaigns; a storm reply is queued only in an eligible later uplink reply slot, repeats on subsequent eligible uplinks, and closes when a received flag arrives or the operator cancels. Phase 2 prevents campaign creation, cancels queued campaign replies, and marks active campaigns disabled (`public/api/index.php:12-13,39-55`; `src/Services/ApiService.php:111,117-120`). Tests cover repeating, relay routing, priority, received-flag stop, operator cancel, and Phase 2 (`tests/api_smoke.php:52-58`).

**BLOCKED (waiting on hardware/firmware/site data) — physical data flow.** No ESP32 firmware, Android app, serial bridge process, USB packet reader/writer, or LoRa radio driver exists in the project file inventory. The API is the future bridge interface; a “SERIAL_BRIDGE” status record is just a status endpoint, not a serial implementation (`public/api/index.php:57-60`; `public/ingest.php:1-3`; `rg --files` inventory contained no firmware/app/bridge project). The proposal says packet sizes are planned and will be fixed with firmware; no binary packet size or USB framing is implemented here (Proposal, Table 8; migration 004 comments at `sql/migrations/004_bridge_fields_research_rehearsal.sql:1-2`; migration 005 comments at `sql/migrations/005_downlink_routing_timing_storm_campaigns.sql:1-2`).

## 3. API

All API routes are dispatched in `public/api/index.php:8-63`. `X-Bridge-Token` is required only on the bridge ingest, bridge status, pending-command fetch, and command-status report routes (`public/api/index.php:7,57-60`). The endpoint-level smoke test covers most operator and bridge routes; the exceptions are noted below.

| Method and path | Auth | Inputs and output | Status / test evidence |
|---|---|---|---|
| `GET /api/sites` | None | No input; configured site objects and `selected_site` (`public/api/index.php:10`). | **IMPLEMENTED AND TESTED** — API smoke checks site list (`tests/api_smoke.php:31`). |
| `POST /api/settings/site` | None | JSON `site_id`; returns selected ID and site (`public/api/index.php:11`). | **IMPLEMENTED AND TESTED** — API and per-site smoke tests (`tests/api_smoke.php:33,62-64`; `tests/site_safe_zone_smoke.ps1:16-48`). |
| `GET /api/settings/phase-mode` | None | No input; returns `phase_mode` and `storm_turn_back_enabled` (`public/api/index.php:12`). | **IMPLEMENTED AND TESTED** — phase smoke tests (`tests/api_smoke.php:26,58`). |
| `POST /api/settings/phase-mode` | None | JSON phase `PHASE1`, `PHASE2`, or `PHASE3`; returns mode/enabled flag; Phase 2 disables campaigns (`public/api/index.php:13`). | **IMPLEMENTED AND TESTED** — Phase 2 rejection/cancellation and setting restoration (`tests/api_smoke.php:58`). |
| `GET /api/status` | None | No input; DB/test-DB marker, component rows, last packet, hiker link states and 15-second timeout (`public/api/index.php:14-19`). | **IMPLEMENTED AND TESTED** — API smoke checks status; test database safety flag also checked (`tests/api_smoke.php:24`). |
| `GET /api/hikers` | None | No input; hiker cards’ latest packet, battery, flags, reception, age, and state (`public/api/index.php:20-22`). | **IMPLEMENTED AND TESTED** — hiker/last-heard/battery assertions (`tests/api_smoke.php:44`). |
| `GET /api/hikers/{node_id}/latest` | None | Path node ID; latest packet + reception rows + age/link state, or 404 (`public/api/index.php:23`). | **IMPLEMENTED AND TESTED** — latest packet and receptions checked (`tests/api_smoke.php:41,50`). |
| `GET /api/positions/latest` | None | No input; latest positions for configured hikers (`public/api/index.php:24`). | **IMPLEMENTED AND TESTED** — API smoke checks HTTP 200 (`tests/api_smoke.php:28`). |
| `GET /api/safe-zone` | None | No input; active site polygon, site config, network nodes with configured coordinates, and reference points (`public/api/index.php:25`). | **IMPLEMENTED AND TESTED** — safe-zone/site smoke tests (`tests/api_smoke.php:30`; `tests/site_safe_zone_smoke.ps1:16-48`). |
| `PUT /api/safe-zone` | None | JSON site, name, ordered coordinate vertices, optional creator/demo marker; creates new active polygon version or validation error (`public/api/index.php:26`; validation `src/Services/ApiService.php:43-53`). | **IMPLEMENTED AND TESTED** — save and site isolation smoke coverage (`tests/api_smoke.php:36`; `tests/site_safe_zone_smoke.ps1:23-47`). |
| `GET /api/events` | None | `limit`, `offset`, date range, node, test mode, event type/severity, classification, validation, path/heard-by/duplicate/delivery filters; returns event rows (`public/api/index.php:27-36`). | **IMPLEMENTED AND TESTED** — basic events and filtered OFF_TRAIL query tested; not every filter combination (`tests/api_smoke.php:29,49`). |
| `GET /api/logs/packets` | None | Same filters where applicable; returns one row per reception with heard-by, path, RSSI/SNR, relay delay, duplicate and command/timing fields (`public/api/index.php:27-36`). | **IMPLEMENTED AND TESTED** — packet log field assertions (`tests/api_smoke.php:59`). |
| `POST /api/events/{id}/resolve` | None | JSON optional `resolved_by` (1–100 chars); resolves event idempotently or 404 (`public/api/index.php:38`). | **IMPLEMENTED BUT UNTESTED** — no test call found in `tests/api_smoke.php`. |
| `GET /api/commands/storm-turn-back/active` | None | No input; active campaigns, current phase, enabled flag (`public/api/index.php:39`). | **IMPLEMENTED AND TESTED** — test checks campaign closes after received flag (`tests/api_smoke.php:56`). |
| `POST /api/commands/storm-turn-back` | None | JSON `base_id` plus either `node_id` or `all:true`; arms campaigns for enabled hikers with a received uplink; returns campaigns (`public/api/index.php:40-48`). | **IMPLEMENTED AND TESTED** — arm, Phase 2 rejection, priority/repeat behavior (`tests/api_smoke.php:52-58`). |
| `POST /api/commands/storm-turn-back/cancel` | None | JSON `node_id` or `all:true`; returns cancelled count/campaigns (`public/api/index.php:50-55`). | **IMPLEMENTED AND TESTED** — operator cancellation checked (`tests/api_smoke.php:57`). |
| `GET /api/commands/pending` | `X-Bridge-Token` | No input; up to 100 queued commands, marks first fetch timestamp, returns route and elapsed timing (`public/api/index.php:57`). | **IMPLEMENTED AND TESTED** — direct/relay route and fetch timing checks (`tests/api_smoke.php:45-46,51,55`). |
| `POST /api/bridge/ingest` | `X-Bridge-Token` | JSON `uplink`, `reception`, optional root `is_demo`; returns packet/reception IDs, duplicate flag, classification, chosen route and queued indicator; 201 (`public/api/index.php:58`; validation `src/Services/ApiService.php:79-123`). | **IMPLEMENTED AND TESTED** — normal, duplicate, relay, SOS, storm and invalid envelope cases (`tests/api_smoke.php:38-60`). |
| `POST /api/bridge/status` | `X-Bridge-Token` | JSON `component` (`BASE_CAMP`, `SERIAL_BRIDGE`, `CHECKPOINT`), `state`, optional details; returns `{ok:true}` (`public/api/index.php:59`). | **IMPLEMENTED BUT UNTESTED** — no call found in API smoke test. |
| `POST /api/commands/{id}/status` | `X-Bridge-Token` | JSON status `SENT_TO_BASE`, `DELIVERED`, `RECEIVED`, `FAILED`, or `TIMED_OUT`, optional reason; stamps applicable timestamps and returns elapsed time (`public/api/index.php:60`). | **IMPLEMENTED AND TESTED** — SENT_TO_BASE path tested; other statuses not individually tested (`tests/api_smoke.php:47`). |
| `GET /api/research/summary` | None | No input; eligible/excluded packet, event, alert, and command counts (`public/api/index.php:61`; calculations `src/Services/ApiService.php:125-135`). | **IMPLEMENTED AND TESTED** — fixture test proves exclusions (`tests/research_summary_test.php:1-25`; API smoke also checks rehearsal exclusion `tests/api_smoke.php:61-64`). |
| `POST /ingest.php` (legacy, not under `/api`) | Form `token` compared to `ingest_token`; no bridge header | Form fields `hiker_id`, `lat`, `lng`, optional `heard_by`; returns legacy position ID, inside flag, alert flag (`public/ingest.php:1-30`). | **IMPLEMENTED BUT UNTESTED** — no HTTP test for this legacy endpoint was run/found. |

For API error behavior, JSON parse/validation exceptions return 400, other exceptions return a generic 500, and unmatched routes return 404 (`public/api/index.php:62-63`).

## 4. Database

**IMPLEMENTED AND TESTED — table structure.** The inspected `sql/schema.sql` defines the tables below. The configured disposable database was queried read-only after tests; it contains the current route/timing/storm columns and `storm_turnback_campaigns`. Foreign-key behavior is specified in the schema. No migration ledger table exists in that database, so the exact historical application of each migration cannot be proved.

| Table | Key data and relationships | Evidence |
|---|---|---|
| `hiker_nodes` | Unique `node_id`, display/device metadata, enabled flag; parent of uplinks, commands, campaigns, events, actions. | `sql/schema.sql:5-17,92,139,156,173,187` |
| `network_nodes` | Base/checkpoint code/type, enabled/state/optional lat-lon. | `sql/schema.sql:18-27` |
| `trail_reference_points` | Site-scoped labeled typed coordinates and enable/demo flags. | `sql/schema.sql:28-40` |
| `safe_zones` | Site/version/name, demo/rehearsal, active flag and creator; parent of polygon vertices and referenced by packets. | `sql/schema.sql:41-52,93` |
| `safe_zone_vertices` | Ordered lat/lon vertices with cascade delete to zone. | `sql/schema.sql:59-67` |
| `app_settings` | Key/value state including selected site and phase mode. | `sql/schema.sql:53-58` |
| `uplink_packets` | One logical hiker/site/sequence record, coordinates, accuracy, satellite count, battery, flags, classification, safe-zone reference, timestamp, duplicate/test/demo/rehearsal fields; unique `(site_id,hiker_node_id,sequence_number)`. | `sql/schema.sql:68-94` |
| `packet_receptions` | One row per hearing path: heard-by/base/checkpoint IDs, checkpoint RSSI, path, received RSSI/SNR, hop delay, receive time and duplicate/demo/test flags; FK to logical uplink. | `sql/schema.sql:95-116` |
| `downlink_commands` | Addressed hiker/site/base/sequence, route and relay node, command type/status, campaign reference, flags, created/fetched/sent/delivered/received times and failure reason. | `sql/schema.sql:117-140` |
| `storm_turnback_campaigns` | Per-hiker/site campaign status, base, demo/test flags and lifecycle timestamps; FK to hiker. | `sql/schema.sql:141-157` |
| `system_events` | Event severity/type/message/metadata, hiker/site and demo/test flags, resolution data; hiker FK set null on deletion. | `sql/schema.sql:158-174` |
| `operator_actions` | Audit-like action, target hiker, details, site and flags; hiker FK set null. | `sql/schema.sql:175-188` |
| `system_status` | Component status, heartbeat time, details. | `sql/schema.sql:189-196` |
| `nodes`, `positions`, `alerts` | Backward-compatible legacy tables; no declared foreign keys in the shown schema. | `sql/schema.sql:211-235` |

### Migrations 001–005

| Migration | Purpose based on SQL | Application state evidence |
|---|---|---|
| `001_milestone2.sql` | Creates the original Milestone 2 tables, seed records, and legacy tables; it also explicitly creates/uses `hiker_dashboard` (`001_milestone2.sql:1-3`, table definitions through line 235). | **PARTIAL** — current configured test DB has expected tables, but no migration history exists; hardcoded database selection creates a setup hazard. |
| `002_local_map_references.sql` | Adds optional node coordinates and display-only trail reference points (`002_local_map_references.sql:1-14`). | **PARTIAL** — current DB has those structures; exact execution record absent. |
| `003_site_aware_safe_zones.sql` | Adds site IDs, site/version and site/code uniqueness, settings, and removes only exact old synthetic references (`003_site_aware_safe_zones.sql:1-32`). | **PARTIAL** — current DB has site-scoped zones/references; exact execution record absent. |
| `004_bridge_fields_research_rehearsal.sql` | Adds/updates base/checkpoint reception metadata, site and demo/rehearsal flags, sequence uniqueness by site, and record backfills; expressly says it defines no radio packet sizes or USB framing (`004_bridge_fields_research_rehearsal.sql:1-54`). | **PARTIAL** — current DB has these columns/keys; exact execution record absent. |
| `005_downlink_routing_timing_storm_campaigns.sql` | Adds per-reception duplicate marker, route/campaign/fetch columns, millisecond command timestamps, backfills routes/duplicate flags, creates storm campaign table, seeds phase mode (`005_downlink_routing_timing_storm_campaigns.sql:1-69`). | **PARTIAL** — test DB has `is_duplicate`, route/timing fields, campaign table, and `phase_mode=PHASE1`; no ledger to prove how/when migration ran. |

## 5. Frontend

**IMPLEMENTED AND TESTED — live dashboard.** The main page presents status, hiker cards, alerts, map, storm controls, a packet-reception table, and active-phase badge (`public/index.php:43-78`). It refreshes the API every five seconds and renders the packet log/phase (`public/assets/js/dashboard.js:39-50,64-69`). Hiker cards display battery, report age/time, and last-heard node (`public/assets/js/dashboard.js:11`).

**IMPLEMENTED AND TESTED — plain local map.** The dashboard uses an inline SVG with a plain gray background and grid (`public/index.php:62-68`). JavaScript converts coordinates into a local equirectangular frame using `x=(lon-lon0)*cos(lat0)*111320` and `y=(lat-lat0)*110540`, preferring configured Base Camp coordinates as origin and otherwise falling back to site center/polygon/first valid point (`public/assets/js/dashboard.js:17-25`). It draws the polygon, marks its color when a hiker is outside, draws hiker/node/reference markers, distance grid labels, north arrow and meter scale (`public/assets/js/dashboard.js:26-36`). Hikers are styled by online/off-trail/link-lost state (`public/assets/js/dashboard.js:31-33`; styles in `public/assets/css/dashboard.css:17-18`). **Limitation:** all configured Base Camp and Checkpoint coordinates are null, so the rendered node markers cannot appear until positions are supplied (`config/sites.php:8,17,27,34`).

**IMPLEMENTED AND TESTED — safe-zone editor.** The editor supports ordered vertex rows, click-to-add and drag-to-move points, coordinate validation, at least three distinct points, self-intersection rejection, versioned save, and visual reference-point list (`public/safe-zone.php:3`; `public/assets/js/safe-zone.js:13-24`; server-side save validation `src/Services/ApiService.php:43-53`). Existing tests cover simple/self-crossing polygon validation and per-site save isolation (`tests/polygon_validation_test.php:1-7`; `tests/site_safe_zone_smoke.ps1:16-48`).

**PARTIAL — GPX corridor generation.** The editor locally parses one GPX track/route and allows a corridor half-width of 5–50 m. Tracks over 250 points are simplified in-browser with Douglas–Peucker using a configurable meter tolerance; preview reports before/after point counts and measured maximum deviation, and still requires explicit save (`public/assets/js/safe-zone.js:23-24`; UI `public/safe-zone.php:2`). The helper uses a local meter projection and normal offsets for open tracks; closed loops use a centroid-based expansion (`public/assets/js/safe-zone-geometry.js:2-4`). Straight, elbow, closed-loop, invalid-input, and 3000-point simplification tests pass (`tests/gpx_corridor_test.js:1-35`). Complex concave/branch geometry, multiple segments, and accuracy against surveyed boundaries remain unverified; multiple segments are rejected by the UI.

**IMPLEMENTED AND TESTED — phase and reception-log UI.** The dashboard phase badge displays the active mode; the Settings page exposes all three modes and explains the storm behavior (`public/assets/js/dashboard.js:48`; `public/settings.php:1`; `public/assets/js/site-settings.js:25-35`). The packet table displays a row per API reception, with time, hiker/sequence, heard-by node, relay hop delay, route and duplicate marker (`public/index.php:78`; `public/assets/js/dashboard.js:13,41,47`). API and dashboard contract tests passed (`tests/api_smoke.php:59`; `tests/dashboard_packet_log_contract_test.js:1-20`).

## 6. Sites and configuration

**IMPLEMENTED BUT UNTESTED — configured locations.** The settings file has four site entries (`config/sites.php:3-36`):

| Site | Coordinates and flags | Status |
|---|---|---|
| PUP Sta. Mesa (`pup`) | Center null; no reference points; Base/Checkpoint coordinates null. | **PARTIAL** — placeholder site; the SQL schema seeds an explicitly demo PUP polygon rather than a surveyed site boundary (`config/sites.php:4-9`; `sql/schema.sql:203-210`). |
| PUP Mountain Prep (Demo) (`pup_mountain_prep`) | Center null; no refs/nodes; `terrain_profile=mountain`, `is_demo=true`, `is_rehearsal=true`. | **IMPLEMENTED AND TESTED** — marked unscored; API smoke verifies ingest data is demo/rehearsal excluded (`config/sites.php:10-18`; `tests/api_smoke.php:62-64`). |
| Mt. Balagbag (`balagbag`) | Center and sole PEAK reference at 14.8243087, 121.1804131; Base/Checkpoint positions null. Config labels center as operator-supplied peak reference. | **PARTIAL** — peak reference exists, but this is not a surveyed trail boundary or node location (`config/sites.php:19-27`; `tests/site_safe_zone_smoke.ps1:19-25`). |
| Mt. Mapalad backup (`mapalad_backup`) | Center null; no refs/nodes. | **BLOCKED (waiting on hardware/firmware/site data)** — intentionally no invented coordinates; route/site positions and boundary are not configured (`config/sites.php:29-35`; `tests/site_safe_zone_smoke.ps1:33-38`). |

Current disposable database settings after the tests were observed as `selected_site=pup` and `phase_mode=PHASE1`; the API returned `test_database=true`. These are runtime observations, not claims about any other database.

## 7. Proposal compliance matrix

Source reviewed: `C:\Users\Kenchi\Downloads\Proposal1-hiker-final-v78-nobasemap (1).docx`. DOCX citations below name its tables/sheets/sections because the document does not have stable source line numbers.

| Proposal requirement | Status | Evidence, test, and gap |
|---|---|---|
| Table 8 uplink fields: node ID, sequence, lat/lon, accuracy, satellite count, battery, flags (link-lost, low-battery, emergency, turn-back-received, test-mode); uplink does not carry `base_id`. | **IMPLEMENTED AND TESTED** | Validation allow-list and checks are `src/Services/ApiService.php:79-99`; `base_id` in uplink is rejected at `tests/api_smoke.php:60`. Logical JSON only; the proposal calls byte sizes planned, not final (Proposal, Table 8). |
| Table 8 downlink fields: addressed `node_id`, sending Base Camp `base_id`, answered sequence, type (ACK/alert/SOS received/storm turn-back). | **PARTIAL** | Pending command output has node, base, sequence, and logical type (`public/api/index.php:57`); command schema has base/sequence/type (`sql/schema.sql:117-139`). No firmware payload serializer or downlink packet implementation exists. Proposal's Table 8 size values are planned and must not be treated as final. |
| Checkpoint copy carries Checkpoint ID and the RSSI at which it heard the hiker; Base Camp writes its own ID to reception log. | **IMPLEMENTED AND TESTED** | Relay validation requires enabled Checkpoint ID and checkpoint RSSI, and writes heard-by/base IDs (`src/Services/ApiService.php:95-106`); test covers separate checkpoint reception and RSSI (`tests/api_smoke.php:38-43`). Physical relay remains untested. |
| One logical copy per hiker/site/sequence; second/direct-vs-checkpoint copy marked duplicate. | **IMPLEMENTED AND TESTED** | Unique index and reception-level marker (`sql/schema.sql:90,95-115`; `src/Services/ApiService.php:102-108`); API test verifies one packet, one queued downlink, and duplicate reception row (`tests/api_smoke.php:38-43`). Concurrent duplicate races have no dedicated test. |
| Ray-cast point-in-polygon at base camp; edge handling and inside/outside transitions. | **IMPLEMENTED AND TESTED** | Ray-casting implementation, including edge-inside behavior, `src/geofence.php:1-16`; tested by `tests/geofence_test.php:1-5`, API outside/SOS and safe-zone smoke. Algorithm was not changed. |
| 15-second base-camp/dashboard link-loss; retain last location, time, hearing node. | **IMPLEMENTED AND TESTED** | Read-time 15s states and last report data in `public/api/index.php:15-23`, display in `public/assets/js/dashboard.js:9-11`; 16s fixture test `tests/api_smoke.php:50`. |
| Hiker-app LINK LOST after three unanswered uplinks, screen/vibration; off during Phase 2. | **BLOCKED (waiting on hardware/firmware/site data)** | No Hiker Node app/firmware exists in the project. Server 15s state is not an implementation of the phone's three-unanswered-uplinks warning. Proposal, Table 1 and Table 14. |
| ACK after every inside uplink. | **PARTIAL** | Ordinary inside unique uplink queues ACK; emergency takes precedence and returns SOS received instead of an extra ACK (`src/Services/ApiService.php:117-120`). The API test confirms inside ACK and the one-reply rule; an inside emergency gets an SOS reply (`tests/api_smoke.php:38,48`). This matches the recent single-reply priority implementation but not a literal ACK-for-every-inside-packet reading of the proposal's Table 1. |
| Off-trail alert raises base-camp event and commands same hiker; alarm/vibration/screen warning on hiker phone. | **PARTIAL** | Backend creates OFF_TRAIL warning and alert command; UI renders event (`src/Services/ApiService.php:109-120`; `public/assets/js/dashboard.js:12,45`). SOS-outside test confirms event (`tests/api_smoke.php:49`). Hiker phone alarm/vibration/screen behavior is not implemented without app/firmware. |
| SOS button / 30-minute inactivity prompt and emergency flag; base-camp alarm plus SOS RECEIVED. | **PARTIAL** | Backend emergency flag transition, CRITICAL event, and SOS reply are present and outside-SOS test passes (`src/Services/ApiService.php:112,117-120`; `tests/api_smoke.php:48-49`). No phone SOS/inactivity app or 30-minute timer exists. Emergency remains processed server-side if supplied in Phase 2 even though proposal says SOS/inactivity are switched off in Phase 2. |
| Battery level in dashboard and low-battery warning. | **PARTIAL** | Battery is stored, validated 0–100, and displayed; low level is highlighted at <=20% (`src/Services/ApiService.php:89-93`; `public/api/index.php:21`; `public/assets/js/dashboard.js:11`; test `tests/api_smoke.php:44`). App-side tone/vibration/LOW BATTERY warning is blocked on the missing app. |
| Last heard by Base Camp or Checkpoint shown live. | **IMPLEMENTED AND TESTED** | Latest reception node is returned/rendered and per-reception log shows each heard-by node (`public/api/index.php:21,35`; `public/assets/js/dashboard.js:11,13,47`); API smoke checks both hiker card data and packet log (`tests/api_smoke.php:44,59`). |
| Route reply via Checkpoint only if hiker was last heard only through it; otherwise direct. | **IMPLEMENTED AND TESTED** | Any direct reception selects `DIRECT`, otherwise selected relay checkpoint (`src/Services/ApiService.php:15-19`); direct and checkpoint-only tests (`tests/api_smoke.php:46,51,55`). Radio routing remains unverified. |
| Direct 2-second / relayed 3-second Hiker Node receive windows; 3-second end-to-end target; 15-second crossing-level miss. | **BLOCKED (waiting on hardware/firmware/site data)** | DB/API records command creation/fetch/sent timestamps and exposes elapsed milliseconds (`src/Services/ApiService.php:21-30`; `public/api/index.php:57,60`). They do not measure Hiker Node receive-window arrival, phone alert activation, 2s/3s receipt, or 15s crossing-level outcome. Those require firmware timer/record-sheet observations (Proposal, Table 14, Sheet 1, Sheet 3, Sheet 6); no pass/fail inference is implemented. |
| Storm command repeats in each reply slot until received/cancelled; Phase 2 disables it. | **IMPLEMENTED AND TESTED** | Campaign implementation and Phase 2 cancellation (`public/api/index.php:39-55`; `src/Services/ApiService.php:111,117-120`); repeat/stop/cancel/Phase 2 tests (`tests/api_smoke.php:52-58`). Phone warning and physical receipt still blocked. |
| Sheet 1: 100-packet uplink/downlink exchange success and laptop processing time / sent-in-window record. | **PARTIAL** | Receptions and commands/timestamps can provide observed received rows and backend elapsed time; tests exercise backend command timing (`tests/api_smoke.php:46-47`). The application has no sent-packet denominator, no hiker timer, and does not record whether a reply was sent within its 2s receive window; Sheet 1 is still the measurement authority (Proposal, Sheet 1). |
| Sheet 4: per-point uplink/downlink PDR; RSSI/SNR; distance; heard base ID; wrong-node counts; Checkpoint-answer count/hop delay; storm arrival/delay/received flag. | **PARTIAL** | Packet/reception log stores RSSI, SNR, heard-by, route, relay delay, and sequence (`sql/schema.sql:68-115`; `public/api/index.php:35`); route/hop values tested (`tests/api_smoke.php:50-59`). No test-point distance, sent denominator, phone-warning time, or wrong-node reception is logged. Per prior task direction, wrong-node deliveries and 2s/15s arrival are not inferred; Sheet 4/firmware record them. Proposal, Sheet 4. |
| Phase 2 disables phone link-lost / SOS/inactivity / storm functions while acknowledgements remain. | **PARTIAL** | Storm campaign is explicitly disabled and tested in Phase 2. No phone app exists; backend does not suppress a supplied emergency flag in Phase 2; ordinary ACKs remain (`src/Services/ApiService.php:112,117-120`; `public/api/index.php:13`). The Hiker Node switch-off requirements cannot be verified. Proposal, Chapter 1 and Table 14. |
| Offline dashboard plot without tiles or Internet. | **IMPLEMENTED AND TESTED** | Runtime code scans below found no non-loopback HTTP(S) URL, CDN, hosted font, map library, or tile reference; `public/tiles` does not exist. SVG map is local (`public/index.php:62-68`). This is static code evidence, not a packet-capture audit of a running browser. |

## 8. Tests

The configured `config.php` named `hiker_dashboard_test_codex`, and the running API returned `test_database=true` before tests. All DB-writing tests below were run only against that disposable test database; the API smoke test itself also checks both the configured name and API test marker (`tests/api_smoke.php:3-25`).

| Test file | Coverage | Command and result |
|---|---|---|
| `tests/geofence_test.php` | Inside/outside, edge/vertex inside, empty polygon. | `C:\xampp\php\php.exe tests\geofence_test.php` — **PASS**, all five cases. |
| `tests/polygon_validation_test.php` | Accepts simple polygon; rejects self-crossing. | `C:\xampp\php\php.exe tests\polygon_validation_test.php` — **PASS**, both cases. |
| `tests/research_summary_test.php` | Demo/rehearsal/test-mode packet, event, alert, and command exclusion; rolls fixtures back in a transaction. | `C:\xampp\php\php.exe tests\research_summary_test.php` — **PASS**. |
| `tests/api_smoke.php` | API availability; safety gate; sites/phase; geofence save; normal/duplicate/relay ingest; one reply; routing; timestamps; SOS outside; link-loss; storm lifecycle and Phase 2; packet logs; rehearsal exclusion; invalid uplink. | `C:\xampp\php\php.exe tests\api_smoke.php http://127.0.0.1:8002 <bridge-token>` — **PASS**, all printed checks passed. It writes demo/test rows and a new active demo polygon version to the disposable database. |
| `tests/gpx_corridor_test.js` | Straight/elbow/closed-loop corridor generation and invalid width/coordinates. | `node tests\gpx_corridor_test.js` — **PASS**, all cases. |
| `tests/dashboard_packet_log_contract_test.js` | API query fields, dashboard API request/render fields, table columns, active phase label. | `node tests\dashboard_packet_log_contract_test.js` — **PASS**, all five checks. |
| `tests/site_safe_zone_smoke.ps1` | Site-specific polygon independence, Balagbag peak config, rehearsal/mapalad empty coordinates; cleanup. | `tests\site_safe_zone_smoke.ps1 -BaseUrl http://127.0.0.1:8002/api` — **PASS**. It creates/deletes a Balagbag test polygon. |

Additional checks run: PHP lint passed for all PHP files; `node --check` passed for all six JavaScript files; PowerShell parser passed for `server-control.ps1` and `tests/site_safe_zone_smoke.ps1`; `git diff --check` passed. Tests do not exercise real serial/LoRa/hiker app, timing arrival windows, concurrent duplicate insert races, real field coordinates, browser network capture, or every API filter/status variation. After tests, `selected_site=pup` and `phase_mode=PHASE1` were verified restored. The disposable DB has the API smoke test's synthetic PUP polygon as active version 24; the original active demo zone was not restored by that smoke script. No production DB was queried or tested.

## 9. Data integrity

**IMPLEMENTED AND TESTED — normal demo/rehearsal/test exclusions.** Ingest sets `is_demo` from the explicit demo input, test-mode flag, or demo site; rehearsal comes from site configuration (`src/Services/ApiService.php:86,93-95`). Event and command rows inherit those flags (`src/Services/ApiService.php:109-120`). Research summary filters packet rows by demo/rehearsal/test columns and JSON test flag, and filters event/command rows by the corresponding columns (`src/Services/ApiService.php:125-135`). Rehearsal ingest and transactional exclusion fixtures passed (`tests/api_smoke.php:62-64`; `tests/research_summary_test.php:1-25`).

**PARTIAL — leak boundaries.** Events and commands rely on denormalized `is_demo`, `is_rehearsal`, and `test_mode` columns; unlike packet counts, their summary clauses do not inspect JSON flags or re-derive eligibility from the source packet. Normal API creation propagates flags, but manual SQL imports or other writers that fail to copy them could make an event/command count eligible. Packet flags JSON is checked for the exact `test_mode` JSON string/number encodings in the summary, while test fields on other records are scalar columns (`src/Services/ApiService.php:126,129-133`). The dashboard event query does not add a demo/rehearsal filter and event rendering does not show those flags (`public/assets/js/dashboard.js:40,45`; `public/api/index.php:27-36`); demo events can therefore appear among recent events. The top banner's data-mode calculation checks `test_mode` only, not `is_demo` or `is_rehearsal` (`public/assets/js/dashboard.js:51-55`), so its label can understate rehearsal/demo data even though hiker cards have a per-row marker (`public/assets/js/dashboard.js:11`).

**PARTIAL — research-valid GPS fixes.** Ingest accepts null accuracy/satellite counts and does not enforce the proposal's valid-fix rule (accuracy <=10 m and at least five satellites); it validates only nonnegative accuracy and a numeric satellite range (`src/Services/ApiService.php:89-93`). Summary packet counts do not filter by accuracy, satellite count, or fix validity (`src/Services/ApiService.php:125-128`). The proposal requires these fields to be recorded and Sheet 3 records valid-fix status (Proposal, Table 8, Sheet 3). The dashboard stores/displays values, but research extraction must use sheet-level eligibility or a future validated rule.

## 10. Security

**PARTIAL — current local exposure controls.** The supplied launcher binds only to `127.0.0.1` and chooses a local port (`server-control.ps1:6-7,53-57`). The bridge API uses a shared token in `X-Bridge-Token` and constant-time `hash_equals` comparison (`public/api/index.php:7`). The legacy endpoint checks a separate configured `ingest_token` (`public/ingest.php:11-14`). `config.php` is git-ignored (`.gitignore:1-4`); the token itself is stored as local configuration, not in a vault.

**PARTIAL — operator/API authorization.** Dashboard pages and all site/settings/safe-zone/event/storm/research read/write routes have no user authentication or role checks; only bridge-specific operations require the shared token (`public/api/index.php:10-55,61`; bridge guard at lines 7,57-60). No CSRF/session protections are present in the inspected form/JSON route implementation. The PHP built-in server is for local development, not a hardened deployment (`server-control.ps1:6-7,38-57`).

If someone changes the bind address to a LAN/public interface or forwards the port, unauthenticated users could read hiker coordinates, battery, events, packet receptions, and research counts; change site/phase settings or safe zones; resolve events; and arm/cancel storm campaigns. Bridge-only routes still require the shared token, but plain HTTP would expose credentials and data in transit. This exposure follows from the open routes above; no code-level bind restriction exists in `public/router.php` or `public/api/index.php`.

## 11. Offline guarantee

**IMPLEMENTED AND TESTED — no remote map, font, CSS, or script dependency found.** A recursive source scan of `public`, `src`, `config`, and `sql` found no non-loopback HTTP(S) URLs, CDN, hosted font, Leaflet/Mapbox, OSM tile URL, `L.tileLayer`, or tile-folder reference. The only HTTP URLs found in tests were `127.0.0.1` defaults. `public/tiles` does not exist. Page styles/scripts are relative local assets (`public/index.php:21-22`; `public/settings.php:1`; `public/safe-zone.php:3`), and CSS uses local/system font fallbacks (`public/assets/css/dashboard.css:1`). No browser packet capture was performed; conclusion is from source search and directory inspection.

## 12. Known issues and technical debt, ranked

1. **BLOCKED (waiting on hardware/firmware/site data) — no end-to-end system.** No app, firmware, serial bridge, or LoRa transport is in this repository, so command queueing is not proof of radio transmission or hiker receipt (`public/api/index.php:57-60`; project file inventory; Proposal Table 14).
2. **PARTIAL — production-readiness/security.** Unauthenticated operator routes expose coordinates and mutate field settings; server launcher is loopback-only but this is not enforced in the API itself (`public/api/index.php:7-13,25-55`; `server-control.ps1:6-7`).
3. **PARTIAL — database setup can target wrong database.** `schema.sql` hardcodes `USE hiker_dashboard`, while local config may point elsewhere (`sql/schema.sql:1-3`; `config.example.php:1-14`). There is no migration ledger.
4. **BLOCKED (waiting on hardware/firmware/site data) — actual deployment coordinates.** PUP boundary is explicitly demo; all Base/Checkpoint positions are null; Balagbag has only a peak point; Mapalad is blank (`sql/schema.sql:203-210`; `config/sites.php:4-35`). Also, `/api/safe-zone` currently takes network-node positions from `config/sites.php`, not the `network_nodes.latitude/longitude` database columns (`public/api/index.php:25`; `sql/schema.sql:18-27`).
5. **PARTIAL — study measurement coverage.** Packet counts do not enforce the valid GPS fix rule; no sent-denominator, actual 2s/3s/15s receipt timer, wrong-node receiver log, or phone-warning activation timestamp exists (`src/Services/ApiService.php:89-93,125-135`; `public/api/index.php:35,57,60`).
6. **PARTIAL — Phase 2 semantics.** Storm is disabled, but server-side emergency processing still occurs if an emergency flag arrives during Phase 2, and phone-specific link-lost/SOS/inactivity switch-offs cannot exist without the app (`src/Services/ApiService.php:112,117-120`).
7. **PARTIAL — demo state clarity.** Some demo/rehearsal events are rendered in the recent-alert list without a marker; top-level mode checks test-mode only (`public/assets/js/dashboard.js:12,40-55`).
8. **PARTIAL — legacy duplicate architecture.** New site-aware API tables coexist with legacy `nodes`, `positions`, `alerts`; legacy processor uses global active zone selection and does not share the modern API event/command behavior (`sql/schema.sql:211-235`; `src/report.php:1-14`).
9. **IMPLEMENTED BUT UNTESTED — API edge cases.** Event resolution and bridge heartbeat endpoint were not exercised; concurrency/race, malformed event JSON, and all query-filter permutations have no tests (`public/api/index.php:38,59`; test inventory in Section 8).

## 13. Not finished / blocked

- **BLOCKED (waiting on hardware/firmware/site data)** — fixed LoRa payload sizes/types and binary encoding; proposal Table 8 calls byte sizes planned and the user has directed that none be invented.
- **BLOCKED (waiting on hardware/firmware/site data)** — USB serial framing, BLE/OTG app-to-board interface, firmware, and actual serial bridge implementation.
- **BLOCKED (waiting on hardware/firmware/site data)** — Hiker Node GPS acquisition, alarm/vibration/screen UI, battery warning, SOS/inactivity timer, 2s/3s reply windows, three-unanswered-uplink phone link warning, and phone-side storm received flag.
- **BLOCKED (waiting on hardware/firmware/site data)** — Base Camp/Checkpoint packet reception/retransmission, radio downlink, relay repeat timing, and physical verification of DIRECT/CHECKPOINT_RELAY routing.
- **BLOCKED (waiting on hardware/firmware/site data)** — surveyed PUP Phase 2 boundary and actual PUP node coordinates; Mt. Balagbag trail corridor, trailhead/Base Camp and second-checkpoint coordinates; Mt. Mapalad route and node coordinates. `config/sites.php` currently holds no such values except the Balagbag peak reference.
- **BLOCKED (waiting on hardware/firmware/site data)** — written site/ethics permissions and field sheets, which are proposal prerequisites (Proposal, Appendix A and Research Timeline/Table 15).
- **PARTIAL** — Sheet 1/Sheet 4 results: backend stores some received-side radio metadata and command timing, but paper-sheet fields such as sent denominator, distance, wrong-node count, Hiker Node timer alert arrival, storm phone-warning delay, and reported field conditions remain manual or hardware-dependent (Proposal, Sheets 1 and 4).

## 14. Suggested next steps in priority order

1. Settle local database setup safely: make schema import target explicit, keep a dedicated test DB config, and add a migration ledger before applying upgrades to any non-test data (`sql/schema.sql:1-3`; no ledger found in current DB).
2. Add authentication/CSRF or keep the interface strictly local and document that boundary; do not expose the PHP development server on a LAN/public interface (`public/api/index.php:7-13,25-61`; `server-control.ps1:6-7`).
3. Obtain and verify surveyed PUP polygon/node positions and official Balagbag/Mapalad route, Base Camp, Checkpoint and reference coordinates before configuring live sites (`config/sites.php:4-35`).
4. Freeze the firmware-owned logical field agreement from Proposal Table 8, then implement the bridge only against the firmware's documented serial interface; do not infer sizes or framing.
5. Add hardware-backed timestamp sources for sent/received/warning activation and record-sheet fields, with no synthetic pass/fail values; preserve the manual sources for wrong-node and crossing-level outcomes.
6. Align Phase 2 behavior with the paper and define how unexpected emergency flags are handled; add tests for the chosen rule. Add app/firmware tests for the phone-only switch-offs.
7. Mark demo/rehearsal events in the dashboard and make the top-level API/demo banner account for `is_demo` and `is_rehearsal`; keep research summary eligibility centralized and test flags across all writers.
8. Add tests for event resolution, bridge heartbeat, validation errors, query filters, concurrency/de-duplication, and browser offline request behavior.
9. Use Sheets 0–7 to collect actual bench, open-ground, and mountain results only after approvals, parts, and site data are ready; keep demo/rehearsal records excluded from research counts.

## One-page summary table

| Area | Status | One-line note |
|---|---|---|
| PHP/MariaDB dashboard foundation | **IMPLEMENTED AND TESTED** | Local PHP 8.0.30 / MariaDB 10.4.32 app; proposal planned PHP 8.2.12. |
| Bridge ingest logical API | **IMPLEMENTED AND TESTED** | Validates proposal's logical fields, stores reception metadata, and requires bridge token. |
| LoRa/USB transport and Hiker Node app | **BLOCKED (waiting on hardware/firmware/site data)** | No packet framing, firmware, Android app, or physical bridge in repository. |
| Point-in-polygon and demo safe zone | **IMPLEMENTED AND TESTED** | Ray casting and edge-inside behavior pass tests; PUP sample boundary is explicitly demo. |
| Deduplication and route choice | **IMPLEMENTED AND TESTED** | One logical sequence row; later receptions marked; reply route recorded direct or checkpoint relay. |
| Alerts, ACK/SOS and storm campaigns | **IMPLEMENTED AND TESTED** | One reply uses SOS > alert > storm > ACK; storm lifecycle and Phase 2 disable tested. |
| Link loss and last-heard display | **IMPLEMENTED AND TESTED** | 15-second state, position/time and receiver shown; phone three-miss warning is not implemented. |
| Command timing | **PARTIAL** | Created/fetched/reported-sent elapsed milliseconds exist; actual receive/alert arrival timers do not. |
| Dashboard map and safe-zone editor | **IMPLEMENTED AND TESTED** | Offline SVG/equirectangular plot, editor and tested basic GPX corridor; site coordinates remain incomplete. |
| Research data exclusion | **IMPLEMENTED AND TESTED** | Demo/rehearsal/test rows excluded in normal API flow; denormalized flag errors can leak event/command counts. |
| Security | **PARTIAL** | Bridge routes token-protected; operator API has no user authentication or CSRF defenses. |
| Offline asset guarantee | **IMPLEMENTED AND TESTED** | Source scan found no external asset/tile references and no `public/tiles` folder. |
| PUP/Balagbag/Mapalad readiness | **BLOCKED (waiting on hardware/firmware/site data)** | PUP is demo-only, Balagbag has a peak point only, and backup site/node coordinates are unset. |
| Test suite | **IMPLEMENTED AND TESTED** | All seven test files passed in this review; API writes were confined to the disposable test DB. |
