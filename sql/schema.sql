-- MariaDB 10.4 compatible schema. Select the configured database before importing.
-- Synthetic hikers and zones are in sql/demo_seed.sql and are never part of this schema.

CREATE TABLE IF NOT EXISTS hiker_nodes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  node_id VARCHAR(16) NOT NULL UNIQUE,
  display_name VARCHAR(100) NOT NULL,
  phone_model VARCHAR(100) NULL,
  android_version VARCHAR(40) NULL,
  app_version VARCHAR(40) NULL,
  firmware_version VARCHAR(40) NULL,
  connection_design ENUM('BLE','USB_OTG','UNKNOWN') NOT NULL DEFAULT 'UNKNOWN',
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS network_nodes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  node_code VARCHAR(16) NOT NULL,
  site_id VARCHAR(32) NOT NULL DEFAULT 'pup',
  node_type ENUM('BASE_CAMP','CHECKPOINT') NOT NULL,
  display_name VARCHAR(100) NOT NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  last_seen_at DATETIME NULL,
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  is_verified TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_network_site_code(site_id,node_code)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS trail_reference_points (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(24) NOT NULL,
  label VARCHAR(100) NOT NULL,
  point_type ENUM('PEAK','JUNCTION','REST_STOP','OTHER') NOT NULL DEFAULT 'OTHER',
  site_id VARCHAR(32) NOT NULL DEFAULT 'pup',
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  is_demo TINYINT(1) NOT NULL DEFAULT 1,
  is_verified TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_reference_site_code(site_id,code)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS safe_zones (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  version INT UNSIGNED NOT NULL,
  site_id VARCHAR(32) NOT NULL DEFAULT 'pup',
  is_demo TINYINT(1) NOT NULL DEFAULT 0,
  is_rehearsal TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by VARCHAR(100) NULL,
  margin_m DECIMAL(7,2) NULL,
  gpx_file_name VARCHAR(255) NULL,
  gpx_point_count INT UNSIGNED NULL,
  simplified_point_count INT UNSIGNED NULL,
  simplify_tolerance_m DECIMAL(9,3) NULL,
  simplify_max_deviation_m DECIMAL(9,3) NULL,
  recorded_track_length_m DECIMAL(12,2) NULL,
  ground_length_m DECIMAL(12,2) NULL,
  recording_note TEXT NULL,
  UNIQUE KEY uq_zone_site_version(site_id,version), KEY ix_zone_active(site_id,is_active, id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS app_settings (
  setting_key VARCHAR(64) PRIMARY KEY,
  setting_value VARCHAR(255) NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
INSERT IGNORE INTO app_settings(setting_key,setting_value) VALUES('selected_site','pup'),('phase_mode','PHASE1'),('bench_session_enabled','0');
CREATE TABLE IF NOT EXISTS test_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  site_id VARCHAR(32) NOT NULL,
  label VARCHAR(100) NOT NULL,
  phase VARCHAR(32) NULL,
  design ENUM('A','B') NULL,
  tester_code VARCHAR(64) NULL,
  test_point VARCHAR(120) NULL,
  started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  ended_at DATETIME(3) NULL,
  KEY ix_test_session_active(site_id,ended_at,started_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS site_locations (
  site_id VARCHAR(32) PRIMARY KEY,
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  is_verified TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
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
CREATE TABLE IF NOT EXISTS uplink_packets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hiker_node_id INT UNSIGNED NOT NULL,
  site_id VARCHAR(32) NOT NULL DEFAULT 'pup',
  sequence_number INT UNSIGNED NOT NULL,
  latitude DECIMAL(10,7) NOT NULL,
  longitude DECIMAL(10,7) NOT NULL,
  gps_accuracy_m DECIMAL(8,2) NULL,
  satellite_count SMALLINT UNSIGNED NULL,
  battery_level TINYINT UNSIGNED NULL,
  flags_json JSON NULL,
  classification ENUM('INSIDE','OUTSIDE','UNCLASSIFIED') NOT NULL DEFAULT 'UNCLASSIFIED',
  safe_zone_id INT UNSIGNED NULL,
  session_id BIGINT UNSIGNED NULL,
  received_at DATETIME NOT NULL,
  is_duplicate TINYINT(1) NOT NULL DEFAULT 0,
  validation_status ENUM('VALID','INVALID','LATE','DUPLICATE') NOT NULL DEFAULT 'VALID',
  raw_payload TEXT NULL,
  test_mode TINYINT(1) NOT NULL DEFAULT 0,
  is_demo TINYINT(1) NOT NULL DEFAULT 0,
  is_rehearsal TINYINT(1) NOT NULL DEFAULT 0,
  is_bench TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_uplink_hiker(hiker_node_id),
  UNIQUE KEY uq_hiker_site_session_sequence(site_id,hiker_node_id,session_id,sequence_number),
  KEY ix_uplink_received(received_at), KEY ix_uplink_class(classification), KEY ix_uplink_session(session_id),
  CONSTRAINT fk_uplink_hiker FOREIGN KEY(hiker_node_id) REFERENCES hiker_nodes(id),
  CONSTRAINT fk_uplink_zone FOREIGN KEY(safe_zone_id) REFERENCES safe_zones(id) ON DELETE SET NULL,
  CONSTRAINT fk_uplink_session FOREIGN KEY(session_id) REFERENCES test_sessions(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS packet_receptions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uplink_packet_id BIGINT UNSIGNED NOT NULL,
  session_id BIGINT UNSIGNED NULL,
  received_by_node VARCHAR(16) NOT NULL,
  base_id VARCHAR(16) NOT NULL DEFAULT 'BASE',
  checkpoint_id VARCHAR(16) NULL,
  checkpoint_rssi_dbm SMALLINT NULL,
  is_duplicate TINYINT(1) NOT NULL DEFAULT 0,
  site_id VARCHAR(32) NOT NULL DEFAULT 'pup',
  is_demo TINYINT(1) NOT NULL DEFAULT 0,
  is_rehearsal TINYINT(1) NOT NULL DEFAULT 0,
  is_bench TINYINT(1) NOT NULL DEFAULT 0,
  path_type ENUM('DIRECT','CHECKPOINT_RELAY','UNKNOWN') NOT NULL DEFAULT 'UNKNOWN',
  relay_rssi_dbm SMALLINT NULL,
  rssi_dbm SMALLINT NULL,
  snr_db DECIMAL(6,2) NULL,
  received_at DATETIME NOT NULL,
  relay_hop_delay_ms INT UNSIGNED NULL,
  raw_metadata JSON NULL,
  test_mode TINYINT(1) NOT NULL DEFAULT 0,
  KEY ix_reception_packet(uplink_packet_id), KEY ix_reception_time(received_at), KEY ix_reception_session(session_id),
  CONSTRAINT fk_reception_packet FOREIGN KEY(uplink_packet_id) REFERENCES uplink_packets(id) ON DELETE CASCADE,
  CONSTRAINT fk_reception_session FOREIGN KEY(session_id) REFERENCES test_sessions(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS downlink_commands (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hiker_node_id INT UNSIGNED NOT NULL,
  site_id VARCHAR(32) NOT NULL DEFAULT 'pup',
  base_id VARCHAR(16) NOT NULL DEFAULT 'BASE',
  sequence_number INT UNSIGNED NULL,
  storm_campaign_id BIGINT UNSIGNED NULL,
  session_id BIGINT UNSIGNED NULL,
  route_type ENUM('DIRECT','CHECKPOINT_RELAY') NOT NULL DEFAULT 'DIRECT',
  route_via_node_id VARCHAR(16) NULL,
  command_type ENUM('ACK','OFF_TRAIL_ALERT','STORM_TURN_BACK','SOS_ACK') NOT NULL,
  payload_json JSON NULL,
  status ENUM('QUEUED','SENT_TO_BASE','DELIVERED','RECEIVED','FAILED','TIMED_OUT','CANCELLED') NOT NULL DEFAULT 'QUEUED',
  test_mode TINYINT(1) NOT NULL DEFAULT 0,
  is_demo TINYINT(1) NOT NULL DEFAULT 0,
  is_rehearsal TINYINT(1) NOT NULL DEFAULT 0,
  is_bench TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  fetched_at DATETIME(3) NULL,
  sent_at DATETIME(3) NULL,
  delivered_at DATETIME(3) NULL,
  received_at DATETIME(3) NULL,
  failure_reason VARCHAR(500) NULL,
  KEY ix_command_status(status,created_at), KEY ix_command_hiker(hiker_node_id,created_at), KEY ix_command_storm(storm_campaign_id), KEY ix_command_session(session_id),
  CONSTRAINT fk_command_hiker FOREIGN KEY(hiker_node_id) REFERENCES hiker_nodes(id),
  CONSTRAINT fk_command_session FOREIGN KEY(session_id) REFERENCES test_sessions(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS storm_turnback_campaigns (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hiker_node_id INT UNSIGNED NOT NULL,
  site_id VARCHAR(32) NOT NULL DEFAULT 'pup',
  base_id VARCHAR(16) NOT NULL DEFAULT 'BASE',
  status ENUM('ACTIVE','RECEIVED','CANCELLED','DISABLED') NOT NULL DEFAULT 'ACTIVE',
  test_mode TINYINT(1) NOT NULL DEFAULT 0,
  is_demo TINYINT(1) NOT NULL DEFAULT 0,
  is_rehearsal TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  last_queued_at DATETIME(3) NULL,
  received_at DATETIME(3) NULL,
  cancelled_at DATETIME(3) NULL,
  disabled_at DATETIME(3) NULL,
  KEY ix_storm_active(site_id,hiker_node_id,status,created_at),
  CONSTRAINT fk_storm_hiker FOREIGN KEY(hiker_node_id) REFERENCES hiker_nodes(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS system_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hiker_node_id INT UNSIGNED NULL,
  session_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(50) NOT NULL,
  severity ENUM('INFO','WARNING','CRITICAL','ERROR') NOT NULL DEFAULT 'INFO',
  message VARCHAR(500) NOT NULL,
  metadata_json JSON NULL,
  test_mode TINYINT(1) NOT NULL DEFAULT 0,
  is_demo TINYINT(1) NOT NULL DEFAULT 0,
  is_rehearsal TINYINT(1) NOT NULL DEFAULT 0,
  is_bench TINYINT(1) NOT NULL DEFAULT 0,
  site_id VARCHAR(32) NOT NULL DEFAULT 'pup',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  resolved_by VARCHAR(100) NULL,
  KEY ix_event_time(created_at), KEY ix_event_type(event_type), KEY ix_event_hiker(hiker_node_id,created_at), KEY ix_event_session(session_id),
  CONSTRAINT fk_event_hiker FOREIGN KEY(hiker_node_id) REFERENCES hiker_nodes(id) ON DELETE SET NULL,
  CONSTRAINT fk_event_session FOREIGN KEY(session_id) REFERENCES test_sessions(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS operator_actions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  action_type VARCHAR(50) NOT NULL,
  target_node_id INT UNSIGNED NULL,
  details_json JSON NULL,
  operator_id VARCHAR(100) NULL,
  test_mode TINYINT(1) NOT NULL DEFAULT 0,
  is_demo TINYINT(1) NOT NULL DEFAULT 0,
  is_rehearsal TINYINT(1) NOT NULL DEFAULT 0,
  is_bench TINYINT(1) NOT NULL DEFAULT 0,
  site_id VARCHAR(32) NOT NULL DEFAULT 'pup',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_action_time(created_at),
  CONSTRAINT fk_action_hiker FOREIGN KEY(target_node_id) REFERENCES hiker_nodes(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS system_status (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  component VARCHAR(32) NOT NULL UNIQUE,
  state ENUM('ONLINE','OFFLINE','UNKNOWN') NOT NULL DEFAULT 'UNKNOWN',
  last_seen_at DATETIME NULL,
  details_json JSON NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Backward-compatible tables used by the earlier mock/ingest workflow.
CREATE TABLE IF NOT EXISTS nodes (
  id VARCHAR(16) PRIMARY KEY,
  role ENUM('hiker','base','checkpoint') NOT NULL,
  label VARCHAR(64) NOT NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS positions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  hiker_id VARCHAR(16) NOT NULL,
  lat DOUBLE NOT NULL,
  lng DOUBLE NOT NULL,
  heard_by VARCHAR(16) NOT NULL,
  is_inside TINYINT(1) NOT NULL,
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX ix_legacy_position(hiker_id,received_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS alerts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  hiker_id VARCHAR(16) NOT NULL,
  position_id INT NOT NULL,
  message VARCHAR(255) NOT NULL,
  downlink_sent TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS schema_migrations (
  migration VARCHAR(190) PRIMARY KEY,
  checksum CHAR(64) NOT NULL,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS mode_switch_audit (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor VARCHAR(100) NOT NULL,
  from_mode ENUM('DEMO','LIVE') NOT NULL,
  to_mode ENUM('DEMO','LIVE') NOT NULL,
  switched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  from_database VARCHAR(128) NOT NULL,
  to_database VARCHAR(128) NOT NULL,
  KEY ix_mode_switch_time(switched_at)
) ENGINE=InnoDB;
