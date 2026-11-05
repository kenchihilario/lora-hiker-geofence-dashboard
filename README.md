# Trailwatch Dashboard

Trailwatch is my Computer Engineering thesis project. I’m building it as an offline-capable dashboard for monitoring hiker safety from base camp, with a local map, packet history, alerts, and downlink records. It uses PHP 8, MariaDB, plain JavaScript, and SVG; DEMO / SIMULATION and LIVE use separate databases. I haven’t built the ESP32/LoRa or USB serial bridge, so packet sizes and serial framing are still up to the firmware work.

## Windows setup and run

I use the PHP and MariaDB that come with XAMPP. Keep the server bound to loopback: the operator/settings guard defaults ON, and the PHP development server must never be exposed to a LAN or public interface. After setting `$php` in step 2, set up a local operator login once with `& $php tools/set_operator_password.php`. The username and password hash are stored in the ignored `data/operator.json`; the password itself is not stored. Sign in at the dashboard page. The app uses a PHP session, expires it when the browser session ends, and checks CSRF tokens on operator writes. Run the setup tool again to change the password.

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

   Then open `http://127.0.0.1:8000`. `data/mode.json` selects `DEMO` or `LIVE`; if it is missing or invalid, the app falls back to DEMO. Settings switches modes and shows the active database and flagged-row count. Site, phase, and Bench session settings are stored in the selected database.

### Dashboard batch shortcuts

I also added three shortcuts in the project folder: double-click `Launch Dashboard.bat` to start the dashboard, `Restart Dashboard.bat` to restart it, or `Stop Dashboard.bat` to stop it. They use the project’s server controls and keep it on `127.0.0.1`.

## Backups and data safety

Before field use or maintenance, I back up LIVE with `& $php tools/backup_db.php --profile=live`. It uses XAMPP’s `mysqldump.exe` at `C:\xampp\mysql\bin\mysqldump.exe` and writes a unique SQL dump under `backups/<timestamp>/`; it won’t overwrite an existing backup. Clearing demo rows in Settings requires typing `CLEAR` and makes a DEMO backup first. That action is disabled in LIVE. I never use LIVE for tests.

LIVE rejects incoming records marked demo, rehearsal, or `test_mode`; rejection details go to the local ignored `data/rejections.log`. The simulator is DEMO-only. LIVE starts without seeded hikers or a safe zone. Until a polygon is configured, positions stay UNCLASSIFIED and do not raise OFF TRAIL events. In LIVE Settings, turn on **Bench session** before Phase 1 bench trials so new packets, events, and commands are marked BENCH and excluded from research summaries. The switch defaults OFF, so unflagged LIVE records are labeled REAL and count as research data. Turn Bench session off before collecting scored field data. Settings also supports marking an existing site's hiker/time range as BENCH after typing `MARK BENCH`; choose times in your local time and the page converts them to UTC before sending. DEMO, REHEARSAL, TEST, and test-mode JSON rows are protected.

## Tests

I run database tests against `hiker_dashboard_test_codex` or the project-local scratch override. They fail closed in ordinary LIVE mode. Scratch tests create and drop randomly named `hiker_dashboard_scratch_*` databases. The LIVE first-run test uses a temporary LIVE-mode PHP server pointed at a scratch database; it does not open `hiker_dashboard_live`.

To run the full PHP, Node, and PowerShell suite without touching either configured database, I use `& $php tests/run_scratch_suite.php`. It creates and drops disposable scratch databases. Its LIVE-mode integration checks use only a scratch database. `tests/test-preflight.js` is a safety helper for other tests; running it by itself while LIVE mode is selected is expected to refuse.

When I select LIVE, Settings and `& $php tools/live_check.php` show a read-only readiness checklist. It reports database and table checks, migration checksums, token configuration without showing token values, site and field registry readiness, flagged rows, local-only status, and latest LIVE backup age. An empty LIVE database will still need site and field setup.

For an existing database, I check its migration ledger and take a backup before applying anything. Migration 007 adds editable site/node/reference registry fields, 008 adds safe-zone rings, and 009 adds BENCH classification. I did not access or migrate the existing LIVE database for this BENCH change, so verify its ledger first. After backing it up, the operator sequence is `& $php tools/backup_db.php --profile=live`, `& $php tools/migrate_db.php --profile=live --allow-live-schema`, then `& $php tools/live_check.php`. This changes the LIVE schema; never use LIVE for tests.

