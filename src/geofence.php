<?php
/**
 * Ray casting on the small local test area. A point exactly on an edge counts as inside.
 *
 * $polygon may be either:
 *   - a flat list of [lat, lng] points (one ring, the original format), or
 *   - a list of rings, each a list of [lat, lng] points. The first ring is the outer
 *     edge and any further rings are holes (for example the inner edge of a corridor
 *     around a closed loop trail).
 *
 * With several rings the even-odd rule is used: a point inside the outer ring and
 * inside a hole crosses the edges an even number of times, so it is outside.
 */
function point_in_polygon(float $lat, float $lng, array $polygon): bool {
    if ($polygon === []) return false;

    // Flat list of points -> wrap as a single ring so old callers keep working.
    $first = reset($polygon);
    $rings = (is_array($first) && isset($first[0]) && is_array($first[0])) ? $polygon : [$polygon];

    $inside = false;
    foreach ($rings as $ring) {
        $n = count($ring);
        if ($n < 3) continue;
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $yi = (float)$ring[$i][0]; $xi = (float)$ring[$i][1];
            $yj = (float)$ring[$j][0]; $xj = (float)$ring[$j][1];
            $cross = ($lng - $xi) * ($yj - $yi) - ($lat - $yi) * ($xj - $xi);
            // On an edge of ANY ring counts as inside (the edge of the corridor is safe).
            if (abs($cross) < 1e-10 && $lat >= min($yi,$yj)-1e-10 && $lat <= max($yi,$yj)+1e-10
                && $lng >= min($xi,$xj)-1e-10 && $lng <= max($xi,$xj)+1e-10) return true;
            if ((($yi > $lat) !== ($yj > $lat)) && ($lng < ($xj-$xi)*($lat-$yi)/($yj-$yi)+$xi)) $inside = !$inside;
        }
    }
    return $inside;
}
