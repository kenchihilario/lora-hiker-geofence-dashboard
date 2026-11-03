-- Downlink route and bridge timing, reception duplicate flag, and storm campaigns.
-- This stores application timestamps and logical routing only; no radio or USB frame is defined.
ALTER TABLE packet_receptions
  ADD COLUMN IF NOT EXISTS is_duplicate TINYINT(1) NOT NULL DEFAULT 0;
UPDATE packet_receptions r
JOIN (
  SELECT id,ROW_NUMBER() OVER(PARTITION BY uplink_packet_id ORDER BY id) reception_number
  FROM packet_receptions
) ranked ON ranked.id=r.id
SET r.is_duplicate=IF(ranked.reception_number>1,1,0);

ALTER TABLE downlink_commands
  ADD COLUMN IF NOT EXISTS route_type ENUM('DIRECT','CHECKPOINT_RELAY') NOT NULL DEFAULT 'DIRECT',
  ADD COLUMN IF NOT EXISTS route_via_node_id VARCHAR(16) NULL,
  ADD COLUMN IF NOT EXISTS storm_campaign_id BIGINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS fetched_at DATETIME(3) NULL;
ALTER TABLE downlink_commands ADD INDEX IF NOT EXISTS ix_command_storm(storm_campaign_id);
ALTER TABLE downlink_commands
  MODIFY COLUMN status ENUM('QUEUED','SENT_TO_BASE','DELIVERED','RECEIVED','FAILED','TIMED_OUT','CANCELLED') NOT NULL DEFAULT 'QUEUED';

ALTER TABLE downlink_commands
  MODIFY COLUMN created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  MODIFY COLUMN sent_at DATETIME(3) NULL,
  MODIFY COLUMN delivered_at DATETIME(3) NULL,
  MODIFY COLUMN received_at DATETIME(3) NULL;

-- Backfill the relay route for existing commands where no direct reception exists.
UPDATE downlink_commands c
SET c.route_type='CHECKPOINT_RELAY',
    c.route_via_node_id=(
      SELECT r.checkpoint_id
      FROM packet_receptions r
      JOIN uplink_packets p ON p.id=r.uplink_packet_id
      WHERE p.site_id=c.site_id AND p.hiker_node_id=c.hiker_node_id
        AND p.sequence_number=c.sequence_number AND r.path_type='CHECKPOINT_RELAY'
      ORDER BY r.id DESC LIMIT 1
    )
WHERE EXISTS(
  SELECT 1 FROM packet_receptions r
  JOIN uplink_packets p ON p.id=r.uplink_packet_id
  WHERE p.site_id=c.site_id AND p.hiker_node_id=c.hiker_node_id
    AND p.sequence_number=c.sequence_number AND r.path_type='CHECKPOINT_RELAY'
)
AND NOT EXISTS(
  SELECT 1 FROM packet_receptions r
  JOIN uplink_packets p ON p.id=r.uplink_packet_id
  WHERE p.site_id=c.site_id AND p.hiker_node_id=c.hiker_node_id
    AND p.sequence_number=c.sequence_number AND r.path_type='DIRECT'
);

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

INSERT IGNORE INTO app_settings(setting_key,setting_value) VALUES('phase_mode','PHASE1');
