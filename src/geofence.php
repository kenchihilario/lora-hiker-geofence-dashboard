<?php
/** Ray casting on the small local test area. A point exactly on an edge counts as inside. */
function point_in_polygon(float $lat, float $lng, array $polygon): bool {
    $n = count($polygon);
    if ($n < 3) return false;
    $inside = false;
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        $yi = (float)$polygon[$i][0]; $xi = (float)$polygon[$i][1];
        $yj = (float)$polygon[$j][0]; $xj = (float)$polygon[$j][1];
        $cross = ($lng - $xi) * ($yj - $yi) - ($lat - $yi) * ($xj - $xi);
        if (abs($cross) < 1e-10 && $lat >= min($yi,$yj)-1e-10 && $lat <= max($yi,$yj)+1e-10
            && $lng >= min($xi,$xj)-1e-10 && $lng <= max($xi,$xj)+1e-10) return true;
        if ((($yi > $lat) !== ($yj > $lat)) && ($lng < ($xj-$xi)*($lat-$yi)/($yj-$yi)+$xi)) $inside = !$inside;
    }
    return $inside;
}
