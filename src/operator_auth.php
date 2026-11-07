<?php
require_once __DIR__.'/db.php';

function operator_credentials_path(): string {
    if(scratch_test_override_enabled()&&getenv('TRAILWATCH_TEST_OPERATOR_FILE')){
        $candidate=getenv('TRAILWATCH_TEST_OPERATOR_FILE');$runtime=realpath(dirname(__DIR__).'/.runtime');$resolved=realpath($candidate);
        if(!$runtime||!$resolved||stripos($resolved,$runtime.DIRECTORY_SEPARATOR)!==0)throw new RuntimeException('Scratch operator credentials must be inside .runtime.');
        return $resolved;
    }
    return dirname(__DIR__).'/data/operator.json';
}

function operator_session_start(): void {
    if(session_status()===PHP_SESSION_ACTIVE)return;
    $root=dirname(__DIR__);$runtime=$root.'/.runtime/sessions';
    if(!is_dir($runtime)&&!mkdir($runtime,0700,true)&&!is_dir($runtime))throw new RuntimeException('Could not create local session directory.');
    ini_set('session.use_only_cookies','1');
    session_name('TRAILWATCHSESSID');session_save_path($runtime);
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','httponly'=>true,'samesite'=>'Strict']);
    session_start();
}

function operator_csrf_token(): string {
    operator_session_start();
    if(!isset($_SESSION['csrf_token'])||!is_string($_SESSION['csrf_token']))$_SESSION['csrf_token']=bin2hex(random_bytes(32));
    $token=$_SESSION['csrf_token'];setcookie('TRAILWATCH_CSRF',$token,['expires'=>0,'path'=>'/','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','httponly'=>false,'samesite'=>'Strict']);return $token;
}

function operator_is_authenticated(): bool {
    operator_session_start();
    return isset($_SESSION['operator_user'])&&is_string($_SESSION['operator_user'])&&$_SESSION['operator_user']!=='';
}

function operator_page_guard(): void {
    require_local_operator();
    if(!operator_is_authenticated()){header('Location: /login.php',true,302);exit;}
}

function operator_api_error(string $method,bool $bridgeRoute): ?array {
    if($bridgeRoute)return null;
    if(!is_local_remote_addr($_SERVER['REMOTE_ADDR']??null)&&local_only_enabled())return ['status'=>403,'error'=>'operator routes are available only from this computer'];
    if(!operator_is_authenticated())return ['status'=>401,'error'=>'operator login required'];
    if(!in_array(strtoupper($method),['GET','HEAD'],true)){
        $provided=(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'');$expected=operator_csrf_token();
        if($provided===''||!hash_equals($expected,$provided))return ['status'=>403,'error'=>'valid CSRF token required'];
    }
    return null;
}

function operator_credentials(): ?array {
    $path=operator_credentials_path();if(!is_file($path))return null;
    $value=json_decode((string)file_get_contents($path),true);
    if(!is_array($value)||!is_string($value['username']??null)||!is_string($value['password_hash']??null))return null;
    return $value;
}

function operator_login(string $username,string $password): bool {
    $credentials=operator_credentials();
    if(!$credentials||!hash_equals($credentials['username'],$username)||!password_verify($password,$credentials['password_hash']))return false;
    operator_session_start();session_regenerate_id(true);$_SESSION['operator_user']=$credentials['username'];$_SESSION['csrf_token']=bin2hex(random_bytes(32));return true;
}

function operator_logout(): void {
    operator_session_start();$_SESSION=[];
    if(ini_get('session.use_cookies')){$params=session_get_cookie_params();setcookie(session_name(),'',time()-42000,$params['path'],$params['domain']??'',$params['secure'],$params['httponly']);}
    setcookie('TRAILWATCH_CSRF','',['expires'=>time()-42000,'path'=>'/','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','httponly'=>false,'samesite'=>'Strict']);
    session_destroy();
}
