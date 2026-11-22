<#
.SYNOPSIS
    Restores the owner-UAT database euro_assist_v2_uat to its clean baseline.

.DESCRIPTION
    Replaces ONLY the local database euro_assist_v2_uat with the verified baseline backup kept in
    the private folder ..\.work\uat\baseline. Everything done during UAT is lost: providers you
    added, edited, deleted or restored, imports/exports, pair-review decisions, sessions.
    The preserved migration database (euro_assist_v2_local) and the frozen legacy database are
    never touched; their checksums are compared before and after.

    Refuses to run while the UAT web server is running, refuses any other target, and requires
    the exact confirmation text. It never runs automatically.

.PARAMETER Confirm
    Must be exactly "RESET euro_assist_v2_uat". If omitted you are asked to type it.

.PARAMETER CreateBaseline
    One-time setup: save the CURRENT UAT database as the baseline (refuses if one exists).

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File .\reset-uat.ps1
#>
param(
    [string]$Target = 'euro_assist_v2_uat',
    [string]$Confirm = '',
    [switch]$CreateBaseline
)

$Repo = $PSScriptRoot
. (Join-Path $Repo 'scripts\uat\uat-common.ps1')
$P = Get-UatPaths $Repo
$Phrase = "RESET $($Script:UatDatabase)"

if ($Target -cne $Script:UatDatabase -or $Script:UatProtected -contains $Target) {
    Stop-UatWithError "Refusing: reset-uat only operates on $($Script:UatDatabase) (got '$Target')."
}
foreach ($tool in @($P.MariaDb, $P.MariaDbDump, $P.RootIni)) { if (-not (Test-Path $tool)) { Stop-UatWithError "Missing local tool or private file: $tool" } }
if (-not (Test-UatPort $Script:UatDbPort)) { Stop-UatWithError 'MariaDB is not running. Start it with .\start-uat.ps1 (then stop the app) or as described in docs/07_LOCAL_SETUP.md.' }
if ($null -ne (Get-UatProcess $P 'server' $Script:UatServerMarker) -or $null -ne (Get-UatProcess $P 'worker' "--name=$($Script:UatWorkerName)")) {
    Stop-UatWithError 'The UAT application is running. Stop it first with .\stop-uat.ps1.'
}
if (-not (Test-Path $P.BaselineDir)) { New-Item -ItemType Directory -Force $P.BaselineDir | Out-Null }
if (-not (Test-Path $P.RunDir)) { New-Item -ItemType Directory -Force $P.RunDir | Out-Null }

$baseline = Join-Path $P.BaselineDir "$($Script:UatDatabase)-baseline.sql"
$baselineHash = "$baseline.sha256"
$baselineCounts = Join-Path $P.BaselineDir "$($Script:UatDatabase)-baseline-counts.txt"

# The root client options file holds a client-only setting mariadb-dump rejects: use a filtered copy.
$dumpIni = Join-Path $P.RunDir 'dump-client.ini'
Get-Content $P.RootIni | Where-Object { $_ -notmatch 'local[-_]infile' } | Set-Content -Path $dumpIni -Encoding ASCII

function Invoke-Sql([string]$Database, [string]$Sql) {
    $out = & $P.MariaDb "--defaults-extra-file=$($P.RootIni)" -N -B $Database -e $Sql
    if ($LASTEXITCODE -ne 0) { Stop-UatWithError "Database command failed on $Database." }
    return $out
}

$CountSql = "SELECT CONCAT_WS(' ', (SELECT COUNT(*) FROM providers), (SELECT COUNT(*) FROM providers WHERE deleted_at IS NOT NULL), (SELECT COUNT(*) FROM provider_translations), (SELECT COUNT(*) FROM featured_providers), (SELECT COUNT(*) FROM provider_types), (SELECT COUNT(*) FROM legacy_id_maps), (SELECT COUNT(*) FROM legacy_pair_suggestions WHERE status='suggested'), (SELECT COUNT(*) FROM import_jobs), (SELECT COUNT(*) FROM export_jobs), (SELECT COUNT(*) FROM users WHERE is_active=1))"
$CountLabels = 'providers deleted translations featured types legacy_maps suggestions_open import_jobs export_jobs active_users'

function Get-Fingerprint([string]$Database) {
    $tables = Invoke-Sql $Database 'SHOW TABLES'
    $sql = ($tables | ForEach-Object { "CHECKSUM TABLE ``$_`` EXTENDED;" }) -join ' '
    return ((Invoke-Sql $Database $sql) -join "`n")
}

