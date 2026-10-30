<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/geofence.php';

/**
 * Handle one position report (one decoded uplink).
 * Stores it, runs the geofence check, and raises an alert when a hiker
 * goes from inside to outside (one alert per exit, not one per packet).
 */
function process_report(string $hikerId, float $lat, float $lng, string $heardBy): array {
    $pdo = db();

    $zone = $pdo->query("SELECT polygon_json FROM safe_zones WHERE is_active = 1 ORDER BY id DESC LIMIT 1")->fetch();
    if (!$zone) {
        throw new RuntimeException('No active safe zone.');
    }
    $polygon = json_decode($zone['polygon_json'], true);
    $inside = point_in_polygon($lat, $lng, $polygon);

    $stmt = $pdo->prepare("SELECT is_inside FROM positions WHERE hiker_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$hikerId]);
    $prev = $stmt->fetch();

    $stmt = $pdo->prepare("INSERT INTO positions (hiker_id, lat, lng, heard_by, is_inside) VALUES (?,?,?,?,?)");
    $stmt->execute([$hikerId, $lat, $lng, $heardBy, $inside ? 1 : 0]);
    $positionId = (int)$pdo->lastInsertId();

    $alert = false;
    if (!$inside && (!$prev || (int)$prev['is_inside'] === 1)) {
        $stmt = $pdo->prepare("INSERT INTO alerts (hiker_id, position_id, message) VALUES (?,?,?)");
        $stmt->execute([$hikerId, $positionId, "$hikerId left the safe zone (heard by $heardBy)"]);
        $alert = true;
    }
    return ['position_id' => $positionId, 'inside' => $inside, 'alert' => $alert];
}
