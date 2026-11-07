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
