# Trailwatch Dashboard

Offline PHP 8 and MariaDB dashboard with a browser JavaScript/SVG map. DEMO / SIMULATION and LIVE use separate MariaDB databases. The app does not implement LoRa transmission or USB serial framing; firmware-owned packet sizes and serial details remain unspecified.

## Windows setup and run

Use PHP and MariaDB already included with XAMPP. Keep the server bound to loopback; the operator/settings guard defaults ON and the PHP development server must never be exposed to a LAN or public interface.

1. Copy `config.example.php` to `config.php`. The DEMO profile should point to the disposable `hiker_dashboard_test_codex` database. LIVE points to `hiker_dashboard_live`. Set independent private bridge tokens in `bridge_tokens.demo` and `bridge_tokens.live`; bridge callers send the active profile token in `X-Bridge-Token`. Keep credentials/tokens local and out of git/output.
2. In PowerShell, use the bundled XAMPP PHP executable and initialize the profiles you intend to use. The setup tool imports `sql/schema.sql`; DEMO also imports `sql/demo_seed.sql`. LIVE imports schema only:

   ```powershell
   $php = 'C:\xampp\php\php.exe'
   & $php tools/setup_db.php --profile=demo
   & $php tools/setup_db.php --profile=live
   ```

   The schema file has no fixed database name. The setup tool requires the name to match the chosen config profile. Run `& $php tools/migrate_db.php --profile=demo` to apply or verify the nine-migration ledger (001–009); it is safe to rerun. Migration 008 adds ring boundaries to safe zones; migration 009 adds the BENCH source class. LIVE schema migration requires the explicit `--allow-live-schema` switch and is never part of tests.
3. Start the local app from the project folder:

   ```powershell
   $php = 'C:\xampp\php\php.exe'
   & $php -S 127.0.0.1:8000 -t public public/router.php
   ```

   Open `http://127.0.0.1:8000`. `data/mode.json` selects `DEMO` or `LIVE`; absent or invalid content falls back to DEMO. The Settings page switches modes and shows the active DB and flagged-row count. Site and phase settings live inside each selected database.

### Dashboard batch shortcuts

From the project folder, double-click `Launch Dashboard.bat` to start the local dashboard, `Restart Dashboard.bat` to stop and start it again, or `Stop Dashboard.bat` to stop it. These use the project-local server controls; keep the server bound to `127.0.0.1`.

## Backups and data safety

`& $php tools/backup_db.php --profile=live` uses the XAMPP `mysqldump.exe` at `C:\xampp\mysql\bin\mysqldump.exe` and writes a unique SQL dump under `backups/<timestamp>/`; it never overwrites an existing backup. **Backup live data** before field use or maintenance. Clearing demo rows in Settings requires typing `CLEAR` and creates a DEMO backup first; it is disabled in LIVE. Do not use LIVE for tests.

LIVE rejects incoming records marked demo, rehearsal, or `test_mode`; rejection details go to the local ignored `data/rejections.log`. The simulator is DEMO-only. LIVE starts without seeded hikers or a safe zone. Until a polygon is configured, positions stay UNCLASSIFIED and do not raise OFF TRAIL events. In LIVE Settings, turn on **Bench session** before Phase 1 bench trials so new packets, events, and commands are marked BENCH and excluded from research summaries. Turn it off before collecting scored field data. Settings also supports marking a selected site's existing hiker/time range as BENCH after typing `MARK BENCH`; DEMO, REHEARSAL, TEST, and test-mode JSON rows are protected.

## Tests

Tests that use MariaDB require the active DEMO profile to point to `hiker_dashboard_test_codex`, or use the explicit project-local scratch override used by the scratch harnesses. Tests fail closed in ordinary LIVE mode. Scratch tests create and drop only randomly named `hiker_dashboard_scratch_*` databases. The LIVE first-run integration harness uses an isolated LIVE-mode PHP server whose `live` profile is a scratch database; it never opens `hiker_dashboard_live`.

