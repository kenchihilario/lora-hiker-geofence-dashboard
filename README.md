# LoRa Hiker Geofence Dashboard

Local PHP/MariaDB dashboard with a **Demo / Simulation Mode** overview. The dashboard reads API-backed records and clearly marks demo data; it does not implement radio transport or parse serial frames.

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

4. Open http://127.0.0.1:8000. The dashboard reads local API data and keeps clearly marked demo records separate from hardware transport. No serial or LoRa framing is implemented.

## API endpoints

All responses are JSON. Bridge endpoints require `X-Bridge-Token` matching `bridge_token` in local config. The bridge submits the agreed logical JSON object only; actual USB serial framing and LoRa binary encoding remain unspecified.

- `GET /api/status`
- `GET /api/hikers`, `GET /api/hikers/{node_id}/latest`, `GET /api/positions/latest`
- `GET /api/events`, `GET /api/logs/packets` (support time range, hiker, type, severity, path, classification, validation, delivery, and demo filters; limit 1–200 and offset)
- `GET /api/safe-zone` (also returns configured network-node and trail reference markers), `PUT /api/safe-zone` with `{ "site_id": "pup", "name": "...", "vertices": [{"latitude": 14.8, "longitude": 121.0}] }`
- `POST /api/commands/storm-turn-back` with `{ "node_id": "H1", "test_mode": true }` or `{ "all": true, "test_mode": true }`
- `POST /api/events/{id}/resolve` records operator resolution without deleting or rewriting the original event
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
## Offline site maps and safe zones

The dashboard and safe-zone editor render an SVG on a plain background. They do not load Leaflet tiles, online map data, remote fonts, CDNs, or other map assets. The latitude/longitude database values remain the source coordinates; the display projects them to a local meter frame around the site center or polygon.

Three test sites are configured in `config/sites.php`:

- **PUP Sta. Mesa** has no confirmed center yet. Its center is intentionally `null`; enter a surveyed coordinate before using map clicks. The current SQL seed polygon is marked `DEMO ONLY` and is illustrative, not a surveyed PUP boundary.
- **PUP Mountain Prep (Demo)** is a separate, empty mountain-preparation profile. It has no assumed PUP coordinates or safe zone. Select it and import the surveyed mountain GPX when available; its polygon history is separate from the other sites.
- **Mt. Balagbag** uses the supplied peak coordinate `14.8243087, 121.1804131` (770 m). Base Camp and Checkpoint locations remain unset until confirmed.

Select a site from the dashboard, Settings, or Safe zone editor. Safe zones are versioned and active independently by site. In the editor, add coordinates in the table or use the local SVG to add/drag vertices; save creates a new version. GPX files are read locally in the browser and create a preview corridor using the selected half-width (5–50 m). The preview is not saved until the operator presses **Save as new version**. Self-intersecting polygons are rejected by both the editor and API.

For an existing database, run these once in order:

```bash
mysql -u root hiker_dashboard < sql/migrations/002_local_map_references.sql
mysql -u root hiker_dashboard < sql/migrations/003_site_aware_safe_zones.sql
```

The fresh-install `sql/schema.sql` already includes the site-aware tables. Add only verified Base Camp, Checkpoint, junction, rest-stop, and other reference coordinates to `config/sites.php` or the `trail_reference_points` table (each table row has a `site_id`). Do not treat demo coordinates as surveyed trail data.

Offline geometry checks:

```bash
node tests/gpx_corridor_test.js
php tests/geofence_test.php
php tests/polygon_validation_test.php
```

The GPX corridor test covers straight, L-shaped, and closed-loop tracks. API site/polygon checks can be run with `tests/site_safe_zone_smoke.ps1` against the configured disposable local database.
