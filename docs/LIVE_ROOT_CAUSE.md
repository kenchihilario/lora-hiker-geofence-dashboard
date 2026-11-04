# LIVE dashboard diagnosis — Task 0

Read-only diagnosis on 2026-11-04. No application code, database rows, configuration, mode, or Git branch was changed. **Stop condition reached: InnoDB integrity is suspect. Do not proceed to Tasks 1–6 until the database is recovered and independently verified.**

## Findings

- Local mode file is already `LIVE`; active DB profile is `hiker_dashboard_live` on `127.0.0.1`. No mode switch was performed.
- MariaDB reports `10.4.32-MariaDB`, `innodb_force_recovery=0`, and error log `C:\xampp\mysql\data\mysql_error.log`.
- All 18 tables returned `status / OK` for `CHECK TABLE`. That does not clear the InnoDB warning below.
- `SHOW ENGINE INNODB STATUS` reported log sequence number `143497` and last checkpoint `143488` at inspection time.
- The latest error-log tail (2026-11-04 11:27:11–11:27:12) contains repeated messages such as page `space=0, page=494` with LSN `50103853` while the current system log sequence number was `140309`, followed by: “Your database may be corrupt or you may have copied the InnoDB tablespace but not the InnoDB log files.” MariaDB then logged startup at LSN `140300`. This is a material LSN mismatch consistent with the reported redo-log removal; table checks alone are insufficient proof of integrity.
- LIVE has 18 tables and five migration-ledger rows (`001_milestone2.sql` through `005_downlink_routing_timing_storm_campaigns.sql`). `app_settings` contains `selected_site=pup` and `phase_mode=PHASE1`; the missing-selected-site candidate is not supported by this query.
- Read-only counts: 0 hikers, 0 network nodes, 0 safe zones/vertices, 0 uplinks, 0 receptions, 0 events, and 0 downlink commands. This agrees with the supplied empty-LIVE context.

## Browser error assessment

The browser error `Cannot read properties of null (reading 'name')` is a JavaScript null dereference, but its exact source was not established. The database has no selected-site setting missing, and it has no safe-zone row, so an empty-zone/null-handling path is a plausible frontend cause. The dashboard API endpoint JSON and browser error were **not** inspected/reproduced after the InnoDB damage signal; the task rules require stopping when LIVE looks damaged. “Checking active database…” therefore also remains unconfirmed at the endpoint level.

## Required next action

Stop application work against LIVE. Have the database owner recover the MariaDB data directory from a known-good backup and verify InnoDB integrity using an approved recovery procedure. Do not treat `CHECK TABLE` returning OK as a complete integrity check. After recovery is confirmed, rerun read-only health checks before any API diagnosis or code changes.

No LIVE backup was attempted because the stop condition was reached and no change was planned. No tests were run. Current Git branch remains `main`; no branch or task commits were created.

## Recheck after reported rebuild — 2026-11-04

The operator reported rebuilding MariaDB with a fresh `ibdata1` and reloading LIVE from a dump. I rechecked the active instance read-only before any API calls. It is still MariaDB `10.4.32`, data directory `C:\xampp\mysql\data\`, `innodb_force_recovery=0`, with the configured mode file set to `LIVE`. Server uptime was 997 seconds at 11:44 Manila time, placing this instance's start at about 11:27:25. The active error log's last write was 11:27:12 and ends with this startup sequence:

- `InnoDB: 10.4.32 started; log sequence number 140300`
- Immediately around startup, pages reported LSNs including `50103853` and `50196610` while the current system log sequence number was `140309`.
- The log explicitly says the database may be corrupt or tablespaces may have been copied without matching InnoDB log files.

**Stop condition remains active.** These are warnings from the current server startup, not merely an older log segment. I did not run fresh `CHECK TABLE`, inspect endpoint JSON, open the LIVE dashboard, create a backup, create a branch, or proceed with Tasks 1–6. The earlier `CHECK TABLE` results in this report predate the claimed rebuild and do not verify the reloaded database. No database or code was modified in this recheck.

## Recheck after second restart — 2026-11-04

The active MariaDB instance restarted again. At this inspection, uptime was 150 seconds; `mysql_error.log` was last written at 11:48:36 and its post-restart contents contained only startup messages, with no LSN mismatch/corruption warning. The server reports MariaDB 10.4.32, `innodb_force_recovery=0`. Read-only checks then completed:

- `CHECK TABLE`: all 18 tables returned `status / OK`, no issues.
- `SHOW ENGINE INNODB STATUS`: log sequence number `2148174`, checkpoint `2148165`.
- `app_settings`: `phase_mode=PHASE3`, `selected_site=balagbag`.
- `schema_migrations`: entries 001 through 005, all applied at `2026-11-04 08:30:32`.
- hiker nodes, network nodes, zones/vertices, uplinks, receptions, events, commands, storm campaigns, operator actions and reference points all have 0 rows.

### LIVE dashboard GET responses

The local server at `127.0.0.1:8002` reported `active_mode=LIVE` and `active_database=hiker_dashboard_live`. These are the page-load GET endpoints from `public/assets/js/dashboard.js` (including its site picker); all returned HTTP 200:

```text
GET /api/status
{"database":"ONLINE","active_mode":"LIVE","active_database":"hiker_dashboard_live","demo_row_count":0,"live_demo_count_error":false,"test_database":false,"components":[],"last_packet_at":null,"hiker_links":[],"link_loss_timeout_seconds":15,"demo_mode":"data is marked per record"}