To run the complete PHP, Node, and PowerShell suite without touching the configured demo or LIVE databases, run `& $php tests/run_scratch_suite.php`. It creates and drops disposable scratch databases; the LIVE-mode integration portion requires the project to remain in LIVE mode so it can verify the simulator guard, and still redirects every database connection to a scratch database. `tests/test-preflight.js` is a safety helper loaded by other tests; running it alone while LIVE mode is selected is expected to refuse.

The read-only LIVE readiness checklist is available in Settings while LIVE is active and from `& $php tools/live_check.php`. It reports PASS/FAIL for database reachability and table checks, migration checksums, tokens (without displaying them), site and field registry readiness, flagged rows, local-only guard, and latest LIVE backup age. An empty LIVE database is expected to fail the field-setup checks until the operator configures the site.

For existing databases, take a backup before applying pending migrations. Migration 007 adds editable site/node/reference registry fields; migration 008 adds safe-zone ring boundaries. No LIVE database was accessed or migrated for this ring change. A historical readiness note said migration 007 was pending then, but verify the current ledger before acting. After a fresh backup and review, the operator command sequence is `& $php tools/backup_db.php --profile=live`, then `& $php tools/migrate_db.php --profile=live --allow-live-schema`, then `& $php tools/live_check.php`. This changes the selected LIVE schema; never use LIVE for tests.

From Settings, register hiker node IDs and phone models, save Base Camp and Checkpoint node records, and add/edit/archive reference markers. Site center and network node coordinates are kept in the selected database. Config coordinates are fallback display references and remain marked `UNSURVEYED`; enter only surveyed/operator-supplied coordinates and mark them verified after confirmation. Archiving disables a row without deleting it. The safe-zone GPX editor stores ordered rings. A closed loop creates an outer and inner boundary so the corridor band is safe and the middle is outside; an open track creates one outline. The configured half-width is the margin on EACH side of the track (5–50 m). For expected GPS error up to 10 m, consider about 15–20 m on the mountain. See `docs/LOOP_CORRIDORS.md`. Existing databases need migrations 008 and 009 for rings and BENCH classification; back up before migration.

```powershell
$php = 'C:\xampp\php\php.exe'
& $php tests/geofence_test.php
& $php tests/polygon_validation_test.php
& $php tests/research_summary_test.php
& $php tests/mode_safety_test.php
& $php tests/live_check_test.php
& $php tests/registry_service_test.php
& $php tests/clear_demo_scratch_test.php
& $php tests/live_schema_scratch_test.php
& $php tests/migration_scratch_test.php
& $php tests/api_smoke.php http://127.0.0.1:8000
node tests/gpx_corridor_test.js
node tests/dashboard_packet_log_contract_test.js
node tests/live_empty_dashboard_test.js
node tests/offline_static_test.js
.\tests\site_safe_zone_smoke.ps1 -BaseUrl http://127.0.0.1:8000/api -Database hiker_dashboard_test_codex
```

When the project is already in LIVE mode, `& $php tests/live_first_run_scratch_test.php` creates, exercises, and drops an isolated scratch database through a temporary loopback PHP server. The test refuses to switch the project's mode and never connects to `hiker_dashboard_live`.

The endpoint smoke test writes clearly marked DEMO/TEST rows in the disposable DB and restores its previous active site, phase, and PUP polygon. Do not pass a LIVE bridge token or change the active mode while testing.

## Simulator and CSV export

The PHP simulator posts only the existing logical uplink and reception JSON contract to the local bridge ingest endpoint. It defines no byte sizes, framing, or hardware transport. Example:

```powershell
$php = 'C:\xampp\php\php.exe'
& $php tools/simulate_hikers.php --scenario=normal_walk --base-url=http://127.0.0.1:8000/api
& $php tools/simulate_hikers.php --scenario=relay_via_checkpoint --base-url=http://127.0.0.1:8000/api
& $php tools/simulate_hikers.php --gpx=.\trail.gpx --speed=2 --base-url=http://127.0.0.1:8000/api
```

