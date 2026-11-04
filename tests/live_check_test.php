<?php
require_once __DIR__.'/../src/Services/LiveCheckService.php';
$all=['database_reachable'=>true,'integrity_ok'=>true,'integrity_detail'=>'fixture OK','migrations_current'=>true,'migration_detail'=>'fixture current','tokens_ok'=>true,'site_id'=>'balagbag','hiker_count'=>1,'base_present'=>true,'base_located'=>true,'checkpoint_present'=>true,'checkpoint_located'=>true,'safe_zone_active'=>true,'flagged_rows'=>0,'local_guard'=>true,'backup_age_seconds'=>1,'database_name'=>'scratch-fixture'];
$report=build_live_check_report($all);
if(!$report['ok']||count($report['checks'])!==11)throw new RuntimeException('Complete fixture should produce eleven passing checks.');
$cases=['database_reachable'=>'database','integrity_ok'=>'integrity','migrations_current'=>'migrations','tokens_ok'=>'bridge_tokens','site_id'=>'site','hiker_count'=>'hikers','base_present'=>'network_nodes','safe_zone_active'=>'safe_zone','flagged_rows'=>'flagged_rows','local_guard'=>'local_guard','backup_age_seconds'=>'backup'];
foreach($cases as $field=>$key){$bad=$all;$bad[$field]=$field==='site_id'?null:($field==='backup_age_seconds'?null:($field==='hiker_count'?0:($field==='flagged_rows'?3:false)));$result=build_live_check_report($bad);$row=array_values(array_filter($result['checks'],fn($c)=>$c['key']===$key))[0]??null;if(!$row||$row['status']!=='FAIL'||$result['ok'])throw new RuntimeException("$key readiness failure was not detected.");}
$bad=$all;$bad['checkpoint_located']=false;$result=build_live_check_report($bad);if(($result['checks'][6]['status']??null)!=='FAIL')throw new RuntimeException('Missing Checkpoint coordinates were not detected.');
echo "PASS read-only LIVE checker: all eleven checks pass and each failure case is detected.\n";
