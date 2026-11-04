<?php
require_once __DIR__ . '/../src/Services/ApiService.php';
try{require_disposable_test_profile();}catch(Throwable $e){fwrite(STDERR,"REFUSED: ".$e->getMessage()."\n");exit(2);}
$pdo=db();$hiker=$pdo->prepare('SELECT id FROM hiker_nodes WHERE node_id=? AND is_enabled=1');$hiker->execute(['H1']);$hikerId=(int)$hiker->fetchColumn();if(!$hikerId){fwrite(STDERR,"FAIL: seeded H1 hiker is required\n");exit(1);}
$before=research_summary($pdo);$pdo->beginTransaction();
try{
 $q=$pdo->prepare('SELECT sequence_number FROM uplink_packets WHERE site_id=? AND hiker_node_id=? AND sequence_number=?');$seq=60000;while($seq<=65535){$q->execute(['pup',$hikerId,$seq]);if(!$q->fetchColumn())break;$seq++;}if($seq>65535)throw new RuntimeException('No unused test sequence number remains.');
 $insert=$pdo->prepare('INSERT INTO uplink_packets(hiker_node_id,site_id,sequence_number,latitude,longitude,flags_json,classification,received_at,test_mode,is_demo,is_rehearsal) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
 $base=['hiker'=>$hikerId,'lat'=>14.8,'lng'=>121.0,'class'=>'INSIDE','received'=>gmdate('Y-m-d H:i:s')];
 $rows=[['pup',0,0,0,'{}'],['pup',1,0,0,'{}'],['pup_mountain_prep',0,1,0,'{}'],['pup',0,0,1,'{}'],['pup',0,0,0,'{"test_mode":true}']];
 foreach($rows as $i=>$row){[$site,$demo,$rehearsal,$test,$flags]=$row;$insert->execute([$base['hiker'],$site,$seq+$i,$base['lat'],$base['lng'],$flags,$base['class'],$base['received'],$test,$demo,$rehearsal]);}
 $eventInsert=$pdo->prepare("INSERT INTO system_events(hiker_node_id,event_type,severity,message,test_mode,is_demo,is_rehearsal,site_id) VALUES(?,'OFF_TRAIL','WARNING','research exclusion fixture',?,?,?,?)");
 $commandInsert=$pdo->prepare("INSERT INTO downlink_commands(hiker_node_id,site_id,base_id,sequence_number,command_type,status,test_mode,is_demo,is_rehearsal) VALUES(?,?,'BASE',?,'OFF_TRAIL_ALERT','QUEUED',?,?,?)");
 $recordRows=[['pup',0,0,0],['pup',1,0,0],['pup_mountain_prep',0,1,0],['pup',0,0,1]];
 foreach($recordRows as $i=>$row){[$site,$demo,$rehearsal,$test]=$row;$eventInsert->execute([$hikerId,$test,$demo,$rehearsal,$site]);$commandInsert->execute([$hikerId,$site,$seq+$i,$test,$demo,$rehearsal]);}
 $sourcePacket=$pdo->prepare('SELECT id FROM uplink_packets WHERE site_id=\'pup\' AND hiker_node_id=? AND sequence_number=?');$sourcePacket->execute([$hikerId,$seq+1]);$sourcePacketId=(int)$sourcePacket->fetchColumn();
 $wrongFlags=$pdo->prepare("INSERT INTO system_events(hiker_node_id,event_type,severity,message,metadata_json,test_mode,is_demo,is_rehearsal,site_id) VALUES(?,'OFF_TRAIL','WARNING','wrong denormalized flags',?,0,0,0,'pup')");$wrongFlags->execute([$hikerId,json_encode(['packet_id'=>$sourcePacketId])]);
 $sourceJsonPacket=$pdo->prepare('SELECT id FROM uplink_packets WHERE site_id=\'pup\' AND hiker_node_id=? AND sequence_number=?');$sourceJsonPacket->execute([$hikerId,$seq+4]);$sourceJsonPacketId=(int)$sourceJsonPacket->fetchColumn();
 $jsonFlagsEvent=$pdo->prepare("INSERT INTO system_events(hiker_node_id,event_type,severity,message,metadata_json,test_mode,is_demo,is_rehearsal,site_id) VALUES(?,'OFF_TRAIL','WARNING','source JSON test-mode flag',?,0,0,0,'pup')");$jsonFlagsEvent->execute([$hikerId,json_encode(['packet_id'=>$sourceJsonPacketId])]);
 $jsonFlagsCommand=$pdo->prepare("INSERT INTO downlink_commands(hiker_node_id,site_id,base_id,sequence_number,command_type,status,test_mode,is_demo,is_rehearsal) VALUES(?,'pup','BASE',?,'OFF_TRAIL_ALERT','QUEUED',0,0,0)");$jsonFlagsCommand->execute([$hikerId,$seq+4]);
 $after=research_summary($pdo);$pdo->rollBack();
 if((int)$after['packet_count']!==(int)$before['packet_count']+1){fwrite(STDERR,"FAIL: summary should add the one eligible packet and exclude demo, rehearsal, and both test-mode cases\n");exit(1);}
 if((int)$after['excluded_packet_count']!==(int)$before['excluded_packet_count']+4){fwrite(STDERR,"FAIL: summary should count four newly excluded fixtures\n");exit(1);}
 foreach(['event_count','alert_event_count','command_count','alert_command_count'] as $key)if((int)$after[$key]!== (int)$before[$key]+1){fwrite(STDERR,"FAIL: summary should count the eligible $key fixture\n");exit(1);}
 foreach(['excluded_command_count','excluded_alert_command_count'] as $key)if((int)$after[$key]!== (int)$before[$key]+4){fwrite(STDERR,"FAIL: summary should exclude demo, rehearsal, denormalized test and source-packet JSON test-mode $key fixtures\n");exit(1);}
 foreach(['excluded_event_count','excluded_alert_event_count'] as $key)if((int)$after[$key]!== (int)$before[$key]+5){fwrite(STDERR,"FAIL: summary must exclude demo/rehearsal/test events and events linked to source packets flagged by columns or JSON\n");exit(1);}
 echo "PASS: research summary excludes demo, rehearsal, and test-mode packets, events, alerts, and commands\n";
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();fwrite(STDERR,'FAIL: '.$e->getMessage()."\n");exit(1);}
