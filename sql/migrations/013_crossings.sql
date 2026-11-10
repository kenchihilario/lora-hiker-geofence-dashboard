CREATE TABLE IF NOT EXISTS crossings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  session_id BIGINT UNSIGNED NOT NULL,
  node_id VARCHAR(16) NOT NULL,
  crossing_no TINYINT UNSIGNED NOT NULL,
  gate_no TINYINT UNSIGNED NULL,
  gate_side ENUM('OUTER','FIELD') NULL,
  started_at DATETIME(3) NOT NULL,
  reentry_at DATETIME(3) NULL,
  ended_at DATETIME(3) NULL,
  remarks TEXT NULL,
  alert_latency_s DECIMAL(10,3) NULL,
  alarm_screen_on ENUM('Y','N') NULL,
  alert_noticed ENUM('Y','N') NULL,
  alert_understood ENUM('Y','N') NULL,
  UNIQUE KEY uq_crossing_session_node_no(session_id,node_id,crossing_no),
  KEY ix_crossing_session_window(session_id,node_id,started_at,reentry_at),
  CONSTRAINT fk_crossing_session
    FOREIGN KEY (session_id) REFERENCES test_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_crossing_node
    FOREIGN KEY (node_id) REFERENCES hiker_nodes(node_id) ON UPDATE CASCADE
) ENGINE=InnoDB;
