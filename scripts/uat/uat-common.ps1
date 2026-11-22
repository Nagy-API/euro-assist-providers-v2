# Shared helpers for start-uat.ps1 / stop-uat.ps1 / reset-uat.ps1 (Windows PowerShell 5.1+).
# Dot-source only. Never prints credentials. Touches nothing outside the repository, the outer
# private .work\uat folder and the isolated local MariaDB database euro_assist_v2_uat.

Set-StrictMode -Version 2.0
$ErrorActionPreference = 'Stop'

# The ONLY database UAT tooling may target. Deliberately a constant (see config/uat.php).
$Script:UatDatabase = 'euro_assist_v2_uat'
$Script:UatProtected = @('euro_assist_v2_local', 'euro_assist_legacy_audit_20261121', 'euro_assist_v2_test', 'euro_assist_v2_rehearsal')
$Script:UatDbPort = 33316
$Script:UatWorkerName = 'euro-assist-uat'
$Script:UatServerMarker = 'euro_assist_uat=1'

function Get-UatPaths([string]$Repo) {
    $ws = Split-Path -Parent $Repo
    $work = Join-Path $ws '.work'
    $uat = Join-Path $work 'uat'
    $db = Join-Path $work 'euro-assist-20261121\database'
    return [pscustomobject]@{
        Repo        = $Repo
        Workspace   = $ws
        Php         = Join-Path $ws '.tools\php-8.3.37\php.exe'
        MariaDbd    = Join-Path $ws '.tools\mariadb-11.4.14-winx64\bin\mariadbd.exe'
        MariaDb     = Join-Path $ws '.tools\mariadb-11.4.14-winx64\bin\mariadb.exe'
        MariaDbDump = Join-Path $ws '.tools\mariadb-11.4.14-winx64\bin\mariadb-dump.exe'
        ServerIni   = Join-Path $db 'server.ini'
        RootIni     = Join-Path $db 'root-client.ini'
        Connections = Join-Path $db 'connections.json'
        UatDir      = $uat
        RunDir      = Join-Path $uat 'run'
        LogDir      = Join-Path $uat 'logs'
        BaselineDir = Join-Path $uat 'baseline'
        Credentials = Join-Path $Repo 'storage\app\private\provisioned\uat'
    }
}

function Write-UatStep([string]$Text) { Write-Host "  - $Text" }
function Write-UatOk([string]$Text) { Write-Host "  OK  $Text" -ForegroundColor Green }
function Stop-UatWithError([string]$Text) {
    Write-Host ''
    Write-Host "  ERROR: $Text" -ForegroundColor Red
    Write-Host ''
    exit 1
}

function Assert-UatPrerequisites($P) {
    if (-not (Test-Path $P.Php)) { Stop-UatWithError "Portable PHP 8.3 not found at $($P.Php). See docs/07_LOCAL_SETUP.md." }
    if (-not (Test-Path (Join-Path $P.Repo 'vendor\autoload.php'))) { Stop-UatWithError 'Composer dependencies are missing (vendor\). Run "composer install" with the portable PHP first.' }
    if (-not (Test-Path (Join-Path $P.Repo 'public\build\manifest.json'))) { Stop-UatWithError 'Built assets are missing (public\build). Run "npm run build" first.' }
    if (-not (Test-Path $P.MariaDbd)) { Stop-UatWithError "Portable MariaDB not found at $($P.MariaDbd)." }
    if (-not (Test-Path $P.Connections)) { Stop-UatWithError 'The private database settings file (.work\...\connections.json) is missing.' }
    foreach ($d in @($P.RunDir, $P.LogDir)) { if (-not (Test-Path $d)) { New-Item -ItemType Directory -Force $d | Out-Null } }
}

function Test-UatPort([int]$Port) {
    $client = New-Object System.Net.Sockets.TcpClient
    try {
        $async = $client.BeginConnect('127.0.0.1', $Port, $null, $null)
        if (-not $async.AsyncWaitHandle.WaitOne(400)) { return $false }
        $client.EndConnect($async)
        return $true
    } catch { return $false } finally { $client.Close() }
}

function Start-UatMariaDb($P) {
    if (Test-UatPort $Script:UatDbPort) { Write-UatOk "MariaDB is running on 127.0.0.1:$($Script:UatDbPort)"; return }
    if (-not (Test-Path $P.ServerIni)) { Stop-UatWithError 'MariaDB is stopped and its private server.ini was not found.' }
    Write-UatStep 'Starting the local portable MariaDB (hidden window)...'
    # No redirection: Start-Process then uses ShellExecute, so the database server never inherits
    # (and holds open) this console's handles. MariaDB writes its own error log per server.ini.
    Start-Process -FilePath $P.MariaDbd -ArgumentList ('--defaults-file="' + $P.ServerIni + '"') -WindowStyle Hidden | Out-Null
    for ($i = 0; $i -lt 60; $i++) {
        Start-Sleep -Milliseconds 500
        if (Test-UatPort $Script:UatDbPort) { Write-UatOk 'MariaDB started'; return }
    }
    Stop-UatWithError 'MariaDB did not start within 30 seconds. Check the MariaDB error log in the private .work folder.'
}

