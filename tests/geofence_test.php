<?php
require_once __DIR__.'/../src/db.php';try{require_disposable_test_profile();}catch(Throwable $e){fwrite(STDERR,"REFUSED: ".$e->getMessage()."\n");exit(2);}
require_once __DIR__ . '/../src/geofence.php';
$poly=[[0,0],[0,1],[1,1],[1,0]];
$cases=[['inside',.5,.5,true],['outside',2,.5,false],['edge counts inside',0,.5,true],['vertex counts inside',0,0,true],['invalid polygon',0,0,false]];
foreach($cases as [$label,$lat,$lng,$expected]){$actual=point_in_polygon($lat,$lng,$label==='invalid polygon'?[]:$poly);if($actual!==$expected){fwrite(STDERR,"FAIL $label\n");exit(1);}echo "PASS $label\n";}
