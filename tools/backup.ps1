# Database backup (Phase 12): mysqldump of the app database -> BACKUP_DIR\execomlogistics_db-YYYYMMDD-HHMMSS.sql.gz,
# then deletes backups older than BACKUP_KEEP_DAYS. Uses BACKUP_DB_USER / BACKUP_DB_PASS from .env (read-only account,
# see tools\setup-db-users.ps1); falls back to DB_USER / DB_PASS. Every run appends one line to BACKUP_DIR\backup.log.
# Also zips storage\attachments (attachment files) to BACKUP_DIR\attachments-YYYYMMDD-HHMMSS.zip (same retention).
# Exit code 0 = backup written and verified, 1 = failed. MySQL (XAMPP) must be running.
#
#   powershell -ExecutionPolicy Bypass -File tools\backup.ps1
#   (scheduled daily at 21:00 by tools\install-backup-task.ps1)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'env.ps1')

$dir  = EnvValue 'BACKUP_DIR' 'C:\EXECOM-Backups'
$keep = [int](EnvValue 'BACKUP_KEEP_DAYS' '30')
$db   = EnvValue 'DB_NAME' 'execomlogistics_db'
New-Item -ItemType Directory -Force $dir | Out-Null
$log = Join-Path $dir 'backup.log'
function Log([string]$msg) { Add-Content -Path $log -Value ((Get-Date -Format 'yyyy-MM-dd HH:mm:ss') + '  ' + $msg) -Encoding ASCII }

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$sql = Join-Path $dir "$db-$stamp.sql"
$gz  = "$sql.gz"
try {
    $user = EnvValue 'BACKUP_DB_USER' (EnvValue 'DB_USER' 'root')
    $pass = if (EnvValue 'BACKUP_DB_USER' '') { EnvValue 'BACKUP_DB_PASS' '' } else { EnvValue 'DB_PASS' '' }
    $dumpArgs = @('-h', (EnvValue 'DB_HOST' '127.0.0.1'), '-P', (EnvValue 'DB_PORT' '3306'), '-u', $user,
                  '--single-transaction', '--quick', '--triggers', '--no-tablespaces', '--default-character-set=utf8mb4',
                  "--result-file=$sql", $db)
    try {
        $ErrorActionPreference = 'Continue' # PS 5.1: stderr lines must not become terminating errors
        $env:MYSQL_PWD = $pass
        $err = & (Join-Path $MysqlBin 'mysqldump.exe') @dumpArgs 2>&1
        $code = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = 'Stop'
        Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue
    }
    if ($code -ne 0) { throw "mysqldump exit $code : $($err -join ' ')" }
    $tail = Get-Content $sql -Tail 1
    if ($tail -notlike '-- Dump completed*') { throw 'the dump is incomplete (no "Dump completed" line).' }

    # gzip (the .sql is removed once the .gz is written)
    $in  = [IO.File]::OpenRead($sql)
    $out = [IO.File]::Create($gz)
    $z   = New-Object IO.Compression.GZipStream($out, [IO.Compression.CompressionLevel]::Optimal)
    try { $in.CopyTo($z) } finally { $z.Dispose(); $out.Dispose(); $in.Dispose() }
    Remove-Item $sql

    # Attachments (storage\attachments: PO / order / collection photos and PDFs) -> attachments-<stamp>.zip
    $attDir = Join-Path (Split-Path $PSScriptRoot -Parent) 'storage\attachments'
    $zip = $null
    if ((Test-Path $attDir) -and @(Get-ChildItem $attDir -File).Count -gt 0) {
        $zip = Join-Path $dir "attachments-$stamp.zip"
        Add-Type -AssemblyName System.IO.Compression.FileSystem
        [IO.Compression.ZipFile]::CreateFromDirectory($attDir, $zip, [IO.Compression.CompressionLevel]::Optimal, $false)
    }

    $old = @(Get-ChildItem $dir -Filter "$db-*.sql.gz") + @(Get-ChildItem $dir -Filter 'attachments-*.zip') |
        Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-$keep) }
    $old | Remove-Item -Force
    $size = [math]::Round((Get-Item $gz).Length / 1KB, 1)
    $attNote = if ($zip) { "; attachments $([IO.Path]::GetFileName($zip)) ($([math]::Round((Get-Item $zip).Length / 1KB, 1)) KB)" } else { '; no attachments' }
    Log "OK    $([IO.Path]::GetFileName($gz)) ($size KB)$attNote; removed $(@($old).Count) older than $keep days"
    Write-Output "Backup written: $gz ($size KB)$attNote"
    exit 0
} catch {
    Remove-Item $sql, $gz -ErrorAction SilentlyContinue
    if ($zip) { Remove-Item $zip -ErrorAction SilentlyContinue }
    Log "FAIL  $($_.Exception.Message)"
    Write-Output "BACKUP FAILED: $($_.Exception.Message)"
    exit 1
}
