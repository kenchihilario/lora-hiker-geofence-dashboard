# Codex Project Brief: LoRa-Based GPS Tracking and Active Geofencing Dashboard

**Source of truth:** `Proposal1-hiker-final-v77.docx`  
**Project:** Two-Way LoRa-Based GPS Tracking and Active Geofencing Warning System for Eco-Trail Hiker Safety  
**Purpose of this document:** Convert the research proposal into an implementation-ready plan for the website/dashboard. This is a software specification, not a claim that the hardware or radio protocol is already implemented.

---

## 1. Instructions for Codex

Build the **base-camp web dashboard and its local backend** for the proposed hiker-safety prototype. Treat this document as the product requirements specification.

Before writing code:

1. Inspect the existing repository and identify its current framework, folder structure, database setup, and any existing code. Do not overwrite working code without explaining why.
2. Produce a short implementation plan and proposed file tree.
3. Identify missing decisions listed in **Section 15**. Do not silently invent hardware packet formats, serial message formats, or operational rules.
4. Implement in small, testable stages. Keep the application runnable after each stage.
5. Use a clean separation between the web UI, PHP backend, database, and Python serial bridge.
6. Include database migrations/schema, sample seed data, setup instructions, and tests.
7. Do not claim that real LoRa communication works unless it is connected to the actual ESP32 firmware and verified. Provide a clearly labelled **Demo/Simulation Mode** for UI development only.

The website is one part of a larger system. The Android Hiker Node app and ESP32 firmware are separate components. The website must communicate with the Base Camp Node through the laptop's USB serial connection using a small Python serial bridge; PHP itself must not try to open the serial port directly.

---

## 2. Product Overview

The system monitors up to **two Hiker Nodes** on a known eco-trail. Each Hiker Node is an Android phone connected to an ESP32 + SX1276 LoRa board. The phone provides GPS, battery level, display, speaker, and vibration. The ESP32/SX1276 provides the LoRa radio link.

A **Base Camp Node** at the trail start connects by USB serial to a laptop. The laptop runs the PHP website/backend, MariaDB database, and Python serial bridge. A battery-powered **Checkpoint Node** acts as a single-hop relay. It has no laptop.

The dashboard must:

- Show each registered Hiker Node on an offline map.
- Show the latest reported position and whether it is inside or outside the stored safe-zone polygon.
- Evaluate every received coordinate using the Point-in-Polygon (ray-casting) method on the server.
- Raise a visible and audible base-camp alarm when a hiker is outside the safe zone.
- Send a targeted LoRa downlink alert to the correct Hiker Node.
- Send a short acknowledgement when a received position is inside the safe zone.
- Show link status and retain the last known position when communication is lost.
- Show which node last heard each hiker: Base Camp Node or Checkpoint Node.
- Show phone battery level and low-battery status.
- Let the operator send a manual `STORM - TURN BACK` message to one hiker or all hikers.
- Log received packets, decisions, alerts, acknowledgements, relay information, and operator actions.
- Work locally without cellular data, internet access, or cloud services during field testing.

**Important:** The prototype is a monitoring aid. It does not replace guides, trail rules, normal emergency procedures, or a real cellular phone carried as a safety backup.

---

## 3. System Components and Responsibilities

### 3.1 Hiker Node (separate Android app + ESP32 firmware)

There are two Hiker Nodes, each with a unique `node_id`.

The phone app:
- Reads Android GPS location, reported accuracy, satellite/fix information where available, and phone battery percentage.
- Sends the position and metadata to the ESP32 board using one of two phone-to-board designs:
  - **Design A:** Bluetooth Low Energy (BLE); the ESP32 board has its own 18650 battery pack.
  - **Design B:** USB-C OTG serial; the phone powers the board and can be charged through the adapter's pass-through USB-C port.
