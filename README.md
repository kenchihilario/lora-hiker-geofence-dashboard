# LoRa Hiker Geofence Dashboard

Local PHP/MariaDB dashboard foundation. The current live overview is a static **Demo / Simulation Mode** shell. The Milestone 2 API stores records marked `test_mode`; it does not implement radio transport or parse serial frames.

## Setup

Requires PHP 8.0+ (PHP 8.2 is the proposal target) and MariaDB 10.4+.

1. Create the local database and tables, including clearly marked synthetic safe-zone sample data:

   ```bash
   mysql -u root < sql/schema.sql
   ```

2. Copy `config.example.php` to `config.php`, set the MariaDB credentials, and set a private `bridge_token` for local bridge endpoints. `config.php` is git-ignored.

3. Start the local server (router enables `/api/...` paths):

   ```bash
   php -S 127.0.0.1:8000 -t public public/router.php
   ```

4. Open http://127.0.0.1:8000. The dashboard remains the Milestone 1 illustrative shell; the API is separate and does not feed it yet.

## API endpoints

All responses are JSON. Bridge endpoints require `X-Bridge-Token` matching `bridge_token` in local config. The bridge submits the agreed logical JSON object only; actual USB serial framing and LoRa binary encoding remain unspecified.

- `GET /api/status`
- `GET /api/hikers`, `GET /api/hikers/{node_id}/latest`, `GET /api/positions/latest`
- `GET /api/events`, `GET /api/logs/packets` (support `limit`, `offset`, and basic filters)
- `GET /api/safe-zone`, `PUT /api/safe-zone` with `{ "name": "...", "vertices": [{"latitude": 14.8, "longitude": 121.0}] }`
- `POST /api/commands/storm-turn-back` with `{ "node_id": "H1", "test_mode": true }` or `{ "all": true, "test_mode": true }`
- `POST /api/bridge/ingest`, `POST /api/bridge/status`
- `GET /api/commands/pending`, `POST /api/commands/{id}/status`
- `GET /api/research/summary` (descriptive counts only)

Ingest JSON follows the spec's logical packet fields (`node_id`, `sequence_number`, coordinates and optional GPS/battery/flags/reception metadata). It is not a wire protocol. Receptions are stored separately so direct and relay copies can be attached to one logical packet. Duplicate `(node_id, sequence_number)` packets are marked and their reception records retained. Valid classified positions queue a targeted ACK or off-trail alert record; nothing transmits it.

Safe-zone edge behavior is explicitly defined: points on an edge count as inside. Updating a zone creates a new version and deactivates the prior version. Authentication for operator-facing actions is not included in this local prototype; bridge routes use the configured shared token.

## Validation

Run the independent ray-casting checks:

```bash
php tests/geofence_test.php
```

For endpoint smoke checks, use a disposable MariaDB database with the server running:

```bash
php tests/api_smoke.php http://127.0.0.1:8000 YOUR_BRIDGE_TOKEN
```

The endpoint smoke test changes the active safe zone and writes demo-marked records. Do not run it against research data.

## Existing backend

`public/ingest.php`, `src/report.php`, and `tools/mock_hike.php` are retained for the previous prototype workflow. They use the legacy `positions`/`alerts` tables from the earlier schema and are separate from the new API schema. Hardware integration remains out of scope until the firmware team supplies serial and packet formats.