<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../geofence.php';

function json_value($value): ?string { return $value === null ? null : json_encode($value, JSON_THROW_ON_ERROR); }
function site_config(): array { return require __DIR__ . '/../../config/sites.php'; }
function selected_site(PDO $pdo): string {
    $q=$pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key='selected_site'");$q->execute();$site=(string)($q->fetchColumn()?:'pup');
    return isset(site_config()[$site])?$site:'pup';
}
function active_zone(PDO $pdo, ?string $siteId=null): ?array {
    $siteId=$siteId??selected_site($pdo);
    $q=$pdo->prepare('SELECT id,name,version,site_id,created_at FROM safe_zones WHERE is_active=1 AND site_id=? ORDER BY version DESC LIMIT 1');$q->execute([$siteId]);$z=$q->fetch();
    if (!$z) return null;
    $q=$pdo->prepare('SELECT latitude,longitude FROM safe_zone_vertices WHERE safe_zone_id=? ORDER BY vertex_order'); $q->execute([$z['id']]);
    $z['vertices']=array_map(fn($v)=>['latitude'=>(float)$v['latitude'],'longitude'=>(float)$v['longitude']],$q->fetchAll()); return $z;
}
function save_zone(PDO $pdo,array $data): array {
    $siteId=(string)($data['site_id']??selected_site($pdo));if(!isset(site_config()[$siteId]))throw new InvalidArgumentException('unknown test site');
    $name=trim((string)($data['name']??'')); $vertices=$data['vertices']??null;
    if($name===''||strlen($name)>100) throw new InvalidArgumentException('name must be 1–100 characters');
    if(!is_array($vertices)||count($vertices)<3||count($vertices)>500) throw new InvalidArgumentException('vertices must contain 3–500 coordinate pairs');
    $clean=[]; foreach($vertices as $v){ if(!is_array($v)||!isset($v['latitude'],$v['longitude'])||!is_numeric($v['latitude'])||!is_numeric($v['longitude'])) throw new InvalidArgumentException('each vertex needs numeric latitude and longitude'); $lat=(float)$v['latitude'];$lng=(float)$v['longitude'];if(!is_finite($lat)||!is_finite($lng)||abs($lat)>90||abs($lng)>180)throw new InvalidArgumentException('vertex coordinate out of range');$clean[]=[$lat,$lng]; }
    if(count(array_unique(array_map(fn($p)=>sprintf('%.7f,%.7f',$p[0],$p[1]),$clean)))<3) throw new InvalidArgumentException('polygon needs at least three distinct vertices');
    if(polygon_self_intersects($clean))throw new InvalidArgumentException('polygon crosses itself; adjust the vertex order');
    $pdo->beginTransaction(); try{$q=$pdo->prepare('SELECT COALESCE(MAX(version),0)+1 FROM safe_zones WHERE site_id=?');$q->execute([$siteId]);$version=(int)$q->fetchColumn();$q=$pdo->prepare('UPDATE safe_zones SET is_active=0 WHERE site_id=? AND is_active=1');$q->execute([$siteId]);$q=$pdo->prepare('INSERT INTO safe_zones(name,version,is_active,site_id,created_by) VALUES(?,?,1,?,?)');$q->execute([$name,$version,$siteId,$data['created_by']??null]);$id=(int)$pdo->lastInsertId();$q=$pdo->prepare('INSERT INTO safe_zone_vertices(safe_zone_id,vertex_order,latitude,longitude) VALUES(?,?,?,?)');foreach($clean as $i=>$p)$q->execute([$id,$i+1,$p[0],$p[1]]);$q=$pdo->prepare("INSERT INTO operator_actions(action_type,details_json,test_mode) VALUES('SAFE_ZONE_UPDATED',?,?)");$q->execute([json_value(['safe_zone_id'=>$id,'version'=>$version,'site_id'=>$siteId]),!empty($data['test_mode'])?1:0]);$pdo->commit();return active_zone($pdo,$siteId);}catch(Throwable $e){$pdo->rollBack();throw $e;}
}
function polygon_self_intersects(array $p): bool {
    $n=count($p);$cross=fn($a,$b,$c)=>(($b[1]-$a[1])*($c[0]-$a[0])-($b[0]-$a[0])*($c[1]-$a[1]));
    $on=fn($a,$b,$c)=>min($a[0],$b[0])-1e-10<=$c[0]&&$c[0]<=max($a[0],$b[0])+1e-10&&min($a[1],$b[1])-1e-10<=$c[1]&&$c[1]<=max($a[1],$b[1])+1e-10;
    for($i=0;$i<$n;$i++){ $a=$p[$i];$b=$p[($i+1)%$n];for($j=$i+1;$j<$n;$j++){if($j===$i||($j+1)%$n===$i||($i+1)%$n===$j)continue;$c=$p[$j];$d=$p[($j+1)%$n];$o1=$cross($a,$b,$c);$o2=$cross($a,$b,$d);$o3=$cross($c,$d,$a);$o4=$cross($c,$d,$b);if(($o1*$o2)<0&&($o3*$o4)<0||abs($o1)<1e-12&&$on($a,$b,$c)||abs($o2)<1e-12&&$on($a,$b,$d)||abs($o3)<1e-12&&$on($c,$d,$a)||abs($o4)<1e-12&&$on($c,$d,$b))return true;}}
    return false;
}
function ingest_packet(PDO $pdo,array $d): array {
    $id=trim((string)($d['node_id']??''));$seq=$d['sequence_number']??null;$lat=$d['latitude']??null;$lng=$d['longitude']??null;
    if($id===''||strlen($id)>16||!is_numeric($seq)||(int)$seq<0||(int)$seq>65535)throw new InvalidArgumentException('node_id and sequence_number (0–65535) are required');
    foreach(['latitude'=>$lat,'longitude'=>$lng] as $k=>$v)if(!is_numeric($v)||!is_finite((float)$v))throw new InvalidArgumentException("$k must be numeric");$lat=(float)$lat;$lng=(float)$lng;if(abs($lat)>90||abs($lng)>180)throw new InvalidArgumentException('coordinates out of range');
    $accuracy=$d['gps_accuracy_m']??null;$sats=$d['satellite_count']??null;$battery=$d['battery_level']??null;
    if($accuracy!==null&&(!is_numeric($accuracy)||(float)$accuracy<0||!is_finite((float)$accuracy)))throw new InvalidArgumentException('gps_accuracy_m must be nonnegative');
    if($sats!==null&&(filter_var($sats,FILTER_VALIDATE_INT)===false||$sats<0||$sats>255))throw new InvalidArgumentException('satellite_count must be 0–255');
    if($battery!==null&&(filter_var($battery,FILTER_VALIDATE_INT)===false||$battery<0||$battery>100))throw new InvalidArgumentException('battery_level must be 0–100');
    $h=$pdo->prepare('SELECT id FROM hiker_nodes WHERE node_id=? AND is_enabled=1');$h->execute([$id]);$hiker=$h->fetchColumn();if(!$hiker)throw new InvalidArgumentException('unknown or disabled hiker node_id');
    $zone=active_zone($pdo);$inside=$zone?point_in_polygon($lat,$lng,array_map(fn($v)=>[$v['latitude'],$v['longitude']],$zone['vertices'])):null;$received=$d['received_at']??gmdate('Y-m-d H:i:s');$dt=date_create((string)$received);if(!$dt)throw new InvalidArgumentException('received_at must be a valid date/time');$received=$dt->format('Y-m-d H:i:s');
    $flags=$d['flags']??[];if(!is_array($flags))throw new InvalidArgumentException('flags must be an object');$test=!empty($flags['test_mode'])||!empty($d['test_mode']);
    $pdo->beginTransaction();try{
      $q=$pdo->prepare('SELECT id FROM uplink_packets WHERE hiker_node_id=? AND sequence_number=?');$q->execute([$hiker,(int)$seq]);$existing=$q->fetchColumn();$duplicate=(bool)$existing;
      if($duplicate){$packet=(int)$existing;$q=$pdo->prepare("UPDATE uplink_packets SET is_duplicate=1,validation_status='DUPLICATE' WHERE id=?");$q->execute([$packet]);}
      else{$class=$inside===null?'UNCLASSIFIED':($inside?'INSIDE':'OUTSIDE');$q=$pdo->prepare('INSERT INTO uplink_packets(hiker_node_id,sequence_number,latitude,longitude,gps_accuracy_m,satellite_count,battery_level,flags_json,classification,safe_zone_id,received_at,test_mode) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');$q->execute([$hiker,(int)$seq,$lat,$lng,$accuracy,$sats,$battery,json_value($flags),$class,$zone['id']??null,$received,$test?1:0]);$packet=(int)$pdo->lastInsertId();}
      $path=$d['path_type']??(isset($d['received_by'])&&$d['received_by']==='CHK'?'CHECKPOINT_RELAY':'DIRECT');if(!in_array($path,['DIRECT','CHECKPOINT_RELAY','UNKNOWN'],true))throw new InvalidArgumentException('invalid path_type');$by=trim((string)($d['received_by']??'BASE'));if(strlen($by)>16)throw new InvalidArgumentException('received_by too long');$q=$pdo->prepare('INSERT INTO packet_receptions(uplink_packet_id,received_by_node,path_type,relay_rssi_dbm,rssi_dbm,snr_db,received_at,relay_hop_delay_ms,raw_metadata,test_mode) VALUES(?,?,?,?,?,?,?,?,?,?)');$q->execute([$packet,$by,$path,$d['relay_rssi_dbm']??null,$d['rssi_dbm']??null,$d['snr_db']??null,$received,$d['relay_hop_delay_ms']??null,json_value($d['reception_metadata']??null),$test?1:0]);
      if(!$duplicate){$type=!empty($flags['emergency'])?'EMERGENCY':($inside===false?'OFF_TRAIL':($inside===true?'POSITION_SAFE':'POSITION_UNCLASSIFIED'));$sev=$type==='EMERGENCY'?'CRITICAL':($type==='OFF_TRAIL'?'WARNING':'INFO');$msg=$type==='OFF_TRAIL'?'Position classified outside active safe zone':($type==='EMERGENCY'?'Hiker emergency flag received':'Position packet recorded');$q=$pdo->prepare('INSERT INTO system_events(hiker_node_id,event_type,severity,message,metadata_json,test_mode) VALUES(?,?,?,?,?,?)');$q->execute([$hiker,$type,$sev,$msg,json_value(['packet_id'=>$packet,'latitude'=>$lat,'longitude'=>$lng,'classification'=>$inside===null?'UNCLASSIFIED':($inside?'INSIDE':'OUTSIDE')]),$test?1:0]);
        if($inside===true){$q=$pdo->prepare('SELECT classification FROM uplink_packets WHERE hiker_node_id=? AND is_duplicate=0 AND id<? ORDER BY id DESC LIMIT 1');$q->execute([$hiker,$packet]);$prev=$q->fetchColumn();if($prev==='OUTSIDE'){$q=$pdo->prepare('INSERT INTO system_events(hiker_node_id,event_type,severity,message,metadata_json,test_mode) VALUES(?,?,?,?,?,?)');$q->execute([$hiker,'GEOFENCE_RETURN','INFO','Hiker returned inside active safe zone',json_value(['packet_id'=>$packet,'latitude'=>$lat,'longitude'=>$lng,'previous_classification'=>'OUTSIDE']),$test?1:0]);$pdo->prepare("UPDATE system_events SET resolved_at=NOW(),resolved_by=? WHERE hiker_node_id=? AND event_type='OFF_TRAIL' AND resolved_at IS NULL")->execute(['system:geofence_return',$hiker]);}}
        if($inside!==null){$cmd=$inside?'ACK':'OFF_TRAIL_ALERT';$q=$pdo->prepare('INSERT INTO downlink_commands(hiker_node_id,sequence_number,command_type,payload_json,test_mode) VALUES(?,?,?,?,?)');$q->execute([$hiker,(int)$seq,$cmd,json_value(['packet_id'=>$packet,'demo'=>$test]),$test?1:0]);}
      }
      $pdo->commit();return ['packet_id'=>$packet,'duplicate'=>$duplicate,'classification'=>$inside===null?'UNCLASSIFIED':($inside?'INSIDE':'OUTSIDE'),'test_mode'=>$test,'command_queued'=>!$duplicate&&$inside!==null];
    }catch(Throwable $e){$pdo->rollBack();throw $e;}
}



