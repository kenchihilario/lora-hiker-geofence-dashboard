<?php
require_once __DIR__.'/ApiService.php';

function live_check_status(bool $ok): string { return $ok ? 'PASS' : 'FAIL'; }

/** Pure report builder so every checklist rule can be exercised without a live database. */
function build_live_check_report(array $f): array {
    $checks = [];
    $add = static function(string $key,string $label,bool $ok,string $detail) use (&$checks): void {
        $checks[]=['key'=>$key,'label'=>$label,'status'=>live_check_status($ok),'detail'=>$detail];
    };
    $add('database','Database reachable',!empty($f['database_reachable']),!empty($f['database_reachable'])?'Connection succeeded.':'Connection failed.');
    $add('integrity','Database table integrity',!empty($f['integrity_ok']),$f['integrity_detail']??'Table integrity was not verified.');
    $add('migrations','Schema migrations current',!empty($f['migrations_current']),$f['migration_detail']??'Migration ledger was not verified.');
    $add('bridge_tokens','Bridge tokens configured and distinct',!empty($f['tokens_ok']),'Demo and LIVE token configuration is '.(!empty($f['tokens_ok'])?'present and distinct.':'missing, placeholder, or not distinct.'));
    $add('site','Selected site',!empty($f['site_id']),!empty($f['site_id'])?'Selected site: '.$f['site_id'].'.':'No valid site is selected.');
    $sessionActive=!empty($f['active_test_session']);
    $sessionDetail=$sessionActive?'Active test session: '.($f['active_test_session']['label']??'label unavailable').'.':'No active test session for the selected site; bridge uplinks will be rejected with HTTP 409.';
    $add('test_session','Active test session',$sessionActive,$sessionDetail);
    $add('hikers','Hiker registry',(int)($f['hiker_count']??0)>0,(int)($f['hiker_count']??0).' enabled hiker(s) registered.');
    $scoped=!array_key_exists('node_site_scoped',$f)||!empty($f['node_site_scoped']);
    $nodeOk=$scoped&&!empty($f['base_present'])&&!empty($f['base_located'])&&!empty($f['checkpoint_present'])&&!empty($f['checkpoint_located']);
    $add('network_nodes','Base Camp and Checkpoint nodes',$nodeOk,'Base Camp: '.(!empty($f['base_present'])?'registered':'missing').', '.(!empty($f['base_located'])?'coordinates set':'coordinates unset').'; Checkpoint: '.(!empty($f['checkpoint_present'])?'registered':'missing').', '.(!empty($f['checkpoint_located'])?'coordinates set':'coordinates unset').'.'.($scoped?'':' Legacy nodes are not associated with a site yet.'));
    $add('safe_zone','Active safe-zone polygon',!empty($f['safe_zone_active']),!empty($f['safe_zone_active'])?'An active polygon is configured.':'No active safe-zone polygon is configured.');
    $flagCount=(int)($f['flagged_rows']??-1);
    $add('flagged_rows','Flagged demo/rehearsal/test rows',$flagCount===0,$flagCount<0?'Flagged-row count was not verified.':$flagCount.' flagged row(s) found.');
    $add('local_guard','Local-only operator guard',!empty($f['local_guard']),!empty($f['local_guard'])?'Enabled.':'Disabled.');
    $backupAge=$f['backup_age_seconds']??null;
    $add('backup','LIVE backup available',$backupAge!==null,$backupAge===null?'No LIVE SQL backup was found under backups/<timestamp>/.':'Latest LIVE backup age: '.max(0,(int)$backupAge).' seconds.');
    return ['ok'=>!array_filter($checks,static fn($c)=>$c['status']!=='PASS'),'checked_at'=>gmdate('c'),'database'=>$f['database_name']??null,'checks'=>$checks];
}

