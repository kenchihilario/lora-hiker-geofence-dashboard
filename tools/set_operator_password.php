<?php
if(PHP_SAPI!=='cli'){fwrite(STDERR,"Run this tool from the command line.\n");exit(2);}
$root=dirname(__DIR__);$path=$root.'/data/operator.json';$dir=dirname($path);
if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir)){fwrite(STDERR,"Could not create data directory.\n");exit(1);}
$username=trim((string)readline('Operator username: '));
if($username===''||strlen($username)>80||preg_match('/[\r\n\x00-\x1f]/',$username)){fwrite(STDERR,"Username must be 1–80 printable characters.\n");exit(2);}
$password=(string)readline('New password (minimum 12 characters): ');$confirm=(string)readline('Repeat password: ');
if(strlen($password)<12||!hash_equals($password,$confirm)){fwrite(STDERR,"Passwords must match and contain at least 12 characters.\n");exit(2);}
$payload=json_encode(['username'=>$username,'password_hash'=>password_hash($password,PASSWORD_DEFAULT)],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";$tmp=$path.'.tmp';
if(file_put_contents($tmp,$payload,LOCK_EX)===false||!rename($tmp,$path)){@unlink($tmp);fwrite(STDERR,"Could not save operator credentials.\n");exit(1);}
@chmod($path,0600);fwrite(STDOUT,"Operator credentials saved locally in data/operator.json. The password is not stored in plain text.\n");
