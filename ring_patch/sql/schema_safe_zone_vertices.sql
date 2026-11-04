-- Replace the existing CREATE TABLE safe_zone_vertices block in sql/schema.sql with this one,
-- so fresh installs already have rings. Migration 008 stays idempotent on top of it.
CREATE TABLE IF NOT EXISTS safe_zone_vertices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  safe_zone_id INT UNSIGNED NOT NULL,
  ring_index SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  vertex_order SMALLINT UNSIGNED NOT NULL,
  latitude DECIMAL(10,7) NOT NULL,
  longitude DECIMAL(10,7) NOT NULL,
  UNIQUE KEY uq_vertex_ring_order(safe_zone_id, ring_index, vertex_order),
  CONSTRAINT fk_vertex_zone FOREIGN KEY(safe_zone_id) REFERENCES safe_zones(id) ON DELETE CASCADE
) ENGINE=InnoDB;
