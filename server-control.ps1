param(
  [Parameter(Mandatory=$true)][ValidateSet('start','restart','stop')][string]$Action,
  [string]$ProjectRoot = (Split-Path -Parent $MyInvocation.MyCommand.Path)
)
$ErrorActionPreference = 'Stop'
$php = 'C:\xampp\php\php.exe'
$hostAddress = '127.0.0.1'
$runtime = Join-Path $ProjectRoot '.runtime'
$pidFile = Join-Path $runtime 'dashboard-server.pid'
$portFile = Join-Path $runtime 'dashboard-server.port'
$stdout = Join-Path $runtime 'dashboard-server.out.log'
$stderr = Join-Path $runtime 'dashboard-server.err.log'

function Get-SavedPort {
  $savedPort = 0
  if ((Test-Path $portFile) -and [int]::TryParse((Get-Content -LiteralPath $portFile -Raw).Trim(), [ref]$savedPort)) { return $savedPort }
  return 8000
}
function Get-ManagedServer {
  if (-not (Test-Path $pidFile)) { return $null }
  $savedPid = 0
  if (-not [int]::TryParse((Get-Content -LiteralPath $pidFile -Raw).Trim(), [ref]$savedPid)) { return $null }
  $savedPort = Get-SavedPort
  $proc = Get-CimInstance Win32_Process -Filter "ProcessId = $savedPid" -ErrorAction SilentlyContinue
  if ($null -eq $proc) { return $null }
  if ($proc.ExecutablePath -ne $php -or $proc.CommandLine -notmatch "-S\s+127\.0\.0\.1:$savedPort\b") { return $null }
  return $proc
}
function Stop-ManagedServer {
  $proc = Get-ManagedServer
  if ($null -ne $proc) {
    Stop-Process -Id $proc.ProcessId -Force
    Write-Host "Stopped Trailwatch PHP server (PID $($proc.ProcessId), port $(Get-SavedPort))."
  } else { Write-Host 'Trailwatch PHP server was not running.' }
  Remove-Item -LiteralPath $pidFile,$portFile -Force -ErrorAction SilentlyContinue
}

if (-not (Test-Path $ProjectRoot)) { throw "Project folder not found: $ProjectRoot" }
if (-not (Test-Path $php)) { throw "PHP not found at $php. Update the path in server-control.ps1." }
if (-not (Test-Path (Join-Path $ProjectRoot 'public\router.php'))) { throw 'public\router.php is missing.' }
New-Item -ItemType Directory -Force -Path $runtime | Out-Null
if ($Action -eq 'stop') { Stop-ManagedServer; exit 0 }
if ($Action -eq 'restart') { Stop-ManagedServer; Start-Sleep -Milliseconds 700 }

$existing = Get-ManagedServer
if ($null -ne $existing) {
  $port = Get-SavedPort; $url = "http://${hostAddress}:$port/"
  Write-Host "Trailwatch is already running at $url (PID $($existing.ProcessId))."
  Start-Process $url
  exit 0
}

$port = $null
foreach ($candidate in 8000..8010) {
  $listener = Get-NetTCPConnection -LocalAddress $hostAddress -LocalPort $candidate -State Listen -ErrorAction SilentlyContinue
  if (-not $listener) { $port = $candidate; break }
}
if ($null -eq $port) { throw 'Ports 8000–8010 are all in use. Close an unused local server and retry.' }
$url = "http://${hostAddress}:$port/"
$proc = Start-Process -FilePath $php -ArgumentList @('-S', "${hostAddress}:$port", '-t', 'public', 'public/router.php') -WorkingDirectory $ProjectRoot -WindowStyle Hidden -RedirectStandardOutput $stdout -RedirectStandardError $stderr -PassThru
Set-Content -LiteralPath $pidFile -Value $proc.Id -NoNewline
Set-Content -LiteralPath $portFile -Value $port -NoNewline
Start-Sleep -Seconds 1
if ($proc.HasExited) {
  Remove-Item -LiteralPath $pidFile,$portFile -Force -ErrorAction SilentlyContinue
  throw "PHP server exited during startup. Check $stderr"
}
Write-Host "Trailwatch dashboard is running at $url (PID $($proc.Id))."
Write-Host 'Keep XAMPP MySQL running for database-backed API features.'
Start-Process $url
