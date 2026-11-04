<?php
require_once __DIR__.'/ApiService.php';

function registry_schema_ready(PDO $pdo): bool {
    return schema_table_exists($pdo,'site_locations')&&schema_column_exists($pdo,'network_nodes','site_id')&&schema_column_exists($pdo,'network_nodes','is_verified')&&schema_column_exists($pdo,'trail_reference_points','is_verified');
}

function registry_location(array $input): array {
    $lat=$input['latitude']??null;$lon=$input['longitude']??null;
    if(($lat===null||$lat==='')&&($lon===null||$lon===''))return [null,null];
    if($lat===null||$lon===null||$lat===''||$lon===''||!is_numeric($lat)||!is_numeric($lon))throw new InvalidArgumentException('latitude and longitude must both be valid numbers, or both be blank');
    $lat=(float)$lat;$lon=(float)$lon;if(!is_finite($lat)||!is_finite($lon)||abs($lat)>90||abs($lon)>180)throw new InvalidArgumentException('coordinates are out of range');
    return [$lat,$lon];
}
function registry_boolean(array $input,string $key,bool $default=false): int {
    if(!array_key_exists($key,$input))return $default?1:0;
    if(!in_array($input[$key],[true,false,0,1,'0','1'],true))throw new InvalidArgumentException($key.' must be boolean');
    return filter_var($input[$key],FILTER_VALIDATE_BOOLEAN)?1:0;
}
function registry_audit(PDO $pdo,string $action,?int $hikerId,array $details,string $siteId): void {
    $flags=site_record_flags($siteId);$q=$pdo->prepare('INSERT INTO operator_actions(action_type,target_node_id,details_json,operator_id,is_demo,is_rehearsal,site_id) VALUES(?,?,?,\'local operator\',?,?,?)');
    $q->execute([$action,$hikerId,json_value($details),$flags['is_demo'],$flags['is_rehearsal'],$siteId]);
}
function registry_text(array $input,string $key,int $max,bool $required=true): ?string {
    $raw=$input[$key]??'';if(!is_string($raw)&&!is_int($raw)&&!is_float($raw))throw new InvalidArgumentException($key.' must be text');$value=trim((string)$raw);if($required&&$value==='')throw new InvalidArgumentException($key.' is required');if(strlen($value)>$max)throw new InvalidArgumentException($key.' must be at most '.$max.' characters');return $value===''?null:$value;
}
