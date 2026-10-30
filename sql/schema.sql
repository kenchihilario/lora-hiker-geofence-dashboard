CREATE DATABASE IF NOT EXISTS hiker_dashboard;
USE hiker_dashboard;

CREATE TABLE IF NOT EXISTS nodes (
  id    VARCHAR(16) PRIMARY KEY,
  role  ENUM('hiker','base','checkpoint') NOT NULL,
  label VARCHAR(64) NOT NULL
);

-- polygon_json is a list of [lat, lng] pairs
CREATE TABLE IF NOT EXISTS safe_zones (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(100) NOT NULL,
  polygon_json TEXT NOT NULL,
  is_active    TINYINT(1) NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS positions (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  hiker_id    VARCHAR(16) NOT NULL,
  lat         DOUBLE NOT NULL,
  lng         DOUBLE NOT NULL,
  heard_by    VARCHAR(16) NOT NULL,       -- which node heard this uplink (BASE or CHK)
  is_inside   TINYINT(1) NOT NULL,
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (hiker_id, received_at)
);

CREATE TABLE IF NOT EXISTS alerts (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  hiker_id      VARCHAR(16) NOT NULL,
  position_id   INT NOT NULL,
  message       VARCHAR(255) NOT NULL,
  downlink_sent TINYINT(1) NOT NULL DEFAULT 0,  -- set by the serial bridge once the LoRa downlink goes out
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT IGNORE INTO nodes (id, role, label) VALUES
  ('H1',  'hiker',      'Hiker Node 1'),
  ('H2',  'hiker',      'Hiker Node 2'),
  ('BASE','base',       'Base Camp Node'),
  ('CHK', 'checkpoint', 'Checkpoint Node');

-- SAMPLE ONLY: a made-up corridor, not the real trail. Replace with the real safe zone later.
INSERT INTO safe_zones (name, polygon_json, is_active) VALUES
  ('Sample corridor (placeholder)',
   '[[14.8000,121.0000],[14.8000,121.0006],[14.8060,121.0006],[14.8060,121.0000]]', 1);
