<?php
require_once __DIR__.'/../src/Services/LiveCheckService.php';
if(active_mode()!=='LIVE'){
    fwrite(STDERR,"REFUSED: tools/live_check.php runs only when LIVE mode is active. No database was checked.\n");
    exit(2);
}
try{
    $report=live_check_report(db(),config(),dirname(__DIR__));
    foreach($report['checks'] as $check)echo '['.$check['status'].'] '.$check['label'].' — '.$check['detail']."\n";
    exit($report['ok']?0:1);
}catch(Throwable $e){
    fwrite(STDERR,"[FAIL] Readiness check could not complete: ".$e->getMessage()."\n");
    exit(1);
}