Scenarios are in `tools/scenarios/`: normal walk, drift off trail, hiker silent, duplicate reception, out-of-order sequence, SOS, turn-back received, and checkpoint relay. Every packet is marked DEMO. The dashboard exports packet and event CSV through `api/export.csv?type=packets` and `api/export.csv?type=events`. CSV includes a source label; GPS valid-fix is derived for display/export only using accuracy `<= 10 m` and at least `5` satellites, with null values invalid.

## Sites and safe zones

Only operator-provided coordinates are included: Balagbag peak (`14.8243087, 121.1804131`, 770 m), the user-provided Balagbag place pin (`14.8239931, 121.1795801`), and the PUP Oval pin (`14.598126, 121.0120373`). The pins are explicitly UNSURVEYED/UNVERIFIED. PUP's boundary remains DEMO. Mt. Mapalad and Base Camp/Checkpoint coordinates remain unset.

Record a GPX track with a phone GPS app while walking the intended corridor, export the `.gpx` file, select the intended site in Settings or `/safe-zone`, choose the file locally, preview its corridor, and explicitly save it after checking the surveyed path. No file is uploaded to a map service. Tracks over 250 points are simplified locally with Douglas–Peucker before preview; set the meter tolerance and check the resulting point count and measured maximum deviation in the preview message. If simplification still leaves more than 250 points, increase the tolerance and preview again. Corridor half-width remains 5–50 m and means the margin on EACH side of the track. Points exactly on a safe-zone boundary count as INSIDE. A closed GPX loop whose end is within 0.5 m of its start is recognized and becomes an outer ring plus an inner ring; the corridor band is safe and the loop's middle is outside. An open track becomes one polygon outline. See `docs/LOOP_CORRIDORS.md`. This is generated geometry, not survey validation.

## Local API and integration boundary

Bridge routes require `X-Bridge-Token` for the active database profile. Operator and settings routes require loopback when `local_only` is true (default); no accounts, sessions, or CSRF protection are implemented. A valid uplink envelope separates `uplink` fields from `reception` metadata. Base ID is written by the receiver into reception logging, never inserted into the uplink. No LoRa packet size, binary format, or USB serial framing is defined here.

### API endpoints

The routes below return JSON except the CSV export routes. Bridge routes require `X-Bridge-Token` matching the active profile token. The bridge sends the logical JSON contract only; USB serial framing and LoRa binary encoding remain unspecified.

- `GET /api/status`
- `GET /api/hikers`, `GET /api/hikers/{node_id}/latest`, `GET /api/positions/latest`
- `GET /api/events`, `GET /api/logs/packets` (time range, hiker, type, severity, path, classification, validation, delivery, and demo filters; limit 1–200 and offset)
- `GET /api/safe-zone`, `PUT /api/safe-zone`
- `POST /api/commands/storm-turn-back`
- `POST /api/events/{id}/resolve`
- `POST /api/bridge/ingest`, `POST /api/bridge/status`
- `POST /api/bridge/validate` (bridge token plus loopback; dry-run validation; no committed rows)
- `GET /api/commands/pending`, `POST /api/commands/{id}/status`
- `GET /api/research/summary`
- `POST /api/hikers`, `PUT /api/hikers/{node_id}` (registration, edits, deactivation)
- `GET /api/network-nodes`, `POST /api/network-nodes`, `PUT /api/network-nodes/{node_code}`
- `POST /api/reference-points`, `PUT /api/reference-points/{id}`
- `PUT /api/sites/location` (current selected site)
- `GET /api/live-check` (LIVE-only, loopback, read-only readiness)
- `GET /api/export.csv?type=packets`, `GET /api/export.csv?type=events`

### Bridge fields, reception paths, and timing

The earlier README describes the logical uplink as `node_id`, `sequence_number`, coordinates, and optional GPS, battery, flags, and reception metadata. A direct reception and a Checkpoint relay reception attach to one logical packet; duplicate `(node_id, sequence_number)` packets are marked while reception records are retained. Valid classified positions queue a targeted acknowledgement or off-trail alert record; the dashboard does not transmit it. These are application records, not a wire format.

The earlier README does not specify numeric receive windows or command timing thresholds. No 2-second or 3-second pass/fail timing is claimed here; the API exposes stored command timestamps and elapsed durations where available, without judging them.