I use Settings to register hiker IDs and phone models, save Base Camp and Checkpoint nodes, and add, edit, or archive reference markers. Coordinates stay in the selected database. Defaults remain marked `UNSURVEYED`; I only mark coordinates verified after they have been surveyed. Archiving disables a record without deleting it. The GPX editor stores ordered safe-zone rings. A closed loop creates an outer and inner edge, making the trail corridor safe and the middle outside; an open track creates one outline. The half-width is the margin on EACH side of the trail (5–50 m). For expected GPS error up to 10 m, I’m planning around 15–20 m on the mountain. See `docs/LOOP_CORRIDORS.md`. Existing databases need migrations 008 and 009; back up before migrating.

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

The endpoint smoke test writes clearly marked DEMO/TEST rows in a disposable database and restores its active site, phase, and PUP polygon. Don’t pass a LIVE bridge token or point a test at LIVE.

## Simulator and CSV export

For hardware-free checks, I use the PHP simulator to post the existing logical uplink and reception JSON fields to the local bridge endpoint. It doesn’t define byte sizes, serial framing, or hardware transport. For example:

```powershell
$php = 'C:\xampp\php\php.exe'
& $php tools/simulate_hikers.php --scenario=normal_walk --base-url=http://127.0.0.1:8000/api
& $php tools/simulate_hikers.php --scenario=relay_via_checkpoint --base-url=http://127.0.0.1:8000/api
& $php tools/simulate_hikers.php --gpx=.\trail.gpx --speed=2 --base-url=http://127.0.0.1:8000/api
```

I keep the simulator scenarios in `tools/scenarios/`: normal walk, drift off trail, hiker goes silent, duplicate reception, out-of-order sequence, SOS, turn-back received, and Checkpoint relay. Every simulated packet is marked DEMO. I can export packet and event CSV from `api/export.csv?type=packets` and `api/export.csv?type=events`. Each row has a source label. GPS valid-fix is derived for display/export only: accuracy `<= 10 m` and at least `5` satellites; null values count as invalid.

## Sites and safe zones

For now, I’ve only included the coordinates provided for the project: Balagbag peak (`14.8243087, 121.1804131`, 770 m), the user-provided Balagbag place pin (`14.8239931, 121.1795801`), and the PUP Oval pin (`14.598126, 121.0120373`). They remain UNSURVEYED/UNVERIFIED. PUP’s boundary is still DEMO. Mt. Mapalad and Base Camp/Checkpoint coordinates are unset.

To prepare a safe zone, I record a GPX track with a phone while walking the intended corridor, export the `.gpx`, select the site in Settings or `/safe-zone`, load the file locally, preview it, and save only after checking the surveyed route. The file isn’t sent to a map service. Tracks over 250 points are simplified in the browser with Douglas–Peucker before preview; I can adjust the meter tolerance and check the point count and measured maximum deviation. If there are still over 250 points, I increase the tolerance and preview again. The half-width is 5–50 m on EACH side. A point exactly on the boundary counts as INSIDE. A GPX loop with its end within 0.5 m of its start becomes an outer and inner ring: the corridor is safe, but the middle is outside. An open track becomes one outline. See `docs/LOOP_CORRIDORS.md`. This geometry is not a substitute for surveying.

## Local API and integration boundary

I keep bridge routes behind the active profile’s `X-Bridge-Token`. Operator pages and API routes require a local operator login and loopback when `local_only` is true (the default); state-changing operator API requests also require a session CSRF token. Bridge routes stay independent and use their bridge token. Uplink fields are kept separate from reception metadata; the receiving node supplies `base_id` in the reception log, not in the uplink. LoRa packet sizes, binary format, and USB serial framing are not defined here.

### API endpoints

The routes below return JSON except the CSV export routes. Bridge routes require `X-Bridge-Token` matching the active profile token. The bridge sends the logical JSON contract only; USB serial framing and LoRa binary encoding remain unspecified.

- `GET /api/status`
- `GET /api/hikers`, `GET /api/hikers/{node_id}/latest`, `GET /api/positions/latest`
- `GET /api/events`, `GET /api/logs/packets` (time range, hiker, type, severity, path, classification, validation, delivery, and duplicate filters; limit 1–200 and offset)
- `GET /api/settings/bench-session`, `POST /api/settings/bench-session` (LIVE-only switch; changes are audited)
- `POST /api/packets/mark-bench` (LIVE-only; selected site, hiker and time range; requires typing `MARK BENCH`)
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

The uplink uses logical fields such as `node_id`, `sequence_number`, coordinates, GPS accuracy, satellite count, battery, and flags. Reception metadata is stored separately. A direct or Checkpoint relay reception attaches to the logical packet; duplicates are marked and each reception remains logged. One uplink queues at most one reply, following SOS > off-trail alert > storm turn-back > ACK. These are application records, not a wire format, and the dashboard does not transmit the command.

I haven’t set numeric receive-window thresholds or claimed 2-second/3-second pass results. The API exposes command timestamps and elapsed durations where available, without judging whether a radio delivery met a window.