function collect_live_check_facts(PDO $pdo,array $config,string $projectRoot): array {
    $facts=['database_reachable'=>false,'integrity_ok'=>false,'integrity_detail'=>'No table checks completed.','migrations_current'=>false,'migration_detail'=>'Migration ledger was not verified.','tokens_ok'=>false,'site_id'=>null,'active_test_session'=>false,'hiker_count'=>0,'base_present'=>false,'base_located'=>false,'checkpoint_present'=>false,'checkpoint_located'=>false,'safe_zone_active'=>false,'flagged_rows'=>-1,'local_guard'=>!empty($config['local_only']),'backup_age_seconds'=>null,'database_name'=>null];
    $pdo->query('SELECT 1')->fetchColumn();$facts['database_reachable']=true;
    $profile=$config['db_profiles']['live']??[];$facts['database_name']=$profile['db_name']??null;
    $demoToken=(string)($config['bridge_tokens']['demo']??'');$liveToken=(string)($config['bridge_tokens']['live']??'');
    $isConfigured=static fn(string $token): bool=>$token!==''&&!str_starts_with($token,'replace-with-');
    $facts['tokens_ok']=$isConfigured($demoToken)&&$isConfigured($liveToken)&&!hash_equals($demoToken,$liveToken);

    $tables=$pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type=\'BASE TABLE\' ORDER BY table_name')->fetchAll(PDO::FETCH_COLUMN);
    $errors=[];
    foreach($tables as $table){$quoted='`'.str_replace('`','``',(string)$table).'`';$rows=$pdo->query('CHECK TABLE '.$quoted)->fetchAll(PDO::FETCH_ASSOC);foreach($rows as $row){if(($row['Msg_type']??'')==='error'||(($row['Msg_type']??'')==='status'&&($row['Msg_text']??'')!=='OK'))$errors[]=$table.': '.($row['Msg_text']??'integrity check failed');}}
    $facts['integrity_ok']=count($tables)>0&&!$errors;
    $facts['integrity_detail']=$facts['integrity_ok']?'CHECK TABLE returned OK for '.count($tables).' table(s).':($errors?implode('; ',$errors):'No tables were found to check.');
    $requiredTables=['schema_migrations','app_settings','hiker_nodes','network_nodes','safe_zones','uplink_packets','packet_receptions','downlink_commands','storm_turnback_campaigns','system_events','operator_actions','trail_reference_points'];$missingTables=array_values(array_diff($requiredTables,$tables));
    if($missingTables){$facts['integrity_ok']=false;$facts['integrity_detail']='Missing required table(s): '.implode(', ',$missingTables);$facts['migration_detail']='Required table(s) are missing; schema migrations cannot be verified.';return $facts;}

    $expected=[];$missing=[];$files=glob($projectRoot.'/sql/migrations/*.sql')?:[];sort($files,SORT_STRING);$ledger=[];
    try{foreach($pdo->query('SELECT migration,checksum FROM schema_migrations')->fetchAll(PDO::FETCH_ASSOC) as $row)$ledger[$row['migration']]=$row['checksum'];}catch(Throwable $e){$files=[];}
    foreach($files as $file){$name=basename($file);$expected[]=$name;if(!isset($ledger[$name])||!hash_equals(hash_file('sha256',$file),(string)$ledger[$name]))$missing[]=$name;}
    $facts['migrations_current']=!$missing;
    $facts['migration_detail']=$missing?'Missing or checksum-mismatched: '.implode(', ',$missing):'Recorded '.count($expected).' migration(s), all checksums match.';

    $q=$pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key='selected_site'");$q->execute();$site=(string)($q->fetchColumn()?:'');$facts['site_id']=isset(site_config()[$site])?$site:null;
    if($facts['site_id']&&test_session_schema_available($pdo))$facts['active_test_session']=active_test_session($pdo,$site)?:false;
    $q=$pdo->prepare('SELECT COUNT(*) FROM hiker_nodes WHERE is_enabled=1');$facts['hiker_count']=(int)$q->fetchColumn();
    $facts['node_site_scoped']=schema_column_exists($pdo,'network_nodes','site_id');
    foreach(['BASE_CAMP'=>'base','CHECKPOINT'=>'checkpoint'] as $type=>$prefix){$sql='SELECT COUNT(*),COALESCE(SUM(latitude IS NOT NULL AND longitude IS NOT NULL),0) FROM network_nodes WHERE node_type=? AND is_enabled=1'.($facts['node_site_scoped']?' AND site_id=?':'');$q=$pdo->prepare($sql);$q->execute($facts['node_site_scoped']?[$type,$site]:[$type]);[$count,$located]=$q->fetch(PDO::FETCH_NUM);$facts[$prefix.'_present']=(int)$count>0;$facts[$prefix.'_located']=(int)$located>0;}
    $q=$pdo->prepare('SELECT COUNT(*) FROM safe_zones WHERE is_active=1 AND site_id=?');$q->execute([$site]);$facts['safe_zone_active']=(int)$q->fetchColumn()>0;
    $facts['flagged_rows']=demo_row_count($pdo);
    $dbFile=$projectRoot.'/backups';$backupFiles=[];foreach(glob($dbFile.'/*/hiker_dashboard_live.sql')?:[] as $file)if(is_file($file))$backupFiles[]=$file;
    if($backupFiles){usort($backupFiles,static fn($a,$b)=>filemtime($b)<=>filemtime($a));$facts['backup_age_seconds']=max(0,time()-filemtime($backupFiles[0]));}
    return $facts;
}

function live_check_report(PDO $pdo,array $config,string $projectRoot): array {
    return build_live_check_report(collect_live_check_facts($pdo,$config,$projectRoot));
}
