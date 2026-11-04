<?php
require_once __DIR__.'/../src/db.php';
require_once __DIR__.'/../src/Services/ApiService.php';
try{require_disposable_test_profile();}catch(Throwable $e){fwrite(STDERR,"REFUSED: ".$e->getMessage()."\n");exit(2);}
$root=dirname(__DIR__);$base=db_profile('demo');$name='hiker_dashboard_scratch_'.bin2hex(random_bytes(4));$runtime=$root.'/.runtime';if(!is_dir($runtime))mkdir($runtime,0700,true);$configPath=$runtime.'/clear-demo-scratch-config.php';
$cfg=['db_profiles'=>['demo'=>['db_host'=>$base['db_host'],'db_name'=>$name,'db_user'=>$base['db_user'],'db_pass'=>$base['db_pass']],'live'=>['db_host'=>'127.0.0.1','db_name'=>'hiker_dashboard_live','db_user'=>'unused','db_pass'=>'']],'bridge_tokens'=>['demo'=>'unused','live'=>'unused'],'local_only'=>true];file_put_contents($configPath,"<?php return ".var_export($cfg,true).";\n");$failed=false;
try{
 $cmd=[PHP_BINARY,$root.'/tools/setup_db.php','--profile=demo','--schema-only','--config='.$configPath];$pipes=[];$proc=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);if(!is_resource($proc))throw new RuntimeException('Could not start scratch schema setup');fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);if(proc_close($proc)!==0)throw new RuntimeException($err?:'Scratch schema setup failed');
 $p=new PDO("mysql:host={$base['db_host']};dbname=$name;charset=utf8mb4",$base['db_user'],$base['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $p->exec("INSERT INTO positions(hiker_id,lat,lng,heard_by,is_inside) VALUES('LEGACY1',14.8,121.0,'BASE',1)");$positionId=(int)$p->lastInsertId();
 $p->exec("INSERT INTO alerts(hiker_id,position_id,message) VALUES('LEGACY1',$positionId,'Unclassified legacy alert')");$alertId=(int)$p->lastInsertId();
 $p->exec("INSERT INTO hiker_nodes(node_id,display_name) VALUES('DEMO1','Demo fixture')");$hikerId=(int)$p->lastInsertId();
 $q=$p->prepare("INSERT INTO uplink_packets(hiker_node_id,site_id,sequence_number,latitude,longitude,flags_json,classification,received_at,is_demo) VALUES(?,'pup',7,14.8,121.0,'{}','INSIDE',NOW(),1)");$q->execute([$hikerId]);$packetId=(int)$p->lastInsertId();
 $q=$p->prepare("INSERT INTO system_events(hiker_node_id,event_type,severity,message,metadata_json,is_demo,site_id) VALUES(?,'POSITION','INFO','Demo fixture',?,1,'pup')");$q->execute([$hikerId,json_encode(['packet_id'=>$packetId])]);
 $result=clear_demo_data($p);
 if($result['legacy_rows_kept']!==2||$result['legacy_rows_kept_detail']!==['positions'=>1,'alerts'=>1])throw new RuntimeException('Clear result did not report both unclassified legacy rows as kept.');
 foreach(['positions'=>$positionId,'alerts'=>$alertId] as $table=>$id){$q=$p->prepare("SELECT COUNT(*) FROM $table WHERE id=?");$q->execute([$id]);if((int)$q->fetchColumn()!==1)throw new RuntimeException("Unflagged legacy $table row was deleted.");}
 if((int)$p->query('SELECT COUNT(*) FROM uplink_packets')->fetchColumn()!==0||(int)$p->query('SELECT COUNT(*) FROM system_events')->fetchColumn()!==0)throw new RuntimeException('Flagged DEMO packet/event fixtures were not cleared.');
 echo "PASS: clear-demo operation removes flagged fixtures and preserves/reports unflagged legacy positions and alerts.\n";
}catch(Throwable $e){fwrite(STDERR,'FAIL '.$e->getMessage()."\n");$failed=true;}
finally{try{$admin=new PDO('mysql:host='.$base['db_host'].';charset=utf8mb4',$base['db_user'],$base['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$admin->exec("DROP DATABASE IF EXISTS `$name`");}catch(Throwable $e){fwrite(STDERR,"CLEANUP ERROR: clear-demo scratch database drop failed.\n");$failed=true;}@unlink($configPath);}
if($failed)exit(1);
