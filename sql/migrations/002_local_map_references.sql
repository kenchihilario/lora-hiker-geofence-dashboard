-- Existing installations: add coordinates and display-only trail references.
ALTER TABLE network_nodes ADD COLUMN IF NOT EXISTS latitude DECIMAL(10,7) NULL;
ALTER TABLE network_nodes ADD COLUMN IF NOT EXISTS longitude DECIMAL(10,7) NULL;
CREATE TABLE IF NOT EXISTS trail_reference_points (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(24) NOT NULL UNIQUE,
  label VARCHAR(100) NOT NULL,
  point_type ENUM('PEAK','JUNCTION','REST_STOP','OTHER') NOT NULL DEFAULT 'OTHER',
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  is_demo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;
