# Prompt for Codex: loop-trail corridor rings

Paste everything below the line into Codex. Put the `ring_patch` folder from this download somewhere Codex can read it (for example `D:\Projects for PC\ring_patch`).

---

You are applying a prepared patch to the Trailwatch dashboard so a closed-loop GPX track becomes a corridor RING (outer edge + inner edge, middle NOT safe) instead of a filled polygon. The patch folder is `ring_patch` (path given by the user). Work ONLY on the DEMO profile and disposable scratch databases. Do NOT migrate, read, or modify the LIVE database. Make a git commit (or a copy of the changed files) before you start so it can be reverted.

## Why
Today `bufferTrack()` pushes a closed loop outward from its centroid and the editor saves one flat list of vertices, so the whole oval is "safe". The red line on the oval is a TRAIL: the safe zone is a band of +/- half-width meters around it. Ring = [outer edge, inner edge]; `point_in_polygon` (already ring-aware in the patch) uses the even-odd rule, so the hole is outside.

## Apply these changes (copy from the patch, then do the manual edits)
1. Copy `ring_patch/src/geofence.php` over `src/geofence.php`. It is backward compatible: a flat list of points still means one ring.
2. Copy `ring_patch/public/assets/js/safe-zone-geometry.js` and `safe-zone.js` over the existing files in `public/assets/js/`.
3. Copy `ring_patch/sql/migrations/008_safe_zone_rings.sql` into `sql/migrations/`. Do not edit migrations 001-007.
4. In `sql/schema.sql`, replace the `CREATE TABLE IF NOT EXISTS safe_zone_vertices` block with the one in `ring_patch/sql/schema_safe_zone_vertices.sql`.
5. In `src/Services/ApiService.php`, follow the header comment of `ring_patch/src/Services/ApiService_ring_patch.php`: replace `active_zone()`, `save_zone()` and `polygon_self_intersects()` with the versions there (it also adds `rings_cross()`), and replace the single line in `ingest_packet()` that calls `point_in_polygon` with the commented one-liner at the bottom of that file. Confirm `schema_column_exists($pdo,$table,$column)` exists and is reachable from ApiService.php (it is used in `public/api/index.php`); if it lives somewhere ApiService.php cannot see, move or require it. Check how `InvalidArgumentException` is turned into an HTTP error in `public/api/index.php` and make sure the messages thrown by `save_zone()` reach the operator.
6. `public/safe-zone.php`: change `<polygon id="editor-poly"/>` to `<path id="editor-poly" fill-rule="evenodd"/>`, and bump the two script cache strings from `?v=20261104-1` to `?v=20261105-1`.
7. `public/index.php`: change the dashboard map element `<polygon id="api-zone" ...>` to `<path id="api-zone" ...>` (keep its other attributes). In `public/assets/css/dashboard.css`, check every selector that targets `#api-zone` or `#editor-poly` (including any `polygon#...` form) and make it work for a `path`; keep `fill-rule:evenodd`.
8. `public/assets/js/dashboard.js`:
   - line ~20: replace `const vertices=Array.isArray(zone?.vertices)?zone.vertices:[];` with
     `const rings=Array.isArray(zone?.rings)&&zone.rings.length?zone.rings:(Array.isArray(zone?.vertices)&&zone.vertices.length?[zone.vertices]:[]);const vertices=rings.flat();`
   - line ~25: `polygon.setAttribute('points','')` becomes `polygon.setAttribute('d','')`.
   - line ~36: replace `polygon.setAttribute('points',polyMeters.map(p=>`${x(p.x)},${y(p.y)}`).join(' '));` with
     `polygon.setAttribute('d',rings.filter(r=>r.length>=3).map(r=>'M'+r.map(project).map(p=>`${x(p.x)},${y(p.y)}`).join(' L ')+' Z').join(' '));`
     (keep the `polyMeters` variable if anything else uses it; otherwise remove it).
   - `grep -n "api-zone\|'points'\|polygon" public/assets/js/*.js tests/*.js` and fix anything else that assumed an SVG `<polygon>` or a flat `zone.vertices` for drawing.
9. Tests:
   - Copy `ring_patch/tests/geofence_test.php` over `tests/geofence_test.php` (same original cases plus ring cases).
   - Copy `ring_patch/tests/gpx_ring_test.js` to `tests/`. It uses the real PUP oval points. Register it in `tests/run_scratch_suite.php` if that runner lists test files explicitly.
   - In `tests/gpx_corridor_test.js` replace the line that checks the editor source with a whitespace-insensitive check:
     `const editorFlat=editor.replace(/\s+/g,'');assert.ok(editorFlat.includes('TrailGeometry.simplifyTrack(track,tolerance)')&&editorFlat.includes('measuredmaximumdeviation')&&editorFlat.includes('simplified.length>250'));`
   - `tests/migration_scratch_test.php`: expect EIGHT ledger rows and make sure 008 is applied twice without error (idempotent) and that `safe_zone_vertices.ring_index` exists afterwards.
   - Add `tests/ring_zone_scratch_test.php`, modeled on `tests/api_smoke.php` (disposable DB only, registered in the suite). It must cover: (a) `save_zone` with two rings (outer square, inner square) then `active_zone` returns `rings` with 2 rings and `vertices` equal to the outer ring; (b) a packet in the band is INSIDE, a packet in the middle (hole) is classified OFF_TRAIL, a packet beyond the outer edge is OFF_TRAIL; (c) the old payload `{vertices:[...]}` still saves and loads as one ring; (d) crossing rings, an inner ring not inside the outer ring, 3 rings, and a ring with 2 points are each rejected; (e) a database WITHOUT `ring_index` (apply only 001-007 in a scratch DB) still loads/saves single-ring zones and refuses two rings with the migration-008 message.
10. Docs: README and `docs/` mention "seven migrations" and one flat vertex list; update to eight and to rings. Add a short `docs/LOOP_CORRIDORS.md` explaining: closed track => outer + inner ring, middle outside; open track => one outline; the half-width is the margin on EACH side; min 5 m, and use at least the expected GPS error (the proposal's valid fix is 10 m accuracy, so about 15 to 20 m on the mountain).
11. Run `php tests/run_scratch_suite.php` (disposable scratch DB). Report the full PASS/FAIL counts. Do not edit the proposal DOCX.

## Report back (read-only summary, no secrets)
- The diff summary per file.
- The scratch suite result.
- Anything in steps 5-8 that did not match the patch assumptions (line numbers drifted, helper location, CSS selectors, tests that assumed a polygon element).
- Confirmation that the LIVE database was not touched, and the exact commands the user should run on LIVE later (backup first, then `tools/migrate_db.php`, then `tools/live_check.php`).
