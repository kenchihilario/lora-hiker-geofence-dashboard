# Loop-trail safe-zone corridors

The safe-zone editor treats a GPX route as a corridor around the recorded trail.

- A **closed track** (last point within 0.5 m of the first) becomes two ordered rings: an outer boundary and an inner boundary. The zone between them is safe; the middle of the loop is outside the zone. The repeated closing point is removed before offsetting.
- An **open track** becomes one outline made from the two offset sides and the ends.
- Half-width is the margin from the track centerline on **each** side. The allowed value is 5–50 m. The tool refuses an offset that collapses a closed loop's inner edge.
- The proposal's valid-fix rule uses GPS accuracy at or below 10 m. That is a measurement acceptance rule, not a recommended corridor margin. For mountain preparation, consider a half-width around 15–20 m to allow for expected GPS error; inspect and survey the route before using it operationally.

Ring 0 is the outer boundary; ring 1, when present, is the hole/inner boundary. A safe zone has at most two rings and 500 vertices total. The database groups vertices by `safe_zone_id`, `ring_index`, and `vertex_order`. Older single-ring requests using `vertices: [...]` continue to work. Existing databases need migration `008_safe_zone_rings.sql` before saving two-ring zones; until then single-ring zones still work and a two-ring save returns a migration 008 message.

A preview does not save. Review the track, width, and simplification deviation, then explicitly save a new safe-zone version. This is geometry generation, not survey validation or a guarantee of field accuracy.