- Displays incoming alerts.
- On an off-trail alert, sounds an alarm, vibrates, and displays `OFF TRAIL - TURN BACK`.
- Displays `LINK LOST` and gives a short vibration after three consecutive uplinks receive no reply.
- Warns when phone battery falls below 20%.
- Has an SOS action (hold for 2 seconds) and inactivity check (30 minutes without movement, followed by an `ARE YOU OK?` prompt; no response within 15 seconds sets an emergency flag).
- Displays `SOS RECEIVED` when acknowledged.
- Receives and displays `STORM - TURN BACK`.

The website does not implement the Android app UI. It must expose and log the backend behavior needed by that app.

### 3.2 Base Camp Node

- ESP32 + SX1276 LoRa radio.
- Connected to the laptop by USB serial.
- Receives direct uplinks and packets forwarded by the Checkpoint Node.
- Sends downlink acknowledgements and alerts to a specific `node_id`.
- Does not make the geofence decision itself; the PHP backend makes that decision.

### 3.3 Checkpoint Node

- ESP32 + SX1276 LoRa radio on its own two-cell 18650 battery pack.
- Installed at the second checkpoint.
- No laptop and no direct database connection.
- Repeats each uplink it hears once to the Base Camp Node, adding its own node identity and the RSSI at which it heard the hiker.
- Repeats downlinks from Base Camp to the Hiker Node.
- Uses duplicate suppression so it does not relay the same `node_id` + sequence number twice.
- The dashboard must identify relayed packets and preserve the relay metadata.

### 3.4 Laptop / Local Server

The proposal specifies:
- XAMPP 8.2.12
- PHP 8.2.12
- MariaDB 10.4.32
- Python 3.12 serial bridge
- pySerial 3.5
- Offline OpenStreetMap raster tiles in PNG format, zoom levels 15–19
- Local storage only; no cloud or internet dependency during operation

The dashboard should be desktop-first because it is operated at the base camp, but it should remain usable on a tablet-sized screen.

---

## 4. Proposed Website Pages

Build the following pages. Keep navigation simple and make the live monitoring screen the main landing page.

### 4.1 Live Dashboard (`/dashboard`)

This is the primary operator screen.

**Map area**
- Render locally stored/offline map tiles.
- Show the stored safe-zone polygon.
- Show a marker for each Hiker Node's latest known coordinate.
- Distinguish safe, off-trail, link-lost, and emergency states.
- Show the last known position even when the node becomes disconnected.
- Show a popup/card when a marker is selected:
  - Hiker Node name and `node_id`
  - Latest latitude and longitude
  - Last report timestamp
  - Inside/outside status
  - Phone battery percentage
  - GPS accuracy and fix validity
  - Last-heard-by node (Base Camp or Checkpoint)
  - RSSI/SNR if available
  - Link state and time since last packet

**Hiker status panel**
- One card per configured Hiker Node (two in the prototype).
- Current state: `SAFE`, `OFF TRAIL`, `LINK LOST`, `LOW BATTERY`, or `EMERGENCY`.
- Latest battery percentage.
- Last report time and elapsed time.
- Last-heard-by node.
- Do not treat a stale last coordinate as a live coordinate.

**Alert panel**
- Newest alerts first.
- Include hiker, alert type, time, coordinate, delivery state, and acknowledgement state.
- Highlight active/unresolved off-trail and emergency alerts.
- Provide a way to acknowledge/clear an operator-facing alert. Clearing a dashboard notification must not rewrite or delete the original event log.

**Connection status**
- Base Camp Node online/offline.
- Serial bridge connected/disconnected.
- Last packet received.
- Checkpoint Node heard recently/not recently, based on its packet metadata.
- Database/API status.

**Operator actions**
- `STORM - TURN BACK` for a selected hiker.
- `STORM - TURN BACK` for all configured hikers.
- Require confirmation before sending.
- Display command state: queued, sent to Base Camp Node, delivered to Hiker Node, received/confirmed by Hiker Node, failed, or timed out. Only show a state when supported by an actual event/log.

