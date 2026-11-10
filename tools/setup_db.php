<?php
require_once __DIR__.'/../src/db.php';
$opts=getopt('', ['profile:','config:','schema-only']);$profile=$opts['profile']??'demo';
if(!in_array($profile,['demo','live'],true)){fwrite(STDERR,"Use --profile=demo or --profile=live\n");exit(2);}
$configFile=$opts['config']??(__DIR__.'/../config.php');if(!is_file($configFile)){fwrite(STDERR,"Config file not found.\n");exit(2);}
$c=require $configFile;$p=$c['db_profiles'][$profile]??null;
if(!is_array($p)||!preg_match('/^[A-Za-z0-9_]+$/',(string)($p['db_name']??''))){fwrite(STDERR,"Configured $profile database profile is invalid.\n");exit(2);}
$expected=$profile==='live'?'hiker_dashboard_live':(string)($p['db_name']??'');
if($p['db_name']!==$expected){fwrite(STDERR,"Configured database name does not match selected profile.\n");exit(2);}
if($profile==='demo'&&$expected==='hiker_dashboard_live'){fwrite(STDERR,"Refusing to seed a DEMO setup into the LIVE database.\n");exit(2);}
if(isset($opts['schema-only'])&&($profile!=='demo'||!str_starts_with($expected,'hiker_dashboard_scratch_'))){fwrite(STDERR,"--schema-only is reserved for disposable hiker_dashboard_scratch_* databases.\n");exit(2);}
try {
 $pdo=utc_pdo('mysql:host='.$p['db_host'].';charset=utf8mb4',$p['db_user'],$p['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $pdo->exec("CREATE DATABASE IF NOT EXISTS `$expected` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
 $pdo->exec("USE `$expected`");
 $schema=file_get_contents(__DIR__.'/../sql/schema.sql');
 foreach(array_filter(array_map('trim',preg_split('/;\s*(?:\r?\n|$)/',$schema))) as $sql)$pdo->exec($sql);
 if($profile==='demo'&&!isset($opts['schema-only'])){$seed=file_get_contents(__DIR__.'/../sql/demo_seed.sql');foreach(array_filter(array_map('trim',preg_split('/;\s*(?:\r?\n|$)/',$seed))) as $sql)$pdo->exec($sql);}
 $q=$pdo->prepare('INSERT IGNORE INTO schema_migrations(migration,checksum) VALUES(?,?)');
 foreach(glob(__DIR__.'/../sql/migrations/*.sql') as $f){$name=basename($f);if(preg_match('/^00[1-5]_/', $name))$q->execute([$name,hash_file('sha256',$f)]);}
 echo "Database setup complete for profile $profile.\n";
} catch(Throwable $e){fwrite(STDERR,"Setup failed: ".$e->getMessage()."\n");exit(1);}
