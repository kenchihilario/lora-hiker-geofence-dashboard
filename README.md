# Trailwatch Dashboard

A local, offline hiker-tracking dashboard I built for our thesis project (two-way LoRa GPS hiker tracking) because I wanted to learn more about PHP, MariaDB, geofencing, and how a base station talks to hiker devices. It runs on my laptop with no internet and no map tiles: the map is plain SVG drawn by my own JavaScript.

I'm a Computer Engineering student, so this is a school project and not a finished product. Please read the "What works and what doesn't" part before trusting anything in here.

## What it does

- Takes hiker position packets (latitude, longitude, GPS accuracy, satellites, battery, flags) and checks each one against a safe zone I record from a real GPX track.
- Raises events for off-trail, SOS, link lost (no packet for 15 seconds), and storm turn-back, and queues one reply per packet (SOS first, then off-trail alert, then storm turn-back, then plain ACK).
- Keeps every packet, every reception (direct or through a Checkpoint), and the command timestamps, and exports them to CSV for the thesis data sheets.
- Has helper tools for the test phases in the paper: test sessions, crossings, marker checks, and position error checks.
- Keeps DEMO data and LIVE data in two separate databases so test junk can never end up in real results.

## What works and what doesn't

Works and is tested on my machine (DEMO, no hardware):

- The dashboard, API, database schema, and 15 migrations.
- The full scratch test suite: 542 PASS, 0 FAIL at the last full run.
- A laptop-side serial line bridge (`tools/serial_bridge.py`) with a fake ESP32 line generator. Its own tests give 27 PASS.

Not done yet:

- The ESP32 firmware, the LoRa radio code, and the Android hiker phone app are not part of this project folder. I write the firmware myself and haven't started testing it on real boards yet.
- The bridge has never run on a real USB serial port. The 84 to 120 ms it logs is laptop-side only on localhost, so it is **not** a timing result for the paper.
- The safe zone currently in LIVE is not my real walked lap. Base Camp and Checkpoint coordinates are not set yet.

The dashboard does not transmit anything over LoRa. Over-the-air packet sizes and encoding belong to the firmware and are not defined here.

## What you need

- Windows with XAMPP (PHP 8.2 and MariaDB). The app uses `C:\xampp\php\php.exe`.
- Node.js, only for the JavaScript tests.
- Python 3.12 for the serial bridge, plus `pyserial` only if you use a real serial port (`--port`).

There is no frontend framework and no package manager for the app itself.

## Setup

1. Copy `config.example.php` to `config.php`. The DEMO profile points at the disposable `hiker_dashboard_test_codex` database and LIVE points at `hiker_dashboard_live`. Set a different private bridge token for each in `bridge_tokens.demo` and `bridge_tokens.live`. Bridge callers send the active profile's token in `X-Bridge-Token`. Keep `config.php` and tokens out of git and out of screenshots.
2. Create the databases (DEMO also gets the demo seed, LIVE gets the schema only):

   ```powershell
   $php = 'C:\xampp\php\php.exe'
   & $php tools/setup_db.php --profile=demo
   & $php tools/setup_db.php --profile=live
   ```

3. Apply the migrations. There are fifteen (001 to 015), each one is recorded with a checksum, and it is safe to rerun:

   ```powershell
   & $php tools/migrate_db.php --profile=demo
   ```

   For LIVE, back up first and then add the explicit switch. The runner refuses without it:

   ```powershell
   & $php tools/backup_db.php --profile=live
   & $php tools/migrate_db.php --profile=live --allow-live-schema
   ```

   Do not edit old migrations. 001 to 009 have mixed line endings and the checksum is taken from the raw bytes, so never normalize them.

4. Set the local operator password with `& $php tools/set_operator_password.php`, then sign in at `/login.php`.

## Running it

```powershell
$php = 'C:\xampp\php\php.exe'
& $php -S 127.0.0.1:8002 -t public public/router.php
```

Open `http://127.0.0.1:8002` (8000 also works, I just use 8002). Keep the server on `127.0.0.1`. Operator pages require a login and CSRF tokens, and the local-only guard is ON by default. Never expose this server to a LAN or the internet.

`Launch Dashboard.bat`, `Restart Dashboard.bat`, and `Stop Dashboard.bat` are shortcuts for starting and stopping the server.

## DEMO vs LIVE

`data/mode.json` picks the mode. If it is missing or invalid, the app falls back to DEMO. The badge on the dashboard shows which mode is active, and the Settings page switches modes.

- LIVE rejects anything marked demo, rehearsal, or `test_mode`. Rejections go to the ignored `data/rejections.log`.
- The simulator only runs in DEMO. Never run the tests or the simulator while the badge says LIVE.
- In LIVE, turn on **Bench session** in Settings before bench trials so the new rows are marked BENCH and left out of the research summary. Turn it off before scored field collection.
- Clearing demo data needs typing `CLEAR`, takes a DEMO backup first, and is disabled in LIVE.
- `backup_db.php` defaults to LIVE if you leave out `--profile`, so always pass it.

