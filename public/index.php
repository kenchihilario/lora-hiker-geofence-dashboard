<?php
require_once __DIR__.'/../src/operator_auth.php'; operator_page_guard(); $dashboardMode=active_mode();$csrfToken=operator_csrf_token();
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
if($dashboardMode==='LIVE'){$hikers=[];$alerts=[];}
function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken,ENT_QUOTES,'UTF-8') ?>">
  <meta name="theme-color" content="#101918">
  <title><?= e($dashboardMode) ?> · Trailwatch · Operations</title>
  <link rel="stylesheet" href="assets/css/dashboard.css?v=20261107-1">
  <script src="assets/js/source-flags.js?v=20261105-2" defer></script>
  <script src="assets/js/dashboard-label-layout.js?v=20261104-1" defer></script>
  <script src="assets/js/utc-time.js?v=20261110-1" defer></script>
  <script src="assets/js/dashboard.js?v=20261110-1" defer></script>
</head>
<body data-active-mode="<?= e($dashboardMode) ?>">
<div class="app-shell">
  <aside class="sidebar" aria-label="Main navigation">
    <a class="brand" href="index.php" aria-label="Trailwatch home"><span class="brand-mark">T</span><span>trailwatch<small>BASE CAMP CONSOLE</small></span></a>
    <div class="nav-label">OPERATIONS</div>
    <nav>
      <a class="nav-link active" href="index.php"><span class="nav-icon">⌖</span>Live dashboard<span class="nav-count"><?= $dashboardMode==='LIVE'?'00':'02' ?></span></a>
      <a class="nav-link" href="safe-zone.php"><span class="nav-icon">⬡</span>Safe zone</a>
      <a class="nav-link" href="settings.php"><span class="nav-icon">⚙</span>Settings</a>
      <a class="nav-link disabled" href="#hikers"><span class="nav-icon">♙</span>Hiker nodes</a>
      <a class="nav-link disabled" href="#logs"><span class="nav-icon">≡</span>Event logs</a>
    </nav>
    <div class="sidebar-bottom"><div class="operator"><div class="avatar">BC</div><div><strong>Base Camp</strong><small>Local operator</small></div><span class="online-dot"></span></div><div class="local-note"><span>▣</span> LOCAL SYSTEM<br><small>Designed for offline use</small></div></div>
  </aside>

  <main class="main-area">
    <header class="topbar"><div class="crumb">Operations <span>/</span> Live dashboard</div><div class="top-meta"><strong id="top-mode-indicator" class="mode-indicator <?= $dashboardMode==='LIVE'?'mode-live':'mode-demo' ?>"><?= $dashboardMode==='LIVE'?'LIVE':'DEMO / SIMULATION' ?></strong><div class="clock"><span class="live-dot"></span> LOCAL API <span class="divider">·</span> <span id="data-age">Connecting…</span></div><button class="icon-button" aria-label="Help" title="Help">?</button><div class="top-avatar">BC</div></div></header>
    <div class="demo-banner <?= $dashboardMode==='LIVE'?'mode-live':'mode-demo' ?>" id="demo-banner"><span class="demo-icon">i</span><div><strong id="main-mode-banner"><?= $dashboardMode==='LIVE'?'LIVE DATABASE':'DEMO / SIMULATION MODE' ?></strong><span id="mode-banner-detail"><?= $dashboardMode==='LIVE'?'Separate live database. Demo, rehearsal, and test packets are rejected.':'Simulation records only. No radio delivery or real hiker status is implied.' ?></span></div><span class="demo-tag" id="data-mode-tag"><?= $dashboardMode ?></span></div>
    <div class="bench-session-banner" id="bench-session-banner" hidden role="status"><strong>BENCH SESSION ACTIVE</strong> · New LIVE uplinks are marked BENCH and excluded from research summaries.</div>
    <div class="api-error" id="no-active-session" hidden role="alert"></div>

    <section class="page-heading"><div><div class="eyebrow">TEST SITE</div><h1 id="dashboard-title"><?= $dashboardMode==='LIVE'?'Live monitoring':'Demo / simulation overview' ?></h1><p>Monitor hiker locations, safety status, and system connections.</p><label class="site-picker">Test site <select id="site-select" aria-label="Test site"></select></label><span class="phase-indicator" id="dashboard-phase-mode" role="status">Phase —</span></div><button class="button button-quiet" id="refresh-demo"><span>↻</span> Refresh view</button></section>

    <section class="connection-strip" aria-label="System connections">
      <div class="connection"><span class="connection-symbol connected">⌂</span><div><small>BASE CAMP NODE</small><strong><i class="status-dot green"></i> Unknown <em>· API</em></strong></div></div>
      <div class="connection"><span class="connection-symbol muted">⌁</span><div><small>SERIAL BRIDGE</small><strong><i class="status-dot gray"></i> Unknown <em>· Awaiting heartbeat</em></strong></div></div>
      <div class="connection"><span class="connection-symbol connected">⌖</span><div><small>CHECKPOINT NODE</small><strong><i class="status-dot amber"></i> No checkpoint report <em>· API</em></strong></div></div>
      <div class="connection"><span class="connection-symbol connected">▤</span><div><small>LOCAL DATA</small><strong><i class="status-dot green"></i> Waiting <em id="data-state">for API response</em></strong></div></div>
    </section>

    <div class="content-grid"><div class="api-error" id="api-error" role="alert" hidden></div><div class="database-check-inline" id="database-check-inline" role="status">Checking active database…</div>
      <section class="panel map-panel" aria-labelledby="map-title">
        <div class="panel-heading"><div><div class="section-kicker">FIELD MAP</div><h2 id="map-title">Trail &amp; hiker locations</h2></div><span class="offline-pill"><span>▧</span> TILE-FREE LOCAL MAP</span></div>
        <div class="map-wrap">
          <div class="no-safe-zone" id="no-safe-zone" hidden>No safe zone set · hikers will remain unclassified until a polygon is configured.</div>
          <svg class="trail-map" viewBox="0 0 800 470" role="img" aria-labelledby="map-desc" preserveAspectRatio="xMidYMid meet">
            <title id="map-desc">Local metric trail sketch; no map tiles or network map data are loaded.</title>
            <rect id="map-background" class="map-background" width="800" height="470"/>
            <g id="map-grid"></g><path id="api-zone" fill-rule="evenodd"/><g id="api-reference-points"></g><g id="api-markers"><text class="map-empty-title" x="400" y="220" text-anchor="middle">No positions yet</text><text class="map-empty-hint" x="400" y="246" text-anchor="middle">Register hikers and configure a safe zone to see positions.</text></g><g id="map-north-scale"></g>
          </svg>
          <div class="map-scale" id="map-scale-label">Meters · local frame</div>
        </div>
        <div class="map-legend"><span><i class="legend-dot safe"></i>Safe</span><span><i class="legend-dot danger"></i>Off trail</span><span><i class="legend-line"></i>Safe-zone boundary</span><span class="legend-note">Position markers · meter grid</span></div>
      </section>

      <aside class="attention-stack"><section class="panel alert-panel" aria-labelledby="alerts-title"><div class="panel-heading"><div><div class="section-kicker">ATTENTION</div><h2 id="alerts-title">Recent alerts</h2></div><span class="alert-counter"><?= $dashboardMode==='LIVE'?'0 ACTIVE':'2 ACTIVE' ?></span></div>
        <div class="alert-list" id="api-events"><?php if($dashboardMode==='LIVE'): ?><p class="empty">No events recorded.</p><?php else: foreach ($alerts as $alert): ?><article class="alert-item <?= e($alert['class']) ?>"><span class="alert-icon"><?= $alert['class'] === 'danger' ? '!' : ($alert['class'] === 'warning' ? '!' : 'i') ?></span><div class="alert-copy"><div class="alert-top"><strong><?= e($alert['type']) ?></strong><time><?= e($alert['time']) ?></time></div><div class="alert-hiker"><?= e($alert['hiker']) ?></div><p><?= e($alert['detail']) ?></p><?php if ($alert['class'] === 'danger'): ?><button class="text-action acknowledge">Acknowledge demo alert <span>→</span></button><?php endif; ?></div></article><?php endforeach; endif; ?></div>
        <a class="panel-footer" href="#logs">View event log <span>→</span></a>
      </section>

      <section class="panel storm-panel" aria-labelledby="storm-title"><div class="section-kicker">OPERATOR COMMAND</div><h2 id="storm-title">STORM · TURN BACK</h2><p class="storm-availability" id="storm-availability" role="status">Checking serial bridge status…</p><div class="storm-actions"><button class="button button-storm" id="storm-button" type="button" disabled><span aria-hidden="true">⚠</span> ARM STORM · TURN BACK</button><button class="button button-quiet" id="cancel-storm-button" type="button" disabled>Cancel active</button></div></section></aside>

      <section class="panel hikers-panel" id="hikers" aria-labelledby="hikers-title"><div class="panel-heading"><div><div class="section-kicker">FIELD TEAM</div><h2 id="hikers-title">Hiker nodes <span class="heading-count"><?= $dashboardMode==='LIVE'?'00':'02' ?></span></h2></div></div>
        <div class="hiker-grid" id="hiker-cards"><?php if($dashboardMode==='LIVE'): ?><p class="empty">No hikers registered. Add a hiker in Settings before testing.</p><?php else: foreach ($hikers as $hiker): $isDanger = $hiker['state'] === 'OFF TRAIL'; ?><article class="hiker-card"><div class="hiker-card-top"><div class="person"><div class="person-avatar <?= $isDanger ? 'avatar-danger' : '' ?>"><?= e(substr($hiker['name'], 0, 1)) ?></div><div><strong><?= e($hiker['name']) ?></strong><small><?= e($hiker['id']) ?> <span>·</span> Hiker Node</small></div></div><span class="state-pill <?= $isDanger ? 'state-danger' : 'state-safe' ?>"><i></i><?= e($hiker['state']) ?></span></div>
          <div class="hiker-coord"><span>LAST KNOWN POSITION</span><strong><?= e($hiker['lat']) ?> <span>/</span> <?= e($hiker['lng']) ?></strong></div>
          <div class="hiker-stats"><div><small>BATTERY</small><strong class="battery <?= $hiker['battery'] < 20 ? 'battery-low' : '' ?>"><span class="battery-icon">▰</span> <?= e((string)$hiker['battery']) ?>%</strong></div><div><small>LAST REPORT</small><strong><?= e($hiker['age']) ?></strong></div><div><small>LAST HEARD BY</small><strong><?= e($hiker['heard']) ?></strong></div></div>
          <div class="hiker-foot"><span>GPS <?= e($hiker['accuracy']) ?> <b>·</b> <?= e($hiker['rssi']) ?></span><span class="demo-chip">DEMO</span></div>
        </article><?php endforeach; endif; ?></div>
      </section>
      <section class="panel packet-log-panel" id="logs" aria-labelledby="packet-log-title"><div class="panel-heading"><div><div class="section-kicker">RECEPTIONS</div><h2 id="packet-log-title">Packet log</h2></div><div class="packet-export-actions"><a class="offline-pill" href="api/export.csv?type=packets">Export packet CSV</a> <a class="offline-pill" href="api/export.csv?type=events">Export event CSV</a></div></div><div class="packet-log-scroll"><table class="packet-log-table"><thead><tr><th>Time</th><th>Hiker / seq</th><th>Heard by</th><th>Relay hop</th><th>Route</th><th>Duplicate</th><th>Session / phase</th></tr></thead><tbody id="packet-log-rows"><tr class="packet-loading-row"><td colspan="7" class="empty"><span class="loading-skeleton" aria-hidden="true"><i></i><i></i></span><span>Loading packet receptions…</span></td></tr></tbody></table></div></section>
    </div>
    <footer class="page-footer"><span>TRAILWATCH <b>·</b> LOCAL BASE CAMP CONSOLE</span><span>Sample coordinates and events <b>·</b> Not for field-safety decisions</span></footer>
  </main>
</div>
<div class="toast" role="status" aria-live="polite" id="toast"></div>
</body>
</html>