# Loads the UAT database account into THIS process's environment only (inherited by the child
# processes it starts). Laravel's .env never overrides real environment variables.
function Set-UatDatabaseEnvironment($P) {
    $c = (Get-Content -Raw $P.Connections | ConvertFrom-Json)
    if (-not ($c.PSObject.Properties.Name -contains 'v2_uat')) { Stop-UatWithError 'No "v2_uat" entry in the private connections file.' }
    $u = $c.v2_uat
    if ($u.database -ne $Script:UatDatabase -or $Script:UatProtected -contains $u.database) { Stop-UatWithError "Refusing: the configured UAT database is not $($Script:UatDatabase)." }
    if ($u.host -ne '127.0.0.1' -or [int]$u.port -ne $Script:UatDbPort) { Stop-UatWithError 'Refusing: the UAT database must be on 127.0.0.1:33316.' }
    $env:APP_ENV = 'local'
    $env:DB_CONNECTION = 'mariadb'
    $env:DB_URL = ''
    $env:DB_HOST = '127.0.0.1'
    $env:DB_PORT = [string]$u.port
    $env:DB_DATABASE = $u.database
    $env:DB_USERNAME = $u.username
    $env:DB_PASSWORD = $u.password
    $env:LOG_CHANNEL = 'single'
}

function Invoke-UatArtisan($P, [string[]]$Arguments) {
    Push-Location $P.Repo
    try {
        # Output goes to the console; only the exit code is returned to the caller.
        & $P.Php artisan @Arguments | Out-Host
        return $LASTEXITCODE
    } finally { Pop-Location }
}

function Get-UatProcess($P, [string]$Name, [string]$Marker) {
    $pidFile = Join-Path $P.RunDir "$Name.pid"
    if (-not (Test-Path $pidFile)) { return $null }
    $id = [int](Get-Content $pidFile -Raw)
    $proc = Get-CimInstance Win32_Process -Filter "ProcessId = $id" -ErrorAction SilentlyContinue
    # Only a process whose command line carries our marker is ours (never touch unrelated PHP/ports).
    if ($null -ne $proc -and $proc.CommandLine -and $proc.CommandLine.Contains($Marker)) { return $proc }
    Remove-Item $pidFile -Force -ErrorAction SilentlyContinue
    return $null
}

function Stop-UatProcesses($P) {
    $stopped = 0
    foreach ($item in @(@('server', $Script:UatServerMarker), @('worker', "--name=$($Script:UatWorkerName)"))) {
        $proc = Get-UatProcess $P $item[0] $item[1]
        if ($null -ne $proc) {
            Stop-Process -Id $proc.ProcessId -Force -ErrorAction SilentlyContinue
            $stopped++
            Write-UatOk "Stopped UAT $($item[0]) (process $($proc.ProcessId))"
        }
        Remove-Item (Join-Path $P.RunDir "$($item[0]).pid") -Force -ErrorAction SilentlyContinue
    }
    Remove-Item (Join-Path $P.RunDir 'url.txt') -Force -ErrorAction SilentlyContinue
    return $stopped
}

function Quote-UatArg([string]$Value) { return '"' + $Value + '"' }

# Starts PHP in the background WITHOUT inheriting this console's handles (ShellExecute of a hidden
# cmd.exe that redirects PHP's output to a private log file). Returns the PHP process id, found as
# the child of that cmd.exe whose command line carries $Marker.
function Start-UatBackgroundPhp($P, [string]$WorkDir, [string]$PhpArguments, [string]$LogFile, [string]$Marker) {
    $line = '/d /s /c "' + (Quote-UatArg $P.Php) + ' ' + $PhpArguments + ' > ' + (Quote-UatArg $LogFile) + ' 2>&1"'
    $psi = New-Object System.Diagnostics.ProcessStartInfo 'cmd.exe', $line
    $psi.UseShellExecute = $true
    $psi.WindowStyle = [System.Diagnostics.ProcessWindowStyle]::Hidden
    $psi.WorkingDirectory = $WorkDir
    $shell = [System.Diagnostics.Process]::Start($psi)
    for ($i = 0; $i -lt 40; $i++) {
        $child = Get-CimInstance Win32_Process -Filter "ParentProcessId = $($shell.Id) AND Name = 'php.exe'" -ErrorAction SilentlyContinue |
            Where-Object { $_.CommandLine -and $_.CommandLine.Contains($Marker) } | Select-Object -First 1
        if ($null -ne $child) { return [int]$child.ProcessId }
        Start-Sleep -Milliseconds 250
    }
    return $null
}

function Test-UatProcessAlive([int]$Id) { return $null -ne (Get-Process -Id $Id -ErrorAction SilentlyContinue) }
