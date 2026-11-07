<?php
require_once __DIR__.'/../src/db.php';
require_once __DIR__.'/../src/Services/ApiService.php';
try{require_disposable_test_profile();}catch(Throwable $e){fwrite(STDERR,"REFUSED: ".$e->getMessage()."\n");exit(2);}
$root=dirname(__DIR__);$base=db_profile('demo');$name='hiker_dashboard_scratch_'.bin2hex(random_bytes(4));$runtime=$root.'/.runtime';if(!is_dir($runtime))mkdir($runtime,0700,true);$configPath=$runtime.'/migration-scratch-config.php';
$cfg=['db_profiles'=>['demo'=>['db_host'=>$base['db_host'],'db_name'=>$name,'db_user'=>$base['db_user'],'db_pass'=>$base['db_pass']],'live'=>['db_host'=>'127.0.0.1','db_name'=>'hiker_dashboard_live','db_user'=>'unused','db_pass'=>'']],'bridge_tokens'=>['demo'=>'unused','live'=>'unused'],'local_only'=>true];file_put_contents($configPath,"<?php return ".var_export($cfg,true).";\n");$failed=false;
try{
 $run=function(string $file,array $args)use($root){$cmd=array_merge([PHP_BINARY,$root.'/'.$file],$args);$pipes=[];$p=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);if(!is_resource($p))throw new RuntimeException('Could not start scratch utility');fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);if(proc_close($p)!==0)throw new RuntimeException($err?:'Scratch utility failed');return$out;};
 $run('tools/setup_db.php',['--profile=demo','--schema-only','--config='.$configPath]);
 $p=new PDO("mysql:host={$base['db_host']};dbname=$name;charset=utf8mb4",$base['db_user'],$base['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $p->exec("INSERT INTO hiker_nodes(node_id,display_name) VALUES('LEGACY','Legacy migration fixture')");$hiker=(int)$p->lastInsertId();
 $q=$p->prepare("INSERT INTO uplink_packets(hiker_node_id,site_id,sequence_number,latitude,longitude,received_at) VALUES(?,'pup',77,14.8,121.0,CURRENT_TIMESTAMP)");$q->execute([$hiker]);$packet=(int)$p->lastInsertId();
 $p->prepare("INSERT INTO packet_receptions(uplink_packet_id,received_by_node,site_id,received_at) VALUES(?,'BASE','pup',CURRENT_TIMESTAMP)")->execute([$packet]);
 $p->prepare("INSERT INTO downlink_commands(hiker_node_id,site_id,sequence_number,command_type) VALUES(?,'pup',77,'ACK')")->execute([$hiker]);
 $p->prepare("INSERT INTO system_events(hiker_node_id,event_type,severity,message,metadata_json,site_id) VALUES(?,'MIGRATION_FIXTURE','INFO','legacy row',?, 'pup')")->execute([$hiker,json_encode(['packet_id'=>$packet])]);
 // Recreate the schema shape before migration 010 while preserving the fixture records.
 foreach([['uplink_packets','fk_uplink_session'],['packet_receptions','fk_reception_session'],['downlink_commands','fk_command_session'],['system_events','fk_event_session']] as [$table,$fk])$p->exec("ALTER TABLE `$table` DROP FOREIGN KEY `$fk`");
 foreach([['uplink_packets','ix_uplink_session'],['packet_receptions','ix_reception_session'],['downlink_commands','ix_command_session'],['system_events','ix_event_session']] as [$table,$ix])$p->exec("ALTER TABLE `$table` DROP INDEX `$ix`");
 $p->exec('ALTER TABLE uplink_packets DROP INDEX uq_hiker_site_session_sequence, ADD UNIQUE INDEX uq_hiker_site_sequence(site_id,hiker_node_id,sequence_number)');
 foreach(['uplink_packets','packet_receptions','downlink_commands','system_events'] as $table)$p->exec("ALTER TABLE `$table` DROP COLUMN session_id");$p->exec('DROP TABLE test_sessions');
 // Exercise the actual ingest service on the pre-010 shape before applying any migration.
 $p->exec("INSERT INTO hiker_nodes(node_id,display_name) VALUES('PRE010','Pre-010 ingest fixture')");$pre010Hiker=(int)$p->lastInsertId();
 $pre010=['uplink'=>['node_id'=>'PRE010','sequence_number'=>78,'latitude'=>14.8,'longitude'=>121.0,'gps_accuracy_m'=>null,'satellite_count'=>null,'battery_level'=>null,'flags'=>['link_lost'=>false,'low_battery'=>false,'emergency'=>false,'turn_back_received'=>false,'test_mode'=>false]],'reception'=>['base_id'=>'BASE','path_type'=>'DIRECT'],'is_demo'=>true];
 $pre010Result=ingest_packet($p,$pre010);if(empty($pre010Result['packet_id'])||empty($pre010Result['command']['id']))throw new RuntimeException('Ingest failed on a schema before migration 010.');
 $q=$p->prepare('SELECT COUNT(*) FROM uplink_packets WHERE id=? AND hiker_node_id=?');$q->execute([(int)$pre010Result['packet_id'],$pre010Hiker]);if((int)$q->fetchColumn()!==1)throw new RuntimeException('Pre-010 ingest did not persist its uplink.');
 echo "PASS ingest persists uplink and reply on a schema without migration 010 or session columns.\n";
 $run('tools/migrate_db.php',['--profile=demo','--config='.$configPath]);$run('tools/migrate_db.php',['--profile=demo','--config='.$configPath]);
 $count=(int)$p->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();if($count!==10)throw new RuntimeException("Expected ten migration ledger rows after repeated run, got $count");
 foreach([['safe_zone_vertices','ring_index'],['uplink_packets','is_bench'],['uplink_packets','session_id']] as [$table,$column]){$q=$p->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');$q->execute([$table,$column]);if(!(int)$q->fetchColumn())throw new RuntimeException("Migration output missing $table.$column");}
 $q=$p->prepare('SELECT p.session_id,r.session_id reception_session,c.session_id command_session,e.session_id event_session FROM uplink_packets p JOIN packet_receptions r ON r.uplink_packet_id=p.id JOIN downlink_commands c ON c.hiker_node_id=p.hiker_node_id AND c.site_id=p.site_id AND c.sequence_number=p.sequence_number JOIN system_events e ON e.hiker_node_id=p.hiker_node_id WHERE p.id=?');$q->execute([$packet]);$row=$q->fetch();if(!$row||!$row['session_id']||count(array_unique(array_map('intval',$row)))!==1)throw new RuntimeException('Migration 010 did not backfill all legacy rows into the same site legacy session');
 echo "PASS migration runner applies ten ordered migrations, reruns safely, and migration 010 backfills packet/reception/command/event rows into one legacy session per site (disposable scratch database).\n";
}catch(Throwable $e){fwrite(STDERR,'FAIL '.$e->getMessage()."\n");$failed=true;}
finally{try{$admin=new PDO('mysql:host='.$base['db_host'].';charset=utf8mb4',$base['db_user'],$base['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$admin->exec("DROP DATABASE IF EXISTS `$name`");}catch(Throwable $e){fwrite(STDERR,"CLEANUP ERROR: scratch database drop failed.\n");$failed=true;}@unlink($configPath);}
if($failed)exit(1);
