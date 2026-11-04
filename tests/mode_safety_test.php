<?php
require_once __DIR__.'/../src/Services/ApiService.php';
try{require_disposable_test_profile();}catch(Throwable $e){fwrite(STDERR,"REFUSED: ".$e->getMessage()."\n");exit(2);}
function check_mode(bool $ok,string $message): void { if(!$ok){fwrite(STDERR,"FAIL $message\n");exit(1);}echo "PASS $message\n"; }
$root=dirname(__DIR__);$config=config();
check_mode(parse_active_mode(null)==='DEMO','missing mode file defaults to DEMO');
check_mode(parse_active_mode('{broken')==='DEMO'&&parse_active_mode('{"mode":"OTHER"}')==='DEMO','invalid mode file falls back to DEMO');
check_mode(parse_active_mode('{"mode":"LIVE"}')==='LIVE'&&parse_active_mode('{"mode":"DEMO"}')==='DEMO','valid mode selects requested profile');
check_mode(profile_name_for_mode(parse_active_mode('{"mode":"LIVE"}'))==='live'&&profile_name_for_mode(parse_active_mode('{"mode":"DEMO"}'))==='demo','mode file value resolves the matching DB profile');
check_mode(($config['db_profiles']['demo']['db_name']??null)==='hiker_dashboard_test_codex','DEMO profile points at the disposable test database');
check_mode(($config['db_profiles']['live']['db_name']??null)==='hiker_dashboard_live','LIVE profile points at the isolated live database');
$demo=['is_demo'=>true,'uplink'=>['flags'=>['test_mode'=>false]]];$rehearsal=['is_rehearsal'=>true,'uplink'=>['flags'=>[]]];$test=['uplink'=>['flags'=>['test_mode'=>true]]];$real=['uplink'=>['flags'=>['test_mode'=>false]]];
check_mode(should_reject_live_packet('LIVE',$demo)&&should_reject_live_packet('LIVE',$rehearsal)&&should_reject_live_packet('LIVE',$test)&&!should_reject_live_packet('LIVE',$real),'LIVE ingest predicate rejects demo, rehearsal, and test-mode envelopes');
check_mode(clear_demo_allowed('DEMO')&&!clear_demo_allowed('LIVE'),'clear demo data is blocked in LIVE mode');
check_mode(site_allowed_in_mode('LIVE',['is_demo'=>false,'is_rehearsal'=>false])&&!site_allowed_in_mode('LIVE',['is_rehearsal'=>true])&&site_allowed_in_mode('DEMO',['is_rehearsal'=>true]),'LIVE cannot select demo or rehearsal sites');
check_mode(simulator_allowed('DEMO')&&!simulator_allowed('LIVE'),'simulator is blocked in LIVE mode');
check_mode(is_local_remote_addr('127.0.0.1')&&is_local_remote_addr('::1')&&!is_local_remote_addr('192.168.1.4'),'local-only guard accepts loopback only');
check_mode(($config['local_only']??null)===true,'local-only guard defaults on in local configuration');
check_mode(gps_valid_fix(10,5)&&!gps_valid_fix(10.01,5)&&!gps_valid_fix(10,4)&&!gps_valid_fix(null,8),'proposal GPS valid-fix rule is derived and null-safe');
check_mode(source_label(['is_demo'=>1,'is_rehearsal'=>0,'test_mode'=>1])==='TEST'&&source_label(['is_demo'=>1,'is_rehearsal'=>1,'test_mode'=>0])==='REHEARSAL'&&source_label([])==='DEMO','export source labels distinguish rehearsal, test, demo, and real rows');
check_mode(source_label([], 'LIVE')==='REAL','LIVE rows without demo flags export as REAL');
$schema=file_get_contents($root.'/sql/schema.sql');
check_mode(!preg_match('/CREATE DATABASE|^\s*USE\s+/mi',$schema),'schema does not hard-code a database name');
check_mode(!str_contains($schema,'DEMO ONLY - Sample corridor')&&!preg_match('/INSERT\s+(?:IGNORE\s+)?INTO\s+(?:hiker_nodes|network_nodes|safe_zones|safe_zone_vertices)/i',$schema),'base schema has no demo hikers, nodes, or safe-zone seed');
check_mode(is_file($root.'/sql/demo_seed.sql')&&str_contains(file_get_contents($root.'/sql/demo_seed.sql'),'DEMO ONLY - Sample corridor'),'demo seed is separated into its own file');
$api=file_get_contents($root.'/public/api/index.php');$rejectAt=strpos($api,'should_reject_live_packet(active_mode(),$incoming)');$connectAt=strpos($api,'$pdo=db();respond(ingest_packet($pdo,$incoming),201)');
check_mode($rejectAt!==false&&$connectAt!==false&&$rejectAt<$connectAt&&str_contains($api,'rejections.log'),'LIVE flagged ingest is logged and rejected before the DB connection/write branch');
$clearGuard=strpos($api,"!clear_demo_allowed(active_mode())");$normalDbOpen=strpos($api,'$pdo=db();',strpos($api,"!clear_demo_allowed(active_mode())"));
check_mode($clearGuard!==false&&$normalDbOpen!==false&&$clearGuard<$normalDbOpen,'LIVE clear-demo refusal occurs before opening a database connection');
echo "Mode safety and static live-schema checks complete.\n";
