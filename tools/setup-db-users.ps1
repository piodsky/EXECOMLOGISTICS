# Create / refresh the dedicated MySQL users and write them to .env (Phase 12).
#   execom_app    : SELECT, INSERT, UPDATE, DELETE on the app database (+ the e2e test database), localhost only
#   execom_backup : SELECT, SHOW VIEW, TRIGGER, LOCK TABLES on the app database (tools\backup.ps1)
# New random passwords every run (run it again to rotate them). Uses an admin account (default root, no password)
# only for this script; migrations keep running as root. The old .env is copied to %TEMP%\execom-backups first.
#
#   powershell -ExecutionPolicy Bypass -File tools\setup-db-users.ps1 [-AdminUser root] [-AdminPass ''] [-NoTestDb]
param(
    [string]$AdminUser = 'root',
    [string]$AdminPass = '',
    [switch]$NoTestDb
)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'env.ps1')

function New-Secret([int]$length = 28) {
    # Letters and digits only (no quoting problems in SQL or .env); rejection sampling keeps it unbiased.
    $chars = [char[]]'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789'
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    $buf = New-Object byte[] 1
    $limit = 256 - (256 % $chars.Length)
    $s = ''
    while ($s.Length -lt $length) {
        $rng.GetBytes($buf)
        if ($buf[0] -lt $limit) { $s += $chars[$buf[0] % $chars.Length] }
    }
    return $s
}

$db = EnvValue 'DB_NAME' 'execomlogistics_db'
if ($db -notmatch '^[A-Za-z0-9_]+$') { throw "Unexpected DB_NAME '$db'." }
$testDb = 'execomlogistics_e2e'
$appPass = New-Secret
$bakPass = New-Secret

$sql = @()
foreach ($h in @('localhost', '127.0.0.1')) {
    $sql += "CREATE USER IF NOT EXISTS 'execom_app'@'$h' IDENTIFIED BY '$appPass';"
    $sql += "ALTER USER 'execom_app'@'$h' IDENTIFIED BY '$appPass';"
    $sql += "GRANT SELECT, INSERT, UPDATE, DELETE ON ``$db``.* TO 'execom_app'@'$h';"
    if (-not $NoTestDb) { $sql += "GRANT SELECT, INSERT, UPDATE, DELETE ON ``$testDb``.* TO 'execom_app'@'$h';" }
    $sql += "CREATE USER IF NOT EXISTS 'execom_backup'@'$h' IDENTIFIED BY '$bakPass';"
    $sql += "ALTER USER 'execom_backup'@'$h' IDENTIFIED BY '$bakPass';"
    $sql += "GRANT SELECT, SHOW VIEW, TRIGGER, LOCK TABLES ON ``$db``.* TO 'execom_backup'@'$h';"
}
$sql += 'FLUSH PRIVILEGES;'
Invoke-Mysql $AdminUser $AdminPass @('-e', ($sql -join ' ')) | Out-Null

# Prove the new accounts work before switching .env.
$n = Invoke-Mysql 'execom_app' $appPass @('-N', '-B', $db, '-e', 'SELECT COUNT(*) FROM users')
$b = Invoke-Mysql 'execom_backup' $bakPass @('-N', '-B', $db, '-e', 'SELECT COUNT(*) FROM products')
$denied = $false
try { Invoke-Mysql 'execom_app' $appPass @('-N', '-B', $db, '-e', 'CREATE TABLE zz_rights_probe (id INT)') | Out-Null } catch { $denied = $true }
if (-not $denied) {
    Invoke-Mysql $AdminUser $AdminPass @($db, '-e', 'DROP TABLE IF EXISTS zz_rights_probe') | Out-Null
    throw 'execom_app can create tables; check the grants before using it.'
}

$bakDir = Join-Path $env:TEMP 'execom-backups'
New-Item -ItemType Directory -Force $bakDir | Out-Null
Copy-Item $EnvFile (Join-Path $bakDir ('env-before-db-users-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.txt'))
Set-EnvValues @{ DB_USER = 'execom_app'; DB_PASS = $appPass; BACKUP_DB_USER = 'execom_backup'; BACKUP_DB_PASS = $bakPass }

Write-Output "OK: execom_app (users: $($n -join '')) and execom_backup (products: $($b -join '')) work; execom_app cannot change the schema."
Write-Output ".env now uses DB_USER=execom_app. Old .env copied to $bakDir."