### 4.2 Safe Zone Management (`/safe-zone`)

- Display the currently stored polygon on the offline map.
- List polygon vertices in order, with latitude and longitude.
- Allow an authorized operator to create/edit the polygon by:
  - entering vertex coordinates, and/or
  - selecting points on the map if the offline map component supports it.
- Validate that there are at least three distinct vertices and that the polygon is valid.
- Save polygon changes to MariaDB.
- Record who changed it and when.
- Clearly indicate which polygon version is currently active.
- Do not automatically alter the polygon based on hiker positions.
- The proposal uses one active safe-zone polygon for the current test. Multiple zones/restricted zones are outside scope.

### 4.3 Hiker Nodes (`/hikers`)

- List the two configured Hiker Nodes.
- View `node_id`, display name, phone model, app version, firmware version, selected phone-to-board design (A/B), and enabled/disabled state.
- View recent battery and connection history.
- Do not allow a user to change a node's radio identity casually; require validation and an audit log.

### 4.4 Event and Packet Logs (`/logs`)

Provide filterable, paginated logs:
- Date/time range
- Hiker Node
- Packet direction (uplink/downlink)
- Packet type
- Direct vs Checkpoint-relayed path
- Inside/outside classification
- Delivery status
- Emergency/alert status

Each row should show the packet sequence number and enough metadata to trace it. Provide CSV export for research analysis. Do not silently discard failed, duplicate, malformed, or late packets; mark them with a status and preserve diagnostic information.

### 4.5 System / Connection Settings (`/settings`)

