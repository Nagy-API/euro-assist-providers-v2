<#
.SYNOPSIS
    Starts the Euro Assist Providers V2 owner-UAT environment on this computer.

.DESCRIPTION
    One command for the owner's acceptance testing (docs/16_UAT_CHECKLIST.md):
      * checks portable PHP 8.3, Composer dependencies and built assets;
      * starts the existing portable MariaDB if it is stopped;
      * points THIS window (not .env) at the isolated database euro_assist_v2_uat and verifies it
        with "php artisan uat:check" (refuses any other database, any non-loopback host, production);
      * applies pending migrations to the UAT database only;
      * starts the web server on 127.0.0.1 (8000, or the next free port 8001-8010, never killing
        anything else) and the background queue worker for Excel imports/exports;
      * opens the login page in your default browser.
    Logs stay private in ..\.work\uat\logs. Nothing is installed; PATH is not changed.

    By default the window stays open: press Enter (or Ctrl+C) to stop everything.
    Use -NoWait to leave it running in the background, then stop it with .\stop-uat.ps1.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File .\start-uat.ps1
#>
param(
    [switch]$NoWait,
    [switch]$NoBrowser
)

$Repo = $PSScriptRoot
. (Join-Path $Repo 'scripts\uat\uat-common.ps1')
$P = Get-UatPaths $Repo

Write-Host ''
Write-Host 'Euro Assist Providers V2 - owner UAT environment' -ForegroundColor Cyan
Write-Host ''

Assert-UatPrerequisites $P
Write-UatOk 'PHP, Composer dependencies and built assets found'

# Already running? Re-use it instead of starting duplicates.
$server = Get-UatProcess $P 'server' $Script:UatServerMarker
$worker = Get-UatProcess $P 'worker' "--name=$($Script:UatWorkerName)"
$urlFile = Join-Path $P.RunDir 'url.txt'
if ($null -ne $server -and $null -ne $worker -and (Test-Path $urlFile)) {
    $url = (Get-Content $urlFile -Raw).Trim()
    Write-UatOk "UAT is already running at $url"
    if (-not $NoBrowser) { Start-Process "$url/login" }
    Write-Host "  To stop it: .\stop-uat.ps1"
    exit 0
}
if ($null -ne $server -or $null -ne $worker) {
    Write-UatStep 'A previous UAT session was only partly running; cleaning it up first.'
    Stop-UatProcesses $P | Out-Null
}

Start-UatMariaDb $P
Set-UatDatabaseEnvironment $P

if ((Invoke-UatArtisan $P @('config:clear', '--quiet')) -ne 0) { Stop-UatWithError 'Could not clear the Laravel configuration cache.' }
Write-UatStep 'Verifying the database target...'
if ((Invoke-UatArtisan $P @('uat:check')) -ne 0) { Stop-UatWithError 'The UAT database check failed (see the message above). Nothing was started.' }
if ((Invoke-UatArtisan $P @('migrate', '--force', '--no-interaction')) -ne 0) { Stop-UatWithError 'Applying database migrations to the UAT database failed.' }
Invoke-UatArtisan $P @('imports:prune') | Out-Null
Invoke-UatArtisan $P @('exports:prune') | Out-Null
Write-UatOk 'Expired private import/export files cleaned up'

# Port: 8000, else the next free loopback port up to 8010. Never stop another program.
$port = $null
foreach ($candidate in 8000..8010) { if (-not (Test-UatPort $candidate)) { $port = $candidate; break } }
if ($null -eq $port) { Stop-UatWithError 'Ports 8000-8010 on 127.0.0.1 are all in use by other programs. Close one of them and try again.' }
if ($port -ne 8000) { Write-Host "  NOTE: port 8000 is used by another program; using port $port instead." -ForegroundColor Yellow }
$url = "http://127.0.0.1:$port"
$env:APP_URL = $url

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$serverScript = Join-Path $Repo 'vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php'
$serverId = Start-UatBackgroundPhp $P (Join-Path $Repo 'public') `
    ('-d ' + $Script:UatServerMarker + " -S 127.0.0.1:$port -t " + (Quote-UatArg (Join-Path $Repo 'public')) + ' ' + (Quote-UatArg $serverScript)) `
    (Join-Path $P.LogDir "server-$stamp.log") $Script:UatServerMarker
if ($null -eq $serverId) { Stop-UatWithError "The web server did not start. Private logs: $($P.LogDir)" }
Set-Content -Path (Join-Path $P.RunDir 'server.pid') -Value $serverId

$workerMarker = "--name=$($Script:UatWorkerName)"
$workerId = Start-UatBackgroundPhp $P $Repo `
    ("artisan queue:work database $workerMarker --tries=1 --timeout=3600 --sleep=2") `
    (Join-Path $P.LogDir "worker-$stamp.log") $workerMarker
if ($null -eq $workerId) { Stop-UatProcesses $P | Out-Null; Stop-UatWithError "The queue worker did not start. Private logs: $($P.LogDir)" }
Set-Content -Path (Join-Path $P.RunDir 'worker.pid') -Value $workerId
Set-Content -Path $urlFile -Value $url

# Wait until the application answers on /up.
$ready = $false
for ($i = 0; $i -lt 60; $i++) {
    Start-Sleep -Milliseconds 500
    if (-not (Test-UatProcessAlive $serverId)) { break }
    try {
        $r = Invoke-WebRequest -Uri "$url/up" -UseBasicParsing -TimeoutSec 3
        if ($r.StatusCode -eq 200) { $ready = $true; break }
    } catch { }
}
if (-not $ready -or -not (Test-UatProcessAlive $workerId)) {
    Stop-UatProcesses $P | Out-Null
    Stop-UatWithError "The application did not start correctly. Private logs: $($P.LogDir)"
}

Write-UatOk "Web server running at $url (process $serverId)"
Write-UatOk "Queue worker running for Excel imports/exports (process $workerId)"
Write-Host ''
Write-Host "  Open:      $url/login" -ForegroundColor Cyan
Write-Host '  Accounts:  admin@uat.local, editor@uat.local, viewer@uat.local'
Write-Host "  Passwords: one text file per account in $($P.Credentials)"
Write-Host '             (open it yourself; it is never shown here and never committed)'
Write-Host "  Logs:      $($P.LogDir)"
Write-Host '  Guide:     docs\16_UAT_CHECKLIST.md'
Write-Host ''

if (-not $NoBrowser) { Start-Process "$url/login" }

if ($NoWait) {
    Write-Host '  Running in the background. Stop it with:  .\stop-uat.ps1' -ForegroundColor Yellow
    exit 0
}

try {
    Read-Host '  Press Enter to stop the UAT environment' | Out-Null
} finally {
    Write-Host ''
    Stop-UatProcesses $P | Out-Null
    Write-Host '  UAT environment stopped. MariaDB keeps running (shared local database server).'
}
