<?php
require_once __DIR__.'/../src/db.php';
try{require_disposable_test_profile();}catch(Throwable $e){fwrite(STDERR,"REFUSED: ".$e->getMessage()."\n");exit(2);}
$root=dirname(__DIR__);$base=db_profile('demo');$name='hiker_dashboard_scratch_'.bin2hex(random_bytes(4));$runtime=$root.'/.runtime';if(!is_dir($runtime))mkdir($runtime,0700,true);$configPath=$runtime.'/scratch-config.php';
$testConfig=['db_profiles'=>['demo'=>['db_host'=>$base['db_host'],'db_name'=>$name,'db_user'=>$base['db_user'],'db_pass'=>$base['db_pass']],'live'=>['db_host'=>'127.0.0.1','db_name'=>'hiker_dashboard_live','db_user'=>'unused','db_pass'=>'']],'bridge_tokens'=>['demo'=>'unused','live'=>'unused'],'local_only'=>true];
file_put_contents($configPath,"<?php return ".var_export($testConfig,true).";\n");
$failed=false;
try{
 $cmd=[PHP_BINARY,$root.'/tools/setup_db.php','--profile=demo','--schema-only','--config='.$configPath];$pipes=[];$proc=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);if(!is_resource($proc))throw new RuntimeException('Could not launch schema setup');fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);if(proc_close($proc)!==0)throw new RuntimeException('Scratch schema setup failed: '.$err);
 $p=new PDO("mysql:host={$base['db_host']};dbname=$name;charset=utf8mb4",$base['db_user'],$base['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 foreach(['hiker_nodes','network_nodes','safe_zones','uplink_packets','system_events'] as $table)if((int)$p->query("SELECT COUNT(*) FROM $table")->fetchColumn()!==0)throw new RuntimeException("Schema-only scratch database unexpectedly seeded $table");
 echo "PASS live-profile schema shape is empty of demo hikers, nodes, zones, packets, and events (verified on disposable scratch DB).\n";
}catch(Throwable $e){fwrite(STDERR,'FAIL '.$e->getMessage()."\n");$failed=true;}
finally{
 try{$admin=new PDO('mysql:host='.$base['db_host'].';charset=utf8mb4',$base['db_user'],$base['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$admin->exec("DROP DATABASE IF EXISTS `$name`");}catch(Throwable $e){fwrite(STDERR,"CLEANUP ERROR: scratch database drop failed.\n");}
 @unlink($configPath);
}
if($failed)exit(1);