- Serial bridge status and configured serial port (show only; changing it should require confirmation).
- Base Camp Node identity.
- Checkpoint Node identity.
- Radio configuration summary (read-only in the initial website).
- Link-loss timeout (default 15 seconds; make configurable only if the team approves changing the proposal's fixed test setting).
- Offline map tile source/path and availability.
- Demo/Simulation Mode toggle, visually unmistakable and separate from real hardware mode.
- Application version and database connection status.

### 4.6 Test / Research View (`/research`)

This is optional for the first MVP but should be planned as a later page. It can summarize measurements from stored logs:
- Packet exchange success rate
- Uplink and downlink packet delivery ratio (PDR)
- End-to-end alert latency
- Crossing-level and packet-level miss rates
- GPS fix stability and position error
- RSSI and SNR
- Correct-node delivery rate
- Last-seen check
- Relay hop delay
- Reliable range and maximum reach
- Phone battery life

Keep raw measurements available for export. Do not invent computed results where the required raw data is absent. Distinguish measured results from targets.

---

## 5. Core Operational Workflow

### 5.1 Uplink received

1. Hiker Node sends a position packet with `node_id`, sequence number, latitude, longitude, GPS accuracy/fix metadata, phone battery level, and flags.
2. Base Camp Node receives it directly, or Checkpoint Node forwards it.
3. Base Camp Node sends the received packet to the laptop over USB serial.
4. Python serial bridge reads the serial data and forwards it to the PHP backend over a local-only interface.
5. PHP validates the packet and checks duplicate identity using `node_id` + sequence number.
6. If the same packet arrives by both direct and relay paths, retain one logical coordinate event and preserve both reception-path records/metadata. Do not create two separate hiker position events.
7. Store the packet and reception metadata.
8. Run Point-in-Polygon (ray-casting) against the active safe-zone polygon.
9. Update the hiker's latest status and last-known position.
10. If inside:
    - Record `SAFE`.
    - Queue a short acknowledgement downlink addressed to that Hiker Node.
11. If outside:
    - Record `OFF TRAIL`.
    - Create an alert event.
    - Trigger the base-camp visual/audio alarm.
    - Queue an alert downlink addressed only to that Hiker Node.
12. Send the downlink command to the Base Camp Node through the serial bridge.
13. Record actual send/receive/acknowledgement outcomes when they arrive.

### 5.2 Link-loss detection

- The proposal sets the dashboard link-loss timeout to **15 seconds**.
- If no valid uplink is received for a Hiker Node within 15 seconds:
  - Mark its link as `LINK LOST`.
  - Keep its marker at the last received coordinate.
  - Display the last report timestamp, elapsed time, and last-heard-by node.
  - Do not move the marker or imply that the hiker is still at that position.
- When a new valid uplink arrives, restore the communication state and recalculate geofence status.
- The Hiker Node's own `LINK LOST` warning is separate: it appears after three consecutive uplinks without a reply. The website must not pretend to control that phone-side warning.

### 5.3 SOS / inactivity emergency

- If an uplink carries the emergency flag:
  - Create an emergency event.
  - Show a prominent dashboard alarm.
  - Send/record the corresponding response so the Hiker Node can show `SOS RECEIVED`.
- Inactivity logic is performed by the Android app. The website receives the emergency flag; it does not infer inactivity from map movement unless a future approved requirement says so.
- Never label a normal GPS stop as an emergency without the app's emergency flag.

### 5.4 Manual storm turn-back command

1. Operator chooses one Hiker Node or all Hiker Nodes.
2. Confirm the action.
3. Create a command record.
4. Backend queues the command for transmission during the Hiker Node's receive window after its next uplink, following the proposal.
5. Base Camp Node sends directly or through the Checkpoint Node according to the last-heard path/routing rule.
6. Record each stage only when confirmed by the relevant event.
7. When the Hiker Node returns the received flag, mark the command as received.

---

## 6. Geofencing Rules

- Use the active polygon stored in MariaDB.
- Use the Point-in-Polygon **ray-casting** algorithm in the PHP backend.
- The proposal treats latitude and longitude as planar coordinates for the small test areas; do not add geodesic correction unless separately approved.
- Evaluate each position fix independently. The proposal specifies no smoothing or debounce.
- Save the result of every classification (`INSIDE` or `OUTSIDE`) with the polygon version used.
- If a hiker returns inside, update dashboard state to `SAFE`.
- Do not make a geofence decision on the website client/browser or on the Hiker Node.
- Define and test how a point exactly on a polygon edge is classified. The proposal does not specify this edge-case rule, so make it an explicit implementation decision and document it.

---

## 7. Radio and Timing Configuration (proposal values)

Treat these as the proposal's initial test configuration, not universal production defaults.

| Setting | Proposal value |
|---|---:|
| Operating frequency | 915 MHz, within the proposal's stated 915–918 MHz band |
| Spreading factor | SF9 |
| Bandwidth | 125 kHz |
| Coding rate | 4/5 |
| Transmit power | 14 dBm |
| GPS report interval | 5 seconds |
| Random send jitter | Up to 0.5 seconds |
| Dashboard link-loss timeout | 15 seconds |
| Hiker phone link-lost warning | 3 consecutive uplinks without reply |
| Hiker downlink receive window | 2 seconds |
| Checkpoint relay random delay | Up to 0.2 seconds |
| Checkpoint duplicate memory | Last 10 `node_id` + sequence pairs |
| Phone low-battery warning | Below 20% |
| SOS hold duration | 2 seconds |
| Inactivity threshold | 30 minutes without movement |
| Inactivity prompt response window | 15 seconds |

The proposal also describes a 3-second target for end-to-end alert latency and 90% packet delivery targets in specified test phases. Show these as **research targets**, not guaranteed live-system behavior.

---

## 8. Packet Data Requirements

The proposal identifies these logical packet fields. It does **not** fully define byte order, encoding, checksum, framing, or the serial bridge's exact wire format.

| Field | Direction | Planned size | Meaning |
|---|---|---:|---|
| `node_id` | Uplink/downlink | 1 byte | Hiker Node sender/recipient |
| `base_id` | Downlink | 1 byte | Base Camp Node sender identity |
| `sequence_number` | Uplink/downlink | 2 bytes | Packet sequence and duplicate detection |
| `latitude_longitude` | Uplink | 8 bytes | Phone position |
| `accuracy_satellite_count` | Uplink | 2 bytes | GPS accuracy/fix information |
| `battery_level` | Uplink | 1 byte | Phone battery percentage |
| `flags` | Uplink | 1 byte | Emergency, low battery, turn-back received, test mode, etc. |
| `type` | Downlink | 1 byte | Acknowledgement or alert type |

Codex must implement a **transport adapter/interface** and a documented placeholder parser until the actual firmware packet specification is supplied. Do not guess a binary layout and then present it as final.

Suggested logical packet object for backend code (not the actual radio byte layout):

```json
{
  "node_id": 1,
  "sequence_number": 1024,
  "latitude": 14.5995,
  "longitude": 120.9842,
  "gps_accuracy_m": 5.0,
  "satellite_count": 8,
  "battery_level": 87,
  "flags": {
    "emergency": false,
    "low_battery": false,
    "turn_back_received": false,
    "test_mode": false
  },
  "received_by": "BASE_CAMP",
  "rssi_dbm": -92,
  "snr_db": 7.5,
  "received_at": "ISO-8601 timestamp"
}
```

This is a proposed internal JSON representation for application code only. Confirm field semantics and actual serial/radio encoding with the firmware team.

---

## 9. Database Plan

Use MariaDB. Use migrations or versioned SQL scripts. Suggested logical tables:

### `hiker_nodes`
- `id` primary key
- `node_id` unique
- `display_name`
- `phone_model`
- `android_version`
- `app_version`
- `firmware_version`
- `connection_design` (`BLE` or `USB_OTG`)
- `is_enabled`
- `created_at`, `updated_at`

### `network_nodes`
- `id`
- `node_code` (Base Camp / Checkpoint)
- `node_type`
- `display_name`
- `is_enabled`

### `safe_zones`
- `id`
- `name`
- `is_active`
- `created_at`
- `updated_at`
- `created_by` (nullable if no authentication/user management is implemented yet)

### `safe_zone_vertices`
- `id`
- `safe_zone_id`
- `vertex_order`
- `latitude`
- `longitude`

### `uplink_packets`
- `id`
- `hiker_node_id`
- `sequence_number`
- `latitude`
- `longitude`
- `gps_accuracy_m`
- `satellite_count`
- `battery_level`
- `flags_json`
- `classification` (`INSIDE`, `OUTSIDE`, `UNCLASSIFIED`)
- `safe_zone_id` / polygon version reference
- `received_at`
- `is_duplicate`
- `validation_status`
- `raw_payload` (for diagnostics; do not store personal data beyond the proposal's test data)

### `packet_receptions`
- `id`
- `uplink_packet_id`
- `received_by_node`
- `path_type` (`DIRECT`, `CHECKPOINT_RELAY`)
- `relay_rssi_dbm`
- `rssi_dbm`
- `snr_db`
- `received_at`
- `relay_hop_delay_ms`
- `raw_metadata`

This separate table is important because the same uplink may be heard directly and through the Checkpoint Node. The application should deduplicate the logical packet but preserve each reception path.

### `downlink_commands`
- `id`
- `hiker_node_id`
- `sequence_number` or correlation reference
- `command_type` (`ACK`, `OFF_TRAIL_ALERT`, `STORM_TURN_BACK`, `SOS_ACK`, etc.)
- `payload_json`
- `status` (`QUEUED`, `SENT_TO_BASE`, `DELIVERED`, `RECEIVED`, `FAILED`, `TIMED_OUT`)
- `created_at`
- `sent_at`
- `delivered_at`
- `received_at`
- `failure_reason`

### `system_events`
- `id`
- `hiker_node_id` nullable
- `event_type`
- `severity`
- `message`
- `metadata_json`
- `created_at`
- `resolved_at` nullable
- `resolved_by` nullable

### `operator_actions`
- `id`
- `action_type`
- `target_node_id` nullable
- `details_json`
- `created_at`
- `operator_id` nullable

### `system_status`
- Store or derive bridge status, last packet time, and node health. Avoid storing a constantly overwritten state without retaining the event history needed for troubleshooting.

Add indexes for timestamps, `node_id`, sequence numbers, event type, and classification. Use foreign keys where appropriate. Use prepared statements for all database queries.

---

## 10. Backend API Plan

Use local-only PHP endpoints. Exact endpoint names may be adjusted to fit the existing project, but keep the responsibilities equivalent.

| Method | Endpoint | Purpose |
|---|---|---|
| `GET` | `/api/status` | Base Camp, serial bridge, database, and latest packet status |
| `GET` | `/api/hikers` | Configured Hiker Nodes and latest status |
| `GET` | `/api/hikers/{id}/latest` | Latest position and metadata for one hiker |
| `GET` | `/api/positions/latest` | Latest known position for each hiker |
| `GET` | `/api/events` | Filterable event/alert history |
| `GET` | `/api/logs/packets` | Filterable packet log |
| `GET` | `/api/safe-zone` | Active polygon and vertices |
| `PUT` | `/api/safe-zone` | Validate and save polygon |
| `POST` | `/api/commands/storm-turn-back` | Queue storm warning for one/all hikers |
| `POST` | `/api/bridge/ingest` | Local serial bridge submits a received packet |
| `POST` | `/api/bridge/status` | Serial bridge heartbeat/status |
| `GET` | `/api/commands/pending` | Bridge retrieves queued downlink commands, if polling is chosen |
| `POST` | `/api/commands/{id}/status` | Bridge/Base Camp reports command transmission status |
| `GET` | `/api/research/summary` | Later-stage performance summaries |

Security requirements:
- Bind the web server and bridge API to localhost/LAN as intended for the test setup; do not expose it publicly by default.
- Add authentication before any deployment beyond a controlled research laptop.
- Validate all incoming fields, node identities, coordinate ranges, packet lengths, and timestamps.
- Protect state-changing routes against CSRF if browser sessions are used.
- Use prepared SQL statements.
- Never trust `node_id`, status, or classification supplied by the browser. The backend must validate and compute classification.
- The bridge should authenticate to the local PHP API using a locally configured secret or another agreed local mechanism. Do not hardcode secrets in source control.

---

## 11. Python Serial Bridge Plan

The bridge is a separate Python 3.12 process using pySerial 3.5.

Responsibilities:
- Open the configured USB serial port.
- Reconnect safely if the port disconnects.
- Parse the agreed serial framing/protocol (to be supplied by the firmware team).
- Validate basic framing and forward received packets to the local PHP backend.
- Poll or receive queued downlink commands from PHP, according to the agreed interface.
- Write downlink commands to the Base Camp Node serial port.
- Report bridge heartbeat and serial errors.
- Log raw serial lines/frames for diagnostics, with sensitive information minimized.
- Never make geofence decisions; PHP owns that logic.
- Never create fake packets in real-hardware mode.

For the first website milestone, implement a mock bridge adapter and interface contract. Keep real serial transport disabled until the firmware serial protocol is agreed.

---

## 12. UI / UX Guidance

- Style: practical field-operations dashboard, not a marketing landing page.
- Prioritize legibility at a laptop screen in outdoor/base-camp conditions.
- Use clear text labels as well as color; never rely on color alone.
- Keep critical alerts visible without requiring the operator to scroll.
- Use distinct visual states:
  - Safe
  - Off trail
  - Link lost
  - Low battery
  - Emergency
  - System/bridge offline
- Use confirmation dialogs for storm warnings, polygon changes, and other consequential actions.
- Show timestamps consistently and indicate the local timezone.
- Show stale-data age.
- Avoid animated effects that distract from alarms.
- Provide a visible indicator when Demo/Simulation Mode is active.
- Make the map useful offline; if map tiles are missing, show a clear fallback rather than a blank map.

---

## 13. Demo / Simulation Mode

Codex should create a development-only simulation mode so the dashboard can be built and tested before the hardware is ready.

Simulation mode may:
- Generate sample hiker positions.
- Move a hiker inside/outside a sample polygon.
- Simulate packet reception and link loss.
- Simulate battery changes.
- Simulate direct vs checkpoint reception.
- Simulate downlink queued/sent/received state.

Simulation mode must:
- Be clearly labelled on every relevant page.
- Use separate demo data or a `test_mode` marker.
- Never mix simulated data into actual research logs without an explicit marker.
- Never imply that a simulated packet was received over LoRa.
- Be easy to disable for real-hardware testing.

---

## 14. Development Milestones

### Milestone 0 — Repository inspection and architecture
- Inspect existing code and assets.
- Confirm PHP version, XAMPP setup, MariaDB availability, map library, and current folder structure.
- Produce the proposed file tree and implementation plan.
- List all unresolved protocol questions.

### Milestone 1 — Static dashboard shell
- Build navigation and dashboard layout.
- Add sample hiker cards, alert panel, system status, and map placeholder.
- Responsive desktop/tablet layout.
- Clearly label all data as demo data.

### Milestone 2 — Database and geofence
- Create schema and seed data.
- Implement safe-zone CRUD/validation.
- Implement and unit-test ray-casting Point-in-Polygon.
- Store position events and classification results.

### Milestone 3 — Local PHP API
- Implement status, hiker, position, event, log, safe-zone, and command endpoints.
- Add validation and error handling.
- Add CSV export.

### Milestone 4 — Live dashboard data
- Connect the UI to the local API.
- Refresh live status using polling initially; consider Server-Sent Events only if it is compatible with the local XAMPP setup and adds real value.
- Update map markers and status cards.
- Implement alert sounds and operator-facing alert state.

### Milestone 5 — Bridge integration contract
- Define the serial frame format with the ESP32 firmware team.
- Implement the Python bridge adapter.
- Add bridge health monitoring and reconnect behavior.
- Test uplink ingest and downlink command flow with a serial mock before using hardware.

### Milestone 6 — Hardware integration
- Connect Base Camp Node.
- Verify direct uplink, acknowledgement, targeted off-trail alert, and downlink.
- Connect Checkpoint Node and verify relay metadata, duplicate handling, and downlink repeat.
- Verify two Hiker Nodes do not receive each other's targeted alerts.

### Milestone 7 — Research logs and validation
- Implement exports and research summaries.
- Verify that raw logs support the proposal's calculations.
- Test the three staged research phases without changing the proposal's test targets silently.

---

## 15. Decisions / Information Still Needed

Do not invent these. Ask the project team or leave clearly marked configuration placeholders.

1. **Existing repository:** What framework and files already exist? Preserve them if usable.
2. **Frontend choice:** The proposal specifies PHP/MariaDB but not a frontend framework. Prefer simple PHP-rendered pages with JavaScript unless the existing repository already uses another stack.
3. **Map library:** Select a library that supports local raster tiles and offline use. Confirm tile URL/path format and attribution requirements.
4. **Serial protocol:** Exact USB serial framing, baud rate, line endings, checksum, and message format are not specified in the proposal.
5. **LoRa binary protocol:** Exact byte order, signed coordinate encoding, scaling, flag bit assignments, packet type values, and checksum are not specified.
6. **Node IDs:** Confirm numeric IDs for Hiker Node 1, Hiker Node 2, Base Camp Node, and Checkpoint Node.
7. **Downlink routing:** Confirm the exact firmware rule for choosing direct vs Checkpoint relay and how the Base Camp Node knows the best route.
8. **Acknowledgement correlation:** Confirm whether the downlink echoes the uplink sequence number and how retries are represented.
9. **Alert retry behavior:** The proposal describes receive windows and repeated storm messages, but the exact retry/timeout policy for ordinary off-trail alerts needs firmware agreement.
10. **Authentication:** Decide whether the initial controlled laptop needs a login, and who can edit polygons or send storm messages.
11. **Offline map assets:** Supply or select the map tile package for the actual test locations. The website must not rely on online map tiles during field operation.
12. **Safe-zone edge rule:** Decide whether a point exactly on a polygon edge counts as inside or outside.
13. **Clock source:** Confirm how the Base Camp laptop and ESP32 nodes timestamp events and how timestamps are reconciled.
14. **Data retention/export:** Confirm the final directory for research data backups and the CSV format needed by the team.
15. **Design A/B selection:** The proposal compares BLE and USB-OTG designs. The dashboard should record the chosen design per Hiker Node, but should not decide which hardware design wins without test data.

---

## 16. Acceptance Criteria

The initial website is ready for hardware integration when:

- [ ] Dashboard loads locally with no internet connection.
- [ ] Two Hiker Nodes can be represented independently.
- [ ] The active safe-zone polygon is stored in MariaDB and drawn on the map.
- [ ] A backend unit test proves inside/outside ray-casting behavior for known test points.
- [ ] Each received position is stored with timestamp, sequence number, battery, GPS metadata, and reception path.
- [ ] Duplicate packets from direct and relay paths are represented as one logical uplink with both reception records retained.
- [ ] An outside coordinate creates an off-trail event and a targeted downlink command for the correct Hiker Node.
- [ ] An inside coordinate creates an acknowledgement command.
- [ ] Link loss is detected after the configured 15-second timeout while retaining the last known position and timestamp.
- [ ] Emergency flags create an emergency event.
- [ ] Storm turn-back commands can be queued for one hiker or all configured hikers and have traceable status.
- [ ] Logs can be filtered and exported to CSV.
- [ ] Demo data is visibly separated from real hardware data.
- [ ] Serial bridge disconnection is visible and does not crash the website.
- [ ] No functionality falsely claims a radio packet was delivered without a corresponding hardware/bridge event.

---

## 17. Recommended Initial File Structure

Adapt this to the repository after inspection. Do not force this structure if the project already has a working convention.

```text
project-root/
├── public/
│   ├── index.php
│   ├── assets/
│   │   ├── css/
│   │   ├── js/
│   │   └── sounds/
│   └── tiles/                 # Offline map tiles, if stored under web root
├── app/
│   ├── Controllers/
│   ├── Services/
│   │   ├── GeofenceService.php
│   │   ├── PacketIngestService.php
│   │   ├── AlertService.php
│   │   └── CommandService.php
│   ├── Repositories/
│   ├── Models/
│   └── Support/
├── config/
│   ├── app.php
│   └── database.php
├── routes/
│   └── api.php
├── database/
│   ├── migrations/
│   └── seeders/
├── bridge/
│   ├── serial_bridge.py
│   ├── adapters/
│   ├── config.example.json
│   └── requirements.txt
├── tests/
│   ├── unit/
│   └── integration/
├── storage/
│   ├── logs/
│   └── exports/
├── docs/
│   ├── CODEX_WEBSITE_SPEC.md
│   └── SERIAL_PROTOCOL.md
└── README.md
```

---

## 18. First Task for Codex

Start with **Milestone 0 and Milestone 1 only**:

1. Inspect the repository.
2. Report the existing stack and any reusable code.
3. Present a proposed file tree and concise build plan.
4. Identify missing protocol decisions.
5. Build the static dashboard shell with clearly marked demo data.
6. Do not implement guessed LoRa/serial packet formats.
7. Do not start hardware integration until the team supplies the actual serial protocol.

The first screen should communicate the main operating picture immediately: offline trail map + safe-zone polygon + two hiker markers + each hiker's current state + latest alerts + Base Camp/Checkpoint/bridge connection health.
