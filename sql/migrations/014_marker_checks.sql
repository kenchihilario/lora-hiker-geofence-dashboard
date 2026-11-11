CREATE TABLE IF NOT EXISTS marker_checks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  session_id BIGINT UNSIGNED NOT NULL,
  node_id VARCHAR(16) NOT NULL,
  gate_no TINYINT UNSIGNED NOT NULL,
  marker_type ENUM('INSIDE','OUTSIDE') NOT NULL,
  window_start DATETIME(3) NOT NULL,
  window_end DATETIME(3) NOT NULL,
  window_seconds SMALLINT UNSIGNED NOT NULL,
  valid_fix_count INT UNSIGNED NOT NULL,
  mean_latitude DECIMAL(10,7) NULL,
  mean_longitude DECIMAL(10,7) NULL,
  classification ENUM('INSIDE','OUTSIDE','UNCLASSIFIED') NOT NULL,
  intended_class ENUM('INSIDE','OUTSIDE') NOT NULL,
  matches_intended TINYINT(1) NOT NULL,
  safe_zone_id INT UNSIGNED NULL,
  warning TEXT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY ix_marker_check_session_node(session_id,node_id,gate_no,marker_type,created_at),
  KEY ix_marker_check_window(session_id,node_id,window_start,window_end),
  CONSTRAINT fk_marker_check_session
    FOREIGN KEY (session_id) REFERENCES test_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_marker_check_node
    FOREIGN KEY (node_id) REFERENCES hiker_nodes(node_id) ON UPDATE CASCADE,
  CONSTRAINT fk_marker_check_zone
    FOREIGN KEY (safe_zone_id) REFERENCES safe_zones(id) ON DELETE SET NULL,
  CONSTRAINT chk_marker_check_gate CHECK (gate_no BETWEEN 1 AND 6)
) ENGINE=InnoDB;
