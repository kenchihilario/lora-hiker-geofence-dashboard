<?php
require_once dirname(__DIR__).'/src/db.php';
try{require_disposable_test_profile();}catch(Throwable $e){fwrite(STDERR,"REFUSED: ".$e->getMessage()."\n");exit(2);}
$base=rtrim($argv[1]??'','/');$cookie=(string)(getenv('TRAILWATCH_TEST_SESSION_COOKIE')?:'');$csrf=(string)(getenv('TRAILWATCH_TEST_CSRF_TOKEN')?:'');$token=(string)(config()['bridge_tokens']['demo']??'');
function auth_http(string $path,string $method='GET',?array $payload=null,string $cookie='',string $csrf='',string $bridge=''): array {$headers="Content-Type: application/json\r\n";if($cookie!=='')$headers.="Cookie: $cookie\r\n";if($csrf!=='')$headers.="X-CSRF-Token: $csrf\r\n";if($bridge!=='')$headers.="X-Bridge-Token: $bridge\r\n";$ctx=stream_context_create(['http'=>['method'=>$method,'header'=>$headers,'content'=>$payload===null?'':json_encode($payload),'ignore_errors'=>true,'timeout'=>5]]);$body=@file_get_contents($GLOBALS['base'].$path,false,$ctx);$line=$http_response_header[0]??'HTTP/1.1 500';return[(int)substr($line,9,3),is_string($body)?json_decode($body,true):null];}
function auth_assert(bool $ok,string $message): void {if(!$ok){fwrite(STDERR,"FAIL $message\n");exit(1);}echo"PASS $message\n";}
if($base===''||$cookie===''||$csrf===''){fwrite(STDERR,"REFUSED: authenticated scratch test needs the suite login context.\n");exit(2);}
[$status]=auth_http('/api/status');auth_assert($status===401,'operator API rejects anonymous loopback requests');
[$status]=auth_http('/api/status','GET',null,$cookie);auth_assert($status===200,'authenticated session can read operator API');
[$status]=auth_http('/api/settings/site','POST',['site_id'=>'pup'],$cookie);auth_assert($status===403,'operator writes reject a missing CSRF token');
[$status]=auth_http('/api/settings/site','POST',['site_id'=>'pup'],$cookie,'invalid-token');auth_assert($status===403,'operator writes reject an invalid CSRF token');
[$status]=auth_http('/api/settings/site','POST',['site_id'=>'pup'],$cookie,$csrf);auth_assert($status===200,'valid session and CSRF token allow an operator write');
[$status]=auth_http('/api/commands/pending','GET',null,'','',$token);auth_assert($status===200,'bridge-token command route remains independent of operator sessions');
