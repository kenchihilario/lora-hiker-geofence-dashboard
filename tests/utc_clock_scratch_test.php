<?php
require_once __DIR__.'/../src/db.php';
try{require_disposable_test_profile();}catch(Throwable $e){fwrite(STDERR,"REFUSED: ".$e->getMessage()."\n");exit(2);}
$pdo=db();
$timezone=$pdo->query("SELECT @@session.time_zone session_zone,ABS(TIMESTAMPDIFF(SECOND,NOW(),UTC_TIMESTAMP())) clock_diff")->fetch();
if(($timezone['session_zone']??null)!=='+00:00'||(int)$timezone['clock_diff']>2)throw new RuntimeException('Scratch connection is not UTC or NOW differs from UTC_TIMESTAMP by more than 2 seconds.');
echo "PASS scratch MariaDB session uses +00:00 and NOW() matches UTC_TIMESTAMP() within 2 seconds\n";
$base=rtrim($argv[1]??'http://127.0.0.1:8000','/');$token=(string)(config()['bridge_tokens']['demo']??'');
function utc_clock_call(string $method,string $path,?array $payload=null,string $token=''): array {
    $headers="Content-Type: application/json\r\n";
    if($token!=='')$headers.="X-Bridge-Token: $token\r\n";
    $cookie=getenv('TRAILWATCH_TEST_SESSION_COOKIE')?:'';$csrf=getenv('TRAILWATCH_TEST_CSRF_TOKEN')?:'';
    if($cookie!=='')$headers.="Cookie: $cookie\r\nX-CSRF-Token: $csrf\r\n";
    $context=stream_context_create(['http'=>['method'=>$method,'header'=>$headers,'content'=>$payload===null?'':json_encode($payload,JSON_THROW_ON_ERROR),'ignore_errors'=>true,'timeout'=>8]]);
    $body=@file_get_contents($GLOBALS['base'].$path,false,$context);$statusLine=$http_response_header[0]??'HTTP/1.1 500';
    return ['status'=>(int)substr($statusLine,9,3),'body'=>is_string($body)?json_decode($body,true):null];
}
$clock=utc_clock_call('GET','/api/clock-check');
if($clock['status']!==200)throw new RuntimeException('Clock-check endpoint did not return HTTP 200.');
foreach(['server_utc_now','db_now','session_time_zone','global_time_zone','system_time_zone','php_timezone'] as $field)if(!array_key_exists($field,$clock['body']??[]))throw new RuntimeException("Clock-check response is missing $field.");
if($clock['body']['session_time_zone']!=='+00:00'||$clock['body']['php_timezone']!=='UTC')throw new RuntimeException('Clock-check response did not report the canonical UTC application clocks.');
echo "PASS clock-check endpoint returns all six clock fields\n";
$session=$pdo->query("SELECT id FROM test_sessions WHERE site_id='pup' AND ended_at IS NULL ORDER BY id DESC LIMIT 1")->fetchColumn();
if(!$session){$q=$pdo->prepare("INSERT INTO test_sessions(site_id,label,phase) VALUES('pup','UTC clock scratch test','PHASE1')");$q->execute();}
$hiker=$pdo->query("SELECT id FROM hiker_nodes WHERE node_id='H1' AND is_enabled=1")->fetchColumn();if(!$hiker)throw new RuntimeException('Seeded H1 is unavailable in the scratch database.');
$sequence=random_int(0,65535);
$makePacket=static function(int $seq,string $receivedAt): array {return ['uplink'=>['node_id'=>'H1','sequence_number'=>$seq,'latitude'=>14.598126,'longitude'=>121.0120373,'gps_accuracy_m'=>6,'satellite_count'=>8,'battery_level'=>90,'flags'=>['link_lost'=>false,'low_battery'=>false,'emergency'=>false,'turn_back_received'=>false,'test_mode'=>false]],'reception'=>['base_id'=>'BASE','path_type'=>'DIRECT','received_at'=>$receivedAt],'is_demo'=>true];};
$offset=utc_clock_call('POST','/api/bridge/ingest',$makePacket($sequence,'2026-11-10T16:00:00+08:00'),$token);
if($offset['status']!==201)throw new RuntimeException('Offset-bearing uplink was not accepted.');
$q=$pdo->prepare('SELECT received_at FROM uplink_packets WHERE site_id=\'pup\' AND hiker_node_id=? AND sequence_number=? ORDER BY id DESC LIMIT 1');$q->execute([(int)$hiker,$sequence]);
if($q->fetchColumn()!=='2026-11-10 08:00:00')throw new RuntimeException('Offset-bearing received_at was not normalized to UTC 08:00:00.');
echo "PASS explicit +08:00 reception timestamp is stored as 08:00:00 UTC\n";
foreach([[10,'ONLINE',8,13],[20,'LINK_LOST',18,23]] as $index=>$case){[$seconds,$expectedState,$min,$max]=$case;$seq=($sequence+1+$index)%65536;$received=gmdate('Y-m-d H:i:s',time()-$seconds);$response=utc_clock_call('POST','/api/bridge/ingest',$makePacket($seq,$received),$token);if($response['status']!==201)throw new RuntimeException('Age fixture uplink was not accepted.');$latest=utc_clock_call('GET','/api/hikers/H1/latest');$age=(int)($latest['body']['seconds_since_report']??-1);if($latest['status']!==200||($latest['body']['link_state']??null)!==$expectedState||$age<$min||$age>$max||!empty($latest['body']['clock_skew_suspected']))throw new RuntimeException("Expected $expectedState for a $seconds-second-old UTC packet; got age $age and state ".($latest['body']['link_state']??'missing').'.');}
echo "PASS UTC packet age keeps 10-second uplink ONLINE and 20-second uplink LINK_LOST\n";
$futureSeq=($sequence+3)%65536;$future=utc_clock_call('POST','/api/bridge/ingest',$makePacket($futureSeq,gmdate('Y-m-d H:i:s',time()+10)),$token);if($future['status']!==201)throw new RuntimeException('Future-time fixture uplink was not accepted.');$futureLatest=utc_clock_call('GET','/api/hikers/H1/latest');if(($futureLatest['body']['link_state']??null)!=='ONLINE'||empty($futureLatest['body']['clock_skew_suspected'])||(int)($futureLatest['body']['seconds_since_report']??-1)!==0)throw new RuntimeException('Future packet time was not exposed while preserving the zero-age ONLINE behavior.');
echo "PASS future packet clock skew is visible without changing link-state behavior\n";
