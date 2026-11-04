-- BENCH is an explicit source class for unscored field/bench trials.
-- Defaults preserve existing records as non-BENCH; operators may classify ranges explicitly.
ALTER TABLE uplink_packets ADD COLUMN IF NOT EXISTS is_bench TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE packet_receptions ADD COLUMN IF NOT EXISTS is_bench TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE downlink_commands ADD COLUMN IF NOT EXISTS is_bench TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE system_events ADD COLUMN IF NOT EXISTS is_bench TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE operator_actions ADD COLUMN IF NOT EXISTS is_bench TINYINT(1) NOT NULL DEFAULT 0;
INSERT IGNORE INTO app_settings(setting_key,setting_value) VALUES('bench_session_enabled','0');
