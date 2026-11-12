CREATE TABLE IF NOT EXISTS position_error_checks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  session_id BIGINT UNSIGNED NOT NULL,
  node_id VARCHAR(16) NOT NULL,
  gate_no TINYINT UNSIGNED NOT NULL,
  window_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  inside_window_start DATETIME(3) NOT NULL,
  inside_window_end DATETIME(3) NOT NULL,
  inside_valid_fix_count INT UNSIGNED NOT NULL,
  inside_mean_latitude DECIMAL(10,7) NOT NULL,
  inside_mean_longitude DECIMAL(10,7) NOT NULL,
  outside_window_start DATETIME(3) NOT NULL,
  outside_window_end DATETIME(3) NOT NULL,
  outside_valid_fix_count INT UNSIGNED NOT NULL,
  outside_mean_latitude DECIMAL(10,7) NOT NULL,
  outside_mean_longitude DECIMAL(10,7) NOT NULL,
  tape_distance_m DECIMAL(8,3) NOT NULL,
  phone_distance_m DECIMAL(8,3) NOT NULL,
  error_m DECIMAL(8,3) NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY ix_position_error_session_node(session_id,node_id,gate_no,created_at),
  CONSTRAINT fk_position_error_session
    FOREIGN KEY (session_id) REFERENCES test_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_position_error_node
    FOREIGN KEY (node_id) REFERENCES hiker_nodes(node_id) ON UPDATE CASCADE,
  CONSTRAINT chk_position_error_gate CHECK (gate_no BETWEEN 1 AND 6),
  CONSTRAINT chk_position_error_tape CHECK (tape_distance_m > 0)
) ENGINE=InnoDB;
