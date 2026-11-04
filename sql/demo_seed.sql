-- Synthetic DEMO / SIMULATION data. Import only into the demo database.
INSERT IGNORE INTO app_settings(setting_key,setting_value) VALUES('selected_site','pup'),('phase_mode','PHASE1');
INSERT IGNORE INTO hiker_nodes(node_id,display_name,connection_design) VALUES
 ('H1','Hiker Node 1','UNKNOWN'),('H2','Hiker Node 2','UNKNOWN');
INSERT IGNORE INTO network_nodes(node_code,node_type,display_name) VALUES
 ('BASE','BASE_CAMP','Base Camp Node'),('CHK','CHECKPOINT','Checkpoint Node');
INSERT IGNORE INTO system_status(component,state) VALUES ('BASE_CAMP','UNKNOWN'),('SERIAL_BRIDGE','UNKNOWN'),('DATABASE','ONLINE');
INSERT INTO safe_zones(name,version,site_id,is_demo,is_rehearsal,is_active,created_by)
 SELECT 'DEMO ONLY - Sample corridor',1,'pup',1,0,1,'demo-seed' WHERE NOT EXISTS(SELECT 1 FROM safe_zones WHERE site_id='pup');
INSERT INTO safe_zone_vertices(safe_zone_id,vertex_order,latitude,longitude)
 SELECT z.id,v.ord,v.lat,v.lng FROM safe_zones z
 JOIN (SELECT 1 ord,14.8000 lat,121.0000 lng UNION ALL SELECT 2,14.8000,121.0006 UNION ALL SELECT 3,14.8060,121.0006 UNION ALL SELECT 4,14.8060,121.0000) v
 WHERE z.version=1 AND z.name='DEMO ONLY - Sample corridor'
 AND NOT EXISTS(SELECT 1 FROM safe_zone_vertices x WHERE x.safe_zone_id=z.id);
INSERT IGNORE INTO nodes(id,role,label) VALUES ('H1','hiker','Hiker Node 1'),('H2','hiker','Hiker Node 2'),('BASE','base','Base Camp Node'),('CHK','checkpoint','Checkpoint Node');
