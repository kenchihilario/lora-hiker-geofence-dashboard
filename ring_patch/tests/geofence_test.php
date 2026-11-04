<?php
require_once __DIR__.'/../src/db.php';try{require_disposable_test_profile();}catch(Throwable $e){fwrite(STDERR,"REFUSED: ".$e->getMessage()."\n");exit(2);}
require_once __DIR__ . '/../src/geofence.php';
$poly=[[0,0],[0,1],[1,1],[1,0]];
$cases=[['inside',.5,.5,true],['outside',2,.5,false],['edge counts inside',0,.5,true],['vertex counts inside',0,0,true],['invalid polygon',0,0,false]];
foreach($cases as [$label,$lat,$lng,$expected]){$actual=point_in_polygon($lat,$lng,$label==='invalid polygon'?[]:$poly);if($actual!==$expected){fwrite(STDERR,"FAIL $label\n");exit(1);}echo "PASS $label\n";}
// Ring corridor: outer square 0..10 with an inner square hole 3..7 (a loop trail: the middle is NOT safe).
$sq=fn($a,$b)=>[[$a,$a],[$a,$b],[$b,$b],[$b,$a]];
$rings=[$sq(0,10),$sq(3,7)];
$ringCases=[['ring: in the band',1,1,true],['ring: middle (hole) is outside',5,5,false],['ring: beyond the outer edge',11,5,false],['ring: on the inner edge counts inside',5,3,true],['ring: on the outer edge counts inside',0,5,true],['ring: single ring written as rings still works',5,5,null]];
foreach($ringCases as [$label,$lat,$lng,$expected]){
    if($expected===null){$actual=point_in_polygon($lat,$lng,[$sq(0,10)]);$expected=true;}else $actual=point_in_polygon($lat,$lng,$rings);
    if($actual!==$expected){fwrite(STDERR,"FAIL $label\n");exit(1);}echo "PASS $label\n";}
