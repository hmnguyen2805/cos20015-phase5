# COS20015 Phase-05: register (or remove) the Windows Task Scheduler job that
# runs app\tools\run_automation.php every 15 minutes (scheduled emails, AI
# extraction of new AE narratives, and a catch-up check run if the nightly
# MySQL event was missed because the PC was off).
#
# Runs as you, only while you are signed in, with php-win.exe so no console
# window flashes up. No administrator rights needed.
#
# Usage (from the phase5 folder):
#   powershell -ExecutionPolicy Bypass -File scripts\register_automation_task.ps1
#   powershell -ExecutionPolicy Bypass -File scripts\register_automation_task.ps1 -EveryMinutes 5
#   powershell -ExecutionPolicy Bypass -File scripts\register_automation_task.ps1 -Remove
param([ValidateRange(1, 1440)][int]$EveryMinutes = 15, [switch]$Remove)

$ErrorActionPreference = 'Stop'
$taskName = 'CTT automation (COS20015)'

if ($Remove) {
    Unregister-ScheduledTask -TaskName $taskName -Confirm:$false
    Write-Host "Removed scheduled task '$taskName'."
    return
}

$phase5 = Split-Path -Parent $PSScriptRoot
$php    = 'C:\xampp\php\php-win.exe'
$script = Join-Path $phase5 'app\tools\run_automation.php'
if (-not (Test-Path $php))    { throw "PHP not found at $php" }
if (-not (Test-Path $script)) { throw "Runner not found at $script" }

$action   = New-ScheduledTaskAction -Execute $php -Argument ('"' + $script + '"') -WorkingDirectory $phase5
# Start a minute from now, then repeat for ever every N minutes.
$trigger  = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) `
                -RepetitionInterval (New-TimeSpan -Minutes $EveryMinutes)
# StartWhenAvailable: if the PC was asleep at a run time, run as soon as it wakes.
# IgnoreNew: never start a second copy while one is still running.
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew `
                -ExecutionTimeLimit (New-TimeSpan -Minutes 10) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries

Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings -Force `
    -Description 'COS20015 Clinical Trial Tracker: scheduled report emails, AI extraction and catch-up data checks.' | Out-Null

$info = Get-ScheduledTask -TaskName $taskName | Get-ScheduledTaskInfo
Write-Host "Scheduled task '$taskName' registered: every $EveryMinutes minutes, next run $($info.NextRunTime)."
Write-Host "Log: storage\logs\automation.log"