GET /api/hikers
{"hikers":[],"link_loss_timeout_seconds":15,"site_id":"balagbag"}

GET /api/events?limit=8
{"events":[],"limit":8,"offset":0}

GET /api/safe-zone
{"site":{"id":"balagbag","display_name":"Mt. Balagbag","center":{"latitude":14.8243087,"longitude":121.1804131},"zoom":16,"center_status":"Peak reference supplied by operator","reference_points":[{"code":"PEAK","label":"Peak · 770 m","point_type":"PEAK","latitude":14.8243087,"longitude":121.1804131},{"code":"USER_PIN","label":"Google Maps place pin (user-provided, unsurveyed)","point_type":"OTHER","latitude":14.8239931,"longitude":121.1795801,"unsurveyed":true}],"network_nodes":{"BASE":null,"CHK":null}},"selected_site":"balagbag","safe_zone":null,"network_nodes":[],"reference_points":[{"code":"PEAK","label":"Peak · 770 m","point_type":"PEAK","latitude":14.8243087,"longitude":121.1804131},{"code":"USER_PIN","label":"Google Maps place pin (user-provided, unsurveyed)","point_type":"OTHER","latitude":14.8239931,"longitude":121.1795801,"unsurveyed":true}]}

GET /api/settings/phase-mode
{"phase_mode":"PHASE3","storm_turn_back_enabled":true}

GET /api/logs/packets?limit=20
{"packets":[],"limit":20,"offset":0}

GET /api/sites
{"sites":[{"id":"pup","display_name":"PUP Sta. Mesa","center":null,"zoom":16,"center_status":"PLACEHOLDER — confirm campus center","reference_points":[{"code":"PUP_OVAL_PIN","label":"PUP Oval Google Maps pin (user-provided, unverified)","point_type":"OTHER","latitude":14.598126,"longitude":121.0120373,"unsurveyed":true}],"network_nodes":{"BASE":null,"CHK":null}},{"id":"pup_mountain_prep","display_name":"PUP Mountain Prep (Demo)","center":null,"zoom":16,"center_status":"UNSCORED REHEARSAL — PUP coordinates are not surveyed; configure a center or import a GPX track.","terrain_profile":"mountain","is_demo":true,"is_rehearsal":true,"reference_points":[],"network_nodes":{"BASE":null,"CHK":null}},{"id":"balagbag","display_name":"Mt. Balagbag","center":{"latitude":14.8243087,"longitude":121.1804131},"zoom":16,"center_status":"Peak reference supplied by operator","reference_points":[{"code":"PEAK","label":"Peak · 770 m","point_type":"PEAK","latitude":14.8243087,"longitude":121.1804131},{"code":"USER_PIN","label":"Google Maps place pin (user-provided, unsurveyed)","point_type":"OTHER","latitude":14.8239931,"longitude":121.1795801,"unsurveyed":true}],"network_nodes":{"BASE":null,"CHK":null}},{"id":"mapalad_backup","display_name":"Mt. Mapalad (backup)","center":null,"zoom":16,"center_status":"BACKUP SITE — coordinates and route are not configured.","reference_points":[],"network_nodes":{"BASE":null,"CHK":null}}],"selected_site":"balagbag"}
```

No POST endpoint was invoked; interactive command/settings endpoints can write to LIVE and were excluded under the no-LIVE-writes rule.

### Reproduced frontend failure and stuck status line

`GET /api/safe-zone` correctly returns `safe_zone: null` for the empty LIVE database while returning a site center and two positioned references. In `public/assets/js/dashboard.js:15-37`, `drawZone()` only returns early when the polygon/hikers/nodes/references/site center have no coordinates. With these references and center, it reaches line 37 and evaluates `zone.name` even though `zone` is null. I executed the actual `drawZone()` function from that file in a Node VM with the LIVE response fields; output was:

```text
TypeError: Cannot read properties of null (reading 'name')
```

This reproduces the exact JavaScript exception from the production renderer path. The local browser automation surface was disabled by Work settings and Chrome forwarded the headless request to the existing session, so I did not alter the user's open browser tab or claim a visual browser-console capture.

The main dashboard calls `GET /api/status` in its page-load `Promise.all` at `public/assets/js/dashboard.js:42`; that endpoint returns HTTP 200 with the correct active database and zero flagged rows. The renderer calls `drawZone()` at line 51 before updating `#database-check-inline` at line 59. The exception is caught as if the API failed, so the dashboard shows a misleading API error and leaves its status line at “Checking active database…”. The settings page also gets its database-check data from `GET /api/status` (`public/assets/js/site-settings.js:11`). Thus the status endpoint is healthy; the dashboard never reaches the DOM update because drawing throws first.

This recheck cleared the database-health stop condition. Task 0 is complete; no code has been changed yet. A LIVE backup is required before proceeding with Tasks 1–6.
