<?php
// Milestone 1 dashboard shell. All displayed locations and events are illustrative demo data.
$hikers = [
    ['id' => 'H1', 'name' => 'Maya Santos', 'state' => 'SAFE', 'battery' => 86, 'heard' => 'Base Camp Node', 'age' => '18 sec ago', 'lat' => '14.8031', 'lng' => '121.0003', 'accuracy' => '± 6 m', 'rssi' => '−82 dBm', 'x' => 46, 'y' => 46],
    ['id' => 'H2', 'name' => 'Luis Reyes', 'state' => 'OFF TRAIL', 'battery' => 18, 'heard' => 'Checkpoint Node', 'age' => '42 sec ago', 'lat' => '14.8057', 'lng' => '121.0010', 'accuracy' => '± 9 m', 'rssi' => '−91 dBm', 'x' => 69, 'y' => 31],
];
$alerts = [
    ['type' => 'OFF TRAIL', 'hiker' => 'H2 · Luis Reyes', 'time' => '10:42:16', 'detail' => 'Outside sample safe zone · 14.8057, 121.0010', 'class' => 'danger'],
    ['type' => 'LOW BATTERY', 'hiker' => 'H2 · Luis Reyes', 'time' => '10:41:58', 'detail' => 'Phone battery reported at 18%', 'class' => 'warning'],
    ['type' => 'POSITION RECEIVED', 'hiker' => 'H1 · Maya Santos', 'time' => '10:41:44', 'detail' => 'Inside sample safe zone · Base Camp Node', 'class' => 'neutral'],
];
function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#101918">
  <title>Trailwatch · Live operations</title>
  <link rel="stylesheet" href="assets/css/dashboard.css">
  <script src="assets/js/dashboard.js" defer></script>
