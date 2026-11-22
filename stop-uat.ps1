<#
.SYNOPSIS
    Stops the UAT web server and queue worker started by start-uat.ps1.

.DESCRIPTION
    Only processes recorded by start-uat.ps1 AND carrying its marker on their command line are
    stopped, so other PHP programs or whatever else uses port 8000 are never touched. The shared
    local MariaDB server is left running (other local databases use it).

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File .\stop-uat.ps1
#>
$Repo = $PSScriptRoot
. (Join-Path $Repo 'scripts\uat\uat-common.ps1')
$P = Get-UatPaths $Repo
if (-not (Test-Path $P.RunDir)) { New-Item -ItemType Directory -Force $P.RunDir | Out-Null }

$n = Stop-UatProcesses $P
if ($n -eq 0) { Write-Host '  The UAT environment was not running.' } else { Write-Host '  UAT environment stopped.' }
exit 0
