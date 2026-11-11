<?php
require_once __DIR__.'/../src/db.php';

function simulate_usage(): never {
    fwrite(STDERR,"Usage: php tools/simulate_hikers.php --scenario=normal_walk [--base-url=http://127.0.0.1:8000/api] OR --scenario=phase1_replay [--inside-count=10] [--outside-count=5] [--annotate] OR --gpx=file.gpx [--speed=1]\n");
    exit(2);
}

function phase1_project(float $lat,float $lon,float $lat0,float $lon0): array {
    return [($lon-$lon0)*cos(deg2rad($lat0))*111320.0,($lat-$lat0)*110540.0];
}

function phase1_unproject(float $x,float $y,float $lat0,float $lon0): array {
    $cos=cos(deg2rad($lat0));
    if(abs($cos)<0.01)throw new RuntimeException('The active safe-zone latitude is too close to a pole for local projection.');
    return ['latitude'=>$lat0+$y/110540.0,'longitude'=>$lon0+$x/($cos*111320.0)];
}

function phase1_replay_steps(array $zone,int $insideCount,int $outsideCount,int $sequenceStart): array {
    $outer=$zone['rings'][0]??[];
    if(!is_array($outer)||count($outer)<3)throw new RuntimeException('The selected site has no active safe-zone outer ring with at least three vertices.');
    $lat0=array_sum(array_column($outer,'latitude'))/count($outer);
    $lon0=array_sum(array_column($outer,'longitude'))/count($outer);
    $points=[];
    foreach($outer as $vertex){
        if(!isset($vertex['latitude'],$vertex['longitude'])||!is_numeric($vertex['latitude'])||!is_numeric($vertex['longitude']))throw new RuntimeException('The active safe-zone contains an invalid vertex.');
        $points[]=phase1_project((float)$vertex['latitude'],(float)$vertex['longitude'],$lat0,$lon0);
    }
    if(count($points)>3&&hypot($points[0][0]-$points[count($points)-1][0],$points[0][1]-$points[count($points)-1][1])<0.001)array_pop($points);
    $area=0.0;$edgeCount=count($points);
    for($i=0;$i<$edgeCount;$i++){$a=$points[$i];$b=$points[($i+1)%$edgeCount];$area+=$a[0]*$b[1]-$b[0]*$a[1];}
    if($edgeCount<3||abs($area)<0.0001)throw new RuntimeException('The active safe-zone outer ring cannot be projected as a polygon.');
    $steps=[];
    for($i=0;$i<$insideCount;$i++){
        $position=(($i+0.5)*$edgeCount)/$insideCount;$edge=(int)floor($position);$t=$position-$edge;$a=$points[$edge%$edgeCount];$b=$points[($edge+1)%$edgeCount];
        $coord=phase1_unproject($a[0]+($b[0]-$a[0])*$t,$a[1]+($b[1]-$a[1])*$t,$lat0,$lon0);
        $steps[]=['sequence_number'=>$sequenceStart+count($steps),'latitude'=>$coord['latitude'],'longitude'=>$coord['longitude'],'intended_class'=>'INSIDE','point_source'=>'FROM_TRACK'];
    }
    for($i=0;$i<$outsideCount;$i++){
        $edge=(int)floor((($i+0.5)*$edgeCount)/$outsideCount);$a=$points[$edge%$edgeCount];$b=$points[($edge+1)%$edgeCount];$dx=$b[0]-$a[0];$dy=$b[1]-$a[1];$length=hypot($dx,$dy);
        if($length<0.001)throw new RuntimeException('The active safe-zone contains a zero-length outer edge.');
        // For a counter-clockwise ring, the outward normal points right; reverse it for clockwise rings.
        $nx=$area>0?$dy/$length:-$dy/$length;$ny=$area>0?-$dx/$length:$dx/$length;
        $coord=phase1_unproject(($a[0]+$b[0])/2+$nx*5.0,($a[1]+$b[1])/2+$ny*5.0,$lat0,$lon0);
        $steps[]=['sequence_number'=>$sequenceStart+count($steps),'latitude'=>$coord['latitude'],'longitude'=>$coord['longitude'],'intended_class'=>'OUTSIDE','point_source'=>'MOVED_BEYOND_MARGIN'];
    }
    return $steps;
}

