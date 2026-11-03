-- Upgrade existing databases to site-specific polygons and reference points.
-- Idempotent for databases created from either the original or current schema.
ALTER TABLE safe_zones ADD COLUMN IF NOT EXISTS site_id VARCHAR(32) NOT NULL DEFAULT 'pup';
ALTER TABLE trail_reference_points ADD COLUMN IF NOT EXISTS site_id VARCHAR(32) NOT NULL DEFAULT 'pup';

SET @old_zone_index = (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='safe_zones' AND index_name='uq_zone_version');
SET @ddl = IF(@old_zone_index>0, 'ALTER TABLE safe_zones DROP INDEX uq_zone_version', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @new_zone_index = (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='safe_zones' AND index_name='uq_zone_site_version');
SET @ddl = IF(@new_zone_index=0, 'ALTER TABLE safe_zones ADD UNIQUE INDEX uq_zone_site_version(site_id,version)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
ALTER TABLE safe_zones ADD INDEX IF NOT EXISTS ix_zone_site_active(site_id,is_active,version);

SET @old_ref_index = (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='trail_reference_points' AND index_name='code');
SET @ddl = IF(@old_ref_index>0, 'ALTER TABLE trail_reference_points DROP INDEX code', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @new_ref_index = (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='trail_reference_points' AND index_name='uq_reference_site_code');
SET @ddl = IF(@new_ref_index=0, 'ALTER TABLE trail_reference_points ADD UNIQUE INDEX uq_reference_site_code(site_id,code)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS app_settings (
  setting_key VARCHAR(64) PRIMARY KEY,
  setting_value VARCHAR(255) NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
INSERT IGNORE INTO app_settings(setting_key,setting_value) VALUES('selected_site','pup');

-- Remove only the exact synthetic rows from the earlier illustrative map seed.
DELETE FROM trail_reference_points
 WHERE (code='PEAK' AND label='DEMO PEAK' AND latitude=14.8060 AND longitude=121.0003)
    OR (code='JUNCTION-1' AND label='DEMO JUNCTION' AND latitude=14.8020 AND longitude=121.0003)
    OR (code='REST-1' AND label='DEMO REST STOP' AND latitude=14.8040 AND longitude=121.0003);
