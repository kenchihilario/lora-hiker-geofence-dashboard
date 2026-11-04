# Chapter 4 data availability

This describes what the dashboard currently records. It does not change the research proposal or infer missing observations.

| Planned metric | Logged fields that help compute it | Still manual or missing |
|---|---|---|
| Packet delivery | Unique uplink sequence, reception timestamp, heard-by node, direct/checkpoint path, duplicate marker, relay RSSI/SNR and hop-delay field | Sent-packet denominator and a reliable originating send timestamp are not available from the base-side ingest alone. Do not treat received rows as a delivery ratio. |
| Command latency | Command creation, first bridge fetch and reported-sent timestamps; derived elapsed milliseconds; direct/relay route | Hiker receive-side timestamp and timer outcome are firmware/record-sheet data. Logged elapsed time ends at the bridge's sent report. |
| Position error | Stored reported latitude/longitude, GPS accuracy, satellite count; valid-fix display/export derivation | Ground-truth surveyed coordinate and measurement procedure are manual. GPS accuracy field is device-reported, not measured error. |
| Alert time | Uplink receive time, event create time, command create/fetch/sent time, event type | Phone warning display/receipt timestamp and the hiker's 2 s/15 s timer observations come from record sheets / firmware. The backend does not infer pass/fail. |
| Battery life | Reported battery level per uplink and event timestamps | Start/end phone battery readings, screen/app conditions, and sampling schedule must be recorded manually. No continuous battery telemetry is invented. |
| Wrong-node receptions | Heard-by node and reception path are logged | Intended destination vs. actual receiving node and wrong-node outcome need record-sheet/firmware observations. The dashboard does not infer wrong-node delivery. |

Rows flagged DEMO, REHEARSAL, or TEST are excluded from research summary counts; CSV exports retain rows and label their source.