$args=getopt('', ['scenario:','gpx:','speed:','base-url:','inside-count:','outside-count:','start-sequence:','annotate']);
if(!simulator_allowed(active_mode())){fwrite(STDERR,"Simulator refused: active mode is LIVE.\n");exit(3);}
$url=(string)($args['base-url']??'http://127.0.0.1:8000/api');$parts=parse_url($url);
if(!$parts||!in_array(strtolower((string)($parts['host']??'')),['127.0.0.1','localhost','::1'],true)){fwrite(STDERR,"Simulator target must be a local loopback API URL.\n");exit(2);}
$endpoint=rtrim($url,'/').'/bridge/ingest';$token=(string)(config()['bridge_tokens']['demo']??config()['bridge_token']??'');if($token===''){fwrite(STDERR,"Demo bridge token is not configured.\n");exit(2);}
$send=function(array $packet)use($endpoint,$token,$url):array{
    $ctx=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\nX-Bridge-Token: $token\r\n",'content'=>json_encode($packet,JSON_THROW_ON_ERROR),'ignore_errors'=>true,'timeout'=>5]]);
    $result=file_get_contents($endpoint,false,$ctx);$status=$http_response_header[0]??'No HTTP response';echo $status."\n";
    if($result===false)throw new RuntimeException('Local API request failed');$json=json_decode($result,true);
    if(str_contains($status,' 4')||str_contains($status,' 5'))throw new RuntimeException((string)($json['error']??'API rejected packet'));
    $command=$json['command']??null;
    if(is_array($command)&&isset($command['id'])){
        $pendingContext=stream_context_create(['http'=>['header'=>"X-Bridge-Token: $token\r\n",'ignore_errors'=>true,'timeout'=>5]]);$pendingRaw=file_get_contents(rtrim($url,'/').'/commands/pending',false,$pendingContext);
        if($pendingRaw===false)throw new RuntimeException('Could not fetch returned command from the local bridge API');$pending=json_decode($pendingRaw,true);$found=false;
        foreach($pending['commands']??[] as $item)if((int)$item['command_id']===(int)$command['id'])$found=true;
        if(!$found)throw new RuntimeException('Returned command was not present in pending command fetch');
        $sentContext=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\nX-Bridge-Token: $token\r\n",'content'=>json_encode(['status'=>'SENT_TO_BASE'],JSON_THROW_ON_ERROR),'ignore_errors'=>true,'timeout'=>5]]);$sentRaw=file_get_contents(rtrim($url,'/').'/commands/'.(int)$command['id'].'/status',false,$sentContext);
        if($sentRaw===false)throw new RuntimeException('Could not report command sent to base');$sent=json_decode($sentRaw,true);if(empty($sent['ok']))throw new RuntimeException('Command status update was rejected');echo 'Reply lifecycle: created, fetched, sent · command '.(int)$command['id']."\n";
    }
    return is_array($json)?$json:[];
};
$packet=function(int $seq,float $lat,float $lon,array $flags=[],string $path='DIRECT'):array{
    $uplinkFlags=array_merge(['link_lost'=>false,'low_battery'=>false,'emergency'=>false,'turn_back_received'=>false,'test_mode'=>false],$flags);$r=['base_id'=>'BASE','path_type'=>$path,'rssi_dbm'=>-80,'snr_db'=>8.5];
    if($path==='CHECKPOINT_RELAY'){$r['checkpoint_id']='CHK';$r['checkpoint_rssi_dbm']=-92;$r['relay_hop_delay_ms']=110;}
    return ['uplink'=>['node_id'=>'H1','sequence_number'=>$seq,'latitude'=>$lat,'longitude'=>$lon,'gps_accuracy_m'=>7.5,'satellite_count'=>7,'battery_level'=>84,'flags'=>$uplinkFlags],'reception'=>$r,'is_demo'=>true];
};

