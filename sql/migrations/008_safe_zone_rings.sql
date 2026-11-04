-- A safe zone may have an outer ring and one inner ring (a loop-trail corridor: the middle is NOT safe).
-- Existing zones keep working: every existing vertex becomes ring 0 (the outer ring).
-- Idempotent. The new unique index is added BEFORE the old one is dropped, because the foreign key on
-- safe_zone_id needs an index whose first column is safe_zone_id at all times.
ALTER TABLE safe_zone_vertices ADD COLUMN IF NOT EXISTS ring_index SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER safe_zone_id;

SET @new_idx=(SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='safe_zone_vertices' AND index_name='uq_vertex_ring_order');
SET @ddl=IF(@new_idx=0,'ALTER TABLE safe_zone_vertices ADD UNIQUE INDEX uq_vertex_ring_order(safe_zone_id,ring_index,vertex_order)','SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @old_idx=(SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='safe_zone_vertices' AND index_name='uq_vertex_order');
SET @ddl=IF(@old_idx>0,'ALTER TABLE safe_zone_vertices DROP INDEX uq_vertex_order','SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
