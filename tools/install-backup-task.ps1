# Registers (or replaces) the Windows scheduled task "EXECOM Database Backup": tools\backup.ps1 every day at -At
# (default 21:00) as the current user. XAMPP's MySQL must be running at that time; a missed run (PC off) is
# simply skipped and the next one runs the following day. Check C:\EXECOM-Backups\backup.log.
#
#   powershell -ExecutionPolicy Bypass -File tools\install-backup-task.ps1 [-At 21:00]
#   remove:  schtasks /Delete /TN "EXECOM Database Backup" /F
param([string]$At = '21:00')
$ErrorActionPreference = 'Stop'
if ($At -notmatch '^\d{2}:\d{2}$') { throw 'Use -At HH:MM, e.g. 21:00.' }
$script = Join-Path $PSScriptRoot 'backup.ps1'
$action = "powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$script`""
$out = & schtasks.exe /Create /TN 'EXECOM Database Backup' /TR $action /SC DAILY /ST $At /F 2>&1
if ($LASTEXITCODE -ne 0) { throw "schtasks failed: $($out -join ' ')" }
Write-Output "Scheduled: EXECOM Database Backup, daily at $At -> $script"
