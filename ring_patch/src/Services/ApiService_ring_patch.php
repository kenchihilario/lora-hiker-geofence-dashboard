<?php
/*
 * PATCH FOR src/Services/ApiService.php  (not a file to deploy as-is)
 *
 * 1. Replace the existing active_zone(), save_zone() and polygon_self_intersects() (original lines 79-103)
 *    with the functions below. polygon_self_intersects() is unchanged and repeated so the block is complete.
 * 2. Replace original line 147 (the $zone=active_zone(...)/$inside=... line in ingest_packet) with the
 *    one-line change at the bottom of this file.
 *
 * Needs src/geofence.php (ring-aware point_in_polygon) and the existing schema_column_exists() helper.
 * It also works on a database that has NOT yet had migration 008: single-ring zones behave exactly as before,
 * and saving a two-ring corridor is refused with a clear message until the migration is applied.
 */

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

/* ---- ingest_packet(): replace original line 147 with this line ---- */
// $zone=active_zone($pdo,$siteId);$inside=$zone?point_in_polygon($lat,$lng,array_map(fn($ring)=>array_map(fn($v)=>[$v['latitude'],$v['longitude']],$ring),$zone['rings'])):null;
