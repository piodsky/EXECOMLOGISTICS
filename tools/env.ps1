# Shared by the tools: read / write the app's .env (same rules as system/Env.php: the last line wins,
# one matching pair of quotes is stripped). Dot-source it:  . (Join-Path $PSScriptRoot 'env.ps1')
# Keep this file ASCII-only (Windows PowerShell 5.1 reads BOM-less files as ANSI).

$AppRoot = Split-Path -Parent $PSScriptRoot
$EnvFile = Join-Path $AppRoot '.env'
$MysqlBin = 'C:\xampp\mysql\bin'

function Read-EnvLines {
    if (-not (Test-Path $EnvFile)) { throw "Missing $EnvFile (copy .env.example to .env first)." }
    return [IO.File]::ReadAllLines($EnvFile, [Text.Encoding]::UTF8)
}

function EnvValue([string]$key, [string]$default = '') {
    $value = $default
    foreach ($l in (Read-EnvLines)) {
        if ($l -match "^\s*$key\s*=(.*)$") {
            $value = $Matches[1].Trim()
            if ($value.Length -ge 2 -and ($value[0] -eq '"' -or $value[0] -eq "'") -and $value[-1] -eq $value[0]) {
                $value = $value.Substring(1, $value.Length - 2)
            }
        }
    }
    return $value
}

# Set (replace every line of) or append KEY=value; UTF-8 without BOM like the rest of the app.
function Set-EnvValues([hashtable]$values) {
    $lines = [Collections.Generic.List[string]]::new()
    $done = @{}
    foreach ($l in (Read-EnvLines)) {
        $hit = $null
        foreach ($k in $values.Keys) { if ($l -match "^\s*$k\s*=") { $hit = $k } }
        if ($hit) {
            if (-not $done[$hit]) { $lines.Add("$hit=$($values[$hit])"); $done[$hit] = $true }
        } else {
            $lines.Add($l)
        }
    }
    foreach ($k in $values.Keys) { if (-not $done[$k]) { $lines.Add("$k=$($values[$k])") } }
    [IO.File]::WriteAllLines($EnvFile, $lines, (New-Object Text.UTF8Encoding $false))
}

# Run mysql.exe with a password passed through MYSQL_PWD (never on the command line). Returns the output lines.
function Invoke-Mysql([string]$user, [string]$pass, [string[]]$extra) {
    $mysqlArgs = @('-h', (EnvValue 'DB_HOST' '127.0.0.1'), '-P', (EnvValue 'DB_PORT' '3306'), '-u', $user, '--default-character-set=utf8mb4') + $extra
    $eap = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue' # PS 5.1: stderr lines of a native tool must not become terminating errors
        $env:MYSQL_PWD = $pass
        $out = & (Join-Path $MysqlBin 'mysql.exe') @mysqlArgs 2>&1
        if ($LASTEXITCODE -ne 0) { throw "mysql failed: $($out -join ' ')" }
        return $out
    } finally {
        $ErrorActionPreference = $eap
        Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue
    }
}
