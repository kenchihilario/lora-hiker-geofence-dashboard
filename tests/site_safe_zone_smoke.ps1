param(
  [string]$BaseUrl = 'http://127.0.0.1:8000/api',
  [string]$MySql = 'C:\xampp\mysql\bin\mysql.exe',
  [string]$Database = 'hiker_dashboard_test_codex'
)
$ErrorActionPreference = 'Stop'
function Api($path, $method='Get', $body=$null) {
  $args = @{ Uri="$BaseUrl/$path"; Method=$method; TimeoutSec=10 }
  if ($null -ne $body) { $args.ContentType='application/json'; $args.Body=($body | ConvertTo-Json -Depth 8 -Compress) }
  Invoke-RestMethod @args
}
$originalSite = (Api 'sites').selected_site
Api 'settings/site' 'Post' @{site_id='pup'} | Out-Null
$original = Api 'safe-zone'
if (-not $original.safe_zone) { throw 'Test requires an existing PUP polygon in this disposable database.' }
$testName = 'DEMO TEST - independent polygon'
try {
  Api 'settings/site' 'Post' @{site_id='balagbag'} | Out-Null
  $site = Api 'safe-zone'
  if ($site.selected_site -ne 'balagbag' -or $site.safe_zone) { throw 'Balagbag incorrectly inherited the PUP polygon.' }
  if ($site.reference_points.Count -ne 1 -or [double]$site.reference_points[0].latitude -ne 14.8243087) { throw 'Balagbag supplied peak coordinate was not returned.' }
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
