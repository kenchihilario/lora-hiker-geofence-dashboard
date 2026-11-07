# Phase 1 bench workflow

## Before a run

1. Back up the database before a schema migration. Migration 010 adds the test-session schema; run pending migrations only after confirming the intended profile and backup.
2. Select the intended site in Settings and register the hiker node in the site registry.
3. Start a test session in Settings. Start a new session after any board or phone restart because sequence numbers restart at 0. Enter a label, `PHASE1`, phone design A or B, tester code, and test point. The dashboard closes the previous active session for that site and records who started the new one.
4. For real Phase 1 bench records, use LIVE mode with the LIVE-only **Bench session** switch ON and the phone app's TEST flag OFF. LIVE rejects DEMO, REHEARSAL, and TEST packets. BENCH marks the uplinks and related events/commands outside research summaries.

## Session assignment and sequence numbers

The bridge envelope does not contain a test-session identifier. The dashboard assigns its currently active session for the selected site when it accepts each uplink. Packet identity for duplicate detection includes site, hiker, session, and sequence number. A repeated sequence in one session remains a duplicate; a repeated sequence in a later session is a new packet. The migration assigns pre-session records to a closed legacy session per site.

The Settings packet-range action can classify eligible historical packets as BENCH. It requires the exact confirmation text `MARK BENCH`; DEMO, REHEARSAL, and TEST records are protected, and the change is audited.

## Reply logging

The ingest result contains the selected logical command object when a command was queued. The hardware bridge may use this to avoid an additional pending-command poll, while the existing pending-command and status endpoints remain available. The DEMO simulator consumes the returned command, fetches pending commands to set `fetched_at`, and reports `SENT_TO_BASE` to set `sent_at`.

Those timestamps are software lifecycle records only. They do not establish delivery to the phone or hiker node, radio behavior, or compliance with the proposal's 2 s / 3 s receive windows. No LoRa packet size, binary encoding, or USB framing is defined here.

## LIVE migration

The change was not applied to LIVE. When the operator is ready, first run `tools/backup_db.php --profile=live`, then apply pending migrations with `tools/migrate_db.php --profile=live --allow-live-schema`, then run `tools/live_check.php`. Confirm the output and migration ledger before starting field work.
