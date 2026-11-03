<?php
require_once __DIR__ . '/../src/Services/ApiService.php';
$valid=[[14.8,121.0],[14.8,121.001],[14.801,121.001],[14.801,121.0]];
$crossing=[[0,0],[2,2],[0,2],[2,0]];
if (polygon_self_intersects($valid)) { fwrite(STDERR,"FAIL simple polygon rejected\n"); exit(1); }
if (!polygon_self_intersects($crossing)) { fwrite(STDERR,"FAIL crossing polygon accepted\n"); exit(1); }
echo "PASS simple polygon accepted\nPASS self-intersecting polygon rejected\n";
