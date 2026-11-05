<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../geofence.php';

function json_value($value): ?string { return $value === null ? null : json_encode($value, JSON_THROW_ON_ERROR); }
function packet_is_demo_or_test(array $data): bool {
    $u=is_array($data['uplink']??null)?$data['uplink']:[];$f=is_array($u['flags']??null)?$u['flags']:[];
    return !empty($data['is_demo'])||!empty($data['is_rehearsal'])||!empty($f['test_mode'])||!empty($data['test_mode']);
}
function should_reject_live_packet(string $mode,array $data,?string $databaseName=null,bool $scratchException=false): bool {
    if($mode!=='LIVE'||!packet_is_demo_or_test($data))return false;
    return !($scratchException&&$databaseName!==null&&preg_match('/^hiker_dashboard_scratch_[a-f0-9]+$/',$databaseName)===1);
}
function clear_demo_allowed(string $mode): bool { return $mode==='DEMO'; }
function site_allowed_in_mode(string $mode,array $site): bool { return $mode!=='LIVE'||(empty($site['is_demo'])&&empty($site['is_rehearsal'])); }
function demo_row_count(PDO $pdo): int {
    $sum=0;$filters=['uplink_packets'=>'is_demo=1 OR is_rehearsal=1 OR test_mode=1','system_events'=>'is_demo=1 OR is_rehearsal=1 OR test_mode=1','downlink_commands'=>'is_demo=1 OR is_rehearsal=1 OR test_mode=1','packet_receptions'=>'is_demo=1 OR is_rehearsal=1 OR test_mode=1','safe_zones'=>'is_demo=1 OR is_rehearsal=1','storm_turnback_campaigns'=>'is_demo=1 OR is_rehearsal=1 OR test_mode=1','operator_actions'=>'is_demo=1 OR is_rehearsal=1 OR test_mode=1','trail_reference_points'=>'is_demo=1'];foreach($filters as $table=>$where)$sum+=(int)$pdo->query("SELECT COUNT(*) FROM $table WHERE $where")->fetchColumn();return $sum;
}
function gps_valid_fix($accuracy,$satellites): bool { return $accuracy!==null&&$satellites!==null&&is_numeric($accuracy)&&(float)$accuracy<=10&&(int)$satellites>=5; }
function source_label(array $r,?string $mode=null): string { $mode=$mode??active_mode();return !empty($r['is_rehearsal'])?'REHEARSAL':(!empty($r['test_mode'])?'TEST':(!empty($r['is_demo'])?'DEMO':(!empty($r['is_bench'])?'BENCH':($mode==='DEMO'?'DEMO':'REAL')))); }
function research_flags_eligible_sql(string $alias): string {
    return "$alias.is_demo=0 AND $alias.is_rehearsal=0 AND $alias.test_mode=0 AND $alias.is_bench=0";
}
function bench_session_enabled(PDO $pdo,bool $lockForIngest=false): bool {
    $q=$pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key='bench_session_enabled'".($lockForIngest?' FOR UPDATE':''));$q->execute();return (string)$q->fetchColumn()==='1';
}
function explicit_timestamp_utc(string $value,string $field): DateTimeImmutable {
    if(!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})$/i',$value))throw new InvalidArgumentException("$field must include an explicit timezone (ISO 8601)");
    $parts=date_parse($value);if(($parts['error_count']??0)>0||($parts['warning_count']??0)>0)throw new InvalidArgumentException("$field must be a valid ISO 8601 date/time");
    try{return(new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));}catch(Throwable $e){throw new InvalidArgumentException("$field must be a valid ISO 8601 date/time");}
}
function mark_packet_range_bench(PDO $pdo,array $data): array {
    if(array_diff(array_keys($data),['site_id','node_id','from','to','confirmation']))throw new InvalidArgumentException('range request contains an unsupported field');
    if(($data['confirmation']??'')!=='MARK BENCH')throw new InvalidArgumentException('type MARK BENCH to confirm');
    if(!is_string($data['site_id']??null))throw new InvalidArgumentException('site_id is required');$site=$data['site_id'];if(!isset(site_config()[$site]))throw new InvalidArgumentException('unknown site_id');
    if(!is_string($data['node_id']??null))throw new InvalidArgumentException('node_id is required');$node=trim($data['node_id']);if($node===''||strlen($node)>16||!preg_match('/^[A-Za-z0-9_-]+$/',$node))throw new InvalidArgumentException('node_id must contain 1–16 letters, numbers, underscore, or hyphen');
    if(!is_string($data['from']??null)||!is_string($data['to']??null))throw new InvalidArgumentException('from and to are required');$fromRaw=trim($data['from']);$toRaw=trim($data['to']);if($fromRaw===''||$toRaw==='')throw new InvalidArgumentException('from and to are required');$from=explicit_timestamp_utc($fromRaw,'from');$to=explicit_timestamp_utc($toRaw,'to');
    $fromValue=$from->format('Y-m-d H:i:s');$toValue=$to->format('Y-m-d H:i:s');if($fromValue>=$toValue)throw new InvalidArgumentException('from must be earlier than to');
    $h=$pdo->prepare('SELECT id FROM hiker_nodes WHERE node_id=?');$h->execute([$node]);$hikerId=(int)$h->fetchColumn();if(!$hikerId)throw new InvalidArgumentException('unknown hiker node_id');
    $q=$pdo->prepare('SELECT id,sequence_number FROM uplink_packets WHERE site_id=? AND hiker_node_id=? AND received_at>=? AND received_at<? AND is_demo=0 AND is_rehearsal=0 AND test_mode=0 AND is_bench=0 AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(flags_json,\'$.test_mode\')),\'false\') NOT IN (\'true\',\'1\') ORDER BY id');$q->execute([$site,$hikerId,$fromValue,$toValue]);$packets=$q->fetchAll();
    $pdo->beginTransaction();try{
        $reception=$pdo->prepare('UPDATE packet_receptions SET is_bench=1 WHERE uplink_packet_id=? AND is_demo=0 AND is_rehearsal=0 AND test_mode=0');
        $event=$pdo->prepare("UPDATE system_events SET is_bench=1 WHERE CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.packet_id')) AS UNSIGNED)=? AND is_demo=0 AND is_rehearsal=0 AND test_mode=0");
        $command=$pdo->prepare('UPDATE downlink_commands SET is_bench=1 WHERE site_id=? AND hiker_node_id=? AND sequence_number=? AND is_demo=0 AND is_rehearsal=0 AND test_mode=0');
        foreach($packets as $packet){$reception->execute([(int)$packet['id']]);$event->execute([(int)$packet['id']]);$command->execute([$site,$hikerId,(int)$packet['sequence_number']]);}
        $update=$pdo->prepare('UPDATE uplink_packets SET is_bench=1 WHERE id=? AND is_demo=0 AND is_rehearsal=0 AND test_mode=0 AND is_bench=0');foreach($packets as $packet)$update->execute([(int)$packet['id']]);
        $siteFlags=site_record_flags($site);
        $audit=$pdo->prepare("INSERT INTO operator_actions(action_type,target_node_id,details_json,test_mode,is_demo,is_rehearsal,is_bench,site_id) VALUES('PACKET_RANGE_MARKED_BENCH',?,?,0,?,?,1,?)");$audit->execute([$hikerId?:null,json_value(['site_id'=>$site,'node_id'=>$node,'from'=>$fromValue,'to'=>$toValue,'packet_count'=>count($packets)]),$siteFlags['is_demo'],$siteFlags['is_rehearsal'],$site]);
        $pdo->commit();return ['ok'=>true,'site_id'=>$site,'node_id'=>$node,'updated_packet_count'=>count($packets)];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function schema_table_exists(PDO $pdo,string $table): bool {
    $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? AND table_type=\'BASE TABLE\'');$q->execute([$table]);return (int)$q->fetchColumn()>0;
}
function schema_column_exists(PDO $pdo,string $table,string $column): bool {
    $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');$q->execute([$table,$column]);return (int)$q->fetchColumn()>0;
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
    $hasRing=schema_column_exists($pdo,'safe_zone_vertices','ring_index');
    $q=$pdo->prepare('SELECT '.($hasRing?'ring_index':'0 AS ring_index').',latitude,longitude FROM safe_zone_vertices WHERE safe_zone_id=? ORDER BY '.($hasRing?'ring_index,':'').'vertex_order');$q->execute([$z['id']]);
    $byRing=[];
    foreach($q->fetchAll() as $v){$byRing[(int)$v['ring_index']][]=['latitude'=>(float)$v['latitude'],'longitude'=>(float)$v['longitude']];}
    ksort($byRing);
    $z['rings']=array_values($byRing);          // [outer, inner?]  inner = hole (middle of a loop trail)
    $z['vertices']=$z['rings'][0]??[];          // kept for older consumers: the OUTER ring only
    return $z;
}
function save_zone(PDO $pdo,array $data): array {
    $siteId=(string)($data['site_id']??selected_site($pdo));if(!isset(site_config()[$siteId]))throw new InvalidArgumentException('unknown site_id');
    $name=trim((string)($data['name']??''));$createdBy=trim((string)($data['created_by']??'local-operator'));
    if($name===''||strlen($name)>100)throw new InvalidArgumentException('name must be 1–100 characters');
    if($createdBy===''||strlen($createdBy)>100)throw new InvalidArgumentException('created_by must be 1–100 characters');
    if(array_key_exists('rings',$data)){
        $rawRings=$data['rings'];
        if(!is_array($rawRings)||count($rawRings)<1||count($rawRings)>2)throw new InvalidArgumentException('rings must contain 1 or 2 rings (outer edge, optional inner edge)');
    }else{
        $vertices=$data['vertices']??null;
        if(!is_array($vertices)||count($vertices)<3||count($vertices)>500)throw new InvalidArgumentException('vertices must contain 3–500 coordinate pairs');
        $rawRings=[$vertices];
    }
    $clean=[];$total=0;
    foreach(array_values($rawRings) as $ringIn){
        if(!is_array($ringIn)||count($ringIn)<3)throw new InvalidArgumentException('each ring needs at least three coordinate pairs');
        $total+=count($ringIn);
        $ring=[];foreach($ringIn as $v){if(!is_array($v)||!isset($v['latitude'],$v['longitude'])||!is_numeric($v['latitude'])||!is_numeric($v['longitude']))throw new InvalidArgumentException('each vertex needs numeric latitude and longitude');$lat=(float)$v['latitude'];$lng=(float)$v['longitude'];if(!is_finite($lat)||!is_finite($lng)||abs($lat)>90||abs($lng)>180)throw new InvalidArgumentException('vertex coordinate out of range');$ring[]=[$lat,$lng];}
        if(count(array_unique(array_map(fn($p)=>sprintf('%.7f,%.7f',$p[0],$p[1]),$ring)))<3)throw new InvalidArgumentException('polygon needs at least three distinct vertices');
        if(polygon_self_intersects($ring))throw new InvalidArgumentException('polygon crosses itself; adjust the vertex order');
        $clean[]=$ring;
    }
    if($total>500)throw new InvalidArgumentException('a safe zone can have at most 500 vertices in total');
    if(count($clean)===2){
        if(rings_cross($clean[0],$clean[1]))throw new InvalidArgumentException('inner and outer edges cross; use a smaller corridor half-width');
        if(!point_in_polygon($clean[1][0][0],$clean[1][0][1],$clean[0]))throw new InvalidArgumentException('inner ring must lie inside the outer ring');
    }
    $hasRing=schema_column_exists($pdo,'safe_zone_vertices','ring_index');
    if(count($clean)>1&&!$hasRing)throw new InvalidArgumentException('database migration 008 (safe_zone_vertices.ring_index) must be applied before saving a loop corridor');
    $flags=site_record_flags($siteId);if(isset($data['is_demo'])&&!is_bool($data['is_demo']))throw new InvalidArgumentException('is_demo must be boolean');if(!empty($data['is_demo']))$flags['is_demo']=1;
    $pdo->beginTransaction();try{
        $q=$pdo->prepare('SELECT COALESCE(MAX(version),0)+1 FROM safe_zones WHERE site_id=?');$q->execute([$siteId]);$version=(int)$q->fetchColumn();
        $q=$pdo->prepare('UPDATE safe_zones SET is_active=0 WHERE site_id=? AND is_active=1');$q->execute([$siteId]);
        $q=$pdo->prepare('INSERT INTO safe_zones(name,version,site_id,is_demo,is_rehearsal,is_active,created_by) VALUES(?,?,?,?,?,1,?)');$q->execute([$name,$version,$siteId,$flags['is_demo'],$flags['is_rehearsal'],$createdBy]);$id=(int)$pdo->lastInsertId();
        if($hasRing){$q=$pdo->prepare('INSERT INTO safe_zone_vertices(safe_zone_id,ring_index,vertex_order,latitude,longitude) VALUES(?,?,?,?,?)');foreach($clean as $r=>$ring)foreach($ring as $i=>$p)$q->execute([$id,$r,$i+1,$p[0],$p[1]]);}
        else{$q=$pdo->prepare('INSERT INTO safe_zone_vertices(safe_zone_id,vertex_order,latitude,longitude) VALUES(?,?,?,?)');foreach($clean[0] as $i=>$p)$q->execute([$id,$i+1,$p[0],$p[1]]);}
        $q=$pdo->prepare("INSERT INTO operator_actions(action_type,details_json,test_mode,is_demo,is_rehearsal,site_id) VALUES('SAFE_ZONE_UPDATED',?,0,?,?,?)");$q->execute([json_value(['safe_zone_id'=>$id,'version'=>$version,'site_id'=>$siteId,'rings'=>count($clean)]),$flags['is_demo'],$flags['is_rehearsal'],$siteId]);
        $pdo->commit();return active_zone($pdo,$siteId);
    }catch(Throwable $e){$pdo->rollBack();throw $e;}
}
function polygon_self_intersects(array $p): bool {   // UNCHANGED
    $n=count($p);$cross=fn($a,$b,$c)=>(($b[1]-$a[1])*($c[0]-$a[0])-($b[0]-$a[0])*($c[1]-$a[1]));
    $on=fn($a,$b,$c)=>min($a[0],$b[0])-1e-10<=$c[0]&&$c[0]<=max($a[0],$b[0])+1e-10&&min($a[1],$b[1])-1e-10<=$c[1]&&$c[1]<=max($a[1],$b[1])+1e-10;
    for($i=0;$i<$n;$i++){ $a=$p[$i];$b=$p[($i+1)%$n];for($j=$i+1;$j<$n;$j++){if($j===$i||($j+1)%$n===$i||($i+1)%$n===$j)continue;$c=$p[$j];$d=$p[($j+1)%$n];$o1=$cross($a,$b,$c);$o2=$cross($a,$b,$d);$o3=$cross($c,$d,$a);$o4=$cross($c,$d,$b);if(($o1*$o2)<0&&($o3*$o4)<0||abs($o1)<1e-12&&$on($a,$b,$c)||abs($o2)<1e-12&&$on($a,$b,$d)||abs($o3)<1e-12&&$on($c,$d,$a)||abs($o4)<1e-12&&$on($c,$d,$b))return true;}}
    return false;
}
/** True when any edge of ring $a touches or crosses any edge of ring $b. Points are [lat,lng]. */
function rings_cross(array $a,array $b): bool {
    $cross=fn($p,$q,$r)=>(($q[1]-$p[1])*($r[0]-$p[0])-($q[0]-$p[0])*($r[1]-$p[1]));
    $on=fn($p,$q,$r)=>min($p[0],$q[0])-1e-10<=$r[0]&&$r[0]<=max($p[0],$q[0])+1e-10&&min($p[1],$q[1])-1e-10<=$r[1]&&$r[1]<=max($p[1],$q[1])+1e-10;
    $na=count($a);$nb=count($b);
    for($i=0;$i<$na;$i++){$p1=$a[$i];$p2=$a[($i+1)%$na];
        for($j=0;$j<$nb;$j++){$p3=$b[$j];$p4=$b[($j+1)%$nb];
            $o1=$cross($p1,$p2,$p3);$o2=$cross($p1,$p2,$p4);$o3=$cross($p3,$p4,$p1);$o4=$cross($p3,$p4,$p2);
            if((($o1*$o2)<0&&($o3*$o4)<0)||(abs($o1)<1e-12&&$on($p1,$p2,$p3))||(abs($o2)<1e-12&&$on($p1,$p2,$p4))||(abs($o3)<1e-12&&$on($p3,$p4,$p1))||(abs($o4)<1e-12&&$on($p3,$p4,$p2)))return true;
        }}
    return false;
}
function valid_node_code(PDO $pdo,string $code,string $type,?string $siteId=null): bool {
    if(!preg_match('/^[A-Za-z0-9_-]{1,16}$/',$code))return false;
    if(!schema_column_exists($pdo,'network_nodes','site_id')){$q=$pdo->prepare('SELECT 1 FROM network_nodes WHERE node_code=? AND node_type=? AND is_enabled=1');$q->execute([$code,$type]);return(bool)$q->fetchColumn();}
    $site=$siteId??selected_site($pdo);$q=$pdo->prepare('SELECT 1 FROM network_nodes WHERE node_code=? AND node_type=? AND is_enabled=1 AND site_id=?');$q->execute([$code,$type,$site]);if($q->fetchColumn())return true;
    $q=$pdo->prepare('SELECT COUNT(*) FROM network_nodes WHERE site_id=?');$q->execute([$site]);if((int)$q->fetchColumn()>0)return false;
    $expected=$type==='BASE_CAMP'?'BASE':($type==='CHECKPOINT'?'CHK':null);$configured=site_config()[$site]['network_nodes']??[];return $expected===$code&&array_key_exists($expected,$configured);
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
function ingest_packet(PDO $pdo,array $d,bool $dryRun=false): array {
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
    $siteId=selected_site($pdo);$siteFlags=site_record_flags($siteId);$demo=(active_mode()==='DEMO'||!empty($d['is_demo'])||$test||$siteFlags['is_demo'])?1:0;$rehearsal=(!empty($d['is_rehearsal'])||$siteFlags['is_rehearsal'])?1:0;$bench=0;
    $baseId=trim((string)$r['base_id']);if(!valid_node_code($pdo,$baseId,'BASE_CAMP',$siteId))throw new InvalidArgumentException('reception.base_id must identify an enabled Base Camp Node');
    $path=$r['path_type'];if(!in_array($path,['DIRECT','CHECKPOINT_RELAY'],true))throw new InvalidArgumentException('reception.path_type must be DIRECT or CHECKPOINT_RELAY');
    $checkpointId=null;$checkpointRssi=null;if($path==='CHECKPOINT_RELAY'){$checkpointId=trim((string)($r['checkpoint_id']??''));if(!valid_node_code($pdo,$checkpointId,'CHECKPOINT',$siteId))throw new InvalidArgumentException('checkpoint_id must identify an enabled Checkpoint Node');$checkpointRssi=$r['checkpoint_rssi_dbm']??null;if(filter_var($checkpointRssi,FILTER_VALIDATE_INT)===false||(int)$checkpointRssi< -200||(int)$checkpointRssi>100)throw new InvalidArgumentException('checkpoint_rssi_dbm must be -200–100');}elseif(isset($r['checkpoint_id'])||isset($r['checkpoint_rssi_dbm']))throw new InvalidArgumentException('direct reception cannot carry checkpoint relay fields');
    $rssi=$r['rssi_dbm']??null;$snr=$r['snr_db']??null;$hop=$r['relay_hop_delay_ms']??null;if($rssi!==null&&(filter_var($rssi,FILTER_VALIDATE_INT)===false||(int)$rssi< -200||(int)$rssi>100))throw new InvalidArgumentException('rssi_dbm must be -200–100');if($snr!==null&&(!is_numeric($snr)||!is_finite((float)$snr)||(float)$snr< -50||(float)$snr>50))throw new InvalidArgumentException('snr_db must be -50–50');if($hop!==null&&(filter_var($hop,FILTER_VALIDATE_INT)===false||(int)$hop<0||(int)$hop>300000))throw new InvalidArgumentException('relay_hop_delay_ms must be 0–300000');
    $heardBy=$path==='CHECKPOINT_RELAY'?$checkpointId:$baseId;$received=$r['received_at']??gmdate('Y-m-d H:i:s');$dt=date_create((string)$received);if(!$dt)throw new InvalidArgumentException('reception.received_at must be a valid date/time');$received=$dt->format('Y-m-d H:i:s');
    $h=$pdo->prepare('SELECT id FROM hiker_nodes WHERE node_id=? AND is_enabled=1');$h->execute([$id]);$hiker=$h->fetchColumn();if(!$hiker)throw new InvalidArgumentException('unknown or disabled hiker node_id');
    $zone=active_zone($pdo,$siteId);$inside=$zone?point_in_polygon($lat,$lng,array_map(fn($ring)=>array_map(fn($v)=>[$v['latitude'],$v['longitude']],$ring),$zone['rings'])):null;
    $pdo->beginTransaction();try{
      $bench=(active_mode()==='LIVE'&&bench_session_enabled($pdo,true))?1:0;
      $q=$pdo->prepare('SELECT id FROM uplink_packets WHERE site_id=? AND hiker_node_id=? AND sequence_number=?');$q->execute([$siteId,$hiker,(int)$seq]);$existing=$q->fetchColumn();$duplicate=(bool)$existing;
      $packetBench=$bench;
      if($duplicate){$packet=(int)$existing;$q=$pdo->prepare('SELECT is_bench FROM uplink_packets WHERE id=?');$q->execute([$packet]);$packetBench=max($bench,(int)$q->fetchColumn());$q=$pdo->prepare("UPDATE uplink_packets SET is_duplicate=1,validation_status='DUPLICATE',is_demo=GREATEST(is_demo,?),is_rehearsal=GREATEST(is_rehearsal,?),is_bench=GREATEST(is_bench,?) WHERE id=?");$q->execute([$demo,$rehearsal,$packetBench,$packet]);}
      else{$class=$inside===null?'UNCLASSIFIED':($inside?'INSIDE':'OUTSIDE');$q=$pdo->prepare('INSERT INTO uplink_packets(hiker_node_id,site_id,sequence_number,latitude,longitude,gps_accuracy_m,satellite_count,battery_level,flags_json,classification,safe_zone_id,received_at,test_mode,is_demo,is_rehearsal,is_bench) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');$q->execute([$hiker,$siteId,(int)$seq,$lat,$lng,$accuracy,$sats,$battery,json_value($flags),$class,$zone['id']??null,$received,$test?1:0,$demo,$rehearsal,$packetBench]);$packet=(int)$pdo->lastInsertId();}
      $q=$pdo->prepare('INSERT INTO packet_receptions(uplink_packet_id,received_by_node,base_id,checkpoint_id,checkpoint_rssi_dbm,is_duplicate,site_id,is_demo,is_rehearsal,path_type,relay_rssi_dbm,rssi_dbm,snr_db,received_at,relay_hop_delay_ms,raw_metadata,test_mode,is_bench) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');$q->execute([$packet,$heardBy,$baseId,$checkpointId,$checkpointRssi,$duplicate?1:0,$siteId,$demo,$rehearsal,$path,$checkpointRssi,$rssi,$snr,$received,$hop,json_value(['checkpoint_id'=>$checkpointId,'checkpoint_rssi_dbm'=>$checkpointRssi]),$test?1:0,$packetBench]);$receptionId=(int)$pdo->lastInsertId();
      $route=uplink_reply_route($pdo,$packet);
      if($duplicate){$q=$pdo->prepare('UPDATE downlink_commands SET route_type=?,route_via_node_id=? WHERE hiker_node_id=? AND site_id=? AND sequence_number=? AND status=\'QUEUED\'');$q->execute([$route['route_type'],$route['route_via_node_id'],$hiker,$siteId,(int)$seq]);if($packetBench){$q=$pdo->prepare('UPDATE downlink_commands SET is_bench=1 WHERE hiker_node_id=? AND site_id=? AND sequence_number=? AND is_demo=0 AND is_rehearsal=0 AND test_mode=0');$q->execute([$hiker,$siteId,(int)$seq]);$q=$pdo->prepare("UPDATE system_events SET is_bench=1 WHERE CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.packet_id')) AS UNSIGNED)=? AND is_demo=0 AND is_rehearsal=0 AND test_mode=0");$q->execute([$packet]);}}
      $previousFlags=[];if(!$duplicate){$q=$pdo->prepare('SELECT flags_json,classification FROM uplink_packets WHERE hiker_node_id=? AND site_id=? AND id<? ORDER BY id DESC LIMIT 1');$q->execute([$hiker,$siteId,$packet]);$prev=$q->fetch();if($prev)$previousFlags=json_decode($prev['flags_json']??'{}',true)?:[];
        $addEvent=function(string $type,string $severity,string $message,array $meta=[])use($pdo,$hiker,$siteId,$test,$demo,$rehearsal,$packetBench){$q=$pdo->prepare('INSERT INTO system_events(hiker_node_id,event_type,severity,message,metadata_json,test_mode,is_demo,is_rehearsal,is_bench,site_id) VALUES(?,?,?,?,?,?,?,?,?,?)');$q->execute([$hiker,$type,$severity,$message,json_value($meta),$test?1:0,$demo,$rehearsal,$packetBench,$siteId]);};
        if($flags['turn_back_received']){$q=$pdo->prepare("UPDATE downlink_commands SET status='CANCELLED',failure_reason='Storm campaign ended after received flag' WHERE storm_campaign_id IN (SELECT id FROM storm_turnback_campaigns WHERE hiker_node_id=? AND site_id=? AND status='ACTIVE') AND status='QUEUED'");$q->execute([$hiker,$siteId]);$q=$pdo->prepare("UPDATE storm_turnback_campaigns SET status='RECEIVED',received_at=CURRENT_TIMESTAMP(3) WHERE hiker_node_id=? AND site_id=? AND status='ACTIVE'");$q->execute([$hiker,$siteId]);}
        if($flags['emergency']&&!($previousFlags['emergency']??false))$addEvent('EMERGENCY','CRITICAL','Hiker emergency flag received',['packet_id'=>$packet,'node_id'=>$id]);
        if($inside===false)$addEvent('OFF_TRAIL','WARNING','Position classified outside active safe zone',['packet_id'=>$packet,'latitude'=>$lat,'longitude'=>$lng]);
        if($flags['low_battery']&&!($previousFlags['low_battery']??false))$addEvent('LOW_BATTERY','WARNING','Hiker reported low battery',['packet_id'=>$packet,'battery_level'=>$battery]);
        if($flags['turn_back_received']&&!($previousFlags['turn_back_received']??false))$addEvent('TURN_BACK_RECEIVED','INFO','Hiker confirmed storm turn-back message',['packet_id'=>$packet]);
        if($inside===true){$q=$pdo->prepare('SELECT classification FROM uplink_packets WHERE hiker_node_id=? AND site_id=? AND is_duplicate=0 AND id<? ORDER BY id DESC LIMIT 1');$q->execute([$hiker,$siteId,$packet]);$prevClass=$q->fetchColumn();if($prevClass==='OUTSIDE'){$addEvent('GEOFENCE_RETURN','INFO','Hiker returned inside active safe zone',['packet_id'=>$packet,'latitude'=>$lat,'longitude'=>$lng]);$pdo->prepare("UPDATE system_events SET resolved_at=NOW(),resolved_by=? WHERE hiker_node_id=? AND site_id=? AND event_type='OFF_TRAIL' AND resolved_at IS NULL")->execute(['system:geofence_return',$hiker,$siteId]);}}
        $eligibleCommands=[];if($inside===false)$eligibleCommands[]='OFF_TRAIL_ALERT';else $eligibleCommands[]='ACK';$activeCampaign=null;
        if(phase_mode($pdo)!=='PHASE2'){$q=$pdo->prepare("SELECT id,test_mode,is_demo,is_rehearsal FROM storm_turnback_campaigns WHERE hiker_node_id=? AND site_id=? AND status='ACTIVE' ORDER BY id DESC LIMIT 1");$q->execute([$hiker,$siteId]);$activeCampaign=$q->fetch()?:null;if($activeCampaign)$eligibleCommands[]='STORM_TURN_BACK';}
        if($flags['emergency'])$eligibleCommands[]='SOS_ACK';$command=select_uplink_downlink($eligibleCommands);
        if($command!==null){$commandTestMode=$test?1:0;$commandDemo=$demo;$commandRehearsal=$rehearsal;if($command==='STORM_TURN_BACK'&&$activeCampaign){$commandTestMode=max($commandTestMode,(int)$activeCampaign['test_mode']);$commandDemo=max($commandDemo,(int)$activeCampaign['is_demo'],$commandTestMode);$commandRehearsal=max($commandRehearsal,(int)$activeCampaign['is_rehearsal']);}$stormCampaignId=($command==='STORM_TURN_BACK'&&$activeCampaign)?(int)$activeCampaign['id']:null;$q=$pdo->prepare('INSERT INTO downlink_commands(hiker_node_id,site_id,base_id,sequence_number,storm_campaign_id,route_type,route_via_node_id,command_type,payload_json,test_mode,is_demo,is_rehearsal,is_bench) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');$q->execute([$hiker,$siteId,$baseId,(int)$seq,$stormCampaignId,$route['route_type'],$route['route_via_node_id'],$command,json_value(['packet_id'=>$packet]),$commandTestMode,$commandDemo,$commandRehearsal,$packetBench]);if($command==='STORM_TURN_BACK'&&$activeCampaign){$q=$pdo->prepare('UPDATE storm_turnback_campaigns SET last_queued_at=CURRENT_TIMESTAMP(3) WHERE id=?');$q->execute([(int)$activeCampaign['id']]);}}
      }
      if($dryRun)$pdo->rollBack();else $pdo->commit();return ['packet_id'=>$packet,'reception_id'=>$receptionId,'duplicate'=>$duplicate,'classification'=>$inside===null?'UNCLASSIFIED':($inside?'INSIDE':'OUTSIDE'),'route'=>$route,'test_mode'=>$test,'is_demo'=>(bool)$demo,'is_rehearsal'=>(bool)$rehearsal,'is_bench'=>(bool)$packetBench,'command_queued'=>!$duplicate&&$command!==null];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function research_summary(PDO $pdo): array {
    $packetEligible=research_flags_eligible_sql('p')." AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(p.flags_json,'$.test_mode')),'false') NOT IN ('true','1')";
    $eventPacketEligible=str_replace('p.','ep.',$packetEligible);
    $commandPacketEligible=str_replace('p.','cp.',$packetEligible);
    $eventEligible=research_flags_eligible_sql('e')." AND (JSON_EXTRACT(e.metadata_json,'$.packet_id') IS NULL OR EXISTS (SELECT 1 FROM uplink_packets ep WHERE ep.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(e.metadata_json,'$.packet_id')) AS UNSIGNED) AND $eventPacketEligible))";
    $commandEligible=research_flags_eligible_sql('c')." AND NOT EXISTS (SELECT 1 FROM uplink_packets cp WHERE cp.hiker_node_id=c.hiker_node_id AND cp.site_id=c.site_id AND cp.sequence_number=c.sequence_number AND NOT ($commandPacketEligible))";
    $q=$pdo->query("SELECT COUNT(*) packet_count,COALESCE(SUM(p.classification='INSIDE'),0) inside_count,COALESCE(SUM(p.classification='OUTSIDE'),0) outside_count FROM uplink_packets p WHERE $packetEligible");$summary=$q->fetch();
    $q=$pdo->query("SELECT COUNT(*) excluded_packet_count FROM uplink_packets p WHERE NOT ($packetEligible)");$summary['excluded_packet_count']=(int)$q->fetchColumn();
    $q=$pdo->query("SELECT COUNT(*) event_count,COALESCE(SUM(event_type='OFF_TRAIL'),0) alert_event_count FROM system_events e WHERE $eventEligible");$eventCounts=$q->fetch();
    $q=$pdo->query("SELECT COUNT(*) excluded_event_count,COALESCE(SUM(event_type='OFF_TRAIL'),0) excluded_alert_event_count FROM system_events e WHERE NOT ($eventEligible)");$eventExcluded=$q->fetch();
    $q=$pdo->query("SELECT COUNT(*) command_count,COALESCE(SUM(command_type='OFF_TRAIL_ALERT'),0) alert_command_count FROM downlink_commands c WHERE $commandEligible");$commandCounts=$q->fetch();
    $q=$pdo->query("SELECT COUNT(*) excluded_command_count,COALESCE(SUM(command_type='OFF_TRAIL_ALERT'),0) excluded_alert_command_count FROM downlink_commands c WHERE NOT ($commandEligible)");$commandExcluded=$q->fetch();
    return array_merge($summary,array_map('intval',$eventCounts),array_map('intval',$eventExcluded),array_map('intval',$commandCounts),array_map('intval',$commandExcluded));
}