try {
    if(($args['scenario']??'')==='phase1_replay'){
        $integerArg=static function(string $key,int $default,int $min,int $max)use($args):int{$raw=$args[$key]??$default;$value=filter_var($raw,FILTER_VALIDATE_INT);if($value===false||$value<$min||$value>$max)throw new InvalidArgumentException("--$key must be an integer from $min to $max");return $value;};
        $insideCount=$integerArg('inside-count',10,0,65535);$outsideCount=$integerArg('outside-count',5,0,65535);$sequenceStart=$integerArg('start-sequence',60000,0,65535);
        if($insideCount+$outsideCount<1||$sequenceStart+$insideCount+$outsideCount-1>65535)throw new InvalidArgumentException('replay packet counts and start sequence must fit within the uplink sequence range 0–65535');
        $zoneContext=stream_context_create(['http'=>['header'=>"X-Bridge-Token: $token\r\n",'ignore_errors'=>true,'timeout'=>5]]);$zoneRaw=file_get_contents(rtrim($url,'/').'/safe-zone',false,$zoneContext);$zoneStatus=$http_response_header[0]??'No HTTP response';
        if($zoneRaw===false)throw new RuntimeException('Could not read the active safe zone from GET /api/safe-zone');$zoneResult=json_decode($zoneRaw,true);
        if(str_contains($zoneStatus,' 4')||str_contains($zoneStatus,' 5'))throw new RuntimeException('GET /api/safe-zone failed: '.($zoneResult['error']??$zoneStatus));
        $zone=$zoneResult['safe_zone']??null;if(!$zone)throw new RuntimeException('The selected site has no active safe zone; nothing was sent.');
        $steps=phase1_replay_steps($zone,$insideCount,$outsideCount,$sequenceStart);$mismatches=0;
        foreach($steps as $step){$result=$send($packet((int)$step['sequence_number'],(float)$step['latitude'],(float)$step['longitude'],['test_mode'=>true]));$classification=(string)($result['classification']??'UNKNOWN');echo 'Sequence '.$step['sequence_number'].' | intended '.$step['intended_class'].' | classified '.$classification."\n";if($classification!==$step['intended_class'])$mismatches++;}
        if(array_key_exists('annotate',$args)){
            echo "No operator login/CSRF session is available in this simulator. Annotate these ranges in Settings for site ".($zoneResult['selected_site']??'selected site').", hiker H1:\n";
            if($insideCount>0)echo 'H1 sequences '.$sequenceStart.'-'.($sequenceStart+$insideCount-1)." | intended INSIDE | point_source FROM_TRACK\n";
            if($outsideCount>0)echo 'H1 sequences '.($sequenceStart+$insideCount).'-'.($sequenceStart+$insideCount+$outsideCount-1)." | intended OUTSIDE | point_source MOVED_BEYOND_MARGIN\n";
        }
        if($mismatches>0)throw new RuntimeException("$mismatches replay packet(s) classified differently from the intended class.");
        echo 'Phase 1 replay complete: '.count($steps).' packets; all intended and returned classifications agree. No radio delivery is implied.'."\n";
    }elseif(isset($args['scenario'])){
        $file=__DIR__.'/scenarios/'.basename((string)$args['scenario']).'.json';if(!is_file($file))throw new RuntimeException('Scenario not found');$scenario=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
        foreach($scenario as $step){$flags=$step['flags']??[];$send($packet((int)$step['sequence_number'],(float)$step['latitude'],(float)$step['longitude'],$flags,(string)($step['path_type']??'DIRECT')));usleep((int)($step['delay_ms']??0)*1000);}
    }elseif(isset($args['gpx'])){
        $file=(string)$args['gpx'];if(!is_file($file))throw new RuntimeException('GPX file not found');$speed=(float)($args['speed']??1);if(!is_finite($speed)||$speed<=0||$speed>100)throw new RuntimeException('speed multiplier must be >0 and <=100');$xml=simplexml_load_file($file);if(!$xml)throw new RuntimeException('Invalid GPX XML');$seq=1;
        foreach($xml->xpath('//*[local-name()="trkpt"]')?:[] as $pt){$lat=(float)$pt['lat'];$lon=(float)$pt['lon'];$send($packet($seq++,$lat,$lon));usleep((int)(1000/$speed)*1000);}
    }else simulate_usage();
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
