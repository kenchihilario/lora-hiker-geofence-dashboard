<?php
require_once __DIR__ . '/../src/db.php';
$pdo = db();

$zone = $pdo->query("SELECT name, polygon_json FROM safe_zones WHERE is_active = 1 ORDER BY id DESC LIMIT 1")->fetch();
$polygon = $zone ? json_decode($zone['polygon_json'], true) : [];

$latest = $pdo->query("
  SELECT p.* FROM positions p
  JOIN (SELECT hiker_id, MAX(id) AS mid FROM positions GROUP BY hiker_id) m ON p.id = m.mid
  ORDER BY p.hiker_id")->fetchAll();

$trail = $pdo->query("SELECT hiker_id, lat, lng FROM positions ORDER BY id DESC LIMIT 200")->fetchAll();
$trail = array_reverse($trail);
$alerts = $pdo->query("SELECT * FROM alerts ORDER BY id DESC LIMIT 15")->fetchAll();

// Plot with plain SVG so it works offline (no map tiles needed).
$pts = $polygon;
foreach ($trail as $t) { $pts[] = [(float)$t['lat'], (float)$t['lng']]; }
$W = 420; $H = 420; $pad = 24;
if ($pts) {
    $lats = array_column($pts, 0); $lngs = array_column($pts, 1);
    $minLat = min($lats); $maxLat = max($lats); $minLng = min($lngs); $maxLng = max($lngs);
    $dLat = max($maxLat - $minLat, 1e-6); $dLng = max($maxLng - $minLng, 1e-6);
    $scale = min(($W - 2*$pad) / $dLng, ($H - 2*$pad) / $dLat);
    $px = fn($lng) => $pad + ($lng - $minLng) * $scale;
    $py = fn($lat) => $H - $pad - ($lat - $minLat) * $scale;
}
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="refresh" content="5">
<title>Hiker Geofence Dashboard</title>
<style>
  :root { --bg:#f6f7f5; --card:#fff; --text:#1d2420; --muted:#66706a; --line:#dfe3df; --ok:#1f7a45; --bad:#c0392b; --zone:#cfe8d8; }
  @media (prefers-color-scheme: dark) {
    :root { --bg:#141816; --card:#1d221f; --text:#e8ece9; --muted:#9aa59e; --line:#2c332f; --ok:#4fc27d; --bad:#ff6b5b; --zone:#25402f; }
  }
  * { box-sizing: border-box; }
  body { margin:0; font-family: system-ui, sans-serif; background:var(--bg); color:var(--text); }
  header { padding:16px 20px; border-bottom:1px solid var(--line); }
  header h1 { margin:0; font-size:18px; }
  header p { margin:4px 0 0; color:var(--muted); font-size:13px; }
  main { display:grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap:16px; padding:16px 20px; }
  .card { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:14px; overflow-x:auto; }
  .card h2 { margin:0 0 10px; font-size:14px; color:var(--muted); text-transform:uppercase; letter-spacing:.04em; }
  table { width:100%; border-collapse:collapse; font-size:14px; }
  th, td { text-align:left; padding:6px 8px; border-bottom:1px solid var(--line); }
  .ok { color:var(--ok); font-weight:600; } .bad { color:var(--bad); font-weight:600; }
  svg { width:100%; height:auto; max-width:460px; display:block; margin:auto; }
  .empty { color:var(--muted); font-size:14px; }
</style>
</head>
<body>
<header>
  <h1>Hiker Geofence Dashboard</h1>
  <p>Safe zone: <?= e($zone['name'] ?? 'none set') ?> &middot; refreshes every 5 s &middot; work in progress</p>
</header>
<main>
  <section class="card">
    <h2>Hikers</h2>
    <?php if (!$latest): ?><p class="empty">No position reports yet. Run <code>php tools/mock_hike.php</code>.</p><?php else: ?>
    <table>
      <tr><th>Hiker</th><th>Status</th><th>Last heard by</th><th>Last seen</th></tr>
      <?php foreach ($latest as $r): ?>
      <tr>
        <td><?= e($r['hiker_id']) ?></td>
        <td class="<?= $r['is_inside'] ? 'ok' : 'bad' ?>"><?= $r['is_inside'] ? 'On trail' : 'OFF TRAIL' ?></td>
        <td><?= e($r['heard_by']) ?></td>
        <td><?= e($r['received_at']) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2>Position plot</h2>
    <?php if ($pts): ?>
    <svg viewBox="0 0 <?= $W ?> <?= $H ?>" role="img" aria-label="Safe zone and hiker positions">
      <polygon fill="var(--zone)" stroke="var(--ok)" stroke-width="2"
        points="<?= implode(' ', array_map(fn($p) => round($px($p[1]),1).','.round($py($p[0]),1), $polygon)) ?>"/>
      <?php foreach ($trail as $t): ?>
        <circle cx="<?= round($px((float)$t['lng']),1) ?>" cy="<?= round($py((float)$t['lat']),1) ?>" r="3"
          fill="<?= $t['hiker_id'] === 'H1' ? 'var(--bad)' : 'var(--text)' ?>" opacity=".55"/>
      <?php endforeach; ?>
    </svg>
    <?php else: ?><p class="empty">Nothing to plot yet.</p><?php endif; ?>
  </section>

  <section class="card">
    <h2>Alerts</h2>
    <?php if (!$alerts): ?><p class="empty">No alerts.</p><?php else: ?>
    <table>
      <tr><th>Time</th><th>Message</th><th>Downlink</th></tr>
      <?php foreach ($alerts as $a): ?>
      <tr><td><?= e($a['created_at']) ?></td><td><?= e($a['message']) ?></td><td><?= $a['downlink_sent'] ? 'sent' : 'pending' ?></td></tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
