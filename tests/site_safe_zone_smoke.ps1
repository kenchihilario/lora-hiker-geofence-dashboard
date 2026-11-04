param(
  [string]$BaseUrl = 'http://127.0.0.1:8000/api',
  [string]$MySql = 'C:\xampp\mysql\bin\mysql.exe',
  [string]$Database = 'hiker_dashboard_test_codex'
)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$modePath = Join-Path $root 'data\mode.json'
if (Test-Path -LiteralPath $modePath) {
  try { if ((Get-Content -Raw -LiteralPath $modePath | ConvertFrom-Json).mode -eq 'LIVE') { throw 'REFUSED: tests cannot run while LIVE mode is active.' } }
  catch { if ($_.Exception.Message -like 'REFUSED:*') { throw }; throw 'REFUSED: invalid mode file; refusing database tests.' }
}
$php = 'C:\xampp\php\php.exe'
$targetHost = ([uri]$BaseUrl).Host
if ($targetHost -notin @('127.0.0.1','localhost','::1','[::1]')) { throw 'REFUSED: site smoke tests must target a loopback server.' }
$runtime = Join-Path $root '.runtime'
New-Item -ItemType Directory -Force -Path $runtime | Out-Null
$probe = Join-Path $runtime 'site_test_db_probe.php'
[IO.File]::WriteAllText($probe,'<?php $c=require __DIR__."/../config.php"; echo $c["db_profiles"]["demo"]["db_name"]??"";')
try { $configuredDemoDb = & $php $probe; if ($LASTEXITCODE -ne 0 -or $configuredDemoDb -ne 'hiker_dashboard_test_codex') { throw 'REFUSED: tests require the dedicated DEMO test DB profile.' } }
finally { Remove-Item -LiteralPath $probe -ErrorAction SilentlyContinue }
function Api($path, $method='Get', $body=$null) {
  $args = @{ Uri="$BaseUrl/$path"; Method=$method; TimeoutSec=10 }
  if ($null -ne $body) { $args.ContentType='application/json'; $args.Body=($body | ConvertTo-Json -Depth 8 -Compress) }
  Invoke-RestMethod @args
}
$status = Api 'status'
if (-not $status.test_database -or $status.active_mode -ne 'DEMO' -or $status.active_database -ne 'hiker_dashboard_test_codex') { throw 'REFUSED: site smoke test requires active DEMO test database hiker_dashboard_test_codex.' }
$originalSite = (Api 'sites').selected_site
Api 'settings/site' 'Post' @{site_id='pup'} | Out-Null
$original = Api 'safe-zone'
if (-not $original.safe_zone) { throw 'Test requires an existing PUP polygon in this disposable database.' }
$testName = 'DEMO TEST - independent polygon'
try {
  Api 'settings/site' 'Post' @{site_id='balagbag'} | Out-Null
  $site = Api 'safe-zone'
  if ($site.selected_site -ne 'balagbag' -or $site.safe_zone) { throw 'Balagbag incorrectly inherited the PUP polygon.' }
  if ($site.reference_points.Count -ne 2 -or [double]$site.reference_points[0].latitude -ne 14.8243087 -or -not ($site.reference_points | Where-Object { $_.label -like '*unsurveyed*' -and [double]$_.latitude -eq 14.8239931 })) { throw 'Balagbag supplied references were not returned.' }
  $vertices = @(
    @{latitude=14.8240;longitude=121.1800}, @{latitude=14.8240;longitude=121.1810},
    @{latitude=14.8250;longitude=121.1810}, @{latitude=14.8250;longitude=121.1800}
  )
  $saved = Api 'safe-zone' 'Put' @{site_id='balagbag';name=$testName;created_by='test';vertices=$vertices}
  if ($saved.safe_zone.site_id -ne 'balagbag') { throw 'Saved polygon has incorrect site_id.' }
  Api 'settings/site' 'Post' @{site_id='pup_mountain_prep'} | Out-Null
  $prep = Api 'safe-zone'
  if ($prep.selected_site -ne 'pup_mountain_prep' -or $prep.safe_zone -or $prep.site.center) { throw 'PUP Mountain Prep must start with an empty polygon and unset center.' }
  if ($prep.site.terrain_profile -ne 'mountain') { throw 'PUP Mountain Prep profile is not marked as mountain preparation.' }
  Api 'settings/site' 'Post' @{site_id='mapalad_backup'} | Out-Null
  $mapalad = Api 'safe-zone'
  if ($mapalad.selected_site -ne 'mapalad_backup' -or $mapalad.site.center -or $mapalad.safe_zone) { throw 'Mt. Mapalad backup site must have no invented center or safe zone.' }
  Api 'settings/site' 'Post' @{site_id='pup'} | Out-Null
  $pup = Api 'safe-zone'
  if ($pup.selected_site -ne 'pup' -or $pup.safe_zone.id -ne $original.safe_zone.id) { throw 'Saving the Balagbag polygon modified the PUP polygon.' }
  Write-Output 'PASS: per-site safe-zone selection and independence'
  Write-Output 'PASS: Balagbag peak reference comes from supplied configuration'
} finally {
  Api 'settings/site' 'Post' @{site_id=$originalSite} | Out-Null
  & $MySql -uroot $Database -e "DELETE FROM safe_zone_vertices WHERE safe_zone_id IN (SELECT id FROM safe_zones WHERE site_id='balagbag' AND name='$testName'); DELETE FROM safe_zones WHERE site_id='balagbag' AND name='$testName';"
  if ($LASTEXITCODE -ne 0) { throw 'Could not remove test polygon from disposable database.' }
}
