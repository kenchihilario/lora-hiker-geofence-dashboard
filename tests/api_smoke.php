<?php
// API smoke test. Run only against a disposable database because it creates demo records.
$base=$argv[1]??'http://127.0.0.1:8000'; $token=$argv[2]??'';
function api_call(string $method,string $path,?array $payload=null,string $token=''): array {
 $headers="Content-Type: application/json\r\n"; if($token!=='')$headers.="X-Bridge-Token: $token\r\n";
 $ctx=stream_context_create(['http'=>['method'=>$method,'header'=>$headers,'content'=>$payload===null?'':json_encode($payload),'ignore_errors'=>true]]);
 $body=file_get_contents($GLOBALS['base'].$path,false,$ctx); $line=$http_response_header[0]??'HTTP/1.1 500';
 return ['status'=>(int)substr($line,9,3),'body'=>json_decode($body,true)];
}
function expect_status(int $expected,array $response,string $label): void {
 if($response['status']!==$expected){fwrite(STDERR,"FAIL $label HTTP {$response['status']} ".json_encode($response['body'])."\n");exit(1);}
 echo "PASS $label HTTP {$response['status']}\n";
}
expect_status(200,api_call('GET','/api/status'),'status');
expect_status(200,api_call('GET','/api/hikers'),'hikers');
expect_status(200,api_call('GET','/api/positions/latest'),'latest positions');
expect_status(200,api_call('GET','/api/hikers/H1/latest'),'hiker latest');
expect_status(200,api_call('GET','/api/events'),'events');
expect_status(200,api_call('GET','/api/safe-zone'),'safe zone read');
$zone=['name'=>'DEMO TEST polygon','test_mode'=>true,'vertices'=>[['latitude'=>14.8,'longitude'=>121.0],['latitude'=>14.8,'longitude'=>121.001],['latitude'=>14.801,'longitude'=>121.001],['latitude'=>14.801,'longitude'=>121.0]]];
expect_status(201,api_call('PUT','/api/safe-zone',$zone),'safe zone update');
$packet=['node_id'=>'H1','sequence_number'=>65530,'latitude'=>14.8005,'longitude'=>121.0005,'battery_level'=>77,'flags'=>['test_mode'=>true]];
expect_status(201,api_call('POST','/api/bridge/ingest',$packet,$token),'bridge ingest');$packet['received_by']='CHK';$packet['path_type']='CHECKPOINT_RELAY';$dup=api_call('POST','/api/bridge/ingest',$packet,$token);expect_status(201,$dup,'duplicate relay ingest');if(empty($dup['body']['duplicate'])){fwrite(STDERR,"FAIL duplicate detection\n");exit(1);}
expect_status(200,api_call('GET','/api/logs/packets?test_mode=1'),'packet log');expect_status(200,api_call('POST','/api/bridge/status',['component'=>'SERIAL_BRIDGE','state'=>'ONLINE'],$token),'bridge heartbeat');
$storm=api_call('POST','/api/commands/storm-turn-back',['node_id'=>'H2','test_mode'=>true]);expect_status(200,$storm,'storm command');$commandId=$storm['body']['commands'][0]['command_id'];expect_status(200,api_call('GET','/api/commands/pending',null,$token),'pending commands');expect_status(200,api_call('POST',"/api/commands/$commandId/status",['status'=>'SENT_TO_BASE'],$token),'command status');expect_status(200,api_call('GET','/api/research/summary'),'research summary');
echo "Smoke test created demo-marked records.\n";
