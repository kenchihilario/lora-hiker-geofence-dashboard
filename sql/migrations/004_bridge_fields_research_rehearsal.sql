-- Logical bridge field mapping and research/rehearsal classification.
-- No radio packet lengths or USB serial framing are introduced here.
ALTER TABLE safe_zones ADD COLUMN IF NOT EXISTS is_demo TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE safe_zones ADD COLUMN IF NOT EXISTS is_rehearsal TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE uplink_packets ADD COLUMN IF NOT EXISTS site_id VARCHAR(32) NOT NULL DEFAULT 'pup';
ALTER TABLE uplink_packets ADD COLUMN IF NOT EXISTS is_demo TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE uplink_packets ADD COLUMN IF NOT EXISTS is_rehearsal TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE packet_receptions ADD COLUMN IF NOT EXISTS base_id VARCHAR(16) NOT NULL DEFAULT 'BASE';
ALTER TABLE packet_receptions ADD COLUMN IF NOT EXISTS checkpoint_id VARCHAR(16) NULL;
ALTER TABLE packet_receptions ADD COLUMN IF NOT EXISTS checkpoint_rssi_dbm SMALLINT NULL;
ALTER TABLE packet_receptions ADD COLUMN IF NOT EXISTS site_id VARCHAR(32) NOT NULL DEFAULT 'pup';
ALTER TABLE packet_receptions ADD COLUMN IF NOT EXISTS is_demo TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE packet_receptions ADD COLUMN IF NOT EXISTS is_rehearsal TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE downlink_commands ADD COLUMN IF NOT EXISTS site_id VARCHAR(32) NOT NULL DEFAULT 'pup';
ALTER TABLE downlink_commands ADD COLUMN IF NOT EXISTS base_id VARCHAR(16) NOT NULL DEFAULT 'BASE';
ALTER TABLE downlink_commands ADD COLUMN IF NOT EXISTS is_demo TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE downlink_commands ADD COLUMN IF NOT EXISTS is_rehearsal TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE system_events ADD COLUMN IF NOT EXISTS site_id VARCHAR(32) NOT NULL DEFAULT 'pup';
ALTER TABLE system_events ADD COLUMN IF NOT EXISTS is_demo TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE system_events ADD COLUMN IF NOT EXISTS is_rehearsal TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE operator_actions ADD COLUMN IF NOT EXISTS site_id VARCHAR(32) NOT NULL DEFAULT 'pup';
ALTER TABLE operator_actions ADD COLUMN IF NOT EXISTS is_demo TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE operator_actions ADD COLUMN IF NOT EXISTS is_rehearsal TINYINT(1) NOT NULL DEFAULT 0;

-- Associate legacy packets/receptions with their old zone (legacy site defaults to PUP).
UPDATE safe_zones SET is_demo=1 WHERE name LIKE 'DEMO ONLY%';
UPDATE safe_zones SET is_rehearsal=1 WHERE site_id='pup_mountain_prep';
UPDATE uplink_packets p LEFT JOIN safe_zones z ON z.id=p.safe_zone_id
 SET p.site_id=COALESCE(z.site_id,p.site_id,'pup'),p.is_demo=IF(p.test_mode=1,1,p.is_demo),p.is_rehearsal=COALESCE(z.is_rehearsal,p.is_rehearsal);
UPDATE packet_receptions r JOIN uplink_packets p ON p.id=r.uplink_packet_id
 SET r.site_id=p.site_id,r.is_demo=IF(r.test_mode=1 OR p.is_demo=1,1,r.is_demo),
     r.is_rehearsal=p.is_rehearsal,
     r.checkpoint_id=IF(r.path_type='CHECKPOINT_RELAY',r.received_by_node,NULL),
     r.checkpoint_rssi_dbm=IF(r.path_type='CHECKPOINT_RELAY',COALESCE(r.relay_rssi_dbm,r.rssi_dbm),NULL);
UPDATE downlink_commands c JOIN uplink_packets p
 ON p.hiker_node_id=c.hiker_node_id AND p.sequence_number=c.sequence_number
 SET c.site_id=p.site_id,c.is_demo=IF(c.test_mode=1 OR p.is_demo=1,1,c.is_demo),c.is_rehearsal=p.is_rehearsal;
UPDATE system_events e JOIN uplink_packets p
 ON p.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(e.metadata_json,'$.packet_id')) AS UNSIGNED)
 SET e.site_id=p.site_id,e.test_mode=GREATEST(e.test_mode,p.test_mode),
     e.is_demo=GREATEST(e.is_demo,p.is_demo),e.is_rehearsal=GREATEST(e.is_rehearsal,p.is_rehearsal);
UPDATE system_events SET is_demo=1 WHERE test_mode=1;
UPDATE operator_actions SET is_demo=1 WHERE test_mode=1;

-- Keep the hiker foreign-key index before replacing the old composite unique key.
ALTER TABLE uplink_packets ADD INDEX IF NOT EXISTS ix_uplink_hiker(hiker_node_id);

-- Scope sequence de-duplication to a site while retaining one logical uplink per hiker/sequence/site.
SET @old_packet_key=(SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='uplink_packets' AND index_name='uq_hiker_sequence');
SET @ddl=IF(@old_packet_key>0,'ALTER TABLE uplink_packets DROP INDEX uq_hiker_sequence','SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @new_packet_key=(SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='uplink_packets' AND index_name='uq_hiker_site_sequence');
SET @ddl=IF(@new_packet_key=0,'ALTER TABLE uplink_packets ADD UNIQUE INDEX uq_hiker_site_sequence(site_id,hiker_node_id,sequence_number)','SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
