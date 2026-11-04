<?php
// Creates one seeded disposable DB, runs the repo test suite through a scratch-only config, then drops it.
$root=dirname(__DIR__);$configFile=$root.'/config.php';if(!is_file($configFile)){fwrite(STDERR,"REFUSED: local config.php is required to obtain the MariaDB host/account.\n");exit(2);}
$local=require $configFile;$source=$local['db_profiles']['demo']??null;if(!is_array($source)){fwrite(STDERR,"REFUSED: demo database profile is missing.\n");exit(2);}
$name='hiker_dashboard_scratch_'.bin2hex(random_bytes(6));$runtime=$root.'/.runtime';if(!is_dir($runtime))mkdir($runtime,0700,true);$suffix=bin2hex(random_bytes(4));$configPath=$runtime.'/test-suite-config-'.$suffix.'.php';$modePath=$runtime.'/test-suite-mode-'.$suffix.'.json';$serverLog=$runtime.'/test-suite-server-'.$suffix.'.log';$server=null;$failed=[];
$config=['db_profiles'=>['demo'=>['db_host'=>$source['db_host'],'db_name'=>$name,'db_user'=>$source['db_user'],'db_pass'=>$source['db_pass']],'live'=>['db_host'=>$source['db_host'],'db_name'=>$name,'db_user'=>$source['db_user'],'db_pass'=>$source['db_pass']]],'bridge_tokens'=>['demo'=>'scratch-suite-demo-token','live'=>'scratch-suite-live-token'],'local_only'=>true];
file_put_contents($configPath,"<?php return ".var_export($config,true).";\n");file_put_contents($modePath,json_encode(['mode'=>'DEMO']));
$env=getenv();$env['TRAILWATCH_SCRATCH_TEST']='1';$env['TRAILWATCH_TEST_CONFIG']=$configPath;$env['TRAILWATCH_TEST_MODE_FILE']=$modePath;
function suite_run(array $command,string $cwd,array $env,?string $logPath=null): int { $pipes=[];$descriptors=[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];$proc=proc_open($command,$descriptors,$pipes,$cwd,$env);if(!is_resource($proc)){fwrite(STDERR,"Could not start: ".basename((string)($command[1]??$command[0]))."\n");return 127;}fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);$status=proc_close($proc);if($out!=='')echo $out;if($err!=='')fwrite(STDERR,$err);return $status; }
function suite_http_ready(string $url): bool {$ctx=stream_context_create(['http'=>['timeout'=>1,'ignore_errors'=>true]]);$body=@file_get_contents($url,false,$ctx);return is_string($body)&&str_contains($body,'hiker_dashboard_scratch_');}
try{
  suite_run([PHP_BINARY,$root.'/tools/setup_db.php','--profile=demo','--config='.$configPath],$root,$env)===0||throw new RuntimeException('Scratch schema+demo seed setup failed.');
  suite_run([PHP_BINARY,$root.'/tools/migrate_db.php','--profile=demo','--config='.$configPath],$root,$env)===0||throw new RuntimeException('Scratch migration setup failed.');
  $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);if(!$socket)throw new RuntimeException('Could not reserve a local test port.');$address=stream_socket_get_name($socket,false);$port=(int)substr(strrchr($address,':'),1);fclose($socket);$baseUrl='http://127.0.0.1:'.$port;
  $pipes=[];$server=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,'-t',$root.'/public',$root.'/public/router.php'],[0=>['pipe','r'],1=>['file',$serverLog,'a'],2=>['file',$serverLog,'a']],$pipes,$root,$env);if(!is_resource($server))throw new RuntimeException('Could not start the loopback scratch API server.');fclose($pipes[0]);
  $ready=false;for($i=0;$i<50;$i++){if(suite_http_ready($baseUrl.'/api/status')){$ready=true;break;}usleep(100000);}if(!$ready)throw new RuntimeException('Scratch API server did not report the scratch database.');
  $phpTests=['geofence_test.php','polygon_validation_test.php','research_summary_test.php','mode_safety_test.php','live_check_test.php','registry_service_test.php','clear_demo_scratch_test.php','live_schema_scratch_test.php','migration_scratch_test.php'];
  foreach($phpTests as $test){$status=suite_run([PHP_BINARY,$root.'/tests/'.$test],$root,$env);if($status!==0)$failed[]=$test;}
  $token=$config['bridge_tokens']['demo'];$status=suite_run([PHP_BINARY,$root.'/tests/api_smoke.php',$baseUrl,$token],$root,$env);if($status!==0)$failed[]='api_smoke.php';
  foreach(['gpx_corridor_test.js','dashboard_packet_log_contract_test.js','live_empty_dashboard_test.js','offline_static_test.js'] as $test){$status=suite_run(['node',$root.'/tests/'.$test],$root,$env);if($status!==0)$failed[]=$test;}
  $pshell=getenv('SystemRoot').'\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';$status=suite_run([$pshell,'-NoProfile','-ExecutionPolicy','Bypass','-File',$root.'/tests/site_safe_zone_smoke.ps1','-BaseUrl',$baseUrl.'/api','-Database',$name],$root,$env);if($status!==0)$failed[]='site_safe_zone_smoke.ps1';
  // Give the integration harness an isolated LIVE mode file; its LIVE DB profile is still scratch-only.
  file_put_contents($modePath,json_encode(['mode'=>'LIVE']));$liveEnv=$env;$liveEnv['TRAILWATCH_TEST_MODE_FILE']=$modePath;
  $status=suite_run([PHP_BINARY,$root.'/tests/live_first_run_scratch_test.php'],$root,$liveEnv);if($status!==0)$failed[]='live_first_run_scratch_test.php';
}catch(Throwable $e){fwrite(STDERR,'SCRATCH SUITE SETUP FAIL: '.$e->getMessage()."\n");$failed[]='scratch suite setup';}
finally{
  if(is_resource($server)){proc_terminate($server);proc_close($server);}
  try{$admin=new PDO('mysql:host='.$source['db_host'].';charset=utf8mb4',$source['db_user'],$source['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);if(preg_match('/^hiker_dashboard_scratch_[a-f0-9]{12}$/',$name))$admin->exec('DROP DATABASE IF EXISTS `'.$name.'`');}catch(Throwable $e){fwrite(STDERR,"CLEANUP FAIL: could not drop generated scratch database.\n");$failed[]='scratch database cleanup';}
  foreach([$configPath,$modePath,$serverLog] as $file)if(is_file($file))@unlink($file);
}
if($failed){fwrite(STDERR,"FAILED: ".implode(', ',$failed)."\n");exit(1);}echo "PASS all PHP, Node, and PowerShell tests against disposable $name; database dropped.\n";
