<?php
// API smoke test; use only against a disposable test database.
$base=$argv[1]??'http://127.0.0.1:8000'; $token=$argv[2]??'';
function call_api(string $method,string $path,?array $payload=null,string $token=''): array {
 $headers="Content-Type: application/json\r\n"; if($token!=='')$headers.="X-Bridge-Token: $token\r\n";
 $ctx=stream_context_create(['http'=>['method'=>$method,'header'=>$headers,'content'=>$payload===null?'':json_encode($payload),'ignore_errors'=>true]]);
 $body=file_get_contents($GLOBALS['base'].$path,false,$ctx); $line=$http_response_header[0]??'HTTP/1.1 500';
 return [(int)substr($line,9,3),json_decode($body,true)];
}
function check(int $expected,array $response,string $label): array {[$code,$data]=$response;if($code!==$expected){fwrite(STDERR,"FAIL $label HTTP $code ".json_encode($data)."\n");exit(1);}echo "PASS $label HTTP $code\n";return $data;}
check(200,call_api('GET','/api/status'),'status'); check(200,call_api('GET','/api/hikers'),'hikers'); check(200,call_api('GET','/api/positions/latest'),'latest positions'); check(200,call_api('GET','/api/hikers/H1/latest'),'hiker latest'); check(200,call_api('GET','/api/events'),'events'); check(200,call_api('GET','/api/safe-zone'),'safe zone read');
$zone=['name'=>'DEMO TEST polygon','test_mode'=>true,'vertices'=>[['latitude'=>14.8,'longitude'=>121.0],['latitude'=>14.8,'longitude'=>121.001],['latitude'=>14.801,'longitude'=>121.001],['latitude'=>14.801,'longitude'=>121.0]]];check(201,call_api('PUT','/api/safe-zone',$zone),'safe zone update');
$packet=['node_id'=>'H1','sequence_number'=>65530,'latitude'=>14.8005,'longitude'=>121.0005,'battery_level'=>77,'flags'=>['test_mode'=>true]];$first=check(201,call_api('POST','/api/bridge/ingest',$packet,$token),'bridge ingest');$packet['received_by']='CHK';$packet['path_type']='CHECKPOINT_RELAY';$second=check(201,call_api('POST','/api/bridge/ingest',$packet,$token),'duplicate relay ingest');if(empty($second['duplicate'])){fwrite(STDERR,"FAIL duplicate not detected\n");exit(1);}check(200,call_api('GET','/api/logs/packets?test_mode=1'),'packet log');
check(200,call_api('POST','/api/bridge/status',['component'=>'SERIAL_BRIDGE','state'=>'ONLINE'],$token),'bridge heartbeat');$storm=check(200,call_api('POST','/api/commands/storm-turn-back',['node_id'=>'H2','test_mode'=>true]),'storm command');$commandId=$storm['commands'][0]['command_id'];$pending=check(200,call_api('GET','/api/commands/pending',null,$token),'pending commands');check(200,call_api('POST',"/api/commands/$commandId/status",['status'=>'SENT_TO_BASE'],$token),'command status');check(200,call_api('GET','/api/research/summary'),'research summary');
echo "Smoke test generated demo-marked records; run against disposable database only.\n";
