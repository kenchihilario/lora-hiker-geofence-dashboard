-- Session-bounded packet sequence identity. The bridge envelope remains unchanged.
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

ALTER TABLE uplink_packets ADD COLUMN IF NOT EXISTS session_id BIGINT UNSIGNED NULL;
ALTER TABLE packet_receptions ADD COLUMN IF NOT EXISTS session_id BIGINT UNSIGNED NULL;
ALTER TABLE downlink_commands ADD COLUMN IF NOT EXISTS session_id BIGINT UNSIGNED NULL;
ALTER TABLE system_events ADD COLUMN IF NOT EXISTS session_id BIGINT UNSIGNED NULL;

INSERT INTO test_sessions(site_id,label,phase,design,tester_code,test_point,started_at,ended_at)
SELECT sites.site_id,'Legacy history (pre-session)',NULL,NULL,NULL,NULL,CURRENT_TIMESTAMP(3),CURRENT_TIMESTAMP(3)
FROM (
  SELECT site_id FROM uplink_packets UNION SELECT site_id FROM packet_receptions
  UNION SELECT site_id FROM downlink_commands UNION SELECT site_id FROM system_events
) sites
WHERE NOT EXISTS (SELECT 1 FROM test_sessions s WHERE s.site_id=sites.site_id AND s.label='Legacy history (pre-session)');

UPDATE uplink_packets p JOIN test_sessions s ON s.site_id=p.site_id AND s.label='Legacy history (pre-session)' SET p.session_id=s.id WHERE p.session_id IS NULL;
UPDATE packet_receptions r JOIN test_sessions s ON s.site_id=r.site_id AND s.label='Legacy history (pre-session)' SET r.session_id=s.id WHERE r.session_id IS NULL;
UPDATE downlink_commands c JOIN test_sessions s ON s.site_id=c.site_id AND s.label='Legacy history (pre-session)' SET c.session_id=s.id WHERE c.session_id IS NULL;
UPDATE system_events e JOIN test_sessions s ON s.site_id=e.site_id AND s.label='Legacy history (pre-session)' SET e.session_id=s.id WHERE e.session_id IS NULL;

SET @has_old_key=(SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='uplink_packets' AND index_name='uq_hiker_site_sequence');
SET @ddl=IF(@has_old_key>0,'ALTER TABLE uplink_packets DROP INDEX uq_hiker_site_sequence','SELECT 1'); PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_new_key=(SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='uplink_packets' AND index_name='uq_hiker_site_session_sequence');
SET @ddl=IF(@has_new_key=0,'ALTER TABLE uplink_packets ADD UNIQUE INDEX uq_hiker_site_session_sequence(site_id,hiker_node_id,session_id,sequence_number)','SELECT 1'); PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl=IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='uplink_packets' AND index_name='ix_uplink_session')=0,'ALTER TABLE uplink_packets ADD KEY ix_uplink_session(session_id)','SELECT 1'); PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='packet_receptions' AND index_name='ix_reception_session')=0,'ALTER TABLE packet_receptions ADD KEY ix_reception_session(session_id)','SELECT 1'); PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='downlink_commands' AND index_name='ix_command_session')=0,'ALTER TABLE downlink_commands ADD KEY ix_command_session(session_id)','SELECT 1'); PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='system_events' AND index_name='ix_event_session')=0,'ALTER TABLE system_events ADD KEY ix_event_session(session_id)','SELECT 1'); PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl=IF((SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='uplink_packets' AND constraint_name='fk_uplink_session')=0,'ALTER TABLE uplink_packets ADD CONSTRAINT fk_uplink_session FOREIGN KEY(session_id) REFERENCES test_sessions(id) ON DELETE SET NULL','SELECT 1'); PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='packet_receptions' AND constraint_name='fk_reception_session')=0,'ALTER TABLE packet_receptions ADD CONSTRAINT fk_reception_session FOREIGN KEY(session_id) REFERENCES test_sessions(id) ON DELETE SET NULL','SELECT 1'); PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='downlink_commands' AND constraint_name='fk_command_session')=0,'ALTER TABLE downlink_commands ADD CONSTRAINT fk_command_session FOREIGN KEY(session_id) REFERENCES test_sessions(id) ON DELETE SET NULL','SELECT 1'); PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='system_events' AND constraint_name='fk_event_session')=0,'ALTER TABLE system_events ADD CONSTRAINT fk_event_session FOREIGN KEY(session_id) REFERENCES test_sessions(id) ON DELETE SET NULL','SELECT 1'); PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