if ($CreateBaseline) {
    if (Test-Path $baseline) { Stop-UatWithError "A baseline already exists: $baseline. Delete it yourself first if you really want to replace it." }
    $current = Invoke-Sql $Script:UatDatabase 'SELECT DATABASE()'
    if ($current -cne $Script:UatDatabase) { Stop-UatWithError 'Unexpected database answered.' }
    & $P.MariaDbDump "--defaults-extra-file=$dumpIni" --single-transaction --routines --hex-blob "--result-file=$baseline" $Script:UatDatabase
    if ($LASTEXITCODE -ne 0) { Stop-UatWithError 'Creating the baseline backup failed.' }
    (Get-FileHash -Algorithm SHA256 $baseline).Hash.ToLower() | Set-Content -Path $baselineHash -Encoding ASCII
    (Invoke-Sql $Script:UatDatabase $CountSql) | Set-Content -Path $baselineCounts -Encoding ASCII
    Write-UatOk "Baseline saved: $baseline"
    Write-Host "  Counts ($CountLabels): $(Get-Content $baselineCounts)"
    exit 0
}

if (-not (Test-Path $baseline) -or -not (Test-Path $baselineHash)) { Stop-UatWithError "No UAT baseline backup found in $($P.BaselineDir)." }
$expectedHash = (Get-Content $baselineHash -Raw).Trim()
if ((Get-FileHash -Algorithm SHA256 $baseline).Hash.ToLower() -ne $expectedHash) { Stop-UatWithError 'The baseline backup does not match its recorded SHA-256. Refusing to restore from it.' }
Write-UatOk 'Baseline backup found and its checksum verified'

Write-Host ''
Write-Host "  This will REPLACE the UAT database $($Script:UatDatabase) with the clean baseline." -ForegroundColor Yellow
Write-Host '  You will lose every change made during UAT: added/edited/deleted/restored providers,'
Write-Host '  imports and exports, pair-review decisions, sessions and any accounts you created.'
Write-Host "  Now      ($CountLabels): $(Invoke-Sql $Script:UatDatabase $CountSql)"
Write-Host "  Baseline ($CountLabels): $(Get-Content $baselineCounts)"
Write-Host '  euro_assist_v2_local and the frozen legacy database are NOT touched.'
Write-Host ''
if ($Confirm -eq '') { $Confirm = Read-Host "  Type exactly '$Phrase' to continue" }
if ($Confirm -cne $Phrase) { Stop-UatWithError 'Confirmation text did not match. Nothing was changed.' }

$localBefore = Get-Fingerprint 'euro_assist_v2_local'
$legacyBefore = Get-Fingerprint 'euro_assist_legacy_audit_20261121'

Write-UatStep 'Restoring the baseline...'
Invoke-Sql 'mysql' "DROP DATABASE IF EXISTS ``$($Script:UatDatabase)``; CREATE DATABASE ``$($Script:UatDatabase)`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" | Out-Null
$restore = Start-Process -FilePath $P.MariaDb -ArgumentList @("--defaults-extra-file=$(Quote-UatArg $P.RootIni)", $Script:UatDatabase) `
    -RedirectStandardInput $baseline -RedirectStandardError (Join-Path $P.LogDir 'reset-restore.err.log') -NoNewWindow -Wait -PassThru
if ($restore.ExitCode -ne 0) { Stop-UatWithError "Restoring the baseline failed. See $($P.LogDir)\reset-restore.err.log" }

$after = Invoke-Sql $Script:UatDatabase $CountSql
if ($after -ne (Get-Content $baselineCounts -Raw).Trim()) { Stop-UatWithError "Restored counts ($after) differ from the baseline." }
if ((Get-Fingerprint 'euro_assist_v2_local') -ne $localBefore) { Stop-UatWithError 'euro_assist_v2_local changed during the reset - investigate before continuing.' }
if ((Get-Fingerprint 'euro_assist_legacy_audit_20261121') -ne $legacyBefore) { Stop-UatWithError 'The legacy audit database changed during the reset - investigate.' }

Write-UatOk "UAT database restored to the baseline ($CountLabels): $after"
Write-UatOk 'euro_assist_v2_local and the frozen legacy database verified unchanged'
Write-Host '  Start the application again with .\start-uat.ps1'
exit 0