</head>
<body>
<div class="app-shell">
  <aside class="sidebar" aria-label="Main navigation">
    <a class="brand" href="index.php" aria-label="Trailwatch home"><span class="brand-mark">T</span><span>trailwatch<small>BASE CAMP CONSOLE</small></span></a>
    <div class="nav-label">OPERATIONS</div>
    <nav>
      <a class="nav-link active" href="index.php"><span class="nav-icon">⌖</span>Live dashboard<span class="nav-count">02</span></a>
      <a class="nav-link disabled" href="#safe-zone"><span class="nav-icon">⬡</span>Safe zone</a>
      <a class="nav-link disabled" href="#hikers"><span class="nav-icon">♙</span>Hiker nodes</a>
      <a class="nav-link disabled" href="#logs"><span class="nav-icon">≡</span>Event logs</a>
    </nav>
    <div class="sidebar-bottom"><div class="operator"><div class="avatar">BC</div><div><strong>Base Camp</strong><small>Local operator</small></div><span class="online-dot"></span></div><div class="local-note"><span>▣</span> LOCAL SYSTEM<br><small>Designed for offline use</small></div></div>
  </aside>

  <main class="main-area">
    <header class="topbar"><div class="crumb">Operations <span>/</span> Live dashboard</div><div class="top-meta"><div class="clock"><span class="live-dot"></span> DEMO SESSION <span class="divider">·</span> 10:42:30</div><button class="icon-button" aria-label="Help" title="Help">?</button><div class="top-avatar">BC</div></div></header>
    <div class="demo-banner"><span class="demo-icon">i</span><div><strong>DEMO / SIMULATION MODE</strong><span>Illustrative positions and alerts only. No LoRa packets are being sent or received.</span></div><span class="demo-tag">NOT LIVE HARDWARE</span></div>

    <section class="page-heading"><div><div class="eyebrow">MONDAY, NOVEMBER 2, 2026 <span>·</span> LOCAL TIME</div><h1>Live overview</h1><p>Monitor hiker locations, safety status, and system connections.</p></div><button class="button button-quiet" id="refresh-demo"><span>↻</span> Refresh view</button></section>

    <section class="connection-strip" aria-label="System connections">
      <div class="connection"><span class="connection-symbol connected">⌂</span><div><small>BASE CAMP NODE</small><strong><i class="status-dot green"></i> Online <em>· Demo</em></strong></div></div>
      <div class="connection"><span class="connection-symbol muted">⌁</span><div><small>SERIAL BRIDGE</small><strong><i class="status-dot gray"></i> Disconnected <em>· Not configured</em></strong></div></div>
      <div class="connection"><span class="connection-symbol connected">⌖</span><div><small>CHECKPOINT NODE</small><strong><i class="status-dot amber"></i> Last heard 42 sec ago <em>· Demo</em></strong></div></div>
      <div class="connection"><span class="connection-symbol connected">▤</span><div><small>LOCAL DATA</small><strong><i class="status-dot green"></i> Demo view <em>· No live API</em></strong></div></div>
    </section>

    <div class="content-grid">
      <section class="panel map-panel" aria-labelledby="map-title">
        <div class="panel-heading"><div><div class="section-kicker">FIELD MAP</div><h2 id="map-title">Trail &amp; hiker locations</h2></div><span class="offline-pill"><span>▧</span> OFFLINE MAP PREVIEW</span></div>
        <div class="map-wrap">
          <svg class="trail-map" viewBox="0 0 800 470" role="img" aria-labelledby="map-desc" preserveAspectRatio="xMidYMid slice">
            <title id="map-desc">Illustrative offline map preview with a sample safe zone, two hiker markers, Base Camp, and Checkpoint.</title>
            <defs><pattern id="grid" width="40" height="40" patternUnits="userSpaceOnUse"><path d="M40 0H0V40" fill="none" stroke="#293633" stroke-width="1" opacity=".48"/></pattern><pattern id="hatch" width="8" height="8" patternTransform="rotate(45)" patternUnits="userSpaceOnUse"><line x1="0" y1="0" x2="0" y2="8" stroke="#d2a95e" stroke-width="2" opacity=".18"/></pattern></defs>
            <rect width="800" height="470" fill="#182522"/><rect width="800" height="470" fill="url(#grid)"/>
            <path d="M-20 340 C110 300 140 390 250 330 S410 300 490 340 660 400 830 290" fill="none" stroke="#253a31" stroke-width="72" opacity=".8"/><path d="M-20 340 C110 300 140 390 250 330 S410 300 490 340 660 400 830 290" fill="none" stroke="#395342" stroke-width="1" stroke-dasharray="6 7" opacity=".7"/>
            <path d="M100 80 540 58 662 201 600 386 215 408 105 272Z" fill="#51785a" fill-opacity=".16" stroke="#8dbb86" stroke-width="2" stroke-dasharray="8 5"/><path d="M100 80 540 58 662 201 600 386 215 408 105 272Z" fill="url(#hatch)"/>
            <text x="320" y="113" class="map-label zone-label">SAMPLE SAFE ZONE</text><text x="86" y="443" class="map-label small-label">Illustrative terrain · map tiles not installed</text>
            <path d="M160 315 C220 330 240 290 320 304 S430 348 505 319 590 300 645 219" fill="none" stroke="#d9c398" stroke-width="4" stroke-linecap="round" stroke-dasharray="3 9" opacity=".85"/>
            <g transform="translate(147 322)"><circle r="18" fill="#1c2925" stroke="#f0c46c" stroke-width="2"/><text y="5" text-anchor="middle" class="marker-text">BC</text><text x="25" y="5" class="map-label">BASE CAMP</text></g>
            <g transform="translate(592 301)"><circle r="16" fill="#1c2925" stroke="#77b3a2" stroke-width="2"/><text y="5" text-anchor="middle" class="marker-text">CP</text><text x="22" y="5" class="map-label">CHECKPOINT</text></g>
            <g class="hiker-marker safe-marker" transform="translate(368 270)"><circle class="marker-halo" r="20"/><circle r="13"/><text y="4" text-anchor="middle" class="marker-text">1</text></g>
            <g class="hiker-marker danger-marker" transform="translate(555 177)"><circle class="marker-halo" r="20"/><circle r="13"/><text y="4" text-anchor="middle" class="marker-text">2</text></g>
            <path d="M555 153v-22h92" fill="none" stroke="#e17b68" stroke-width="1.5"/><rect x="645" y="113" width="116" height="30" rx="5" fill="#382723" stroke="#85493c"/><text x="656" y="133" class="map-label callout">H2 · OFF TRAIL</text>
          </svg>
          <div class="map-zoom"><button aria-label="Zoom in">+</button><button aria-label="Zoom out">−</button></div>
          <div class="map-scale">SAMPLE CORRIDOR <span>·</span> NOT A SURVEY MAP</div>
        </div>
        <div class="map-legend"><span><i class="legend-dot safe"></i>Safe</span><span><i class="legend-dot danger"></i>Off trail</span><span><i class="legend-line"></i>Safe-zone boundary</span><span class="legend-note">Last reported positions · demo</span></div>
      </section>

      <section class="panel alert-panel" aria-labelledby="alerts-title"><div class="panel-heading"><div><div class="section-kicker">ATTENTION</div><h2 id="alerts-title">Recent alerts</h2></div><span class="alert-counter">2 ACTIVE</span></div>
        <div class="alert-list"><?php foreach ($alerts as $alert): ?><article class="alert-item <?= e($alert['class']) ?>"><span class="alert-icon"><?= $alert['class'] === 'danger' ? '!' : ($alert['class'] === 'warning' ? '⚡' : '⌖') ?></span><div class="alert-copy"><div class="alert-top"><strong><?= e($alert['type']) ?></strong><time><?= e($alert['time']) ?></time></div><div class="alert-hiker"><?= e($alert['hiker']) ?></div><p><?= e($alert['detail']) ?></p><?php if ($alert['class'] === 'danger'): ?><button class="text-action acknowledge">Acknowledge demo alert <span>→</span></button><?php endif; ?></div></article><?php endforeach; ?></div>
        <a class="panel-footer" href="#logs">View event log <span>→</span></a>
      </section>

      <section class="panel hikers-panel" id="hikers" aria-labelledby="hikers-title"><div class="panel-heading"><div><div class="section-kicker">FIELD TEAM</div><h2 id="hikers-title">Hiker nodes <span class="heading-count">02</span></h2></div><button class="button button-storm" id="storm-button"><span>⚠</span> STORM · TURN BACK</button></div>
        <div class="hiker-grid"><?php foreach ($hikers as $hiker): $isDanger = $hiker['state'] === 'OFF TRAIL'; ?><article class="hiker-card"><div class="hiker-card-top"><div class="person"><div class="person-avatar <?= $isDanger ? 'avatar-danger' : '' ?>"><?= e(substr($hiker['name'], 0, 1)) ?></div><div><strong><?= e($hiker['name']) ?></strong><small><?= e($hiker['id']) ?> <span>·</span> Hiker Node</small></div></div><span class="state-pill <?= $isDanger ? 'state-danger' : 'state-safe' ?>"><i></i><?= e($hiker['state']) ?></span></div>
          <div class="hiker-coord"><span>LAST KNOWN POSITION</span><strong><?= e($hiker['lat']) ?> <span>/</span> <?= e($hiker['lng']) ?></strong></div>
          <div class="hiker-stats"><div><small>BATTERY</small><strong class="battery <?= $hiker['battery'] < 20 ? 'battery-low' : '' ?>"><span class="battery-icon">▰</span> <?= e((string)$hiker['battery']) ?>%</strong></div><div><small>LAST REPORT</small><strong><?= e($hiker['age']) ?></strong></div><div><small>LAST HEARD BY</small><strong><?= e($hiker['heard']) ?></strong></div></div>
          <div class="hiker-foot"><span>GPS <?= e($hiker['accuracy']) ?> <b>·</b> <?= e($hiker['rssi']) ?></span><span class="demo-chip">DEMO</span></div>
        </article><?php endforeach; ?></div>
      </section>
    </div>
    <footer class="page-footer"><span>TRAILWATCH <b>·</b> LOCAL BASE CAMP CONSOLE</span><span>Sample coordinates and events <b>·</b> Not for field-safety decisions</span></footer>
  </main>
</div>
<div class="toast" role="status" aria-live="polite" id="toast"></div>
</body>
</html>