## Test sessions and phases

Every uplink is stamped with the active test session of the selected site. Without an active session the bridge gets HTTP 409 and nothing is stored. A session has a label, a phase (`PHASE1`, `PHASE2`, `PHASE3`, `BENCH`), a phone design (A or B), a tester code, and a test point. Starting a new session closes the previous one for that site. Duplicate sequences are checked per site, hiker, session, and sequence, so a rebooted counter can restart in a new session.

Phase helpers I built for the paper:

- **Packet annotations** (Phase 1): mark packets as intended INSIDE or OUTSIDE and see whether the stored classification matched.
- **Crossings** (Phase 2 and 3): start, re-entry, and end buttons, with reference packet, outside count, flips, reports after re-entry, and return-to-safe time computed from packet times. Alert latency and the phone observations are typed in by hand.
- **Marker checks** (Phase 2): average the valid fixes in a window and classify that average against the zone.
- **Position error checks** (Phase 2): average two 10 second pauses (inside and outside marker) and compare the phone distance with the tape distance I measure on the ground. The result is signed error, with mean signed, mean absolute, and largest absolute shown as separate lines.

A valid fix means accuracy of 10 m or better and at least 5 satellites. Times in the API and CSV are naive UTC strings. Packet `received_at` only has one-second precision.

## Safe zones and GPX

Record a GPX track with a phone app (I use OsmAnd) while walking the corridor, then open `/safe-zone`, choose the file locally, preview the corridor, and save it after checking it. Nothing is uploaded anywhere.

- The margin is per side (5 to 50 m, default 10 m, with a warning below 10 m).
- A closed loop becomes an outer and an inner ring, so the corridor is safe and the middle of the loop is OUTSIDE. If the preview shows the middle as safe, it is not saved.
- Tracks over 250 points are simplified in the browser (Douglas-Peucker) and the preview shows the point count and the largest deviation.
- Points exactly on the boundary count as INSIDE.
- Every save is a new version. This is generated geometry, not a survey.

Only operator-supplied coordinates are in the site config, and they are marked UNSURVEYED. Real Base Camp and Checkpoint coordinates are entered in Settings and marked verified only after checking them on site.

## Serial bridge (no hardware needed to try it)

`tools/serial_bridge.py` reads text lines from the ESP32 over USB (or from stdin), posts each one to the bridge API, and writes one reply line back. `tools/fake_esp32.py` prints fake lines so I can test without boards. This line format is only for the USB link between the Base Camp ESP32 and the laptop. It is **not** the over-the-air packet.

Uplink line, ESP32 to laptop:

```
U,node_id,sequence,latitude,longitude,accuracy_m,satellites,battery,flags,path[,rssi_dbm[,snr_db[,checkpoint_id[,checkpoint_rssi_dbm[,relay_hop_delay_ms]]]]]
```

- `flags` is five 0/1 characters in this order: link_lost, low_battery, emergency, turn_back_received, test_mode.
- `path` is `DIRECT` or `CHECKPOINT_RELAY`.
- Example: `U,H1,1042,14.825014,121.1801059,7.5,7,84,00001,DIRECT,-92,8.5`

Downlink line, laptop to ESP32:

```
D,node_id,sequence_answered,command_type
```

The command types are `ACK`, `OFF_TRAIL_ALERT`, `SOS_ACK`, and `STORM_TURN_BACK`.

The bridge reads the bridge token from the environment variable `TRAILWATCH_BRIDGE_TOKEN` and never prints it. It only accepts loopback `http://` URLs. Run `python tools\serial_bridge.py --help` for the options. The baud rate is not settled yet (115200 is a placeholder).

A DEMO run needs an active test session first:

```powershell
python tools\fake_esp32.py | python tools\serial_bridge.py --stdin
```

Windows PowerShell puts a BOM at the start of piped text, so the bridge reads stdin as `utf-8-sig`. A real serial port does not send one.

## Simulator

`tools/simulate_hikers.php` posts logical packets to the bridge API in DEMO only. It does not simulate radio hardware.

```powershell
$php = 'C:\xampp\php\php.exe'
& $php tools/simulate_hikers.php --scenario=normal_walk --base-url=http://127.0.0.1:8002/api
& $php tools/simulate_hikers.php --scenario=relay_via_checkpoint --base-url=http://127.0.0.1:8002/api
& $php tools/simulate_hikers.php --gpx=.\trail.gpx --speed=2 --base-url=http://127.0.0.1:8002/api
```

