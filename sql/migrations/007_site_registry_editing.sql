-- Site-scoped editable radio nodes and verification state for reference markers.
ALTER TABLE network_nodes ADD COLUMN IF NOT EXISTS site_id VARCHAR(32) NOT NULL DEFAULT 'pup';
ALTER TABLE network_nodes ADD COLUMN IF NOT EXISTS is_verified TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE trail_reference_points ADD COLUMN IF NOT EXISTS is_verified TINYINT(1) NOT NULL DEFAULT 0;

SET @old_network_code_index=(SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='network_nodes' AND index_name='node_code');
SET @ddl=IF(@old_network_code_index>0,'ALTER TABLE network_nodes DROP INDEX node_code','SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @network_site_index=(SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='network_nodes' AND index_name='uq_network_site_code');
SET @ddl=IF(@network_site_index=0,'ALTER TABLE network_nodes ADD UNIQUE INDEX uq_network_site_code(site_id,node_code)','SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
CREATE TABLE IF NOT EXISTS site_locations (
  site_id VARCHAR(32) PRIMARY KEY,
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  is_verified TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
