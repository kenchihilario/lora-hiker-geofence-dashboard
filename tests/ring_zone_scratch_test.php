<?php
require_once __DIR__.'/../src/db.php';
try{require_disposable_test_profile();}catch(Throwable $e){fwrite(STDERR,"REFUSED: ".$e->getMessage()."\n");exit(2);}
require_once __DIR__.'/../src/Services/ApiService.php';

$base=db_profile('demo');$admin=utc_pdo('mysql:host='.$base['db_host'].';charset=utf8mb4',$base['db_user'],$base['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$names=['hiker_dashboard_scratch_'.bin2hex(random_bytes(6)),'hiker_dashboard_scratch_'.bin2hex(random_bytes(6))];$databases=[];$failed=false;
function ring_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";}
function ring_sql(PDO $pdo,string $sql):void{foreach(array_filter(array_map('trim',preg_split('/;\s*(?:\r?\n|$)/',$sql))) as $statement)$pdo->exec($statement);}
function ring_db(PDO $admin,array $base,string $name,bool $legacy):PDO{
    $admin->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo=utc_pdo('mysql:host='.$base['db_host'].';dbname='.$name.';charset=utf8mb4',$base['db_user'],$base['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    if($legacy){
        // Build the actual pre-008 shape by applying migrations 001 through 007 only.
        $pdo->exec('CREATE TABLE schema_migrations(migration VARCHAR(190) PRIMARY KEY,checksum CHAR(64) NOT NULL,applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
        $q=$pdo->prepare('INSERT IGNORE INTO schema_migrations(migration,checksum) VALUES(?,?)');
        $files=glob(__DIR__.'/../sql/migrations/*.sql');sort($files,SORT_STRING);
        foreach($files as $file){$name=basename($file);if(!preg_match('/^00[1-7]_/', $name))continue;ring_sql($pdo,file_get_contents($file));$q->execute([$name,hash_file('sha256',$file)]);}
        $count=(int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();if($count!==7)throw new RuntimeException("Pre-008 fixture expected seven applied migrations, got $count");
        $pdo->exec("INSERT INTO hiker_nodes(node_id,display_name) VALUES('H1','Scratch hiker')");
        $pdo->exec("INSERT INTO network_nodes(node_code,node_type,display_name) VALUES('BASE','BASE_CAMP','Scratch base')");
        $pdo->exec("INSERT IGNORE INTO app_settings(setting_key,setting_value) VALUES('selected_site','pup'),('phase_mode','PHASE1')");
    }else{
        ring_sql($pdo,file_get_contents(__DIR__.'/../sql/schema.sql'));ring_sql($pdo,file_get_contents(__DIR__.'/../sql/demo_seed.sql'));
    }
    return $pdo;
}
function ring_coords(array $pairs):array{return array_map(fn($p)=>['latitude'=>$p[0],'longitude'=>$p[1]],$pairs);}
function ring_packet(int $sequence,float $lat,float $lon):array{return ['uplink'=>['node_id'=>'H1','sequence_number'=>$sequence,'latitude'=>$lat,'longitude'=>$lon,'gps_accuracy_m'=>5,'satellite_count'=>8,'battery_level'=>90,'flags'=>['link_lost'=>false,'low_battery'=>false,'emergency'=>false,'turn_back_received'=>false,'test_mode'=>false]],'reception'=>['base_id'=>'BASE','path_type'=>'DIRECT']];}
try{
    $newName=$names[0];$databases[]=$newName;$pdo=ring_db($admin,$base,$newName,false);
    $pdo->exec("INSERT INTO test_sessions(site_id,label,phase,design,tester_code,test_point) VALUES('pup','Ring integration session','PHASE1','A','SCRATCH','PUP')");
    $outer=ring_coords([[14.8000,121.0000],[14.8000,121.0020],[14.8020,121.0020],[14.8020,121.0000]]);
    $inner=ring_coords([[14.8005,121.0005],[14.8005,121.0015],[14.8015,121.0015],[14.8015,121.0005]]);
    $saved=save_zone($pdo,['site_id'=>'pup','name'=>'scratch ring corridor','is_demo'=>true,'rings'=>[$outer,$inner]]);
    ring_assert(count($saved['rings']??[])===2&&$saved['vertices']===$saved['rings'][0],'save_zone persists two ordered rings and active_zone returns outer ring as legacy vertices');
    foreach([[60001,14.8002,121.001,'INSIDE','band'],[60002,14.801,121.001,'OUTSIDE','middle hole'],[60003,14.803,121.001,'OUTSIDE','beyond outer edge']] as [$seq,$lat,$lon,$expected,$label]){$result=ingest_packet($pdo,ring_packet($seq,$lat,$lon));ring_assert($result['classification']===$expected,"packet in $label is $expected");if($expected==='OUTSIDE'){$q=$pdo->prepare("SELECT COUNT(*) FROM system_events WHERE event_type='OFF_TRAIL' AND metadata_json LIKE ?");$q->execute(['%"packet_id":'.$result['packet_id'].'%']);ring_assert((int)$q->fetchColumn()>0,"packet in $label emits OFF_TRAIL event");}}
    $legacy=save_zone($pdo,['site_id'=>'pup','name'=>'scratch legacy polygon','is_demo'=>true,'vertices'=>$outer]);ring_assert(count($legacy['rings']??[])===1&&$legacy['vertices']===$outer,'legacy vertices payload saves and loads as one ring');
    $invalid=[
      ['name'=>'crossing rings','data'=>['rings'=>[$outer,ring_coords([[14.8001,121.001],[14.801,121.001],[14.801,121.003],[14.8001,121.003]])]]],
      ['name'=>'inner ring outside outer','data'=>['rings'=>[$outer,ring_coords([[14.803,121.003],[14.803,121.004],[14.804,121.004],[14.804,121.003]])]]],
      ['name'=>'three rings','data'=>['rings'=>[$outer,$inner,$inner]]],
      ['name'=>'two-point ring','data'=>['rings'=>[$outer,ring_coords([[14.8007,121.0007],[14.8008,121.0008]])]]],
    ];
    foreach($invalid as $i=>$case){$data=array_merge(['site_id'=>'pup','name'=>'invalid '.$case['name'],'is_demo'=>true],$case['data']);$rejected=false;try{save_zone($pdo,$data);}catch(InvalidArgumentException $e){$rejected=true;}ring_assert($rejected,$case['name'].' is rejected');}

    $oldName=$names[1];$databases[]=$oldName;$oldPdo=ring_db($admin,$base,$oldName,true);
    ring_assert(!schema_column_exists($oldPdo,'safe_zone_vertices','ring_index')&&(int)$oldPdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn()===7,'pre-008 scratch database applied migrations 001–007 only and has no ring_index');
    $oldSingle=save_zone($oldPdo,['site_id'=>'pup','name'=>'legacy schema single ring','is_demo'=>true,'vertices'=>$outer]);ring_assert(count($oldSingle['rings']??[])===1,'pre-008 database still saves and loads a single-ring zone');
    $message='';try{save_zone($oldPdo,['site_id'=>'pup','name'=>'legacy schema ring','is_demo'=>true,'rings'=>[$outer,$inner]]);}catch(InvalidArgumentException $e){$message=$e->getMessage();}
    ring_assert(str_contains($message,'migration 008'),'pre-008 database refuses two rings with migration 008 guidance');
}catch(Throwable $e){fwrite(STDERR,'FAIL '.$e->getMessage()."\n");$failed=true;}
finally{foreach(array_reverse($databases) as $name){try{$admin->exec("DROP DATABASE IF EXISTS `$name`");}catch(Throwable $e){fwrite(STDERR,"CLEANUP FAIL: could not drop ring scratch DB.\n");$failed=true;}}}
if($failed)exit(1);
echo "PASS ring-zone scratch integration complete; disposable databases dropped.\n";
