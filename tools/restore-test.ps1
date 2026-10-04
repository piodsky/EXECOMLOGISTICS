# Restore test (Phase 12): loads a backup into a scratch database execom_restore_test, checks it, drops it again.
# Proves the backups can actually be restored. Never touches the live database.
#   - default: the newest BACKUP_DIR\execomlogistics_db-*.sql.gz; or -File <path to .sql.gz / .sql>
#   - checks: row counts of the main tables (shown next to the live counts) and the stock rule
#     products.stock = SUM(stock_balances) = SUM(stock_movements) inside the restored copy
# Uses an admin account for CREATE / DROP DATABASE (default root, no password). Exit 0 = restore OK.
#
#   powershell -ExecutionPolicy Bypass -File tools\restore-test.ps1 [-File C:\EXECOM-Backups\...sql.gz] [-AdminUser root] [-AdminPass '']
param(
    [string]$File = '',
    [string]$AdminUser = 'root',
    [string]$AdminPass = ''
)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'env.ps1')

$scratch = 'execom_restore_test'
$live    = EnvValue 'DB_NAME' 'execomlogistics_db'
if ($live -eq $scratch) { throw 'The live database must not be the restore-test database.' }
$dir = EnvValue 'BACKUP_DIR' 'C:\EXECOM-Backups'
if (-not $File) {
    $newest = Get-ChildItem $dir -Filter "$live-*.sql.gz" -ErrorAction SilentlyContinue | Sort-Object LastWriteTime -Descending | Select-Object -First 1
    if (-not $newest) { throw "No backups found in $dir. Run tools\backup.ps1 first." }
    $File = $newest.FullName
}
Write-Output "Restoring $File into $scratch ..."

$tmp = Join-Path $env:TEMP "execom-restore-$PID.sql"
try {
    if ($File -like '*.gz') {
        $in  = [IO.File]::OpenRead($File)
        $z   = New-Object IO.Compression.GZipStream($in, [IO.Compression.CompressionMode]::Decompress)
        $out = [IO.File]::Create($tmp)
        try { $z.CopyTo($out) } finally { $out.Dispose(); $z.Dispose(); $in.Dispose() }
    } else {
        Copy-Item $File $tmp
    }
    if ((Get-Content $tmp -Tail 1) -notlike '-- Dump completed*') { throw 'The backup file is incomplete.' }

    Invoke-Mysql $AdminUser $AdminPass @('-e', "DROP DATABASE IF EXISTS $scratch; CREATE DATABASE $scratch CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;") | Out-Null
    $mysqlArgs = @('-h', (EnvValue 'DB_HOST' '127.0.0.1'), '-P', (EnvValue 'DB_PORT' '3306'), '-u', $AdminUser, '--default-character-set=utf8mb4', $scratch)
    try {
        $env:MYSQL_PWD = $AdminPass
        $p = Start-Process (Join-Path $MysqlBin 'mysql.exe') -ArgumentList $mysqlArgs -RedirectStandardInput $tmp `
            -RedirectStandardError "$tmp.err" -NoNewWindow -Wait -PassThru
    } finally {
        Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue
    }
    if ($p.ExitCode -ne 0) { throw "Import failed: $(Get-Content "$tmp.err" -Raw)" }

    $tables = @('users', 'branches', 'products', 'customers', 'sales', 'sale_items', 'stock_balances', 'stock_movements',
                'product_serials', 'job_orders', 'stock_transfers', 'audit_logs')
    $q = ($tables | ForEach-Object { "SELECT '$_', (SELECT COUNT(*) FROM $scratch.$_), (SELECT COUNT(*) FROM $live.$_)" }) -join ' UNION ALL '
    $rows = Invoke-Mysql $AdminUser $AdminPass @('-N', '-B', '-e', $q)
    Write-Output ('{0,-18} {1,10} {2,10}' -f 'table', 'backup', 'live now')
    foreach ($r in $rows) { $c = $r -split "`t"; Write-Output ('{0,-18} {1,10} {2,10}' -f $c[0], $c[1], $c[2]) }

    $bad = Invoke-Mysql $AdminUser $AdminPass @('-N', '-B', $scratch, '-e',
        'SELECT COUNT(*) FROM products p WHERE p.stock <> (SELECT COALESCE(SUM(b.qty), 0) FROM stock_balances b WHERE b.product_id = p.id) OR p.stock <> (SELECT COALESCE(SUM(m.quantity), 0) FROM stock_movements m WHERE m.product_id = p.id)')
    if (($bad -join '') -ne '0') { throw "Stock rule broken in the restored copy ($($bad -join '') products)." }
    Write-Output 'RESTORE OK: the backup loads and its stock ledger is consistent.'
    $code = 0
} catch {
    Write-Output "RESTORE TEST FAILED: $($_.Exception.Message)"
    $code = 1
} finally {
    Remove-Item $tmp, "$tmp.err" -ErrorAction SilentlyContinue
    try { Invoke-Mysql $AdminUser $AdminPass @('-e', "DROP DATABASE IF EXISTS $scratch;") | Out-Null } catch {}
}
exit $code
