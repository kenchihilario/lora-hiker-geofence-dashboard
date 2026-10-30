<?php
/**
 * Point-in-Polygon by ray casting.
 * $polygon is a list of [lat, lng] pairs. x = lng, y = lat.
 * Returns true if the point is inside (points exactly on an edge are not guaranteed).
 */
function point_in_polygon(float $lat, float $lng, array $polygon): bool {
    $inside = false;
    $n = count($polygon);
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        [$yi, $xi] = $polygon[$i];
        [$yj, $xj] = $polygon[$j];
        $crosses = (($yi > $lat) !== ($yj > $lat))
            && ($lng < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi);
        if ($crosses) {
            $inside = !$inside;
        }
    }
    return $inside;
}
