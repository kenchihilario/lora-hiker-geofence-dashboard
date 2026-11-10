CREATE TABLE IF NOT EXISTS packet_annotations (
  uplink_packet_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  session_id BIGINT UNSIGNED NULL,
  intended_class ENUM('INSIDE','OUTSIDE') NOT NULL,
  point_source ENUM('FROM_TRACK','MOVED_BEYOND_MARGIN') NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `operator` VARCHAR(100) NULL,
  KEY ix_packet_annotation_session(session_id, uplink_packet_id),
  CONSTRAINT fk_packet_annotation_packet
    FOREIGN KEY (uplink_packet_id) REFERENCES uplink_packets(id) ON DELETE CASCADE,
  CONSTRAINT fk_packet_annotation_session
    FOREIGN KEY (session_id) REFERENCES test_sessions(id) ON DELETE SET NULL
) ENGINE=InnoDB;
