<?php
require_once dirname(__DIR__).'/src/db.php';
require_once dirname(__DIR__).'/src/Services/ApiService.php';
try { require_disposable_test_profile(); } catch(Throwable $e) { fwrite(STDERR,"REFUSED: ".$e->getMessage()."\n"); exit(2); }
$base=rtrim($argv[1]??'','/');if($base===''||!in_array(strtolower((string)parse_url($base,PHP_URL_HOST)),['127.0.0.1','localhost','::1'],true)){fwrite(STDERR,"REFUSED: annotation tests require loopback API.\n");exit(2);}
$cookie=(string)getenv('TRAILWATCH_TEST_SESSION_COOKIE');$csrf=(string)getenv('TRAILWATCH_TEST_CSRF_TOKEN');if($cookie===''||$csrf===''){fwrite(STDERR,"REFUSED: scratch operator cookie and CSRF token are required.\n");exit(2);}
function annotation_call(string $method,string $path,?array $payload=null):array{$headers="Content-Type: application/json\r\nCookie: ".getenv('TRAILWATCH_TEST_SESSION_COOKIE')."\r\nX-CSRF-Token: ".getenv('TRAILWATCH_TEST_CSRF_TOKEN')."\r\n";$context=stream_context_create(['http'=>['method'=>$method,'header'=>$headers,'content'=>$payload===null?'':json_encode($payload,JSON_THROW_ON_ERROR),'ignore_errors'=>true,'timeout'=>8]]);$body=@file_get_contents($GLOBALS['base'].$path,false,$context);$line=$http_response_header[0]??'HTTP/1.1 500';return['status'=>(int)substr($line,9,3),'body'=>is_string($body)?$body:''];}
function annotation_expect(int $status,array $result,string $label):array{if($result['status']!==$status){fwrite(STDERR,"FAIL $label HTTP {$result['status']} {$result['body']}\n");exit(1);}echo"PASS $label HTTP $status\n";$data=json_decode($result['body'],true);return is_array($data)?$data:[];}
function annotation_check(bool $condition,string $label):void{if(!$condition){fwrite(STDERR,"FAIL $label\n");exit(1);}echo"PASS $label\n";}
$pdo=db();$base=$base;
try{
  annotation_expect(200,annotation_call('POST','/api/settings/site',['site_id'=>'pup']),'select scratch PUP site');
  $session=annotation_expect(201,annotation_call('POST','/api/test-sessions/start',['label'=>'P4 annotations scratch','phase'=>'PHASE1','design'=>'A','tester_code'=>'P4','test_point'=>'scratch']),'start annotation test session');
  $sessionId=(int)($session['active_session']['id']??0);annotation_check($sessionId>0,'session ID returned for annotation form default');
  $q=$pdo->prepare('SELECT id FROM hiker_nodes WHERE node_id=?');$q->execute(['H1']);$hikerId=(int)$q->fetchColumn();if(!$hikerId)throw new RuntimeException('H1 demo node is missing from scratch DB');
  $sequence=random_int(100000,200000);$classes=['INSIDE','OUTSIDE','UNCLASSIFIED','INSIDE'];$ids=[];
  $insert=$pdo->prepare("INSERT INTO uplink_packets(hiker_node_id,site_id,sequence_number,latitude,longitude,classification,session_id,received_at,test_mode,is_demo) VALUES(?,'pup',?,14.8,121.0,?,?,UTC_TIMESTAMP(),1,1)");
  foreach($classes as $index=>$class){$insert->execute([$hikerId,$sequence+$index,$class,$sessionId]);$ids[]=(int)$pdo->lastInsertId();}
  $one=annotation_expect(200,annotation_call('POST','/api/annotations/packet',['packet_id'=>$ids[3],'action'=>'SET','intended_class'=>'INSIDE','point_source'=>'FROM_TRACK']),'set one packet annotation');
  annotation_check(($one['annotation']['intended_class']??null)==='INSIDE','single annotation returns intended class');
  annotation_check((int)$pdo->query("SELECT COUNT(*) FROM operator_actions WHERE action_type='PACKET_ANNOTATION_SET' AND site_id='pup'")->fetchColumn()===1,'single packet set is audited');
  $range=annotation_expect(200,annotation_call('POST','/api/annotations/range',['session_id'=>$sessionId,'node_id'=>'H1','sequence_from'=>$sequence,'sequence_to'=>$sequence+2,'action'=>'SET','intended_class'=>'OUTSIDE','point_source'=>'MOVED_BEYOND_MARGIN']),'set sequence range annotations');
  annotation_check((int)($range['packet_count']??0)===3&&(int)($range['annotation_count']??0)===3,'range records all three matched packet rows');
  $audit=$pdo->prepare("SELECT COUNT(*) FROM operator_actions WHERE action_type='PACKET_ANNOTATION_RANGE_SET' AND JSON_EXTRACT(details_json,'$.annotation_count')=3 AND site_id='pup'");$audit->execute();annotation_check((int)$audit->fetchColumn()===1,'range set creates one audited operator action with count');
  $summary=annotation_expect(200,annotation_call('GET','/api/annotations/summary?session_id='.$sessionId),'read session annotation summary');
  annotation_check($summary['annotated_count']===4&&$summary['matched_count']===2&&$summary['mismatched_count']===1&&$summary['unclassified_annotated_count']===1,'summary separates matches, mismatch, and unclassified annotations');
  annotation_check(($summary['mismatched_packets']??[])===['H1 #'.$sequence],'summary lists mismatched hiker and sequence');
  $logs=annotation_expect(200,annotation_call('GET','/api/logs/packets?node_id=H1&limit=200'),'packet log reads annotation fields');$bySeq=[];foreach($logs['packets']??[] as $row)if((int)$row['sequence_number']>=$sequence&&(int)$row['sequence_number']<=$sequence+3)$bySeq[(int)$row['sequence_number']]=$row;
  annotation_check(($bySeq[$sequence]['classification_matched']??null)==='N'&&($bySeq[$sequence]['intended_class']??null)==='OUTSIDE'&&($bySeq[$sequence]['point_source']??null)==='MOVED_BEYOND_MARGIN','packet log marks classification mismatch');
  annotation_check(($bySeq[$sequence+1]['classification_matched']??null)==='Y'&&($bySeq[$sequence+2]['classification_matched']??null)===''&&($bySeq[$sequence+3]['classification_matched']??null)==='Y','packet log reports match, blank unclassified, and single-set match');
  $clear=annotation_expect(200,annotation_call('POST','/api/annotations/packet',['packet_id'=>$ids[3],'action'=>'CLEAR']),'clear one packet annotation');annotation_check(array_key_exists('annotation',$clear)&&$clear['annotation']===null,'single clear returns no annotation');
  annotation_check((int)$pdo->query("SELECT COUNT(*) FROM operator_actions WHERE action_type='PACKET_ANNOTATION_CLEARED' AND site_id='pup'")->fetchColumn()===1,'single packet clear is audited');
  $rangeClear=annotation_expect(200,annotation_call('POST','/api/annotations/range',['session_id'=>$sessionId,'node_id'=>'H1','sequence_from'=>$sequence,'sequence_to'=>$sequence+2,'action'=>'CLEAR']),'clear sequence range annotations');annotation_check((int)($rangeClear['annotation_count']??-1)===3,'range clear reports removed annotation count');
  annotation_expect(404,annotation_call('POST','/api/annotations/range',['session_id'=>$sessionId,'node_id'=>'H1','sequence_from'=>$sequence+10,'sequence_to'=>$sequence+11,'action'=>'SET','intended_class'=>'INSIDE','point_source'=>'FROM_TRACK']),'empty range is an error');
  $csvResult=annotation_call('GET','/api/export.csv?type=packets');annotation_expect(200,$csvResult,'packet CSV export');$stream=fopen('php://temp','r+');fwrite($stream,$csvResult['body']);rewind($stream);$header=fgetcsv($stream);fclose($stream);annotation_check(array_slice($header,-3)===['intended_class','point_source','classification_matched'],'CSV annotation fields are the final three columns');
  $pdo->exec('DROP TABLE packet_annotations');
  annotation_expect(200,annotation_call('GET','/api/logs/packets?limit=5'),'pre-012 packet-log read remains available');
  annotation_expect(200,annotation_call('GET','/api/export.csv?type=packets'),'pre-012 packet CSV read remains available');
  $oldSummary=annotation_expect(200,annotation_call('GET','/api/annotations/summary?session_id='.$sessionId),'pre-012 summary read remains available');annotation_check($oldSummary['schema_available']===false,'pre-012 summary reports annotations unavailable');
  $write=annotation_call('POST','/api/annotations/packet',['packet_id'=>$ids[0],'action'=>'SET','intended_class'=>'INSIDE','point_source'=>'FROM_TRACK']);annotation_expect(503,$write,'pre-012 annotation write gives migration guidance');annotation_check(str_contains($write['body'],'migration 012'),'pre-012 write explains required migration');
  annotation_expect(503,annotation_call('POST','/api/annotations/range',['session_id'=>$sessionId,'node_id'=>'H1','sequence_from'=>$sequence,'sequence_to'=>$sequence,'action'=>'SET','intended_class'=>'INSIDE','point_source'=>'FROM_TRACK']),'pre-012 range write gives migration guidance');
  $migration=trim(file_get_contents(dirname(__DIR__).'/sql/migrations/012_packet_annotations.sql'));$pdo->exec(rtrim($migration,';'));
  echo "PASS pre-012 packet annotations table restored on disposable scratch DB.\n";
}catch(Throwable $e){if(!$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='packet_annotations'")->fetchColumn()){$migration=trim(file_get_contents(dirname(__DIR__).'/sql/migrations/012_packet_annotations.sql'));try{$pdo->exec(rtrim($migration,';'));}catch(Throwable){}}fwrite(STDERR,'FAIL '.$e->getMessage()."\n");exit(1);}
