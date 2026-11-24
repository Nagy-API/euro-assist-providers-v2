<#
.SYNOPSIS
    Applies the Euro Assist Providers V2 incremental update to an existing installation.

.DESCRIPTION
    Copies the files in .\files over the application folder, deletes the paths listed in
    removed-files.txt and replaces public\build. A backup copy of every file it overwrites or deletes
    goes to a new folder next to the application. It NEVER touches .env, storage, vendor or the database,
    and it contains no SQL. Use -WhatIf first.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File .\apply-update.ps1 -AppPath "D:\sites\providers-v2" -WhatIf
#>
[CmdletBinding(SupportsShouldProcess = $true)]
param(
    [Parameter(Mandatory = $true)][string]$AppPath
)

$ErrorActionPreference = 'Stop'
$Here = $PSScriptRoot
$Files = Join-Path $Here 'files'
$App = (Resolve-Path -LiteralPath $AppPath).Path

if (-not (Test-Path -LiteralPath (Join-Path $App 'artisan'))) { throw "No 'artisan' file in $App - this is not the application folder." }
if (-not (Test-Path -LiteralPath (Join-Path $App '.env')))   { Write-Warning ".env not found in $App - check you selected the live application folder." }
if (-not (Test-Path -LiteralPath $Files))                    { throw "Missing 'files' folder next to this script." }
if (@(Get-ChildItem -LiteralPath $Here -Recurse -File | Where-Object { $_.Extension -ieq '.sql' }).Count -gt 0) { throw 'The package must not contain SQL files.' }

$protected = @('.env', 'storage', 'vendor', 'node_modules', '.git')
function Test-Protected([string]$rel) {
    foreach ($p in $protected) { if ($rel -eq $p -or $rel.StartsWith("$p\") -or $rel.StartsWith("$p/")) { return $true } }
    return $false
}

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$Backup = Join-Path (Split-Path $App -Parent) "update-backup-$stamp"

$copy = Get-ChildItem -LiteralPath $Files -Recurse -File
$removed = @()
$removedFile = Join-Path $Here 'removed-files.txt'
if (Test-Path -LiteralPath $removedFile) { $removed = Get-Content -LiteralPath $removedFile | Where-Object { $_.Trim() -ne '' -and -not $_.StartsWith('#') } }

Write-Host "Application : $App"
Write-Host "Backup to   : $Backup"
Write-Host "Files to copy: $($copy.Count)    Files to delete: $($removed.Count)"

foreach ($f in $copy) {
    $rel = $f.FullName.Substring($Files.Length + 1)
    if (Test-Protected $rel) { throw "Refusing to touch protected path: $rel" }
}
foreach ($r in $removed) { if (Test-Protected ($r -replace '/', '\')) { throw "Refusing to delete protected path: $r" } }

# public\build is replaced as a whole (hashed asset names change); back up the old one first.
$buildSrc = Join-Path $Files 'public\build'
$replaceBuild = Test-Path -LiteralPath $buildSrc

if ($PSCmdlet.ShouldProcess($App, 'back up, copy new files, delete removed files')) {
    New-Item -ItemType Directory -Path $Backup | Out-Null
    function Save-Backup([string]$rel) {
        $src = Join-Path $App $rel
        if (Test-Path -LiteralPath $src) {
            $dst = Join-Path $Backup $rel
            New-Item -ItemType Directory -Force -Path (Split-Path $dst -Parent) | Out-Null
            Copy-Item -LiteralPath $src -Destination $dst -Force -Recurse
        }
    }
    foreach ($f in $copy) { $rel = $f.FullName.Substring($Files.Length + 1); if (-not $rel.StartsWith('public\build\')) { Save-Backup $rel } }
    foreach ($r in $removed) { Save-Backup ($r -replace '/', '\') }
    if ($replaceBuild) { Save-Backup 'public\build' }

    if ($replaceBuild -and (Test-Path -LiteralPath (Join-Path $App 'public\build'))) { Remove-Item -LiteralPath (Join-Path $App 'public\build') -Recurse -Force }
    foreach ($f in $copy) {
        $rel = $f.FullName.Substring($Files.Length + 1)
        $dst = Join-Path $App $rel
        New-Item -ItemType Directory -Force -Path (Split-Path $dst -Parent) | Out-Null
        Copy-Item -LiteralPath $f.FullName -Destination $dst -Force
    }
    foreach ($r in $removed) {
        $p = Join-Path $App ($r -replace '/', '\')
        if (Test-Path -LiteralPath $p) { Remove-Item -LiteralPath $p -Force }
    }
    Write-Host ''
    Write-Host 'Files applied. Backup kept in:' $Backup -ForegroundColor Green
    Write-Host 'Next: follow UPDATE_GUIDE.md sections 3-7 (environment check, migrate, caches, scheduler, verification).'
} else {
    Write-Host 'Preview only (-WhatIf): nothing was changed.'
    $copy | ForEach-Object { '  copy   ' + $_.FullName.Substring($Files.Length + 1) } | Select-Object -First 60
    $removed | ForEach-Object { '  delete ' + $_ }
}
