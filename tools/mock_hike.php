<?php
// Fake hike so the dashboard can be built and tested before the hardware exists.
// Usage: php tools/mock_hike.php [delay_seconds]
// H1 walks the corridor, then drifts off the safe zone. H2 stays on the trail.
// "heard_by" switches from BASE to CHK partway, like moving out of base camp range.
require_once __DIR__ . '/../src/report.php';

$delay = (int)($argv[1] ?? 1);
$steps = [];
for ($i = 0; $i < 10; $i++) { $steps[] = [14.8005 + $i * 0.0050, 121.0003]; }   // along the trail
for ($i = 1; $i <= 5; $i++) { $steps[] = [14.8055, 121.0003 + $i * 0.0002]; }   // drifting east, off trail

foreach ($steps as $k => [$lat, $lng]) {
    $heard = $k < 7 ? 'BASE' : 'CHK';
    $r1 = process_report('H1', $lat, $lng, $heard);
    $r2 = process_report('H2', $lat - 0.0003, 121.0002, $heard);   // stays inside
    printf("step %02d  H1 %s%s   H2 %s\n", $k + 1,
        $r1['inside'] ? 'inside ' : 'OUTSIDE', $r1['alert'] ? ' [ALERT]' : '',
        $r2['inside'] ? 'inside' : 'OUTSIDE');
    sleep($delay);
}
