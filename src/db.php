<?php
function config(): array {
    static $c = null;
    if ($c === null) {
        $file = __DIR__ . '/../config.php';
        if (!is_file($file)) throw new RuntimeException('Missing config.php. Copy config.example.php and configure MariaDB.');
        $c = require $file;
    }
    return $c;
}
function mode_file_path(): string { return dirname(__DIR__) . '/data/mode.json'; }
function parse_active_mode(?string $contents): string {
    if ($contents === null) return 'DEMO';
    $d = json_decode($contents, true);
    return is_array($d) && in_array(($d['mode'] ?? null), ['DEMO','LIVE'], true) ? $d['mode'] : 'DEMO';
}
function active_mode(): string {
    $p = mode_file_path();
    return parse_active_mode(is_file($p) ? file_get_contents($p) : null);
}
function profile_name_for_mode(string $mode): string { return $mode==='LIVE'?'live':'demo'; }
function active_profile_name(): string { return profile_name_for_mode(active_mode()); }
function db_profile(?string $name=null): array {
    $c=config(); $name=$name??active_profile_name();
    if (isset($c['db_profiles'][$name]) && is_array($c['db_profiles'][$name])) return $c['db_profiles'][$name];
    if ($name==='demo' && isset($c['db_name'])) return ['db_host'=>$c['db_host']??'127.0.0.1','db_name'=>$c['db_name'],'db_user'=>$c['db_user']??'root','db_pass'=>$c['db_pass']??''];
    throw new RuntimeException('Database profile is not configured: '.$name);
}
function disposable_test_profile_selected(): bool {
    try { return active_mode()==='DEMO' && (db_profile('demo')['db_name']??null)==='hiker_dashboard_test_codex'; }
    catch(Throwable $e) { return false; }
}
function require_disposable_test_profile(): void {
    if(active_mode()==='LIVE')throw new RuntimeException('Tests are disabled while LIVE mode is active.');
    if(!disposable_test_profile_selected())throw new RuntimeException('Tests require the dedicated hiker_dashboard_test_codex DEMO profile.');
}
function simulator_allowed(string $mode): bool { return $mode==='DEMO'; }
function bridge_token(): string {
    $c=config(); $name=active_profile_name();
    return (string)($c['bridge_tokens'][$name] ?? ($name==='demo' ? ($c['bridge_token']??'') : ''));
}
function db(): PDO {
    static $connections=[]; $name=active_profile_name();
    if (!isset($connections[$name])) {
        $c=db_profile($name);
        $connections[$name]=new PDO("mysql:host={$c['db_host']};dbname={$c['db_name']};charset=utf8mb4",$c['db_user'],$c['db_pass'],[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,
        ]);
    }
    return $connections[$name];
}
function is_local_remote_addr(?string $address): bool { return in_array($address,['127.0.0.1','::1'],true); }
function local_only_enabled(): bool { return (bool)(config()['local_only'] ?? true); }
function require_local_operator(): void {
    if (local_only_enabled() && !is_local_remote_addr($_SERVER['REMOTE_ADDR'] ?? null)) {
        http_response_code(403); header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error'=>'operator routes are available only from this computer']); exit;
    }
}
function persist_mode(string $mode): void {
    if (!in_array($mode,['DEMO','LIVE'],true)) throw new InvalidArgumentException('mode must be DEMO or LIVE');
    $path=mode_file_path(); $dir=dirname($path); if(!is_dir($dir)&&!mkdir($dir,0777,true)&&!is_dir($dir))throw new RuntimeException('Could not create local data directory');
    $tmp=$path.'.tmp'; if(file_put_contents($tmp,json_encode(['mode'=>$mode],JSON_PRETTY_PRINT),LOCK_EX)===false||!rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('Could not save dashboard mode');}
}
