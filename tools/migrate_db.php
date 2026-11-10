<?php
require_once __DIR__.'/../src/db.php';
$opts=getopt('', ['profile:','config:','allow-live-schema']);$profile=$opts['profile']??($argv[1]??active_profile_name());if(!in_array($profile,['demo','live'],true)){fwrite(STDERR,"Profile must be demo or live.\n");exit(2);}
if($profile==='live'&&active_mode()==='DEMO'&&!isset($opts['allow-live-schema'])){fwrite(STDERR,"Refusing LIVE migration unless explicitly invoked with --allow-live-schema.\n");exit(2);}
try{$cfgPath=$opts['config']??(__DIR__.'/../config.php');$cfg=require $cfgPath;$c=$cfg['db_profiles'][$profile]??null;if(!is_array($c))throw new RuntimeException('Database profile missing in selected config.');if($profile==='live'&&$c['db_name']!=='hiker_dashboard_live'&&!str_starts_with($c['db_name'],'hiker_dashboard_scratch_'))throw new RuntimeException('Refusing unexpected LIVE database name.');$pdo=utc_pdo("mysql:host={$c['db_host']};dbname={$c['db_name']};charset=utf8mb4",$c['db_user'],$c['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations(migration VARCHAR(190) PRIMARY KEY,checksum CHAR(64) NOT NULL,applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
 $required=['hiker_nodes','network_nodes','safe_zones','safe_zone_vertices','uplink_packets','packet_receptions','downlink_commands','system_events','app_settings'];
 $exists=$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='hiker_nodes'")->fetchColumn()>0;
 if($exists){foreach($required as $t){$q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$t]);if(!(int)$q->fetchColumn())throw new RuntimeException('Partially initialized schema; inspect before migrating.');}}
 $requiredColumns=['network_nodes'=>['latitude','longitude'],'safe_zones'=>['site_id','is_demo','is_rehearsal'],'trail_reference_points'=>['site_id'],'uplink_packets'=>['site_id','test_mode','is_demo','is_rehearsal'],'packet_receptions'=>['base_id','checkpoint_id','is_duplicate','path_type','relay_hop_delay_ms'],'downlink_commands'=>['route_type','fetched_at','sent_at'],'storm_turnback_campaigns'=>['status']];
 $verifiedCurrent=$exists;foreach($requiredColumns as $table=>$cols)foreach($cols as $column){$q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');$q->execute([$table,$column]);if(!(int)$q->fetchColumn())$verifiedCurrent=false;}
 $files=glob(__DIR__.'/../sql/migrations/*.sql');sort($files,SORT_STRING);$base=$pdo->prepare('INSERT IGNORE INTO schema_migrations(migration,checksum) VALUES(?,?)');
 foreach($files as $f){$name=basename($f);$hash=hash_file('sha256',$f);$q=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration=?');$q->execute([$name]);$recorded=$q->fetchColumn();if($recorded!==false){if(!hash_equals((string)$recorded,$hash))throw new RuntimeException("Recorded checksum differs for $name; inspect migration history.");continue;}
   // A complete current schema verifies historical migrations 001-005; record them as baseline.
   if($verifiedCurrent&&preg_match('/^00[1-5]_/', $name)){$base->execute([$name,$hash]);continue;}
   $sql=file_get_contents($f);foreach(array_filter(array_map('trim',preg_split('/;\s*(?:\r?\n|$)/',$sql))) as $statement)$pdo->exec($statement);$base->execute([$name,$hash]);
 }
 echo "Migration ledger is current for profile $profile.\n";
}catch(Throwable $e){fwrite(STDERR,"Migration failed: ".$e->getMessage()."\n");exit(1);}
