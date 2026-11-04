<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../geofence.php';

function json_value($value): ?string { return $value === null ? null : json_encode($value, JSON_THROW_ON_ERROR); }
function packet_is_demo_or_test(array $data): bool {
    $u=is_array($data['uplink']??null)?$data['uplink']:[];$f=is_array($u['flags']??null)?$u['flags']:[];
    return !empty($data['is_demo'])||!empty($data['is_rehearsal'])||!empty($f['test_mode'])||!empty($data['test_mode']);
}
function should_reject_live_packet(string $mode,array $data): bool { return $mode==='LIVE'&&packet_is_demo_or_test($data); }
function clear_demo_allowed(string $mode): bool { return $mode==='DEMO'; }
function site_allowed_in_mode(string $mode,array $site): bool { return $mode!=='LIVE'||(empty($site['is_demo'])&&empty($site['is_rehearsal'])); }
function demo_row_count(PDO $pdo): int {
    $sum=0;$filters=['uplink_packets'=>'is_demo=1 OR is_rehearsal=1 OR test_mode=1','system_events'=>'is_demo=1 OR is_rehearsal=1 OR test_mode=1','downlink_commands'=>'is_demo=1 OR is_rehearsal=1 OR test_mode=1','packet_receptions'=>'is_demo=1 OR is_rehearsal=1 OR test_mode=1','safe_zones'=>'is_demo=1 OR is_rehearsal=1','storm_turnback_campaigns'=>'is_demo=1 OR is_rehearsal=1 OR test_mode=1','operator_actions'=>'is_demo=1 OR is_rehearsal=1 OR test_mode=1','trail_reference_points'=>'is_demo=1'];foreach($filters as $table=>$where)$sum+=(int)$pdo->query("SELECT COUNT(*) FROM $table WHERE $where")->fetchColumn();return $sum;
}
function gps_valid_fix($accuracy,$satellites): bool { return $accuracy!==null&&$satellites!==null&&is_numeric($accuracy)&&(float)$accuracy<=10&&(int)$satellites>=5; }
function source_label(array $r,?string $mode=null): string { $mode=$mode??active_mode();return !empty($r['is_rehearsal'])?'REHEARSAL':(!empty($r['test_mode'])?'TEST':(!empty($r['is_demo'])||$mode==='DEMO'?'DEMO':'REAL')); }
function research_flags_eligible_sql(string $alias): string {
    return "$alias.is_demo=0 AND $alias.is_rehearsal=0 AND $alias.test_mode=0";
}
function clear_demo_data(PDO $pdo): array {
    $legacyRowsKept=['positions'=>(int)$pdo->query('SELECT COUNT(*) FROM positions')->fetchColumn(),'alerts'=>(int)$pdo->query('SELECT COUNT(*) FROM alerts')->fetchColumn()];
    $pdo->beginTransaction();
    try{
        $pdo->exec("DELETE e FROM system_events e JOIN uplink_packets p ON p.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(e.metadata_json,'$.packet_id')) AS UNSIGNED) WHERE p.is_demo=1 OR p.is_rehearsal=1 OR p.test_mode=1");
        $pdo->exec("DELETE c FROM downlink_commands c JOIN uplink_packets p ON p.hiker_node_id=c.hiker_node_id AND p.site_id=c.site_id AND p.sequence_number=c.sequence_number WHERE p.is_demo=1 OR p.is_rehearsal=1 OR p.test_mode=1");
        $pdo->exec('DELETE FROM packet_receptions WHERE is_demo=1 OR is_rehearsal=1 OR test_mode=1');
        $pdo->exec('DELETE FROM uplink_packets WHERE is_demo=1 OR is_rehearsal=1 OR test_mode=1');
        $pdo->exec('DELETE FROM downlink_commands WHERE is_demo=1 OR is_rehearsal=1 OR test_mode=1');
        $pdo->exec('DELETE FROM system_events WHERE is_demo=1 OR is_rehearsal=1 OR test_mode=1');
        $pdo->exec('DELETE FROM storm_turnback_campaigns WHERE is_demo=1 OR is_rehearsal=1 OR test_mode=1');
        $pdo->exec('DELETE FROM operator_actions WHERE is_demo=1 OR is_rehearsal=1 OR test_mode=1');
        $pdo->exec('DELETE FROM safe_zones WHERE is_demo=1 OR is_rehearsal=1');
        $pdo->exec('DELETE FROM trail_reference_points WHERE is_demo=1');
        $pdo->exec("INSERT INTO mode_switch_audit(actor,from_mode,to_mode,from_database,to_database) VALUES('local operator','DEMO','DEMO',DATABASE(),DATABASE())");
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return ['legacy_rows_kept'=>array_sum($legacyRowsKept),'legacy_rows_kept_detail'=>$legacyRowsKept];
}
function site_config(): array { return require __DIR__ . '/../../config/sites.php'; }
function selected_site(PDO $pdo): string {
    $q=$pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key='selected_site'");$q->execute();$site=(string)($q->fetchColumn()?:'pup');
    return isset(site_config()[$site])?$site:'pup';
}
function phase_mode(PDO $pdo): string {
    $q=$pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key='phase_mode'");$q->execute();$mode=(string)($q->fetchColumn()?:'PHASE1');
    return in_array($mode,['PHASE1','PHASE2','PHASE3'],true)?$mode:'PHASE1';
}
function uplink_reply_route(PDO $pdo,int $packetId): array {
    $q=$pdo->prepare("SELECT EXISTS(SELECT 1 FROM packet_receptions WHERE uplink_packet_id=? AND path_type='DIRECT') direct_heard,(SELECT checkpoint_id FROM packet_receptions WHERE uplink_packet_id=? AND path_type='CHECKPOINT_RELAY' ORDER BY id DESC LIMIT 1) checkpoint_id");
    $q->execute([$packetId,$packetId]);$v=$q->fetch();
    if((int)$v['direct_heard']===1||empty($v['checkpoint_id']))return ['route_type'=>'DIRECT','route_via_node_id'=>null];
    return ['route_type'=>'CHECKPOINT_RELAY','route_via_node_id'=>$v['checkpoint_id']];
}
function command_elapsed_ms(?string $from,?string $to): ?int {
    if(!$from||!$to)return null;$a=new DateTimeImmutable($from);$b=new DateTimeImmutable($to);
    $aMs=((int)$a->format('U'))*1000+(int)substr($a->format('u'),0,3);$bMs=((int)$b->format('U'))*1000+(int)substr($b->format('u'),0,3);
    return $bMs-$aMs;
}
function add_command_elapsed_times(array $command): array {
    $command['elapsed_created_to_fetched_ms']=command_elapsed_ms($command['created_at']??null,$command['fetched_at']??null);
    $command['elapsed_created_to_sent_ms']=command_elapsed_ms($command['created_at']??null,$command['sent_at']??null);
    $command['elapsed_fetched_to_sent_ms']=command_elapsed_ms($command['fetched_at']??null,$command['sent_at']??null);
    return $command;
}
function site_record_flags(string $siteId): array {
    $site=site_config()[$siteId]??[];
    return ['is_demo'=>!empty($site['is_demo'])?1:0,'is_rehearsal'=>!empty($site['is_rehearsal'])?1:0];
}
function active_zone(PDO $pdo, ?string $siteId=null): ?array {
    $siteId=$siteId??selected_site($pdo);
    $q=$pdo->prepare('SELECT id,name,version,site_id,is_demo,is_rehearsal,created_at FROM safe_zones WHERE is_active=1 AND site_id=? ORDER BY version DESC LIMIT 1');$q->execute([$siteId]);$z=$q->fetch();
    if (!$z) return null;
    $q=$pdo->prepare('SELECT latitude,longitude FROM safe_zone_vertices WHERE safe_zone_id=? ORDER BY vertex_order');$q->execute([$z['id']]);
    $z['vertices']=array_map(fn($v)=>['latitude'=>(float)$v['latitude'],'longitude'=>(float)$v['longitude']],$q->fetchAll());return $z;
}
function save_zone(PDO $pdo,array $data): array {
    $siteId=(string)($data['site_id']??selected_site($pdo));if(!isset(site_config()[$siteId]))throw new InvalidArgumentException('unknown site_id');
    $name=trim((string)($data['name']??''));$vertices=$data['vertices']??null;$createdBy=trim((string)($data['created_by']??'local-operator'));
    if($name===''||strlen($name)>100)throw new InvalidArgumentException('name must be 1–100 characters');
    if($createdBy===''||strlen($createdBy)>100)throw new InvalidArgumentException('created_by must be 1–100 characters');
    if(!is_array($vertices)||count($vertices)<3||count($vertices)>500)throw new InvalidArgumentException('vertices must contain 3–500 coordinate pairs');
    $clean=[];foreach($vertices as $v){if(!is_array($v)||!isset($v['latitude'],$v['longitude'])||!is_numeric($v['latitude'])||!is_numeric($v['longitude']))throw new InvalidArgumentException('each vertex needs numeric latitude and longitude');$lat=(float)$v['latitude'];$lng=(float)$v['longitude'];if(!is_finite($lat)||!is_finite($lng)||abs($lat)>90||abs($lng)>180)throw new InvalidArgumentException('vertex coordinate out of range');$clean[]=[$lat,$lng];}
    if(count(array_unique(array_map(fn($p)=>sprintf('%.7f,%.7f',$p[0],$p[1]),$clean)))<3)throw new InvalidArgumentException('polygon needs at least three distinct vertices');
    if(polygon_self_intersects($clean))throw new InvalidArgumentException('polygon crosses itself; adjust the vertex order');
    $flags=site_record_flags($siteId);if(isset($data['is_demo'])&&!is_bool($data['is_demo']))throw new InvalidArgumentException('is_demo must be boolean');if(!empty($data['is_demo']))$flags['is_demo']=1;
    $pdo->beginTransaction();try{$q=$pdo->prepare('SELECT COALESCE(MAX(version),0)+1 FROM safe_zones WHERE site_id=?');$q->execute([$siteId]);$version=(int)$q->fetchColumn();$q=$pdo->prepare('UPDATE safe_zones SET is_active=0 WHERE site_id=? AND is_active=1');$q->execute([$siteId]);$q=$pdo->prepare('INSERT INTO safe_zones(name,version,site_id,is_demo,is_rehearsal,is_active,created_by) VALUES(?,?,?,?,?,1,?)');$q->execute([$name,$version,$siteId,$flags['is_demo'],$flags['is_rehearsal'],$createdBy]);$id=(int)$pdo->lastInsertId();$q=$pdo->prepare('INSERT INTO safe_zone_vertices(safe_zone_id,vertex_order,latitude,longitude) VALUES(?,?,?,?)');foreach($clean as $i=>$p)$q->execute([$id,$i+1,$p[0],$p[1]]);$q=$pdo->prepare("INSERT INTO operator_actions(action_type,details_json,test_mode,is_demo,is_rehearsal,site_id) VALUES('SAFE_ZONE_UPDATED',?,0,?,?,?)");$q->execute([json_value(['safe_zone_id'=>$id,'version'=>$version,'site_id'=>$siteId]),$flags['is_demo'],$flags['is_rehearsal'],$siteId]);$pdo->commit();return active_zone($pdo,$siteId);}catch(Throwable $e){$pdo->rollBack();throw $e;}
}
function polygon_self_intersects(array $p): bool {
    $n=count($p);$cross=fn($a,$b,$c)=>(($b[1]-$a[1])*($c[0]-$a[0])-($b[0]-$a[0])*($c[1]-$a[1]));
    $on=fn($a,$b,$c)=>min($a[0],$b[0])-1e-10<=$c[0]&&$c[0]<=max($a[0],$b[0])+1e-10&&min($a[1],$b[1])-1e-10<=$c[1]&&$c[1]<=max($a[1],$b[1])+1e-10;
    for($i=0;$i<$n;$i++){ $a=$p[$i];$b=$p[($i+1)%$n];for($j=$i+1;$j<$n;$j++){if($j===$i||($j+1)%$n===$i||($i+1)%$n===$j)continue;$c=$p[$j];$d=$p[($j+1)%$n];$o1=$cross($a,$b,$c);$o2=$cross($a,$b,$d);$o3=$cross($c,$d,$a);$o4=$cross($c,$d,$b);if(($o1*$o2)<0&&($o3*$o4)<0||abs($o1)<1e-12&&$on($a,$b,$c)||abs($o2)<1e-12&&$on($a,$b,$d)||abs($o3)<1e-12&&$on($c,$d,$a)||abs($o4)<1e-12&&$on($c,$d,$b))return true;}}
    return false;
}
function valid_node_code(PDO $pdo,string $code,string $type): bool {
    if(!preg_match('/^[A-Za-z0-9_-]{1,16}$/',$code))return false;
    $q=$pdo->prepare('SELECT 1 FROM network_nodes WHERE node_code=? AND node_type=? AND is_enabled=1');$q->execute([$code,$type]);return(bool)$q->fetchColumn();
}
function downlink_type(string $commandType): string {
    return match($commandType){'ACK'=>'acknowledgement','OFF_TRAIL_ALERT'=>'alert','SOS_ACK'=>'SOS received','STORM_TURN_BACK'=>'storm turn-back',default=>throw new InvalidArgumentException('unknown downlink type')};
}
// Highest priority first for the single reply selected for an uplink.
const UPLINK_DOWNLINK_PRIORITY = ['SOS_ACK','OFF_TRAIL_ALERT','STORM_TURN_BACK','ACK'];
function select_uplink_downlink(array $eligibleTypes): ?string {
    foreach(UPLINK_DOWNLINK_PRIORITY as $type)if(in_array($type,$eligibleTypes,true))return $type;
    return null;
}
function flag_bool($value,string $name): bool {
    if(is_bool($value))return$value;
    if($value===0||$value===1)return(bool)$value;
    throw new InvalidArgumentException("flags.$name must be boolean");
}
function ingest_packet(PDO $pdo,array $d): array {
    $rootAllowed=['uplink','reception','is_demo','is_rehearsal'];if(array_diff(array_keys($d),$rootAllowed))throw new InvalidArgumentException('bridge envelope accepts only uplink, reception, and optional is_demo / is_rehearsal');
    $u=$d['uplink']??null;$r=$d['reception']??null;if(!is_array($u)||!is_array($r))throw new InvalidArgumentException('uplink and reception objects are required');
    $uplinkAllowed=['node_id','sequence_number','latitude','longitude','gps_accuracy_m','satellite_count','battery_level','flags'];if(array_diff(array_keys($u),$uplinkAllowed))throw new InvalidArgumentException('uplink contains an unsupported field');
    foreach($uplinkAllowed as $key)if(!array_key_exists($key,$u))throw new InvalidArgumentException("uplink.$key is required");
    $receptionAllowed=['base_id','path_type','checkpoint_id','checkpoint_rssi_dbm','rssi_dbm','snr_db','relay_hop_delay_ms','received_at'];if(array_diff(array_keys($r),$receptionAllowed))throw new InvalidArgumentException('reception contains an unsupported field');
    foreach(['base_id','path_type'] as $key)if(!isset($r[$key]))throw new InvalidArgumentException("reception.$key is required");
    if(isset($d['is_demo'])&&!is_bool($d['is_demo']))throw new InvalidArgumentException('is_demo must be boolean');if(isset($d['is_rehearsal'])&&!is_bool($d['is_rehearsal']))throw new InvalidArgumentException('is_rehearsal must be boolean');
    $id=trim((string)$u['node_id']);$seq=$u['sequence_number'];if($id===''||strlen($id)>16||filter_var($seq,FILTER_VALIDATE_INT)===false||(int)$seq<0||(int)$seq>65535)throw new InvalidArgumentException('node_id and sequence_number (0–65535) are required');
    foreach(['latitude'=>$u['latitude'],'longitude'=>$u['longitude']] as $key=>$v)if(!is_numeric($v)||!is_finite((float)$v))throw new InvalidArgumentException("uplink.$key must be numeric");$lat=(float)$u['latitude'];$lng=(float)$u['longitude'];if(abs($lat)>90||abs($lng)>180)throw new InvalidArgumentException('coordinates out of range');
    $accuracy=$u['gps_accuracy_m'];$sats=$u['satellite_count'];$battery=$u['battery_level'];
    if($accuracy!==null&&(!is_numeric($accuracy)||(float)$accuracy<0||!is_finite((float)$accuracy)))throw new InvalidArgumentException('gps_accuracy_m must be nonnegative or null');
    if($sats!==null&&(filter_var($sats,FILTER_VALIDATE_INT)===false||(int)$sats<0||(int)$sats>255))throw new InvalidArgumentException('satellite_count must be 0–255 or null');
    if($battery!==null&&(filter_var($battery,FILTER_VALIDATE_INT)===false||(int)$battery<0||(int)$battery>100))throw new InvalidArgumentException('battery_level must be 0–100 or null');
    $rawFlags=$u['flags'];if(!is_array($rawFlags))throw new InvalidArgumentException('uplink.flags must be an object');$flagNames=['link_lost','low_battery','emergency','turn_back_received','test_mode'];if(array_diff(array_keys($rawFlags),$flagNames))throw new InvalidArgumentException('uplink.flags contains an unsupported flag');$flags=[];foreach($flagNames as $f)$flags[$f]=flag_bool($rawFlags[$f]??false,$f);$test=$flags['test_mode'];
    $siteId=selected_site($pdo);$siteFlags=site_record_flags($siteId);$demo=(active_mode()==='DEMO'||!empty($d['is_demo'])||$test||$siteFlags['is_demo'])?1:0;$rehearsal=(!empty($d['is_rehearsal'])||$siteFlags['is_rehearsal'])?1:0;
    $baseId=trim((string)$r['base_id']);if(!valid_node_code($pdo,$baseId,'BASE_CAMP'))throw new InvalidArgumentException('reception.base_id must identify an enabled Base Camp Node');
    $path=$r['path_type'];if(!in_array($path,['DIRECT','CHECKPOINT_RELAY'],true))throw new InvalidArgumentException('reception.path_type must be DIRECT or CHECKPOINT_RELAY');
    $checkpointId=null;$checkpointRssi=null;if($path==='CHECKPOINT_RELAY'){$checkpointId=trim((string)($r['checkpoint_id']??''));if(!valid_node_code($pdo,$checkpointId,'CHECKPOINT'))throw new InvalidArgumentException('checkpoint_id must identify an enabled Checkpoint Node');$checkpointRssi=$r['checkpoint_rssi_dbm']??null;if(filter_var($checkpointRssi,FILTER_VALIDATE_INT)===false||(int)$checkpointRssi< -200||(int)$checkpointRssi>100)throw new InvalidArgumentException('checkpoint_rssi_dbm must be -200–100');}elseif(isset($r['checkpoint_id'])||isset($r['checkpoint_rssi_dbm']))throw new InvalidArgumentException('direct reception cannot carry checkpoint relay fields');
    $rssi=$r['rssi_dbm']??null;$snr=$r['snr_db']??null;$hop=$r['relay_hop_delay_ms']??null;if($rssi!==null&&(filter_var($rssi,FILTER_VALIDATE_INT)===false||(int)$rssi< -200||(int)$rssi>100))throw new InvalidArgumentException('rssi_dbm must be -200–100');if($snr!==null&&(!is_numeric($snr)||!is_finite((float)$snr)||(float)$snr< -50||(float)$snr>50))throw new InvalidArgumentException('snr_db must be -50–50');if($hop!==null&&(filter_var($hop,FILTER_VALIDATE_INT)===false||(int)$hop<0||(int)$hop>300000))throw new InvalidArgumentException('relay_hop_delay_ms must be 0–300000');
    $heardBy=$path==='CHECKPOINT_RELAY'?$checkpointId:$baseId;$received=$r['received_at']??gmdate('Y-m-d H:i:s');$dt=date_create((string)$received);if(!$dt)throw new InvalidArgumentException('reception.received_at must be a valid date/time');$received=$dt->format('Y-m-d H:i:s');
    $h=$pdo->prepare('SELECT id FROM hiker_nodes WHERE node_id=? AND is_enabled=1');$h->execute([$id]);$hiker=$h->fetchColumn();if(!$hiker)throw new InvalidArgumentException('unknown or disabled hiker node_id');
    $zone=active_zone($pdo,$siteId);$inside=$zone?point_in_polygon($lat,$lng,array_map(fn($v)=>[$v['latitude'],$v['longitude']],$zone['vertices'])):null;
    $pdo->beginTransaction();try{
      $q=$pdo->prepare('SELECT id FROM uplink_packets WHERE site_id=? AND hiker_node_id=? AND sequence_number=?');$q->execute([$siteId,$hiker,(int)$seq]);$existing=$q->fetchColumn();$duplicate=(bool)$existing;
      if($duplicate){$packet=(int)$existing;$q=$pdo->prepare("UPDATE uplink_packets SET is_duplicate=1,validation_status='DUPLICATE',is_demo=GREATEST(is_demo,?),is_rehearsal=GREATEST(is_rehearsal,?) WHERE id=?");$q->execute([$demo,$rehearsal,$packet]);}
      else{$class=$inside===null?'UNCLASSIFIED':($inside?'INSIDE':'OUTSIDE');$q=$pdo->prepare('INSERT INTO uplink_packets(hiker_node_id,site_id,sequence_number,latitude,longitude,gps_accuracy_m,satellite_count,battery_level,flags_json,classification,safe_zone_id,received_at,test_mode,is_demo,is_rehearsal) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');$q->execute([$hiker,$siteId,(int)$seq,$lat,$lng,$accuracy,$sats,$battery,json_value($flags),$class,$zone['id']??null,$received,$test?1:0,$demo,$rehearsal]);$packet=(int)$pdo->lastInsertId();}
      $q=$pdo->prepare('INSERT INTO packet_receptions(uplink_packet_id,received_by_node,base_id,checkpoint_id,checkpoint_rssi_dbm,is_duplicate,site_id,is_demo,is_rehearsal,path_type,relay_rssi_dbm,rssi_dbm,snr_db,received_at,relay_hop_delay_ms,raw_metadata,test_mode) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');$q->execute([$packet,$heardBy,$baseId,$checkpointId,$checkpointRssi,$duplicate?1:0,$siteId,$demo,$rehearsal,$path,$checkpointRssi,$rssi,$snr,$received,$hop,json_value(['checkpoint_id'=>$checkpointId,'checkpoint_rssi_dbm'=>$checkpointRssi]),$test?1:0]);$receptionId=(int)$pdo->lastInsertId();
      $route=uplink_reply_route($pdo,$packet);
      if($duplicate){$q=$pdo->prepare('UPDATE downlink_commands SET route_type=?,route_via_node_id=? WHERE hiker_node_id=? AND site_id=? AND sequence_number=? AND status=\'QUEUED\'');$q->execute([$route['route_type'],$route['route_via_node_id'],$hiker,$siteId,(int)$seq]);}
      $previousFlags=[];if(!$duplicate){$q=$pdo->prepare('SELECT flags_json,classification FROM uplink_packets WHERE hiker_node_id=? AND site_id=? AND id<? ORDER BY id DESC LIMIT 1');$q->execute([$hiker,$siteId,$packet]);$prev=$q->fetch();if($prev)$previousFlags=json_decode($prev['flags_json']??'{}',true)?:[];
        $addEvent=function(string $type,string $severity,string $message,array $meta=[])use($pdo,$hiker,$siteId,$test,$demo,$rehearsal){$q=$pdo->prepare('INSERT INTO system_events(hiker_node_id,event_type,severity,message,metadata_json,test_mode,is_demo,is_rehearsal,site_id) VALUES(?,?,?,?,?,?,?,?,?)');$q->execute([$hiker,$type,$severity,$message,json_value($meta),$test?1:0,$demo,$rehearsal,$siteId]);};
        if($flags['turn_back_received']){$q=$pdo->prepare("UPDATE downlink_commands SET status='CANCELLED',failure_reason='Storm campaign ended after received flag' WHERE storm_campaign_id IN (SELECT id FROM storm_turnback_campaigns WHERE hiker_node_id=? AND site_id=? AND status='ACTIVE') AND status='QUEUED'");$q->execute([$hiker,$siteId]);$q=$pdo->prepare("UPDATE storm_turnback_campaigns SET status='RECEIVED',received_at=CURRENT_TIMESTAMP(3) WHERE hiker_node_id=? AND site_id=? AND status='ACTIVE'");$q->execute([$hiker,$siteId]);}
        if($flags['emergency']&&!($previousFlags['emergency']??false))$addEvent('EMERGENCY','CRITICAL','Hiker emergency flag received',['packet_id'=>$packet,'node_id'=>$id]);
        if($inside===false)$addEvent('OFF_TRAIL','WARNING','Position classified outside active safe zone',['packet_id'=>$packet,'latitude'=>$lat,'longitude'=>$lng]);
        if($flags['low_battery']&&!($previousFlags['low_battery']??false))$addEvent('LOW_BATTERY','WARNING','Hiker reported low battery',['packet_id'=>$packet,'battery_level'=>$battery]);
        if($flags['turn_back_received']&&!($previousFlags['turn_back_received']??false))$addEvent('TURN_BACK_RECEIVED','INFO','Hiker confirmed storm turn-back message',['packet_id'=>$packet]);
        if($inside===true){$q=$pdo->prepare('SELECT classification FROM uplink_packets WHERE hiker_node_id=? AND site_id=? AND is_duplicate=0 AND id<? ORDER BY id DESC LIMIT 1');$q->execute([$hiker,$siteId,$packet]);$prevClass=$q->fetchColumn();if($prevClass==='OUTSIDE'){$addEvent('GEOFENCE_RETURN','INFO','Hiker returned inside active safe zone',['packet_id'=>$packet,'latitude'=>$lat,'longitude'=>$lng]);$pdo->prepare("UPDATE system_events SET resolved_at=NOW(),resolved_by=? WHERE hiker_node_id=? AND site_id=? AND event_type='OFF_TRAIL' AND resolved_at IS NULL")->execute(['system:geofence_return',$hiker,$siteId]);}}
        $eligibleCommands=[];if($inside===false)$eligibleCommands[]='OFF_TRAIL_ALERT';else $eligibleCommands[]='ACK';$activeCampaign=null;
        if(phase_mode($pdo)!=='PHASE2'){$q=$pdo->prepare("SELECT id,test_mode,is_demo,is_rehearsal FROM storm_turnback_campaigns WHERE hiker_node_id=? AND site_id=? AND status='ACTIVE' ORDER BY id DESC LIMIT 1");$q->execute([$hiker,$siteId]);$activeCampaign=$q->fetch()?:null;if($activeCampaign)$eligibleCommands[]='STORM_TURN_BACK';}
        if($flags['emergency'])$eligibleCommands[]='SOS_ACK';$command=select_uplink_downlink($eligibleCommands);
        if($command!==null){$commandTestMode=$test?1:0;$commandDemo=$demo;$commandRehearsal=$rehearsal;if($command==='STORM_TURN_BACK'&&$activeCampaign){$commandTestMode=max($commandTestMode,(int)$activeCampaign['test_mode']);$commandDemo=max($commandDemo,(int)$activeCampaign['is_demo'],$commandTestMode);$commandRehearsal=max($commandRehearsal,(int)$activeCampaign['is_rehearsal']);}$stormCampaignId=($command==='STORM_TURN_BACK'&&$activeCampaign)?(int)$activeCampaign['id']:null;$q=$pdo->prepare('INSERT INTO downlink_commands(hiker_node_id,site_id,base_id,sequence_number,storm_campaign_id,route_type,route_via_node_id,command_type,payload_json,test_mode,is_demo,is_rehearsal) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');$q->execute([$hiker,$siteId,$baseId,(int)$seq,$stormCampaignId,$route['route_type'],$route['route_via_node_id'],$command,json_value(['packet_id'=>$packet]),$commandTestMode,$commandDemo,$commandRehearsal]);if($command==='STORM_TURN_BACK'&&$activeCampaign){$q=$pdo->prepare('UPDATE storm_turnback_campaigns SET last_queued_at=CURRENT_TIMESTAMP(3) WHERE id=?');$q->execute([(int)$activeCampaign['id']]);}}
      }
      $pdo->commit();return ['packet_id'=>$packet,'reception_id'=>$receptionId,'duplicate'=>$duplicate,'classification'=>$inside===null?'UNCLASSIFIED':($inside?'INSIDE':'OUTSIDE'),'route'=>$route,'test_mode'=>$test,'is_demo'=>(bool)$demo,'is_rehearsal'=>(bool)$rehearsal,'command_queued'=>!$duplicate&&$command!==null];
    }catch(Throwable $e){$pdo->rollBack();throw $e;}
}
function research_summary(PDO $pdo): array {
    $packetEligible=research_flags_eligible_sql('p')." AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(p.flags_json,'$.test_mode')),'false') NOT IN ('true','1')";
    $packetEligibleBare="is_demo=0 AND is_rehearsal=0 AND test_mode=0 AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(flags_json,'$.test_mode')),'false') NOT IN ('true','1')";
    $eventPacketEligible=str_replace('p.','ep.',$packetEligible);
    $commandPacketEligible=str_replace('p.','cp.',$packetEligible);
    $eventEligible=research_flags_eligible_sql('e')." AND (JSON_EXTRACT(e.metadata_json,'$.packet_id') IS NULL OR EXISTS (SELECT 1 FROM uplink_packets ep WHERE ep.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(e.metadata_json,'$.packet_id')) AS UNSIGNED) AND $eventPacketEligible))";
    $commandEligible=research_flags_eligible_sql('c')." AND NOT EXISTS (SELECT 1 FROM uplink_packets cp WHERE cp.hiker_node_id=c.hiker_node_id AND cp.site_id=c.site_id AND cp.sequence_number=c.sequence_number AND NOT ($commandPacketEligible))";
    $q=$pdo->query("SELECT COUNT(*) packet_count,COALESCE(SUM(classification='INSIDE'),0) inside_count,COALESCE(SUM(classification='OUTSIDE'),0) outside_count FROM uplink_packets WHERE $packetEligibleBare");$summary=$q->fetch();
    $q=$pdo->query("SELECT COUNT(*) excluded_packet_count FROM uplink_packets WHERE NOT ($packetEligibleBare)");$summary['excluded_packet_count']=(int)$q->fetchColumn();
    $q=$pdo->query("SELECT COUNT(*) event_count,COALESCE(SUM(event_type='OFF_TRAIL'),0) alert_event_count FROM system_events e WHERE $eventEligible");$eventCounts=$q->fetch();
    $q=$pdo->query("SELECT COUNT(*) excluded_event_count,COALESCE(SUM(event_type='OFF_TRAIL'),0) excluded_alert_event_count FROM system_events e WHERE NOT ($eventEligible)");$eventExcluded=$q->fetch();
    $q=$pdo->query("SELECT COUNT(*) command_count,COALESCE(SUM(command_type='OFF_TRAIL_ALERT'),0) alert_command_count FROM downlink_commands c WHERE $commandEligible");$commandCounts=$q->fetch();
    $q=$pdo->query("SELECT COUNT(*) excluded_command_count,COALESCE(SUM(command_type='OFF_TRAIL_ALERT'),0) excluded_alert_command_count FROM downlink_commands c WHERE NOT ($commandEligible)");$commandExcluded=$q->fetch();
    return array_merge($summary,array_map('intval',$eventCounts),array_map('intval',$eventExcluded),array_map('intval',$commandCounts),array_map('intval',$commandExcluded));
}
