<?php
// Endpoint for the serial bridge (the script that reads the Base Camp Node's USB serial
// and forwards each decoded uplink). Until the hardware exists, use tools/mock_hike.php instead.
// POST fields: token, hiker_id, lat, lng, heard_by
require_once __DIR__ . '/../src/report.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}
if (!hash_equals(config()['ingest_token'], (string)($_POST['token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['error' => 'bad token']);
    exit;
}
$hikerId = trim((string)($_POST['hiker_id'] ?? ''));
$heardBy = trim((string)($_POST['heard_by'] ?? 'BASE'));
if ($hikerId === '' || !is_numeric($_POST['lat'] ?? null) || !is_numeric($_POST['lng'] ?? null)
    || abs((float)$_POST['lat']) > 90 || abs((float)$_POST['lng']) > 180) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid fields']);
    exit;
}
echo json_encode(process_report($hikerId, (float)$_POST['lat'], (float)$_POST['lng'], $heardBy));
