# LIVE first-run checklist

This guide is for a fresh or restored LIVE database. It does not define any radio/serial framing, packet byte sizes, coordinates, or hardware readiness. Keep the dashboard bound to `127.0.0.1`.

## 1. Verify the local database safely

1. Start MariaDB normally from XAMPP and wait for its startup to finish.
2. Review the MariaDB error log and run `CHECK TABLE` plus `SHOW ENGINE INNODB STATUS` before proceeding. Stop if there is an LSN mismatch, tablespace warning, or corruption warning; restore from a known-good database backup and verify again.
3. For a normal backup or planned shutdown, use the XAMPP MySQL/MariaDB stop control and wait for the server to stop cleanly. Never kill the MariaDB process, copy `ibdata1`/redo logs while the server is running, or delete/replace redo files to clear an error.
4. Confirm the `live` profile in local `config.php` names `hiker_dashboard_live`, its bridge token is configured, and the token differs from the demo token. Do not display either token in screenshots or logs.
5. Create a uniquely named database backup before schema changes:

   ```powershell
   $php = 'C:\xampp\php\php.exe'
   & $php tools/backup_db.php --profile=live
   ```

   The tool writes a timestamped SQL dump under `backups/` and does not overwrite an existing backup.

## 2. Initialize or update the schema

- For a genuinely new, empty LIVE database, import the schema only with `tools/setup_db.php --profile=live`. It does not import `sql/demo_seed.sql` for LIVE.
- For a restored or already populated database, inspect the backup and migration ledger first. This project prepared migration `007_site_registry_editing.sql` but did **not** apply it to `hiker_dashboard_live` during this task. After taking the backup and reviewing that migration, apply the migration ledger explicitly:

  ```powershell
  & $php tools/migrate_db.php --profile=live --allow-live-schema
  ```

  Do not use the scratch test setup against LIVE. Do not use any setup or migration command while the MariaDB integrity check is failing.

## 3. Run the read-only readiness check

Keep `data/mode.json` set to `{"mode":"LIVE"}` and run:

```powershell
& $php tools/live_check.php
```

The Settings page also has a LIVE readiness panel, and the dashboard shows the active database name and flagged-row count. PASS/FAIL covers database reachability, table integrity, migration checksums, token configuration/difference, selected site, enabled hikers, Base Camp and Checkpoint records/coordinates, active safe zone, flagged rows, local-only guard, and backup age. The tool exits nonzero if any item fails. It never inserts or updates rows.

## 4. Configure the first site

1. Open Settings on the loopback dashboard and choose the correct site for the current session.
2. Add a site center, Base Camp Node, and Checkpoint Node only from coordinates supplied by the operator or a survey. Leave values blank if not known. Keep `UNSURVEYED` until a person verifies the location.
3. Add trail junctions, rest stops, peak/user reference markers, and their labels as needed. Unverified references stay visibly marked `UNSURVEYED`. Archive a record by disabling it; do not delete records with history.
4. Register each Hiker Node ID and phone model. Deactivation archives the registry row and leaves its packet history intact.
5. Import a surveyed GPX track in `/safe-zone`, inspect the existing preview, then explicitly save the polygon. Do not infer a boundary from a site pin or visual map preview.
6. Confirm Phase mode, then review the dashboard's “No safe zone set” state or active polygon, hiker registry, events, and packet log. A missing safe zone leaves uplinks unclassified; it does not create OFF TRAIL alerts.

## 5. Begin hardware testing

- Do not run `tools/simulate_hikers.php` while LIVE is active. Use the dedicated scratch integration test for software validation.
- Use only the firmware team's agreed logical fields. Packet byte sizes, LoRa encoding, USB serial framing, command timing windows, and delivery behavior remain firmware/hardware-owned until specified and measured.
- Keep the bridge token private and the server on loopback. Do not expose PHP's development server to a LAN or public interface.
- Record field-test timing and wrong-node observations in the proposal's paper record sheets where required; the dashboard does not infer those values.

## Current LIVE database note

The reloaded database was checked read-only after its latest restart, all 18 existing tables passed `CHECK TABLE`, and the post-restart error-log segment had no LSN mismatch/corruption warning. A backup was saved at `backups/20261104_045805/hiker_dashboard_live.sql`. It contained no hiker, node, safe-zone, packet, event, command, campaign, action, or reference rows. The project has not written to that database after the backup. Schema migration 007 remains unapplied there; use the backup and explicit migration command above before using the new registry write controls.