Always pass `--base-url` (the default port is 8000). Scenarios live in `tools/scenarios/`: normal walk, drift off trail, hiker silent, duplicate receptions, out-of-order, SOS, storm turn-back received, relay via Checkpoint, and reply lifecycle. Two more are built into the PHP file: `phase1_replay` and `return_to_safe`. The simulator restarts sequence numbers at 1 on every run, so use one run per session or the second run is flagged as duplicates.

## Backups and data safety

`tools/backup_db.php` uses XAMPP's `mysqldump.exe` and writes a new SQL dump under `backups/<UTC timestamp>/`. It never overwrites an old one. It prints `Backup complete: ...` when it works. Back up LIVE before field use and before any schema change. The `backups/` folder is git-ignored.

`tools/live_check.php` (also on the Settings page in LIVE) is a read-only checklist: database reachable, table integrity, migration checksums, tokens (not shown), site and node registry, flagged rows, local-only guard, and backup age. An empty LIVE database is expected to fail the field setup checks until I set up the site.

## Tests

```powershell
$php = 'C:\xampp\php\php.exe'
& $php tests/run_scratch_suite.php 2>&1
python tests\serial_bridge_test.py
```

- The suite creates and drops its own `hiker_dashboard_scratch_*` databases and never touches `hiker_dashboard_live`. Run it only while the badge says DEMO and MariaDB is healthy, and only once at a time (it takes several minutes).
- Keep the `2>&1`. FAIL lines go to stderr, and without it a failed file can hide behind a count of 0 FAIL.
- Count lines starting with `PASS` and `FAIL`, and search case-sensitively for `FAILED`. A case-insensitive search matches PASS lines whose test names contain "failed".
- The Python bridge tests are separate and are not part of the PHP suite count.
- `tests/test-preflight.js` is a safety helper loaded by other tests. Running it alone in LIVE mode is expected to refuse.

## API

Everything returns JSON except the CSV exports. Operator routes need the local login and a CSRF token on writes. Bridge routes need `X-Bridge-Token`. `GET /api/safe-zone` also accepts a valid bridge token, so anyone holding the LIVE token can read the polygon.

- Read: `GET /api/status`, `/api/hikers`, `/api/hikers/{node_id}/latest`, `/api/positions/latest`, `/api/events`, `/api/logs/packets`, `/api/safe-zone`, `/api/sites`, `/api/network-nodes`, `/api/reference-points`, `/api/research/summary`, `/api/test-sessions/active`, `/api/settings/phase-mode`, `/api/clock-check`, `/api/live-check`
- Crossings: `GET /api/crossings?session_id=`, `GET /api/crossings/{id}`, `POST /api/crossings/start`, `POST /api/crossings/{id}/reentry`, `POST /api/crossings/{id}/end`, `PUT /api/crossings/{id}`
- Checks: `GET` and `POST /api/marker-checks`, `GET` and `POST /api/position-errors`
- Settings and registry: `POST /api/settings/site`, `POST /api/settings/phase-mode`, `POST /api/test-sessions/start`, `PUT /api/safe-zone`, hiker, network node, reference point, and site location create and update routes, plus `POST /api/events/{id}/resolve`
- Storm: `POST /api/commands/storm-turn-back`, `GET /api/commands/storm-turn-back/active`
- Bridge: `POST /api/bridge/ingest`, `POST /api/bridge/status`, `POST /api/bridge/validate` (dry run, nothing saved), `GET /api/commands/pending`, `POST /api/commands/{id}/status`
- CSV: `GET /api/export.csv?type=` with `packets`, `events`, `crossings`, `marker_checks`, or `position_errors` (the last three also need `session_id`). Each row has a source label: DEMO, REHEARSAL, TEST, BENCH, or REAL.

Bridge errors: 400 for a bad field or unknown hiker or base, 401 for a bad token, 409 when there is no active session, 422 when LIVE rejects a demo or test packet. A duplicate sequence returns 201 with `duplicate: true` and no new command. The bridge sends `base_id` in the reception, not in the uplink.

## Project layout

- `public/` - pages, API router, JavaScript, CSS
- `src/` - database connection, geofence, operator auth, API service
- `sql/` - schema, demo seed, ordered migrations
- `tools/` - setup, migrate, backup, live check, simulator, serial bridge, fake ESP32
- `tests/` - PHP, JavaScript, PowerShell, and Python tests
- `config/sites.php` - default site registry (coordinates marked UNSURVEYED)
- `docs/` - project status, Chapter 4 data notes, phone link notes, loop corridors

For the current detailed state of the project, see `docs/PROJECT_STATUS.md`. If this README and that file disagree, trust the status file and the code.

## Notes

- The alarm is an on-screen alert only. No sounder or physical alarm has been checked.
- Command timestamps stop at the bridge's "sent" report, so they do not prove the hiker received anything. The 2 s and 15 s outcomes are not judged by the dashboard.
- The paper is a reference and I did not change it. Where the code and the paper differ, the difference is noted in `docs/PROJECT_STATUS.md`.
