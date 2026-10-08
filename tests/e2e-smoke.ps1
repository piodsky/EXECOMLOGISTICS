# End-to-end smoke test in headless Edge through the DevTools protocol (PowerShell, no Node.js needed).
#   powershell -ExecutionPolicy Bypass -File tests\e2e-smoke.ps1 [output-dir]
# Signs in as cashier, sells the mockup cart, checks totals/stock/receipt and Sales History; then as admin
# checks inventory, voids the sale (stock returned, audit log, VOID receipt), checks Reports, then Settings, Users and My Account.
# Runs against an ISOLATED copy: the app is copied to htdocs\<app>-e2e with its own .env (test DB
# execomlogistics_e2e, own session cookie) and database.sql is imported into that test DB, so every run starts
# from the sample data and the live app + live database are never touched. The copy is removed after a
# passing run (kept after failures for its logs; -Keep always keeps it). The test DB stays for inspection.
param([string]$Out = (Join-Path $env:TEMP "execom-e2e"), [switch]$Keep)
$ErrorActionPreference = "Stop"
# Absolute path (relative -Out resolves against $PWD), without expanding 8.3 short names like PIODOS~1.
$Out = $ExecutionContext.SessionState.Path.GetUnresolvedProviderPathFromPSPath($Out)
New-Item -ItemType Directory -Force $Out | Out-Null

# One run at a time (runs share the copy, the test DB and the Edge port). Windows drops the lock on exit.
try { $lock = [IO.File]::Open((Join-Path $Out '.e2e-lock'), 'OpenOrCreate', 'ReadWrite', 'None') }
catch { Write-Output 'SETUP ERROR another e2e-smoke run is in progress (wait for it to finish).'; exit 1 }

# --- Isolated test copy + test database ---
$root   = Split-Path $PSScriptRoot -Parent
$appDir = Split-Path $root -Leaf
$e2eDir = Join-Path (Split-Path $root -Parent) "$appDir-e2e"
$marker = Join-Path $e2eDir '.e2e-copy'
$testDb = 'execomlogistics_e2e'
$Base   = "http://localhost/$appDir-e2e"

function Remove-TestCopy {
    if (-not (Test-Path $e2eDir)) { return }
    # Only ever delete a folder this script created.
    if (-not (Test-Path $marker)) { throw "$e2eDir exists but is not an e2e copy (no .e2e-copy marker); remove or rename it yourself." }
    # Marker goes last, so a half-finished delete (locked file) can still be cleaned up by the next run.
    Get-ChildItem -Force $e2eDir | Where-Object { $_.Name -ne '.e2e-copy' } | Remove-Item -Recurse -Force
    Remove-Item -Recurse -Force $e2eDir
}

try {
    $envFile = Join-Path $root '.env'
    if (-not (Test-Path $envFile)) { throw "Missing $envFile (copy .env.example to .env first)." }
    $envLines = [IO.File]::ReadAllLines($envFile, [Text.Encoding]::UTF8)
    # Same rules as system/Env.php: the last line wins, only a matching pair of quotes is stripped.
    function EnvValue([string]$key, [string]$default) {
        $value = $default
        foreach ($l in $envLines) {
            if ($l -match "^\s*$key\s*=(.*)$") {
                $value = $Matches[1].Trim()
                if ($value.Length -ge 2 -and ($value[0] -eq '"' -or $value[0] -eq "'") -and $value[-1] -eq $value[0]) {
                    $value = $value.Substring(1, $value.Length - 2)
                }
            }
        }
        return $value
    }
    if ((EnvValue 'DB_NAME' 'execomlogistics_db') -eq $testDb) { throw "The live .env already uses $testDb; refusing to reset it." }

    Remove-TestCopy
    # Marker first: if the copy fails or is interrupted, the next run may still delete the folder.
    New-Item -ItemType Directory -Force $e2eDir | Out-Null
    New-Item -ItemType File -Force $marker | Out-Null
    $null = & robocopy $root $e2eDir /E /XD (Join-Path $root '.git') (Join-Path $root '.claude') (Join-Path $root 'assets\uploads\products') (Join-Path $root 'storage\logs') (Join-Path $root 'storage\attachments') /XF .env /NFL /NDL /NJH /NJS /NP
    if ($LASTEXITCODE -ge 8) { throw "robocopy failed (exit $LASTEXITCODE)" }
    New-Item -ItemType Directory -Force (Join-Path $e2eDir 'storage\logs'), (Join-Path $e2eDir 'assets\uploads\products') | Out-Null
    Copy-Item (Join-Path $root 'assets\uploads\products\sample-*.png') (Join-Path $e2eDir 'assets\uploads\products')

    # Env.php lets later lines win, so appending the overrides is enough. UTF-8 without BOM (APP_CURRENCY).
    $utf8 = New-Object Text.UTF8Encoding $false
    $testEnv = $envLines + @('', '# e2e overrides', "APP_URL=$Base", "DB_NAME=$testDb", 'SESSION_NAME=EXECOM_E2E_SID')
    [IO.File]::WriteAllLines((Join-Path $e2eDir '.env'), $testEnv, $utf8)

    # Import the sample schema + data into the test DB only (never the live one).
    $sql = [IO.File]::ReadAllText((Join-Path $root 'database.sql'), [Text.Encoding]::UTF8) -replace '\bexecomlogistics_db\b', $testDb
    if ($sql -notmatch "USE $testDb;" -or $sql -match 'execomlogistics_db') { throw 'database.sql rewrite failed; not importing.' }
    $sqlFile = Join-Path $Out 'e2e-database.sql'
    [IO.File]::WriteAllText($sqlFile, $sql, $utf8)
    # Admin account for the test DB (create / drop / import; the app itself runs as DB_USER, e.g. execom_app).
    $mysqlArgs = @('-h', (EnvValue 'DB_HOST' '127.0.0.1'), '-P', (EnvValue 'DB_PORT' '3306'), '-u', (EnvValue 'E2E_DB_USER' 'root'), '--default-character-set=utf8mb4')
    try {
        $env:MYSQL_PWD = EnvValue 'E2E_DB_PASS' ''
        $import = Start-Process 'C:\xampp\mysql\bin\mysql.exe' -ArgumentList $mysqlArgs -RedirectStandardInput $sqlFile `
            -RedirectStandardError (Join-Path $Out 'e2e-import.err') -NoNewWindow -Wait -PassThru
    } finally {
        Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue
    }
    if ($import.ExitCode -ne 0) { throw "Importing database.sql into $testDb failed: $(Get-Content (Join-Path $Out 'e2e-import.err') -Raw)" }
    Write-Output "Test copy: $e2eDir  ->  $Base  (DB $testDb)"
} catch {
    Write-Output "SETUP ERROR $($_.Exception.Message)"
    try { if (-not $Keep) { Remove-TestCopy } } catch {}
    exit 1
}

$edge = 'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe'
$port = 9444
$prof = Join-Path $Out 'edge-profile'
if (Test-Path $prof) { Remove-Item -Recurse -Force $prof }
$proc = Start-Process $edge -ArgumentList @('--headless=new', "--remote-debugging-port=$port", "--user-data-dir=`"$prof`"", '--no-first-run', '--disable-gpu', '--hide-scrollbars', 'about:blank') -PassThru -WindowStyle Hidden

$target = $null
for ($i = 0; $i -lt 50 -and -not $target; $i++) {
    try { $target = (Invoke-RestMethod "http://127.0.0.1:$port/json/list") | Where-Object { $_.type -eq 'page' } | Select-Object -First 1 } catch { Start-Sleep -Milliseconds 200 }
}
$ws = New-Object System.Net.WebSockets.ClientWebSocket
$ws.Options.KeepAliveInterval = [TimeSpan]::FromSeconds(30)
$ws.ConnectAsync([uri]$target.webSocketDebuggerUrl, [Threading.CancellationToken]::None).Wait()

$script:seq = 0
$script:problems = New-Object System.Collections.ArrayList
$script:fails = 0

function Receive-Text {
    $buf = New-Object byte[] 262144
    $ms = New-Object IO.MemoryStream
    do {
        $r = $ws.ReceiveAsync((New-Object 'ArraySegment[byte]' -ArgumentList (, $buf)), [Threading.CancellationToken]::None).Result
        $ms.Write($buf, 0, $r.Count)
    } while (-not $r.EndOfMessage)
    [Text.Encoding]::UTF8.GetString($ms.ToArray())
}

function Cdp([string]$method, $params = @{}) {
    $script:seq++
    $id = $script:seq
    $json = @{ id = $id; method = $method; params = $params } | ConvertTo-Json -Depth 10 -Compress
    $bytes = [Text.Encoding]::UTF8.GetBytes($json)
    $ws.SendAsync((New-Object 'ArraySegment[byte]' -ArgumentList (, $bytes)), [System.Net.WebSockets.WebSocketMessageType]::Text, $true, [Threading.CancellationToken]::None).Wait()
    while ($true) {
        $txt = Receive-Text
        if ($txt -match '^\{"id":(\d+),') {
            if ([int]$Matches[1] -ne $id) { continue }
            if ($method -eq 'Page.captureScreenshot') { return ($txt -replace '^.*"data":"', '' -replace '".*$', '') }
            $obj = $txt | ConvertFrom-Json
            if ($obj.error) { throw "$method : $($obj.error.message)" }
            return $obj.result
        }
        if ($txt -match '"method":"Runtime.exceptionThrown"') { [void]$script:problems.Add('JS exception: ' + $txt.Substring(0, [Math]::Min(400, $txt.Length))) }
        elseif ($txt -match '"method":"Log.entryAdded"' -and $txt -match '"level":"(error|warning)"' -and $txt -notmatch 'nope.php' -and $txt -notmatch '/storage/attachments/' -and -not ($txt -match 'status of 4(03|04|09|22)' -and $txt -match '(reports|settings|roles|branches|receipt|sale-view|pos|checkout|user-form|switch-branch|master-data|suppliers|supplier-form|customer-form|receiving|receiving-view|receiving-form|serials|product-form|stock-integrity|stock-docs|stock-doc-form|stock-doc-view|warehouses|serial-register|transfers|transfer-form|transfer-view|approve|job-view|job-form|job-orders|dashboard|report-profit|report-jobs|report-pricing|report-branches|purchase-requests|purchase-orders|pr-form|pr-view|po-form|po-view|po-print|pr-print|customer-orders|co-form|co-view|dr-form|dr-view|deliveries|order-tracking|dr-print|bill-print|quotations|quote-form|quote-view|quote-print|collections|collection-receipts|collection-form|collection-view|collection-print|checks|soa|soa-print|payables|ap-form|ap-view|disbursements|dv-form|dv-view|dv-print|attachment|attachments|buying|selling|branch-prices|customers/create)\.php')) { [void]$script:problems.Add('log: ' + $txt.Substring(0, [Math]::Min(400, $txt.Length))) }
        elseif ($txt -match '"method":"Runtime.consoleAPICalled"' -and $txt -match '"type":"error"') { [void]$script:problems.Add('console.error: ' + $txt.Substring(0, [Math]::Min(400, $txt.Length))) }
    }
}

function Eval([string]$expr) {
    $r = Cdp 'Runtime.evaluate' @{ expression = $expr; awaitPromise = $true; returnByValue = $true }
    if ($r.exceptionDetails) { throw "eval failed: $expr -> $($r.exceptionDetails.exception.description)" }
    return $r.result.value
}
function WaitFor([string]$expr, [string]$label, [int]$timeoutMs = 8000) {
    $end = (Get-Date).AddMilliseconds($timeoutMs)
    while ((Get-Date) -lt $end) { try { if (Eval $expr) { return } } catch {} ; Start-Sleep -Milliseconds 100 }
    throw "Timed out waiting for: $label"
}
function Nav([string]$url) { [void](Cdp 'Page.navigate' @{ url = $url }); Start-Sleep -Milliseconds 300; WaitFor 'document.readyState === "complete"' "load $url" }
function Shot([string]$name) { $b64 = Cdp 'Page.captureScreenshot' @{ format = 'png' }; [IO.File]::WriteAllBytes((Join-Path $Out "$name.png"), [Convert]::FromBase64String($b64)) }
function Size([int]$w, [int]$h) { [void](Cdp 'Emulation.setDeviceMetricsOverride' @{ width = $w; height = $h; deviceScaleFactor = 1; mobile = $false }) }
function Key([string]$k, [int]$vk) {
    if ($k -eq 'Enter') { [void](Cdp 'Input.dispatchKeyEvent' @{ type = 'keyDown'; key = $k; code = $k; windowsVirtualKeyCode = $vk; text = [string][char]13 }) }
    else { [void](Cdp 'Input.dispatchKeyEvent' @{ type = 'rawKeyDown'; key = $k; code = $k; windowsVirtualKeyCode = $vk }) }
    [void](Cdp 'Input.dispatchKeyEvent' @{ type = 'keyUp'; key = $k; code = $k; windowsVirtualKeyCode = $vk })
}
function TypeText([string]$t) { [void](Cdp 'Input.insertText' @{ text = $t }) }
function Text([string]$sel) { Eval "document.querySelector('$sel')?.textContent.trim()" }
function Check([bool]$cond, [string]$label) {
    if ($cond) { Write-Output "PASS  $label" } else { Write-Output "FAIL  $label"; $script:fails++ }
}
# Default landing: POS (cashiers) or Dashboard (admins with reports.view, Phase 11).
function Login([string]$u, [string]$p, [string]$landing = '') {
    Nav "$Base/login.php"
    [void](Eval 'localStorage.clear()')
    [void](Eval "document.querySelector('[name=username]').value='$u'; document.querySelector('[name=password]').value='$p'; document.querySelector('.login__form').submit()")
    Start-Sleep -Milliseconds 500
    $cond = if ($landing) { "location.pathname.endsWith('/pages/$landing')" } else { "/\/pages\/(pos|dashboard)\.php$/.test(location.pathname)" }
    WaitFor "$cond && document.readyState==='complete'" "redirect to $(if ($landing) { $landing } else { 'home' })"
}
function Logout {
    [void](Eval "document.querySelector('form.topbar__logout').submit()")
    Start-Sleep -Milliseconds 400
    WaitFor "location.pathname.endsWith('/login.php') && document.readyState==='complete'" 'logout'
}
# Submits something that reloads the page (form post + redirect) and waits for the new page.
function Submit([string]$js, [string]$label) {
    [void](Eval "window.__old = true; $js")
    WaitFor "window.__old === undefined && document.readyState === 'complete'" $label
}
function SwitchBranch([int]$id) {
    Submit "const s = document.getElementById('branchSelect'); s.value = '$id'; s.dispatchEvent(new Event('change'))" "switch to branch $id"
}
function Status([string]$path) { Eval "fetch('$Base/$path').then(r => r.status)" }
# POS checkout through the API (as pos.js does); returns 'ok:<sale_no>' or '<status>:<message>'.
function ApiSale([int]$productId) {
    Eval "BB.api('pos/checkout.php', {method: 'POST', body: {items: [{product_id: $productId, qty: 1}], customer_id: null, payment_type: 'cash', discount_percent: '0', amount_paid: '99999.00'}}).then(d => 'ok:' + d.sale.sale_no, e => e.status + ':' + e.message)"
}
function AddUser([string]$full, [string]$uname, [string]$role, [int]$homeBranch, [int]$extra = 0) {
    Nav "$Base/pages/user-form.php"
    $x = if ($extra) { "f.querySelector('[name=`"branches[]`"][value=`"$extra`"]').checked = true;" } else { '' }
    Submit "const f = document.getElementById('userForm'); f.full_name.value = '$full'; f.username.value = '$uname'; f.querySelector('[name=role][value=$role]').checked = true; f.branch_id.value = '$homeBranch'; $x f.password.value = '$script:pw'; f.password_confirm.value = '$script:pw'; f.requestSubmit()" "add $uname"
    Check ((Text '.alert--success span') -eq "$full can now sign in as $uname.") "admin adds $role $uname ($(Text '.alert span'))"
}
$script:pw = 'Mindanao#2026'
# Query the TEST database only ($testDb); returns the rows joined by spaces.
function Sql([string]$q) {
    try {
        $env:MYSQL_PWD = EnvValue 'E2E_DB_PASS' ''
        $o = & 'C:\xampp\mysql\bin\mysql.exe' @mysqlArgs -N -B $testDb -e $q 2>&1
    } finally { Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue }
    return (($o | ForEach-Object { "$_" }) -join ' ').Trim()
}
# Master Data list: open the add dialog, run $fill (f = #mdForm), submit (PRG).
function MdAdd([string]$list, [string]$fill, [string]$label) {
    Nav "$Base/pages/master-data.php?list=$list"
    [void](Eval "document.getElementById('mdAdd').click()")
    Submit "const f = document.getElementById('mdForm'); $fill; f.requestSubmit()" $label
}
function MdId([string]$name) { Eval "document.querySelector('#mdTable tr[data-row=`"$name`"] [data-md-edit]')?.dataset.id || ''" }
function ClickCard([string]$name) {
    [void](Eval "[...document.querySelectorAll('.product-card')].find(c => c.querySelector('.product-card__name').textContent === '$name').click()")
}
function CartQty([string]$name) {
    Eval "(() => { const r = [...document.querySelectorAll('#cartBody tr')].find(tr => tr.querySelector('strong').textContent === '$name'); return r ? Number(r.querySelector('input').value) : 0; })()"
}
# Phase 7a: fill the receiving form (lines = JS array of [productId, qty, cost, serials]) and submit with action save|post.
function RrSubmit([string]$url, [string]$supplier, [string]$lines, [string]$action, [string]$label) {
    Nav $url
    [void](Eval "window.__old = true; const f = document.getElementById('rrForm'); f.supplier_id.value = '$supplier'; f.reference_no.value = 'DR-$label'; ($lines).forEach((l, i) => { let t = document.querySelectorAll('#rrLines tbody[data-line]')[i]; if (!t) { document.getElementById('addLine').click(); t = [...document.querySelectorAll('#rrLines tbody[data-line]')].pop(); } const s = t.querySelector('[data-product]'); s.value = String(l[0]); s.dispatchEvent(new Event('change', {bubbles: true})); t.querySelector('[data-qty]').value = String(l[1]); t.querySelector('[data-cost]').value = l[2]; t.querySelector('[data-serials]').value = l[3] || ''; }); const a = document.createElement('input'); a.type = 'hidden'; a.name = 'action'; a.value = '$action'; f.appendChild(a); f.submit()")
    WaitFor "window.__old === undefined && document.readyState === 'complete' && !!document.querySelector('main')" "rr $label" 20000
}
# Expected branch moving average (spec: integers in 1/10000, half-up), as 'N.NNNN'.
function ExpAvg([long]$qb, [string]$avg, [long]$qty, [string]$cost) {
    $a = [long][math]::Round([decimal]$avg * 10000, [MidpointRounding]::AwayFromZero)
    $c = [long][math]::Round([decimal]$cost * 10000, [MidpointRounding]::AwayFromZero)
    if ($qb -le 0) { $after = $c } else { $after = [long][math]::Floor([decimal](2 * ($qb * $a + $qty * $c) + ($qb + $qty)) / [decimal](2 * ($qb + $qty))) }
    return ('{0}.{1:D4}' -f [long][math]::Floor([decimal]$after / 10000), ($after % 10000))
}
function BranchAvg([string]$pid_) { Sql "SELECT COALESCE((SELECT avg_cost FROM product_branches WHERE product_id = $pid_ AND branch_id = 1), (SELECT unit_cost FROM products WHERE id = $pid_))" }
function BranchQty([string]$pid_) { Sql "SELECT COALESCE(SUM(qty), 0) FROM stock_balances WHERE product_id = $pid_ AND branch_id = 1" }
function PostForm([string]$path, [string]$fields) {
    Eval "fetch('$Base/$path', {method: 'POST', body: new URLSearchParams({_csrf: document.querySelector('meta[name=csrf-token]').content, $fields})}).then(r => r.text().then(t => r.status + ':' + t))"
}
# Phase 7b: fill the stock document form and post it (confirm stubbed).
# $lines = JS array of [productId, qty, serialNos or null]; serial lines tick their serial numbers.
function DocPost([string]$query, [string]$from, [string]$to, [string]$reason, [string]$lines, [string]$label) {
    Nav "$Base/pages/stock-doc-form.php?$query"
    [void](Eval "window.confirm = () => true; window.__dl = $lines; const fs = document.getElementById('fromLocation'); fs.value = '$from'; fs.dispatchEvent(new Event('change')); const ts = document.getElementById('toLocation'); if (ts && '$to' !== '') ts.value = '$to'; document.getElementById('docForm').querySelector('[name=reason]').value = '$reason'; window.__dl.forEach((l, i) => { let t = document.querySelectorAll('#docLines tbody[data-line]')[i]; if (!t) { document.getElementById('addLine').click(); t = [...document.querySelectorAll('#docLines tbody[data-line]')].pop(); } const s = t.querySelector('[data-product]'); s.value = String(l[0]); s.dispatchEvent(new Event('change', {bubbles: true})); if (!l[2]) t.querySelector('[data-qty]').value = String(l[1]); }); true")
    WaitFor "window.__dl.every((l, i) => !l[2] || document.querySelectorAll('#docLines tbody[data-line]')[i].querySelectorAll('[data-serial-list] input').length > 0)" "serial list $label"
    [void](Eval "window.__dl.forEach((l, i) => { if (!l[2]) return; const t = document.querySelectorAll('#docLines tbody[data-line]')[i]; t.querySelectorAll('[data-serial-list] label').forEach(lb => { const c = lb.querySelector('input'); if (l[2].includes(lb.textContent.trim()) && !c.checked) c.click(); }); }); true")
    Submit "document.getElementById('docForm').requestSubmit()" "post $label"
}
function LocQty([string]$pid_, [string]$loc) { Sql "SELECT COALESCE((SELECT qty FROM stock_balances WHERE product_id = $pid_ AND location_id = $loc), 0)" }
function PStock([string]$pid_) { Sql "SELECT stock FROM products WHERE id = $pid_" }

try {
    [void](Cdp 'Page.enable'); [void](Cdp 'Runtime.enable'); [void](Cdp 'Log.enable')
    Size 1536 1024

    # --- Login page ---
    Nav "$Base/login.php"
    Shot '0-login'
    Check ((Text '.login__brand h1') -eq 'EXECOM') 'login shows EXECOM brand'

    # --- POS as cashier ---
    Login 'cashier' 'cashier123'
    WaitFor "document.querySelectorAll('.product-card').length > 0" 'products loaded'
    Check ((Eval "document.querySelectorAll('.product-card').length") -eq 12) 'grid shows 12 products'
    Check ((Eval "![...document.querySelectorAll('.product-card')].some(c => c.textContent.includes(String.fromCharCode(0x20B1))) && !document.getElementById('sumValue') && /^\d+$/.test(document.getElementById('sumLow').textContent)")) "POS: no prices on the product cards, no stock value; Low Stock $(Text '#sumLow')"
    [void](Eval "document.getElementById('lowStockBtn').click()")
    $low = Eval "(() => { const d = document.getElementById('lowDialog'); const rows = document.querySelectorAll('#lowBody tr').length; const ok = d.open && rows === Number(document.getElementById('sumLow').textContent) && (rows > 0 || !document.getElementById('lowEmpty').hidden) && !d.textContent.includes(String.fromCharCode(0x20B1)); d.close(); return ok + ':' + rows; })()"
    Check ($low -like 'true:*') "POS: Low Stock opens the list of low items, no prices ($low)"
    [void](Eval "document.getElementById('allItemsBtn').click()")
    $all = Eval "(() => { const d = document.getElementById('lowDialog'); const n = document.querySelectorAll('#lowBody tr').length; const s = document.getElementById('lowSearch'); s.value = 'mouse'; s.dispatchEvent(new Event('input')); const shown = [...document.querySelectorAll('#lowBody tr')].filter(r => !r.hidden).length; const ok = d.open && n === Number(document.getElementById('sumItems').textContent) && shown === 1 && !d.textContent.includes(String.fromCharCode(0x20B1)); d.close(); return ok + ':' + n + ':' + shown; })()"
    Check ($all -like 'true:*') "POS: Total Items opens every item with its stock, filter works, no prices ($all)"
    WaitFor "[...document.querySelectorAll('.product-card img')].every(i => i.complete && i.naturalWidth > 0)" 'images'
    Check $true 'all product images load'
    Check ((Eval "[...document.querySelectorAll('.tab')].map(t => t.textContent.trim()).join('|')") -eq 'All Items|Laptops & Computers|Peripherals|Accessories|Network|Office Supplies') 'category tabs match mockup'
    $order = Eval "[...document.querySelectorAll('.product-card__name')].map(e => e.textContent).slice(0, 5).join('|')"
    Check ($order -eq 'Laptop|Mouse|Keyboard|Monitor 24"|UPS 1000VA') "grid order matches mockup ($order)"
    Check (Eval "(() => { const t = document.querySelector('.tabs'); return t.scrollWidth <= t.clientWidth; })()") 'all 6 tabs fit without scrolling at 1536px'
    [void](Eval "document.querySelector('[data-category=`"2`"]').click()")
    Check ((Eval "document.querySelectorAll('.product-card').length") -eq 5) 'Peripherals tab shows 5 products'
    [void](Eval "document.querySelector('[data-category=`"all`"]').click()")

    # Mockup cart: Laptop, Mouse x2, RAM 8GB
    foreach ($n in 'Laptop', 'Mouse', 'Mouse', 'RAM 8GB') { ClickCard $n }
    $sub = Text '#tSubtotal'; $vat = Text '#tVat'; $tot = Text '#tTotal'
    Check ($sub -eq ([string][char]0x20B1 + ' 27,500.00')) "subtotal = mockup ($sub)"
    Check ($vat -eq ([string][char]0x20B1 + ' 3,300.00')) "VAT = mockup ($vat)"
    Check ($tot -eq ([string][char]0x20B1 + ' 30,800.00')) "total = mockup ($tot)"
    [void](Eval 'document.activeElement.blur()')
    Shot '1-pos-mockup-cart'

    # F2 + barcode (Webcam) + Enter
    Key 'F2' 113
    Check ((Eval 'document.activeElement.id') -eq 'globalSearch') 'F2 focuses the search box'
    TypeText '4806500000110'; Key 'Enter' 13
    Check ((CartQty 'Webcam') -eq 1) 'scanned barcode adds Webcam'

    # F3 search + F4 add
    Key 'F3' 114
    TypeText 'ss'
    $names = Eval "[...document.querySelectorAll('.product-card__name')].map(e => e.textContent).join('|')"
    Check ($names -eq 'SSD 512GB') "search 'ss' filters grid -> $names"
    Key 'F4' 115
    Check ((CartQty 'SSD 512GB') -eq 1) 'F4 adds highlighted SSD 512GB'
    Key 'Escape' 27

    # Save -> pay -> done
    $webcamStock = [int](Eval "[...document.querySelectorAll('.product-card')].find(c => c.querySelector('.product-card__name').textContent === 'Webcam').querySelector('.stock-pill').textContent.replace(/[^0-9]/g, '')")
    [void](Eval "document.getElementById('btnSave').click()")
    Check (Eval "document.getElementById('payDialog').open") 'Save opens payment dialog'
    $quick = Eval "[...document.querySelectorAll('#quickCash button')].map(b => b.textContent).join('|')"
    Check ($quick -like 'Exact|*35,300.00|*35,500.00|*36,000.00|*40,000.00') "quick cash buttons: $quick"
    [void](Eval "(() => { const a = document.getElementById('payAmount'); a.value = '40000'; a.dispatchEvent(new Event('input', {bubbles: true})); })()")
    Shot '2-payment'
    [void](Eval "document.getElementById('payForm').requestSubmit()")
    WaitFor "document.getElementById('doneDialog').open" 'sale completed dialog'
    $saleNo = Text '#doneNo'
    Check ($saleNo -eq '0000005') "sale saved as No. $saleNo"
    Shot '3-done'
    [void](Eval "document.getElementById('doneNew').click()")
    Start-Sleep -Milliseconds 300
    WaitFor "[...document.querySelectorAll('.product-card')].some(c => c.querySelector('.product-card__name').textContent === 'Webcam' && c.querySelector('.stock-pill').textContent === 'In Stock: $($webcamStock - 1)')" 'Webcam stock refreshed'
    Check $true "stock deducted (Webcam $webcamStock -> $($webcamStock - 1))"
    Check ((Text '#saleNo') -eq '0000006') 'next sale number shown'

    # Receipt
    Nav "$Base/pages/receipt.php?id=5"
    Shot '4-receipt'
    Check ((Eval "document.body.textContent.includes('EXECOM Logistics')")) 'receipt shows company name'

    # Tablet width
    Nav "$Base/pages/pos.php"
    Size 1024 900
    Start-Sleep -Milliseconds 300
    Check (Eval 'document.documentElement.scrollWidth <= window.innerWidth') 'POS: no horizontal scroll at 1024px'
    Shot '5-pos-1024'
    Size 1536 1024

    # --- Sales History as cashier (Phase 2) ---
    Nav "$Base/pages/sales-history.php"
    Check ((Eval "document.querySelectorAll('.sales-table tbody tr.is-void, .sales-table tbody tr:not(.is-void)').length") -eq 5) 'sales history lists 5 sales'
    Check ((Text '.sales-table tbody tr .sale-no') -eq '0000005') 'newest sale is first (0000005)'
    [void](Eval "[...document.querySelectorAll('.chip')].find(a => a.textContent.trim() === 'Today').click()")
    Start-Sleep -Milliseconds 300
    WaitFor "document.readyState === 'complete' && location.search.includes('from=')" 'Today filter'
    Check ((Eval "[...document.querySelectorAll('.sale-no')].some(a => a.textContent === '0000005')") -and (Text '.chip.is-active') -eq 'Today') 'Today chip filters and highlights'
    Nav "$Base/pages/sales-history.php?search=webcam"
    $found = Eval "[...document.querySelectorAll('.sale-no')].map(a => a.textContent).join(',')"
    Check ($found -eq '0000005,0000003') "item search 'webcam' finds sales $found"
    Nav "$Base/pages/sale-view.php?id=5"
    Check ((Eval "document.getElementById('voidBtn') === null")) 'cashier has no Void button'
    Check ((Eval "fetch('$Base/pages/reports.php').then(r => r.status)") -eq 403) 'cashier cannot open Reports (403)'
    Check ((Eval "document.getElementById('reprintBtn').href.includes('receipt.php?id=5&autoprint=1')")) 'Reprint opens receipt with autoprint'

    # --- Logout button, then admin pages ---
    [void](Eval "document.querySelector('form.topbar__logout').submit()")
    Start-Sleep -Milliseconds 400
    WaitFor "location.pathname.endsWith('/login.php') && document.readyState==='complete'" 'logout to login page'
    Check $true 'Logout button signs out'
    Login 'admin' 'admin123'
    Nav "$Base/pages/inventory.php"
    Check ((Text '.pager__info') -like '*of 12') "inventory pager: $(Text '.pager__info')"
    Shot '6-inventory'
    [void](Eval "document.querySelector('[data-adjust]').click()")
    $reasons = Eval "[...document.querySelectorAll('#adjustReason option')].map(o => o.textContent.trim()).join('|')"
    Check ($reasons -like '*Damaged / defective*' -and $reasons -like '*Returned to supplier*') "adjust reasons: $reasons"
    Shot '7-adjust'
    Nav "$Base/pages/product-form.php?id=1"
    Shot '8-product-edit'

    # --- Void a sale as admin (Phase 2) ---
    Nav "$Base/pages/sales-history.php"
    Shot '12-sales-history'
    [void](Eval "document.querySelector('.sales-table tbody tr a.sale-no').click()")
    Start-Sleep -Milliseconds 300
    WaitFor "document.readyState === 'complete' && location.pathname.endsWith('sale-view.php')" 'sale view'
    Check ((Text '#saleTotal') -eq ([string][char]0x20B1 + ' 35,280.00')) "sale view total $(Text '#saleTotal')"
    Shot '13-sale-view'
    [void](Eval "document.getElementById('voidBtn').click()")
    Check (Eval "document.getElementById('voidDialog').open && document.activeElement.id === 'voidReason'") 'Void opens dialog with reason focused'
    # A script-set value skips the browser's minlength check, so this proves the server rejects it.
    [void](Eval "window.__old = true; document.getElementById('voidReason').value = 'x'; document.querySelector('#voidDialog form').requestSubmit()")
    WaitFor "window.__old === undefined && document.readyState === 'complete'" 'short reason submit'
    Check ((Text '.alert--error span') -like 'Enter the reason for voiding (3*255 characters).' -and (Text '.sale-title .badge') -eq 'Completed') 'server rejects a too-short reason'
    [void](Eval "document.getElementById('voidBtn').click(); document.getElementById('voidReason').value = 'Customer returned the items'")
    Shot '14-void-dialog'
    [void](Eval "window.__old = true; document.querySelector('#voidDialog form').requestSubmit()")
    WaitFor "window.__old === undefined && document.readyState === 'complete'" 'void submit'
    Check ((Text '.alert span') -eq 'Sale No. 0000005 was voided. 6 items were returned to stock.') "void flash: $(Text '.alert span')"
    Check ((Text '.sale-title .badge') -eq 'Voided' -and (Eval "document.getElementById('voidBtn') === null")) 'sale shows Voided and no Void button'
    Check ((Text '.void-box__reason') -eq 'Reason: Customer returned the items') 'void reason is shown'
    Shot '15-sale-voided'
    Nav "$Base/pages/inventory.php?search=webcam"
    $ws2 = [int](Eval "document.querySelector('[data-adjust]').dataset.stock")
    Check ($ws2 -eq 16) "Webcam stock back to 16 after void ($ws2)"
    Nav "$Base/pages/product-form.php?id=11"
    Check ((Eval "document.querySelector('.history-card tbody tr td:nth-child(5)').textContent.trim()") -eq 'Voided sale No. 0000005: Customer returned the items') 'stock history shows the void'
    Nav "$Base/pages/sales-history.php?status=cancelled"
    Check ((Eval "document.querySelectorAll('.sales-table tbody tr.is-void').length") -eq 1) 'Voided filter shows the voided sale'
    Check ((Text '.stat--danger .stat__value') -like '1*') 'Voided counter shows 1'
    Nav "$Base/pages/receipt.php?id=5"
    Check ((Text '.void-stamp') -eq 'VOID') 'receipt shows VOID stamp'
    Size 1024 900
    Nav "$Base/pages/sales-history.php"
    Check (Eval 'document.documentElement.scrollWidth <= window.innerWidth') 'sales history: no horizontal page scroll at 1024px'
    Check (Eval "(() => { const w = document.querySelector('.table-wrap'); return w.scrollWidth <= w.clientWidth; })()") 'sales table fits at 1024px (Status + Actions visible)'
    Shot '16-sales-history-1024'
    Size 1536 1024
    Nav "$Base/pages/customers.php"
    Shot '9-customers'
    # --- Reports (Phase 3) - sale 0000005 is voided by now, so it must be left out ---
    Nav "$Base/pages/reports.php"
    WaitFor "document.querySelector('#salesChart svg') !== null" 'chart drawn'
    $net = Eval "document.querySelector('.kpi .stat__value').textContent.trim()"
    Check ($net -eq ([string][char]0x20B1 + ' 17,684.80')) "net sales exclude the voided sale ($net)"
    Check ((Text '.report-note') -like '1 voided sale*35,280.00*') 'voided sale is noted, not counted'
    $bars = [int](Eval "document.querySelectorAll('#salesChart .cc-bar').length")
    Check ($bars -ge 2 -and $bars -le 3) "chart draws one column per day with sales ($bars)"
    Check ((Eval "Math.max(...[...document.querySelectorAll('#salesChart .cc-bar')].map(b => b.getBBox().width))") -le 24) 'columns are at most 24px wide'
    Check ((Text '.cc-peak') -like ([string][char]0x20B1 + ' *')) "only the best day is labelled ($(Text '.cc-peak'))"
    $pt = Eval "(() => { const r = document.querySelector('#salesChart .cc-bar').getBoundingClientRect(); return [Math.round(r.left + r.width / 2), Math.round(r.bottom - 3)]; })()"
    [void](Cdp 'Input.dispatchMouseEvent' @{ type = 'mouseMoved'; x = $pt[0]; y = $pt[1] })
    Start-Sleep -Milliseconds 150
    Check ((Eval "!document.getElementById('chartTip').hidden") -and (Text '.chart-tip__value') -like ([string][char]0x20B1 + ' *')) "hovering a column shows its value ($(Text '.chart-tip__value') - $(Text '.chart-tip__title'))"
    Shot '17-reports'
    [void](Cdp 'Input.dispatchMouseEvent' @{ type = 'mouseMoved'; x = 5; y = 5 })
    [void](Eval "document.querySelector('#salesChart svg').focus()")
    $v1 = Text '.chart-tip__value'
    Key 'ArrowLeft' 37
    Check ((Eval "!document.getElementById('chartTip').hidden") -and (Text '.chart-tip__title') -ne '') "keyboard focus + arrows read the chart ($v1 -> $(Text '.chart-tip__value'))"
    [void](Eval "document.activeElement.blur()")
    Check ((Eval "document.querySelectorAll('#seriesTable tbody tr').length") -eq 30) 'table view lists all 30 days'
    Check ((Text '#topItems .barlist__label') -like 'Monitor 24"*') "top item by sales: $(Text '#topItems .barlist__label')"
    Nav "$Base/pages/reports.php?top=qty"
    Check ((Text '#topItems .barlist__label') -like 'Mouse*' -and (Text '#topItems .barlist__value') -eq '2 sold') "top item by quantity: $(Text '#topItems .barlist__label') ($(Text '#topItems .barlist__value'))"
    Check ((Eval "document.querySelectorAll('#paymentSales .barlist__row').length") -eq 3) 'all 3 payment types listed'
    Check ((Text '#lowStockTable tbody td') -like 'Every product is above*') 'reorder list is empty with the sample stock'
    $csv = Eval "fetch(document.getElementById('exportCsv').href).then(r => r.ok && r.headers.get('content-type').startsWith('text/csv') ? r.text() : 'bad').then(t => t.includes('Net sales') && t.includes('Monitor 24') ? 'ok' : t.slice(0, 80))"
    Check ($csv -eq 'ok') "Export CSV downloads the report ($csv)"
    # Sample sales are dated relative to NOW(), so the 9-month range must end today.
    $monthsFrom = (Get-Date -Day 1).AddMonths(-8).ToString('yyyy-MM-dd')
    Nav "$Base/pages/reports.php?from=$monthsFrom&to=$((Get-Date).ToString('yyyy-MM-dd'))"
    WaitFor "document.querySelector('#salesChart svg') !== null" 'monthly chart'
    Check ((Eval "document.querySelector('.report-card .card__head h2').textContent.trim()") -eq 'Monthly Net Sales' -and (Eval "document.querySelectorAll('#seriesTable tbody tr').length") -eq 9) 'long ranges group by month (9 months)'
    Nav "$Base/pages/reports.php?from=2020-01-01&to=2020-01-31"
    Check ((Text '.chart-empty') -eq 'No sales in this period.') 'empty period shows a message, not an empty chart'
    Size 1024 900
    Nav "$Base/pages/reports.php"
    WaitFor "document.querySelector('#salesChart svg') !== null" 'chart at 1024'
    Check (Eval 'document.documentElement.scrollWidth <= window.innerWidth') 'reports: no horizontal page scroll at 1024px'
    Shot '18-reports-1024'
    Size 1536 2700
    Nav "$Base/pages/reports.php"
    WaitFor "document.querySelector('#salesChart svg') !== null" 'chart full page'
    Shot '19-reports-full'
    Size 1536 1024

    # --- Settings, Users, My Account (Phase 4) ---
    Check (Eval "!!document.getElementById('myAccountLink')") 'user menu has Change Password'
    Nav "$Base/pages/settings.php"
    Check (Eval "document.body.textContent.includes('Receipts still show the sample address')") 'sample-details warning shown until replaced'
    Shot '20-settings'
    [void](Eval "(() => { const f = document.getElementById('settingsForm'); f.shop_address.value = 'Unit 5, IT Center, Makati Avenue, Makati City'; f.shop_phone.value = '(02) 8888-1234'; f.shop_tin.value = '123-456-789-000'; window.__old = true; f.requestSubmit(); })()")
    WaitFor "window.__old === undefined && document.readyState === 'complete'" 'settings save'
    Check ((Text '.alert--success span') -eq 'Company and receipt settings were saved.') "settings saved: $(Text '.alert span')"
    Check ((Eval "document.getElementById('receiptPreview').textContent.includes('IT Center')") -and (Eval "!document.body.textContent.includes('Receipts still show the sample')")) 'receipt preview updated, warning gone'
    Nav "$Base/pages/receipt.php?id=1"
    # MAR has its own address (migration 012), so its receipts print the branch address; the TIN is the company's.
    Check (Eval "document.body.textContent.includes('Perimeter Freedom Park') && document.body.textContent.includes('VAT Reg TIN: 123-456-789-000')") 'receipt prints the branch address and the new company TIN'
    Nav "$Base/pages/users.php"
    Check ((Eval "document.querySelectorAll('#usersTable tbody tr').length") -eq 2) 'users list shows the 2 sample users'
    Check (Eval "(() => { const r = document.querySelector('#usersTable tr[data-username=admin]'); return !!r.querySelector('.badge--you') && !r.querySelector('[data-act=toggle]') && !r.querySelector('[data-act=delete]'); })()") 'own row: You badge, no deactivate/delete'
    Check (Eval "(() => { const r = document.querySelector('#usersTable tr[data-username=cashier]'); return !!r.querySelector('[data-act=toggle]') && !r.querySelector('[data-act=delete]'); })()") 'cashier with sales: deactivate only, no delete'
    Shot '21-users'
    Nav "$Base/pages/user-form.php"
    [void](Eval "(() => { const f = document.getElementById('userForm'); f.full_name.value = 'Ana Cruz'; f.username.value = 'ana'; f.querySelector('[name=role][value=cashier]').checked = true; f.password.value = 'Tindahan2026!'; f.password_confirm.value = 'Tindahan2026!'; })()")
    Shot '22-user-form'
    [void](Eval "window.__old = true; document.getElementById('userForm').requestSubmit()")
    WaitFor "window.__old === undefined && document.readyState === 'complete'" 'user save'
    Check ((Text '.alert--success span') -eq 'Ana Cruz can now sign in as ana.') "user added: $(Text '.alert span')"
    Check (Eval "!!document.querySelector('#usersTable tr[data-username=ana] [data-act=delete]')") 'new user (no sales) can be deleted'
    Nav "$Base/pages/account.php"
    Check ((Eval "document.querySelectorAll('#passwordForm input[type=password]').length") -eq 3) 'My Account has the change-password form'
    Shot '23-account'
    [void](Eval "document.querySelector('form.topbar__logout').submit()")
    Start-Sleep -Milliseconds 400
    WaitFor "location.pathname.endsWith('/login.php') && document.readyState==='complete'" 'logout before ana'
    Login 'ana' 'Tindahan2026!'
    Check (Eval "document.querySelector('.alert--warning') === null && document.querySelector('.user-menu__name strong').textContent === 'ana'") 'new user signs in (no default-password warning)'
    Check ((Eval "fetch('$Base/pages/settings.php').then(r => r.status)") -eq 403) 'new cashier cannot open Settings (403)'
    Nav "$Base/pages/nope.php"
    Shot '11-404'

    # --- Phase 5: branches, branch stock, roles & permissions, audit log ---
    # Ids from database.sql: branch 1 = MAR (main), 4 = DAV; product 2 = Mouse, 11 = Webcam; sales 1-5 are MAR.
    Nav "$Base/pages/pos.php"
    Logout
    Login 'admin' 'admin123' 'dashboard.php'
    Check $true 'admin lands on the Dashboard'
    $sel = Eval "(() => { const s = document.getElementById('branchSelect'); return s ? s.options[s.selectedIndex].textContent : ''; })()"
    Check ($sel -like 'MAR*Maramag City') "admin branch chip shows MAR ($sel)"
    Check ((Eval "[...document.querySelectorAll('#branchSelect option')].map(o => o.value).sort().join(',')") -eq '0,1,2,3,4,5') 'switcher: All (0) + 5 branches'
    Nav "$Base/pages/pos.php"
    SwitchBranch 0
    WaitFor "location.pathname.endsWith('/pages/pos.php')" 'back on POS'
    Check ((Eval "!!document.getElementById('chooseBranchNotice')") -and (Eval "document.querySelectorAll('.product-card').length") -eq 0) 'POS with All branches asks to choose a branch'
    Shot '24-pos-all-branches'
    SwitchBranch 1
    WaitFor "document.querySelectorAll('.product-card').length === 12" 'MAR products back'
    Check (Eval "!document.getElementById('chooseBranchNotice')") 'switching back to MAR restores the products'

    AddUser 'Dave Admin' 'davadmin' 'branch_admin' 4
    AddUser 'Dina Cash' 'davcash' 'cashier' 4
    AddUser 'Tony Tech' 'davtech' 'technician' 4
    AddUser 'Mila Multi' 'davmulti' 'cashier' 4 1
    Check ((Text '#usersTable tr[data-username=davmulti] .user-extra') -like '*MAR*') 'extra branch MAR shown in the users list'
    $multiId = [int](Eval "new URL(document.querySelector('#usersTable tr[data-username=davmulti] a.icon-btn').href).searchParams.get('id')")
    Shot '25-users-branches'

    # Roles editor: unknown permission key, locked super role, no self-demotion / self-deactivation
    Nav "$Base/pages/role-form.php"
    Submit "const f = document.getElementById('roleForm'); f.name.value = 'QA Role'; f.code.value = 'qa_role'; const i = document.createElement('input'); i.type = 'hidden'; i.name = 'permissions[]'; i.value = 'bogus.key'; f.appendChild(i); f.requestSubmit()" 'role save'
    Check ((Eval "document.body.textContent.includes('Unknown permission: bogus.key.')") -and (Eval "location.pathname.endsWith('role-form.php')")) 'roles: unknown permission key is rejected'
    Nav "$Base/pages/roles.php"
    Check (Eval "!document.querySelector('#rolesTable tr[data-role=qa_role]')") 'roles: rejected role was not created'
    Nav (Eval "document.querySelector('#rolesTable tr[data-role=super_admin] a').href")
    Check (Eval "!!document.getElementById('roleLocked') && !document.querySelector('#roleForm button[type=submit]')") 'super admin role is locked (no save)'
    Nav "$Base/pages/user-form.php?id=1"
    Submit "const f = document.getElementById('userForm'); f.querySelector('input[type=hidden][name=role]').value = 'cashier'; f.requestSubmit()" 'self demote'
    Check (Eval "document.body.textContent.includes(`"You can't change your own role.`")") 'last super admin cannot demote themselves'
    $r = Eval "fetch('$Base/pages/users.php', {method: 'POST', body: new URLSearchParams({_csrf: document.querySelector('meta[name=csrf-token]').content, action: 'toggle', id: '1'})}).then(r => r.text()).then(t => t.includes('deactivate your own account') ? 'blocked' : 'not blocked')"
    Check ($r -eq 'blocked') "last super admin cannot deactivate themselves ($r)"
    Nav "$Base/pages/users.php"
    Check ((Text '#usersTable tr[data-username=admin] td:nth-child(6) .badge') -eq 'Active') 'admin is still active'

    # Admin on MAR can still sell
    Nav "$Base/pages/pos.php"
    WaitFor "document.querySelectorAll('.product-card').length === 12" 'admin POS'
    $r = ApiSale 2
    Check ($r -like 'ok:*') "admin sells at MAR ($r)"

    # Branch stock: +3 Webcam at DAV only
    SwitchBranch 4
    Nav "$Base/pages/inventory.php?search=webcam"
    Check ((Text '#stockScope') -eq 'Davao City' -and [int](Eval "document.querySelector('[data-adjust]').dataset.stock") -eq 0) 'DAV inventory: Webcam 0 before'
    [void](Eval "document.querySelector('[data-adjust]').click()")
    Submit "document.getElementById('adjustQty').value = '3'; document.getElementById('adjustReason').value = 'restock'; document.querySelector('#adjustDialog form').requestSubmit()" 'adjust save'
    Check ((Text '.alert--success span') -like 'Webcam: +3. Stock at Davao City is now 3.') "DAV adjust +3 ($(Text '.alert span'))"
    Check ([int](Eval "document.querySelector('[data-adjust]').dataset.stock") -eq 3) 'DAV inventory shows Webcam 3'
    Nav "$Base/pages/product-form.php?id=11"
    $hint = Eval "[...document.querySelectorAll('.form-hint')].map(p => p.textContent).find(t => t.includes('company total')) || ''"
    Check ($hint -like '*Davao City*company total 19') "product page: company total = MAR 16 + DAV 3 ($hint)"
    Check ((Eval "document.querySelector('.history-card tbody tr td:last-child').textContent.trim()") -eq 'DAV') 'stock history row carries branch DAV'
    SwitchBranch 1
    Nav "$Base/pages/inventory.php?search=webcam"
    Check ([int](Eval "document.querySelector('[data-adjust]').dataset.stock") -eq 16) 'MAR Webcam unchanged (16)'
    Logout

    # Branch admin (DAV)
    Login 'davadmin' $script:pw
    $menu = Eval "[...document.querySelectorAll('.sidebar__nav .nav-link span')].map(s => s.textContent.trim()).join('|')"
    # Phase 7a: Receiving + Serial Lookup added after Inventory (branch_admin has receiving.view / serials.view).
    Check ($menu -eq 'Dashboard|How It Works|POS Sales|Sales History|Customer Orders|Billing & Collections|Customers|Job Orders|Purchasing|Receiving|Payables|Suppliers|Inventory|Branch Prices|Stock Operations|Branch Transfers|Serial Lookup|Reports|Settings') "branch admin menu: $menu"
    Check (Eval "[...document.querySelectorAll('.sidebar__nav .nav-link')].pop().href.endsWith('/pages/users.php')") 'branch admin Settings opens the Users tab'
    Check ((Text '[data-branch-code]') -like 'DAV*Davao City' -and (Eval "!document.getElementById('branchSelect')")) 'branch admin: fixed DAV chip, no switcher'
    $st = "$(Status 'pages/roles.php'),$(Status 'pages/branches.php'),$(Status 'pages/settings.php')"
    Check ($st -eq '403,403,403') "branch admin: roles/branches/company settings 403 ($st)"
    $st = "$(Status 'pages/receipt.php?id=1'),$(Status 'pages/sale-view.php?id=1')"
    Check ($st -eq '404,404') "branch admin: MAR receipt / sale view 404 ($st)"
    Nav "$Base/pages/sales-history.php"
    Check ((Eval "document.querySelectorAll('.sales-table .sale-no').length") -eq 0) 'branch admin: DAV sales history is empty'
    Nav "$Base/pages/customers.php"
    Check ((Eval "document.querySelectorAll('main tbody .item-cell__name').length") -eq 0) 'branch admin: MAR customers hidden'
    Nav "$Base/pages/user-form.php"
    $roles = Eval "[...document.querySelectorAll('[name=role]')].map(r => r.value).join(',')"
    Check ($roles -notlike '*branch_admin*' -and $roles -notlike '*super_admin*' -and $roles -like '*cashier*') "branch admin can assign only: $roles"
    Nav "$Base/pages/users.php"
    Check (Eval "(() => { const r = document.querySelector('#usersTable tr[data-username=davmulti]'); return !!r && r.querySelectorAll('.row-actions > *').length === 0 && !r.querySelector('a.item-cell__name'); })()") 'multi-branch cashier row has no actions'
    Check ((Status "pages/user-form.php?id=$multiId") -eq 403) 'branch admin: user-form of multi-branch cashier 403'
    Nav "$Base/pages/product-form.php?id=11"
    Check (Eval "!document.body.textContent.includes('company total')") 'branch admin: no company total on product page'
    Shot '26-branch-admin'
    Logout

    # Technician
    Login 'davtech' $script:pw 'job-orders.php'
    Check $true 'technician lands on Job Orders'
    $st = "$(Status 'pages/pos.php'),$(Eval "BB.api('pos/checkout.php', {method: 'POST', body: {items: [{product_id: 11, qty: 1}], payment_type: 'cash', amount_paid: '99999'}}).then(() => 200, e => e.status)"),$(Status 'pages/account.php')"
    Check ($st -eq '403,403,200') "technician: pos.php / checkout API / account ($st)"
    Logout

    # DAV cashier (single branch): stock is per branch; tampered switch is refused
    Login 'davcash' $script:pw
    WaitFor "document.querySelectorAll('.product-card').length === 12" 'DAV POS products'
    Check ((Eval "[...document.querySelectorAll('.product-card .stock-pill')].filter(p => p.textContent !== 'Out of stock').map(p => p.textContent).join('|')") -eq 'In Stock: 3') 'DAV POS: every product out of stock except the 3 DAV Webcams'
    $r = Eval "fetch('$Base/pages/switch-branch.php', {method: 'POST', body: new URLSearchParams({_csrf: document.querySelector('meta[name=csrf-token]').content, branch_id: '1', return: 'pos.php'})}).then(r => r.status)"
    Nav "$Base/pages/pos.php"
    Check ($r -eq 403 -and (Text '[data-branch-code]') -like 'DAV*') "tampered switch to MAR refused ($r), still DAV"
    $r = ApiSale 2
    Check ($r -like '409:*Mouse is out of stock at Davao City*') "DAV cashier cannot sell MAR stock ($r)"
    $r = ApiSale 11
    Check ($r -like 'ok:*') "DAV cashier sells the DAV Webcam ($r)"
    Logout

    # Audit log
    Login 'admin' 'admin123'
    Nav "$Base/pages/audit-log.php"
    Check ((Eval "document.querySelectorAll('#auditTable tr[data-module=inventory]').length") -eq 0) 'audit log on MAR hides the DAV stock adjustment (scoped)'
    SwitchBranch 0
    Check ((Eval "document.querySelectorAll('#auditTable tr[data-module=sales][data-action=void]').length") -ge 1) 'audit log: sale void'
    Check ((Eval "document.querySelectorAll('#auditTable tr[data-module=users][data-action=create]').length") -ge 5) 'audit log: 5 user creations'
    Check ((Eval "document.querySelectorAll('#auditTable tr[data-module=inventory][data-action=stock_adjust]').length") -ge 1) 'audit log: stock adjustment'
    Check (Eval "![...document.querySelectorAll('#auditTable dt')].some(d => /pass|hash|csrf/i.test(d.textContent))") 'audit log: no password / CSRF fields'
    Shot '27-audit-log'

    # --- Phase 6: master data, suppliers, product + customer fields, cost visibility ---
    # Still admin (super admin). Master data is company-wide; customers/products are saved at MAR.
    SwitchBranch 1
    Nav "$Base/pages/master-data.php"
    $tabs = Eval "[...document.querySelectorAll('.md-tabs a')].map(a => a.textContent.trim()).filter(t => /Brands|Models|Units|Suppliers|Warranty Types/.test(t)).join('|')"
    Check ($tabs -like '*Brands*Models*Units*' -and $tabs -like '*Suppliers*') "master data tabs ($tabs)"
    Check ((Eval "document.querySelector('#mdTable').dataset.list") -eq 'categories' -and (Eval "document.querySelectorAll('#mdTable tbody tr[data-row]').length") -eq 5) 'master data opens Categories (5 sample categories)'
    Shot '28-master-data'

    MdAdd 'brands' "f.querySelector('#mdName').value = 'Lenovo'" 'add brand Lenovo'
    Check ((Text '.alert--success span') -eq 'Brand Lenovo was added.') "md: brand added ($(Text '.alert span'))"
    MdAdd 'brands' "f.querySelector('#mdName').value = 'HP'" 'add brand HP'
    MdAdd 'brands' "f.querySelector('#mdName').value = 'TempBrand'" 'add brand TempBrand'
    MdAdd 'brands' "f.querySelector('#mdName').value = 'lenovo'" 'add duplicate brand'
    Check ((Eval "document.getElementById('mdDialog').open") -and (Text '#err-name') -like '*already exists*') "md: duplicate brand name (case-insensitive) -> field error ($(Text '#err-name'))"
    Shot '29-md-duplicate'
    $lenovo = MdId 'Lenovo'; $hp = MdId 'HP'; $temp = MdId 'TempBrand'
    Check ((Sql "SELECT COUNT(*) FROM brands WHERE name = 'Lenovo'") -eq '1' -and $lenovo -ne '' -and $hp -ne '') "md: one Lenovo row in DB (ids Lenovo=$lenovo HP=$hp)"
    Submit "document.querySelector('#mdTable tr[data-row=TempBrand] [data-act=delete]').form.submit()" 'delete unused brand'
    $r = Eval "fetch('$Base/pages/master-data.php?list=brands', {method: 'POST', body: new URLSearchParams({action: 'delete', id: '$hp'})}).then(r => r.status)"
    Check ($r -eq 403 -and (Sql "SELECT COUNT(*) FROM brands WHERE id = $hp") -eq '1') "md: POST without CSRF token 403 ($r)"
    Check ((Text '.alert--success span') -eq 'TempBrand was deleted.' -and (Sql "SELECT COUNT(*) FROM brands WHERE id = $temp") -eq '0') "md: unused brand deleted ($(Text '.alert span'))"

    MdAdd 'models' "f.querySelector('#mdBrand').value = '$lenovo'; f.querySelector('#mdName').value = 'ThinkPad E14'" 'add model ThinkPad'
    Check ((Text '.alert--success span') -eq 'Model ThinkPad E14 was added.') "md: model added ($(Text '.alert span'))"
    MdAdd 'models' "f.querySelector('#mdBrand').value = '$hp'; f.querySelector('#mdName').value = 'ProBook 440'" 'add model ProBook'
    MdAdd 'models' "f.querySelector('#mdBrand').value = '$lenovo'; f.querySelector('#mdName').value = 'thinkpad e14'" 'add duplicate model'
    Check ((Text '#err-name') -eq 'This brand already has a model with this name.') "md: duplicate model under the same brand -> field error ($(Text '#err-name'))"
    MdAdd 'models' "f.querySelector('#mdName').value = 'No Brand Model'" 'add model without brand'
    Check ((Text '#err-brand_id') -eq 'Choose a brand.') "md: model needs a brand ($(Text '#err-brand_id'))"
    Nav "$Base/pages/master-data.php?list=models"
    $thinkpad = MdId 'ThinkPad E14'; $probook = MdId 'ProBook 440'
    Check ((Text "#mdTable tr[data-row=`"ThinkPad E14`"] td:nth-child(2)") -eq 'Lenovo') 'md: models list shows the brand'

    MdAdd 'units' "f.querySelector('#mdCode').value = 'BNDL'; f.querySelector('#mdName').value = 'Bundle'" 'add unit'
    Check ((Text '.alert--success span') -eq 'Unit Bundle was added.') "md: unit added ($(Text '.alert span'))"
    MdAdd 'units' "f.querySelector('#mdCode').value = 'PC'; f.querySelector('#mdName').value = 'Pieces'" 'add duplicate unit code'
    Check ((Text '#err-code') -eq 'Another unit already uses this code.') "md: duplicate unit code -> field error ($(Text '#err-code'))"
    Nav "$Base/pages/master-data.php?list=units"
    Check (Eval "!document.querySelector('#mdTable tr[data-row=Piece] [data-act=delete]') && !!document.querySelector('#mdTable tr[data-row=Bundle] [data-act=delete]')") 'md: used unit PC has no Delete button, unused Bundle has'
    $r = Eval "fetch('$Base/pages/master-data.php?list=units', {method: 'POST', body: new URLSearchParams({_csrf: document.querySelector('meta[name=csrf-token]').content, action: 'delete', id: '1', return: 'master-data.php?list=units'})}).then(r => r.text()).then(t => t.includes('Deactivate it instead') ? 'blocked' : 'not blocked')"
    Check ($r -eq 'blocked' -and (Sql 'SELECT COUNT(*) FROM units WHERE id = 1') -eq '1') "md: deleting used unit PC is blocked with 'Deactivate it instead' ($r)"
    Nav "$Base/pages/master-data.php?list=units"
    Submit "document.querySelector('#mdTable tr[data-row=Bundle] [data-act=toggle]').form.submit()" 'deactivate Bundle'
    Check ((Text '.alert--success span') -like 'Bundle was deactivated.*' -and (Text '#mdTable tr[data-row=Bundle] .badge') -eq 'Inactive') "md: deactivate works ($(Text '.alert span'))"

    # Supplier with a contact person, code auto-assigned
    Nav "$Base/pages/supplier-form.php"
    Check ((Eval "document.querySelector('#supplierForm [name=code]').placeholder") -eq 'SUP-0001') 'supplier code suggestion is SUP-0001'
    Submit "const f = document.getElementById('supplierForm'); f.querySelector('[name=name]').value = 'Mindanao IT Distributors'; f.querySelector('[name=tin]').value = '222-333-444-000'; f.querySelector('[name=payment_terms]').value = '30 days'; f.querySelector('[name=`"contacts[0][name]`"]').value = 'Rosa Lim'; f.querySelector('[name=`"contacts[0][position]`"]').value = 'Sales Rep'; f.querySelector('[name=`"contacts[0][phone]`"]').value = '0917 000 1111'; f.requestSubmit()" 'add supplier'
    Check ((Text '.alert--success span') -eq 'Mindanao IT Distributors was added.') "supplier added ($(Text '.alert span'))"
    Check ((Text '#suppliersTable tr[data-supplier=SUP-0001] .item-cell__name') -eq 'Mindanao IT Distributors' -and (Eval "document.querySelector('#suppliersTable tr[data-supplier=SUP-0001]').textContent.includes('Rosa Lim')")) 'supplier listed as SUP-0001 with contact Rosa Lim'
    $s = Sql "SELECT CONCAT_WS('|', s.code, s.tin, s.payment_terms, c.name, c.position) FROM suppliers s JOIN supplier_contacts c ON c.supplier_id = s.id"
    Check ($s -eq 'SUP-0001|222-333-444-000|30 days|Rosa Lim|Sales Rep') "supplier + 1 contact in DB ($s)"
    Shot '30-suppliers'

    # Product: brand / model / unit / cost / warranty / specs (Laptop, id 1)
    Nav "$Base/pages/product-form.php?id=1"
    Check (Eval "document.querySelectorAll('#productUnit option').length >= 8 && ![...document.querySelectorAll('#productUnit option')].some(o => o.textContent.includes('Bundle'))") 'product form: inactive unit Bundle is not offered'
    Check (Eval "document.body.textContent.includes('Suggested price')") 'product form: price labelled Suggested price'
    Submit "const f = document.querySelector('[name=unit_cost]').form; const b = document.getElementById('productBrand'); b.value = '$lenovo'; b.dispatchEvent(new Event('change')); document.getElementById('productModel').value = '$thinkpad'; document.getElementById('productUnit').value = '1'; f.unit_cost.value = '21234.56'; f.warranty_days.value = '365'; f.specs.value = 'Core i5, 8GB RAM, 512GB SSD'; f.requestSubmit()" 'save product master fields'
    Check ((Text '.alert--success span') -eq 'Laptop was updated.') "product saved ($(Text '.alert span'))"
    $p = Sql "SELECT CONCAT_WS('|', b.name, m.name, u.code, p.unit_cost, p.warranty_days, p.track_serial, p.specs) FROM products p LEFT JOIN brands b ON b.id = p.brand_id LEFT JOIN product_models m ON m.id = p.model_id LEFT JOIN units u ON u.id = p.unit_id WHERE p.id = 1"
    # Phase 7a: track_serial can no longer be ticked while the Laptop has stock (checkbox locked), so it stays 0.
    Check ($p -eq 'Lenovo|ThinkPad E14|PC|21234.56|365|0|Core i5, 8GB RAM, 512GB SSD') "product fields in DB ($p)"
    Nav "$Base/pages/product-form.php?id=1"
    Check (Eval "document.getElementById('trackSerial').disabled && document.body.textContent.includes('Serial tracking can only change when the product has no stock')") 'product with stock: Track serial numbers checkbox is locked'
    Nav "$Base/pages/product-form.php?id=1"
    Submit "const m = document.getElementById('productModel'); const o = m.querySelector('option[value=`"$probook`"]'); o.disabled = false; o.hidden = false; m.disabled = false; m.value = '$probook'; m.form.requestSubmit()" 'save model of another brand'
    Check ((Text '#err-model_id') -eq 'Choose a model of the selected brand.' -and (Sql 'SELECT model_id FROM products WHERE id = 1') -eq $thinkpad) "product: model of another brand rejected ($(Text '#err-model_id'))"
    Nav "$Base/pages/inventory.php?brand=$lenovo"
    Check ((Eval "[...document.querySelectorAll('#inventoryTable tbody tr')].filter(r => r.querySelector('[data-adjust]')).length") -eq 1 -and (Eval "document.querySelector('#inventoryTable tbody').textContent.includes('ThinkPad E14')")) 'inventory brand filter shows the Lenovo laptop with its model'
    Check (Eval "[...document.querySelectorAll('#inventoryTable th')].some(t => t.textContent.trim() === 'Unit cost') && document.querySelector('#inventoryTable tbody').textContent.includes('21,234.56')") 'admin (products.cost) sees the unit cost column'
    Nav "$Base/pages/master-data.php?list=brands"
    $r = Eval "fetch('$Base/pages/master-data.php?list=brands', {method: 'POST', body: new URLSearchParams({_csrf: document.querySelector('meta[name=csrf-token]').content, action: 'delete', id: '$lenovo', return: 'master-data.php?list=brands'})}).then(r => r.text()).then(t => t.includes('Deactivate it instead') ? 'blocked' : 'not blocked')"
    Check ($r -eq 'blocked' -and (Sql "SELECT COUNT(*) FROM brands WHERE id = $lenovo") -eq '1') "md: deleting brand used by a product is blocked ($r, rows $(Sql "SELECT COUNT(*) FROM brands WHERE id = $lenovo"))"

    # Customer with type + TIN + contact (MAR)
    Nav "$Base/pages/customer-form.php"
    Submit "const f = document.querySelector('[name=customer_type_id]').form; f.querySelector('[name=name]').value = 'DepEd Bukidnon'; f.querySelector('[name=phone]').value = '0921 555 0101'; f.customer_type_id.value = '2'; f.tin.value = '111-222-333-000'; f.querySelector('[name=`"contacts[0][name]`"]').value = 'Ana Ramos'; f.querySelector('[name=`"contacts[0][position]`"]').value = 'Supply Officer'; f.requestSubmit()" 'add customer'
    Check ((Text '.alert--success span') -eq 'DepEd Bukidnon was added.') "customer added ($(Text '.alert span'))"
    $c = Sql "SELECT CONCAT_WS('|', t.name, c.tin, cc.name, cc.position, (SELECT GROUP_CONCAT(branch_id) FROM customer_branches WHERE customer_id = c.id)) FROM customers c JOIN customer_types t ON t.id = c.customer_type_id JOIN customer_contacts cc ON cc.customer_id = c.id WHERE c.name = 'DepEd Bukidnon'"
    Check ($c -eq 'Government|111-222-333-000|Ana Ramos|Supply Officer|1') "customer type/TIN/contact/branch link in DB ($c)"
    Nav "$Base/pages/customers.php?type=2"
    Check ((Eval "[...document.querySelectorAll('#customersTable tbody .item-cell__name')].map(a => a.textContent.trim()).join('|')") -eq 'DepEd Bukidnon') 'customers type filter (Government) lists only DepEd Bukidnon'
    # Phase 5 follow-up a: editing at a branch keeps/creates the link for that branch
    Nav "$Base/pages/customer-form.php?id=1"
    Submit "const f = document.querySelector('[name=customer_type_id]').form; f.customer_type_id.value = '3'; f.requestSubmit()" 'edit customer 1'
    Check ((Text '.alert--success span') -eq 'Juan Dela Cruz was updated.' -and (Sql 'SELECT GROUP_CONCAT(branch_id ORDER BY branch_id) FROM customer_branches WHERE customer_id = 1') -eq '1') 'edit customer at MAR keeps exactly the MAR link'

    # Responsive (new / changed tables)
    Size 1024 900
    foreach ($pgUrl in 'master-data.php?list=models', 'suppliers.php', 'inventory.php', 'customers.php') {
        Nav "$Base/pages/$pgUrl"
        Check (Eval 'document.documentElement.scrollWidth <= window.innerWidth') "$pgUrl : no horizontal page scroll at 1024px"
    }
    Shot '31-master-data-1024'
    Size 1536 1024

    # Phase 5 follow-up b: a grant to an inactive branch survives a user save (CDO = 3 made inactive)
    [void](Sql "UPDATE branches SET is_active = 0 WHERE id = 3; INSERT IGNORE INTO user_branches (user_id, branch_id, granted_by) VALUES ($multiId, 3, 1)")
    Nav "$Base/pages/user-form.php?id=$multiId"
    Submit "document.getElementById('userForm').requestSubmit()" 'save davmulti unchanged'
    Check ((Text '.alert--success span') -like 'Mila Multi was updated.*' -and (Sql "SELECT GROUP_CONCAT(branch_id ORDER BY branch_id) FROM user_branches WHERE user_id = $multiId") -eq '1,3') "user save keeps the inactive-branch grant ($(Text '.alert span'))"
    [void](Sql 'UPDATE branches SET is_active = 1 WHERE id = 3')
    Logout

    # Cashier (MAR) and technician (DAV): no master data / suppliers, no unit cost anywhere
    Login 'cashier' 'cashier123'
    $st = "$(Status 'pages/master-data.php'),$(Status 'pages/master-data.php?list=brands'),$(Status 'pages/suppliers.php'),$(Status 'pages/supplier-form.php')"
    Check ($st -eq '403,403,403,403') "cashier: master-data / list / suppliers / supplier-form 403 ($st)"
    Check (Eval "![...document.querySelectorAll('.sidebar__nav .nav-link span')].some(s => s.textContent.trim() === 'Master Data')") 'cashier menu has no Master Data'
    Nav "$Base/pages/product-form.php?id=1"
    Check (Eval "!document.querySelector('[name=unit_cost]') && !document.documentElement.outerHTML.includes('21234.56') && !document.body.textContent.includes('21,234.56')") 'cashier product page: no unit cost field or value'
    Check (Eval "document.body.textContent.includes('ThinkPad E14')") 'cashier product page still shows brand/model (read-only)'
    Nav "$Base/pages/inventory.php"
    Check (Eval "![...document.querySelectorAll('#inventoryTable th')].some(t => t.textContent.trim() === 'Unit cost') && !document.body.textContent.includes('21,234.56')") 'cashier inventory: no unit cost column'
    $r = Eval "fetch('$Base/api/pos/products.php').then(r => r.text()).then(t => t.includes('unit_cost') || t.includes('21234') ? 'leak' : 'ok')"
    Check ($r -eq 'ok') "POS products API has no unit cost ($r)"
    $r = Eval "fetch('$Base/pages/master-data.php?list=brands', {method: 'POST', body: new URLSearchParams({_csrf: document.querySelector('meta[name=csrf-token]').content, action: 'save', name: 'Hacked'})}).then(r => r.status)"
    Check ($r -eq 403 -and (Sql "SELECT COUNT(*) FROM brands WHERE name = 'Hacked'") -eq '0') "cashier POST to master data 403 ($r)"
    Logout
    Login 'davtech' $script:pw 'job-orders.php'
    $st = "$(Status 'pages/master-data.php'),$(Status 'pages/master-data.php?list=units'),$(Status 'pages/suppliers.php'),$(Status 'pages/supplier-form.php')"
    Check ($st -eq '403,403,403,403') "technician: master-data / list / suppliers / supplier-form 403 ($st)"
    Nav "$Base/pages/product-form.php?id=1"
    Check (Eval "!document.querySelector('[name=unit_cost]') && !document.documentElement.outerHTML.includes('21234.56')") 'technician product page: no unit cost'
    Logout

    # Branch admin (DAV): suppliers yes, master-data lists no; has products.cost
    Login 'davadmin' $script:pw
    $st = "$(Status 'pages/suppliers.php'),$(Status 'pages/master-data.php?list=brands'),$(Status 'pages/master-data.php?list=categories')"
    Check ($st -eq '200,403,403') "branch admin: suppliers 200, master-data lists 403 ($st)"
    Nav "$Base/pages/master-data.php"
    Check (Eval "location.pathname.endsWith('/pages/suppliers.php') && !!document.querySelector('#suppliersTable tr[data-supplier=SUP-0001]')") 'branch admin: Master Data menu opens Suppliers (company-wide list)'
    Check (Eval "![...document.querySelectorAll('a')].some(a => a.href.includes('master-data.php?list='))") 'branch admin: no master-data list tabs'
    Shot '32-branch-admin-suppliers'
    Nav "$Base/pages/inventory.php"
    Check (Eval "[...document.querySelectorAll('#inventoryTable th')].some(t => t.textContent.trim() === 'Unit cost')") 'branch admin (products.cost) sees the unit cost column'
    # Phase 5 follow-up d: tampered role on update is refused
    $cashId = Sql "SELECT id FROM users WHERE username = 'davcash'"
    Nav "$Base/pages/user-form.php?id=$cashId"
    Submit "const f = document.getElementById('userForm'); const r = f.querySelector('[name=role][value=cashier]'); r.value = 'branch_admin'; r.checked = true; f.requestSubmit()" 'tampered role update'
    Check ((Sql "SELECT role FROM users WHERE id = $cashId") -eq 'cashier' -and (Eval "!document.querySelector('.alert--success')")) "branch admin cannot promote davcash to branch_admin ($(Text '.alert span'))"
    Logout
    Login 'admin' 'admin123'

    # --- Phase 7a: receiving, branch average cost, serials, POS serial picker, integrity (admin at MAR) ---
    $year = (Get-Date).Year
    $sup = Sql "SELECT id FROM suppliers WHERE code = 'SUP-0001'"
    Check ((Sql "SELECT COUNT(*) FROM permissions WHERE perm_key IN ('receiving.view','receiving.manage','receiving.post','receiving.cancel','serials.view','inventory.integrity')") -eq '6') 'permissions table has the 6 Phase 7a keys'
    # Serial-tracked product: opening stock must be 0
    Nav "$Base/pages/product-form.php"
    $fillTp = "const f = document.querySelector('[name=code]').form; f.code.value = 'ITM-9001'; f.querySelector('[name=name]').value = 'ThinkPad X1'; f.category_id.value = '1'; f.price.value = '45000'; f.unit_id.value = '1'; f.track_serial.checked = true; f.track_serial.dispatchEvent(new Event('change', {bubbles: true}));"
    Submit "$fillTp f.stock.removeAttribute('readonly'); f.stock.value = '5'; f.requestSubmit()" 'track product with opening stock'
    Check ((Text '#err-stock') -like 'Serial-tracked items start at 0*' -and (Sql "SELECT COUNT(*) FROM products WHERE code = 'ITM-9001'") -eq '0') "track product with opening stock 5 is rejected ($(Text '#err-stock'))"
    Nav "$Base/pages/product-form.php"
    Submit "$fillTp f.stock.value = '0'; f.requestSubmit()" 'track product'
    $tp = Sql "SELECT id FROM products WHERE code = 'ITM-9001' AND track_serial = 1 AND stock = 0"
    Check ((Text '.alert--success span') -eq 'ThinkPad X1 was added to the inventory.' -and $tp -ne '') "admin creates serial-tracked ThinkPad X1 with stock 0 (id $tp)"
    $r = PostForm 'pages/inventory.php' "action: 'adjust', id: '$tp', direction: 'add', quantity: '1', reason: 'restock', return: 'inventory.php'"
    Check ($r -like '*Serial-tracked items are added through Receiving.*' -and (Sql "SELECT stock FROM products WHERE id = $tp") -eq '0') 'adjust stock of a serial-tracked product is refused'
    Nav "$Base/pages/inventory.php?search=ITM-9001"
    Check (Eval "!document.querySelector('#inventoryTable [data-adjust]')") 'inventory: no Adjust button for the serial-tracked product'

    # RR-1 draft: Mouse 10 @ 100.00 + ThinkPad 3 @ 30000.00 with only 2 serials (a draft may hold fewer)
    Nav "$Base/pages/receiving.php"
    Check (Eval "!!document.getElementById('newRrBtn')") 'admin: Receiving list has New Receiving'
    $lines1 = "[[2, 10, '100.00', ''], [$tp, 3, '30000.00', 'sn-a1\nSN-A2']]"
    RrSubmit "$Base/pages/receiving-form.php" $sup $lines1 'save' 'rr1 draft'
    $rr1 = Sql 'SELECT MAX(id) FROM receiving_reports'
    Check ((Text '#rrTitle') -eq "Draft #$rr1" -and (Text '#rrStatus') -eq 'Draft' -and (Sql "SELECT CONCAT_WS('|', status, IFNULL(rr_no, 'null')) FROM receiving_reports WHERE id = $rr1") -eq 'draft|null') "RR draft saved without a number ($(Text '#rrTitle'))"
    Check ((Sql "SELECT GROUP_CONCAT(serial_no ORDER BY serial_no) FROM receiving_item_serials") -eq 'SN-A1,SN-A2') 'draft serials are stored trimmed + upper-case'
    Nav "$Base/pages/receiving.php"
    Check ((Text '.rr-table .rr-no') -eq "Draft #$rr1") 'receiving list shows the draft without RR number'
    $mouseQb = BranchQty 2; $mouseAvg = BranchAvg 2; $mouseStock = Sql 'SELECT stock FROM products WHERE id = 2'
    Nav "$Base/pages/receiving-view.php?id=$rr1"
    Submit "document.getElementById('rrPost').form.submit()" 'post rr1 with too few serials'
    Check ((Text '.alert--error span') -like '*serial*' -and (Sql "SELECT status FROM receiving_reports WHERE id = $rr1") -eq 'draft' -and (Sql "SELECT stock FROM products WHERE id = $tp") -eq '0') "post with serial count != qty is refused ($(Text '.alert span'))"
    RrSubmit "$Base/pages/receiving-form.php?id=$rr1" $sup "[[2, 10, '100.00', ''], [$tp, 3, '30000.00', 'SN-A1\nSN-A1\nSN-A2\nSN-A3']]" 'save' 'rr1 bad serials'
    Check ((Eval "location.pathname.endsWith('receiving-form.php')") -and (Eval "!!document.querySelector('[name=`"items[1][serials]`"][aria-invalid=true], .rr-serials .form-error')")) "duplicate / too many serials in a line -> field error ($(Text '.rr-serials .form-error'))"
    RrSubmit "$Base/pages/receiving-form.php?id=$rr1" $sup "[[2, 10, '100.00', ''], [$tp, 3, '30000.00', 'SN-A1\nSN-A2\nSN-A3']]" 'post' 'rr1 post'
    $rrNo1 = "RR-MAR-$year-000001"
    Check ((Text '#rrTitle') -eq $rrNo1 -and (Text '#rrStatus') -eq 'Posted') "RR posted through the form as $(Text '#rrTitle') ($(Text '.alert span'))"
    $st = Sql "SELECT CONCAT_WS('|', (SELECT stock FROM products WHERE id = 2), (SELECT stock FROM products WHERE id = $tp), (SELECT COUNT(*) FROM stock_movements WHERE type = 'receiving' AND receiving_id = $rr1), (SELECT COUNT(*) FROM product_serials WHERE product_id = $tp AND status = 'in_stock' AND branch_id = 1))"
    Check ($st -eq "$([int]$mouseStock + 10)|3|2|3") "post: Mouse +10, ThinkPad 3, 2 receiving movements with receiving_id, 3 serials in stock ($st)"
    $exp = ExpAvg $mouseQb $mouseAvg 10 '100.00'
    $avg = BranchAvg 2
    Check ($avg -eq $exp) "Mouse branch average = $exp (qty $mouseQb @ $mouseAvg + 10 @ 100.00) -> $avg"
    Check ((BranchAvg $tp) -eq '30000.0000') "ThinkPad branch average = 30000.0000 ($(BranchAvg $tp))"
    $r = PostForm 'pages/inventory.php' "action: 'delete', id: '$tp', return: 'inventory.php'"
    Check ($r -like '*has receiving history*' -and (Sql "SELECT COUNT(*) FROM products WHERE id = $tp") -eq '1') 'deleting a received (never sold) product is blocked: receiving history'
    # Duplicate serial on a later RR -> 409 on post, number not consumed
    RrSubmit "$Base/pages/receiving-form.php" $sup "[[$tp, 1, '29000.00', 'SN-A1']]" 'post' 'rr dup serial'
    $rrDup = Sql 'SELECT MAX(id) FROM receiving_reports'
    Check ((Text '.alert--error span') -like '*SN-A1*' -and (Sql "SELECT status FROM receiving_reports WHERE id = $rrDup") -eq 'draft') "already registered serial -> post refused ($(Text '.alert span'))"
    Submit "document.getElementById('rrDeleteForm').submit()" 'delete dup draft'
    Check ((Text '.alert--success span') -eq "Draft #$rrDup was deleted." -and (Sql "SELECT COUNT(*) FROM receiving_reports WHERE id = $rrDup") -eq '0') "draft deleted ($(Text '.alert span'))"
    Shot '33-receiving-view'

    # POS: picker, serial scan, stale serial, no cost in POS JSON / localStorage
    Nav "$Base/pages/pos.php"
    WaitFor "document.querySelectorAll('.product-card').length === 13" 'POS shows 13 products'
    $j = Eval "fetch('$Base/api/pos/products.php').then(r => r.text())"
    Check ($j -match '"track_serial":true' -and $j -match '"cost_cents"') 'POS products API: track_serial flag, admin (pos.view_cost) gets cost_cents'
    $j = Eval "fetch('$Base/api/pos/serials.php?product_id=$tp').then(r => r.text())"
    Check ($j -like '*SN-A1*' -and $j -notmatch '(?i)cost') "POS serials API lists serials, no cost ($($j.Length) chars)"
    ClickCard 'ThinkPad X1'
    WaitFor "document.getElementById('serialDialog').open && document.querySelectorAll('#serialList input').length === 3" 'serial picker'
    Check $true 'clicking a serial-tracked card opens the picker with 3 serials'
    Shot '34-serial-picker'
    [void](Eval "const b = document.querySelectorAll('#serialList input'); b[0].click(); b[1].click(); document.getElementById('serialForm').requestSubmit()")
    Check ((CartQty 'ThinkPad X1') -eq 2 -and (Eval "document.querySelectorAll('#cartBody .cart-sn__chip').length") -eq 2) 'picking 2 serials -> qty 2 with 2 S/N chips'
    $ls = Eval 'JSON.stringify(localStorage)'
    Check ($ls -like '*serial_no*' -and $ls -notmatch '(?i)cost') 'localStorage cart keeps serials, no cost'
    [void](Eval "document.activeElement.blur(); document.getElementById('btnSave').click()")
    [void](Eval "(() => { const a = document.getElementById('payAmount'); a.value = '100800'; a.dispatchEvent(new Event('input', {bubbles: true})); document.getElementById('payForm').requestSubmit(); })()")
    WaitFor "document.getElementById('doneDialog').open" 'serial sale done'
    $saleTp = Sql 'SELECT MAX(id) FROM sales'
    $st = Sql "SELECT CONCAT_WS('|', si.quantity, si.unit_cost, s.cost_total, (SELECT GROUP_CONCAT(ps.serial_no ORDER BY ps.serial_no) FROM sale_item_serials x JOIN product_serials ps ON ps.id = x.serial_id WHERE x.sale_item_id = si.id AND ps.status = 'sold')) FROM sales s JOIN sale_items si ON si.sale_id = s.id WHERE s.id = $saleTp"
    Check ($st -eq '2|30000.0000|60000.00|SN-A1,SN-A2') "serial sale: qty 2, unit_cost = avg, cost_total, serials sold ($st)"
    [void](Eval "document.getElementById('doneNew').click()")

    # Receipt + sale view (admin sees cost)
    Nav "$Base/pages/receipt.php?id=$saleTp"
    Check (Eval "document.body.textContent.includes('S/N: SN-A1, SN-A2') && !/cost/i.test(document.body.textContent)") 'receipt shows the S/N, no cost'
    Nav "$Base/pages/sale-view.php?id=$saleTp"
    Check ((Eval "[...document.querySelectorAll('th')].some(t => t.textContent.trim() === 'Cost') && document.body.textContent.includes('S/N SN-A1')") -and (Text '#saleCost') -eq ([string][char]0x20B1 + ' 60,000.00')) "admin sale view: S/N, Cost column, cost of items $(Text '#saleCost')"
    # Void the serial sale -> serials back in stock, average re-computed
    Nav "$Base/pages/sale-view.php?id=$saleTp"
    Submit "document.getElementById('voidBtn').click(); document.getElementById('voidReason').value = 'Serial test void'; document.querySelector('#voidDialog form').submit()" 'void serial sale'
    $st = Sql "SELECT CONCAT_WS('|', (SELECT status FROM sales WHERE id = $saleTp), (SELECT GROUP_CONCAT(CONCAT(serial_no, ':', status) ORDER BY serial_no) FROM product_serials WHERE product_id = $tp), (SELECT stock FROM products WHERE id = $tp))"
    Check ($st -eq 'cancelled|SN-A1:in_stock,SN-A2:in_stock,SN-A3:in_stock|3') "void: SN-A1/A2 back in stock, stock 3 ($st)"
    Check ((BranchAvg $tp) -eq '30000.0000') "void re-averages the ThinkPad at its sold cost ($(BranchAvg $tp))"
    # Exact serial scan, then a stale serial (sold elsewhere while in this cart) -> 409, dropped from the cart
    Nav "$Base/pages/pos.php"
    WaitFor "document.querySelectorAll('.product-card').length === 13" 'POS again'
    Key 'F2' 113
    TypeText 'sn-a3'; Key 'Enter' 13
    WaitFor "document.querySelectorAll('#cartBody .cart-sn__chip').length === 1" 'scanned serial added'
    Check ((CartQty 'ThinkPad X1') -eq 1 -and (Text '#cartBody .cart-sn__no') -eq 'SN-A3') 'scanning an exact serial adds that unit'
    $sid3 = Sql "SELECT id FROM product_serials WHERE serial_no = 'SN-A3'"
    $r = Eval "BB.api('pos/checkout.php', {method: 'POST', body: {items: [{product_id: $tp, qty: 1, serial_ids: [$sid3]}], customer_id: null, payment_type: 'cash', discount_percent: '0', amount_paid: '99999.00'}}).then(d => 'ok:' + d.sale.sale_no, e => e.status + ':' + e.message)"
    Check ($r -like 'ok:*') "SN-A3 sold elsewhere through the API ($r)"
    $r = Eval "BB.api('pos/checkout.php', {method: 'POST', body: {items: [{product_id: $tp, qty: 1, serial_ids: [$sid3]}], customer_id: null, payment_type: 'cash', discount_percent: '0', amount_paid: '99999.00'}}).then(d => 'ok', e => e.status + ':' + e.message + ':' + JSON.stringify((e.data || {}).problems || []))"
    Check ($r -like '409:*SN-A3*' -and $r -like "*`"serial_id`":$sid3*") "sold serial -> 409 with problems[].serial_id ($r)"
    $sales = Sql 'SELECT COUNT(*) FROM sales'
    [void](Eval "document.activeElement.blur(); document.getElementById('btnSave').click()")
    [void](Eval "(() => { const a = document.getElementById('payAmount'); a.value = '99999'; a.dispatchEvent(new Event('input', {bubbles: true})); document.getElementById('payForm').requestSubmit(); })()")
    WaitFor "document.querySelectorAll('#cartBody .cart-sn__chip').length === 0" 'stale serial dropped'
    Check ((CartQty 'ThinkPad X1') -eq 0 -and (Sql 'SELECT COUNT(*) FROM sales') -eq $sales) 'stale serial in the cart: checkout 409, serial dropped from the cart, no sale'
    [void](Eval "document.querySelectorAll('dialog[open]').forEach(d => d.close())")
    # Cancel RR-1 after later sales -> 409
    Nav "$Base/pages/receiving-view.php?id=$rr1"
    Submit "document.getElementById('cancelReason').value = 'Wrong delivery'; document.getElementById('cancelReason').form.submit()" 'cancel touched rr1'
    Check ((Text '.alert--error span') -like '*cannot be cancelled*' -and (Sql "SELECT status FROM receiving_reports WHERE id = $rr1") -eq 'posted') "cancel RR after a later sale is blocked ($(Text '.alert span'))"
    # Track flag locked while in stock
    Nav "$Base/pages/product-form.php?id=$tp"
    $r = Eval "(() => { const f = document.querySelector('[name=code]').form; const d = new FormData(f); d.delete('track_serial'); return fetch(f.action, {method: 'POST', body: d}).then(r => r.status); })()"
    Check ($r -eq 422 -and (Sql "SELECT track_serial FROM products WHERE id = $tp") -eq '1') "unticking track_serial while serials are in stock -> $r"

    # RR-2 (Mouse 5 @ 123.4567 + ThinkPad 1 SN-B1), then cancel it untouched -> stock, average and serial restored
    $mouseQb = BranchQty 2; $mouseAvg = BranchAvg 2; $mouseStock = Sql 'SELECT stock FROM products WHERE id = 2'; $tpAvg = BranchAvg $tp
    RrSubmit "$Base/pages/receiving-form.php" $sup "[[2, 5, '123.4567', ''], [$tp, 1, '31000.00', 'SN-B1']]" 'post' 'rr2 post'
    $rr2 = Sql 'SELECT MAX(id) FROM receiving_reports'
    Check ((Text '#rrTitle') -eq "RR-MAR-$year-000002") "second RR numbered $(Text '#rrTitle')"
    $exp = ExpAvg $mouseQb $mouseAvg 5 '123.4567'; $exp2 = ExpAvg 2 $tpAvg 1 '31000.00'
    Check ((BranchAvg 2) -eq $exp -and (BranchAvg $tp) -eq $exp2) "RR-2 averages: Mouse $(BranchAvg 2) (exp $exp), ThinkPad $(BranchAvg $tp) (exp $exp2)"
    Submit "document.getElementById('cancelReason').value = 'Entered twice'; document.getElementById('cancelReason').form.submit()" 'cancel rr2'
    $st = Sql "SELECT CONCAT_WS('|', (SELECT status FROM receiving_reports WHERE id = $rr2), (SELECT stock FROM products WHERE id = 2), (SELECT stock FROM products WHERE id = $tp), (SELECT COUNT(*) FROM product_serials WHERE serial_no = 'SN-B1'))"
    Check ($st -eq "cancelled|$mouseStock|2|0" -and (Text '#rrStatus') -eq 'Cancelled') "cancel untouched RR: stock back, SN-B1 removed ($st; $(Text '.alert span'))"
    Check ((BranchAvg 2) -eq $mouseAvg -and (BranchAvg $tp) -eq $tpAvg) "cancel restores averages (Mouse $(BranchAvg 2), ThinkPad $(BranchAvg $tp))"
    Logout

    # Cashier (MAR): no receiving, serial lookup only, no cost anywhere
    Login 'cashier' 'cashier123'
    $menu = Eval "[...document.querySelectorAll('.sidebar__nav .nav-link span')].map(s => s.textContent.trim()).join('|')"
    Check ($menu -like '*Serial Lookup*' -and $menu -notlike '*Receiving*') "cashier menu: Serial Lookup, no Receiving ($menu)"
    $st = "$(Status 'pages/receiving.php'),$(Status 'pages/receiving-form.php'),$(Status "pages/receiving-view.php?id=$rr1"),$(Status 'pages/stock-integrity.php'),$(Status 'pages/serials.php')"
    Check ($st -eq '403,403,403,403,200') "cashier: receiving list/form/view, integrity 403, serials 200 ($st)"
    $r = PostForm "pages/receiving-form.php" "action: 'save', supplier_id: '$sup'"
    Check ($r -like '403:*' -and (Sql 'SELECT COUNT(*) FROM receiving_reports') -eq '2') "cashier POST receiving draft 403 ($($r.Substring(0, 3)))"
    Nav "$Base/pages/sale-view.php?id=$saleTp"
    Check (Eval "![...document.querySelectorAll('th')].some(t => /cost|margin/i.test(t.textContent)) && !document.getElementById('saleCost') && document.body.textContent.includes('S/N SN-A1')") 'cashier sale view: S/N shown, no Cost / Margin'
    Nav "$Base/pages/pos.php"
    WaitFor "document.querySelectorAll('.product-card').length === 13" 'cashier POS'
    $j = Eval "Promise.all([fetch('$Base/api/pos/products.php').then(r => r.text()), fetch('$Base/api/pos/serials.php?product_id=$tp').then(r => r.text()), fetch('$Base/api/pos/serials.php?serial=SN-A1').then(r => r.text())]).then(a => a.join(' '))"
    Check ($j -like '*SN-A1*' -and ($j -replace '"view_cost":false', '') -notmatch '(?i)cost') 'cashier: POS products / serials / scan JSON have no cost'
    ClickCard 'ThinkPad X1'
    WaitFor "document.querySelectorAll('#serialList input').length === 2" 'cashier picker'
    [void](Eval "document.querySelector('#serialList input').click(); document.getElementById('serialForm').requestSubmit()")
    $ls = Eval 'JSON.stringify(localStorage)'
    Check ((CartQty 'ThinkPad X1') -eq 1 -and $ls -like '*serial_no*' -and $ls -notmatch '(?i)cost') 'cashier picks a serial; localStorage has no cost'
    Logout

    # DAV branch admin: MAR receiving + serials invisible
    Login 'davadmin' $script:pw
    $st = "$(Status "pages/receiving-view.php?id=$rr1"),$(Status "pages/receiving-form.php?id=$rr1"),$(Eval "BB.api('pos/serials.php?serial=SN-A1').then(() => 200, e => e.status)")"
    Check ($st -eq '404,404,404') "DAV admin: MAR RR view/form 404, MAR serial scan 404 ($st)"
    Nav "$Base/pages/serials.php?search=SN-A"
    Check (Eval "!document.body.textContent.includes('SN-A1')") 'DAV admin: serial lookup does not find MAR serials'
    Nav "$Base/pages/receiving.php"
    Check (Eval "!document.body.textContent.includes('RR-MAR-')") 'DAV admin: receiving list has no MAR reports'
    Logout
    Login 'admin' 'admin123'
    Nav "$Base/pages/serials.php?search=SN-A1"
    Check (Eval "document.body.textContent.includes('SN-A1') && document.body.textContent.includes('$rrNo1')") 'admin serial lookup finds SN-A1 with its RR'
    SwitchBranch 0
    RrSubmit "$Base/pages/receiving-form.php" $sup "[[2, 1, '100.00', '']]" 'save' 'rr all branches'
    Check ((Eval "document.body.textContent.includes('Choose a branch first')") -and (Sql 'SELECT COUNT(*) FROM receiving_reports') -eq '2') 'All branches: saving an RR -> Choose a branch first'
    Nav "$Base/pages/inventory.php"
    Nav (Eval "document.getElementById('integrityLink').href")
    Check ((Eval "document.getElementById('integritySummary').classList.contains('alert--success')") -and (Eval "document.querySelectorAll('.integrity-list .badge--danger').length") -eq 0) "stock integrity (All branches): $(Text '#integritySummary span')"
    Shot '35-stock-integrity'
    Size 1024 900
    foreach ($pgUrl in 'receiving.php', "receiving-view.php?id=$rr1", 'serials.php?search=SN', 'stock-integrity.php') {
        Nav "$Base/pages/$pgUrl"
        $wide = Eval "[...document.querySelectorAll('main *')].filter(e => e.getBoundingClientRect().right > window.innerWidth + 1).slice(-3).map(e => e.tagName + '.' + e.className + '#' + e.id + ':' + Math.round(e.getBoundingClientRect().right)).join(' ')"
        Check (Eval 'document.documentElement.scrollWidth <= window.innerWidth') "$pgUrl : no horizontal page scroll at 1024px (scrollWidth $(Eval 'document.documentElement.scrollWidth') $wide)"
    }
    Size 1536 1024
    SwitchBranch 1

    # --- Phase 7b: warehouses + locations, stock operations, counts, serial registration (MAR) ---
    $p7 = "'inventory.transfer','inventory.damage','inventory.issue','counts.create','counts.approve','warehouses.manage'"
    Check ((Sql "SELECT COUNT(*) FROM permissions WHERE perm_key IN ($p7)") -eq '6') 'permissions table has the 6 Phase 7b keys'
    $st = Sql "SELECT GROUP_CONCAT(x ORDER BY x) FROM (SELECT CONCAT(r.code, ':', COUNT(*)) x FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id WHERE p.perm_key IN ($p7) GROUP BY r.code) t"
    Check ($st -eq 'branch_admin:6') "7b keys granted to branch_admin only ($st)"
    $st = Sql "SELECT COUNT(*) FROM warehouses w WHERE NOT EXISTS (SELECT 1 FROM storage_locations l WHERE l.warehouse_id = w.id AND l.code = 'DAMAGED' AND l.kind = 'damaged') OR NOT EXISTS (SELECT 1 FROM storage_locations l WHERE l.warehouse_id = w.id AND l.code = 'DISPLAY' AND l.kind = 'display')"
    Check ($st -eq '0') "every warehouse has DAMAGED + DISPLAY ($st missing)"
    # 4 more ThinkPad units (SN-C1..C4) for display / issue / count
    RrSubmit "$Base/pages/receiving-form.php" $sup "[[$tp, 4, '30000.00', 'SN-C1\nSN-C2\nSN-C3\nSN-C4']]" 'post' 'rrC post'
    $rrC = Sql 'SELECT MAX(id) FROM receiving_reports'
    Check ((Sql "SELECT COUNT(*) FROM product_serials WHERE serial_no LIKE 'SN-C%' AND status = 'in_stock' AND location_id = 1") -eq '4') 'RR with SN-C1..C4 posted'
    $sn = @{}; foreach ($n in 'SN-C1', 'SN-C2', 'SN-C3', 'SN-C4') { $sn[$n] = Sql "SELECT id FROM product_serials WHERE serial_no = '$n'" }
    AddUser 'Mara Admin' 'maradmin' 'branch_admin' 1
    AddUser 'Marco Tech' 'martech' 'technician' 1
    Logout

    # Cashier / technician: no stock operations, no warehouses, API 403
    $denied = "pages/stock-docs.php|pages/stock-doc-form.php?type=transfer|pages/stock-doc-form.php?type=issue|pages/stock-doc-form.php?type=writeoff|pages/stock-doc-view.php?id=1|pages/warehouses.php|pages/serial-register.php?id=8|api/inventory/serials.php?product_id=2&location_id=1"
    foreach ($u in @(@('cashier', 'cashier123', 'pos.php'), @('martech', $script:pw, 'job-orders.php'))) {
        Login $u[0] $u[1] $u[2]
        $menu = Eval "[...document.querySelectorAll('.sidebar__nav .nav-link span')].map(s => s.textContent.trim()).join('|')"
        Check ($menu -notlike '*Stock Operations*') "$($u[0]) menu: no Stock Operations ($menu)"
        $st = ($denied.Split('|') | ForEach-Object { Status $_ }) -join ','
        Check ($st -eq '403,403,403,403,403,403,403,403') "$($u[0]): stock docs list/forms/view, warehouses, serial-register, inventory serials API 403 ($st)"
        $r1 = PostForm 'pages/stock-doc-form.php?type=transfer' "from_location_id: '1', to_location_id: '6', reason: 'hack', 'items[0][product_id]': '2', 'items[0][quantity]': '1'"
        $r2 = PostForm 'pages/warehouses.php' "action: 'save_warehouse', code: 'HACK', name: 'Hack'"
        $r3 = PostForm 'pages/stock-docs.php' "action: 'count_create', location_id: '1'"
        $r4 = PostForm "pages/serial-register.php?id=8" "'serials[1]': 'X1'"
        $st = "$($r1.Substring(0, 3)),$($r2.Substring(0, 3)),$($r3.Substring(0, 3)),$($r4.Substring(0, 3))"
        Check ($st -eq '403,403,403,403' -and (Sql 'SELECT COUNT(*) FROM inventory_docs') -eq '0' -and (Sql "SELECT COUNT(*) FROM warehouses WHERE code = 'HACK'") -eq '0') "$($u[0]): POST transfer / warehouse / count / serial register 403 ($st)"
        Logout
    }

    # MAR branch admin: warehouses + locations
    Login 'maradmin' $script:pw
    $menu = Eval "[...document.querySelectorAll('.sidebar__nav .nav-link span')].map(s => s.textContent.trim()).join('|')"
    Check ($menu -like '*Inventory|Branch Prices|Stock Operations|Branch Transfers|Serial Lookup*') "MAR branch admin menu has Stock Operations ($menu)"
    Nav "$Base/pages/warehouses.php"
    Check ((Eval "[...document.querySelectorAll('.wh-card[data-warehouse=MAIN] tr[data-location]')].map(r => r.dataset.location).sort().join(',')") -eq 'DAMAGED,DISPLAY,GENERAL') 'warehouses tab: MAIN with GENERAL / DAMAGED / DISPLAY'
    $r = Eval "fetch('$Base/pages/warehouses.php', {method: 'POST', body: new URLSearchParams({action: 'save_warehouse', code: 'NOCSRF', name: 'No token'})}).then(r => r.status)"
    Check ($r -eq 403 -and (Sql "SELECT COUNT(*) FROM warehouses WHERE code = 'NOCSRF'") -eq '0') "warehouse POST without CSRF -> $r"
    [void](Eval "document.getElementById('addWarehouse').click()")
    Submit "const f = document.getElementById('whForm'); f.querySelector('[name=code]').value = 'wh2'; f.querySelector('[name=name]').value = 'Back Storage'; f.requestSubmit()" 'add WH2'
    $wh2 = Sql "SELECT id FROM warehouses WHERE code = 'WH2' AND branch_id = 1"
    $st = Sql "SELECT GROUP_CONCAT(CONCAT(code, ':', kind, ':', is_default) ORDER BY code) FROM storage_locations WHERE warehouse_id = '$wh2'"
    Check ($wh2 -ne '' -and $st -eq 'DAMAGED:damaged:0,DISPLAY:display:0,GENERAL:stock:1') "add warehouse WH2 -> $st ($(Text '.alert span'))"
    [void](Eval "document.getElementById('addWarehouse').click()")
    Submit "const f = document.getElementById('whForm'); f.querySelector('[name=code]').value = 'WH2'; f.querySelector('[name=name]').value = 'Again'; f.requestSubmit()" 'dup WH2'
    Check ((Eval "document.getElementById('whDialog').open") -and (Text '#whForm #err-code') -like '*already uses this code*' -and (Sql "SELECT COUNT(*) FROM warehouses WHERE code = 'WH2'") -eq '1') "duplicate warehouse code -> dialog error ($(Text '#whForm #err-code'))"
    Nav "$Base/pages/warehouses.php"
    [void](Eval "document.querySelector('[data-loc-new][data-warehouse-id=`"$wh2`"]').click()")
    Check (Eval "document.getElementById('locDialog').open") 'Add Location dialog opens'
    Submit "const f = document.getElementById('locForm'); f.querySelector('[name=code]').value = 'BIN-A'; f.querySelector('[name=name]').value = 'Shelf A'; f.requestSubmit()" 'add BIN-A'
    $binA = Sql "SELECT id FROM storage_locations WHERE warehouse_id = $wh2 AND code = 'BIN-A' AND kind = 'stock' AND is_default = 0 AND is_sellable = 0"
    Check ($binA -ne '') "location BIN-A added to WH2 (id $binA; $(Text '.alert span'))"
    [void](Eval "document.querySelector('[data-loc-new][data-warehouse-id=`"$wh2`"]').click()")
    Submit "const f = document.getElementById('locForm'); f.querySelector('[name=code]').value = 'bin-a'; f.querySelector('[name=name]').value = 'Shelf A2'; f.requestSubmit()" 'dup BIN-A'
    Check ((Eval "document.getElementById('locDialog').open") -and (Text '#locForm #err-code') -like '*already uses this code*') "duplicate location code -> dialog error ($(Text '#locForm #err-code'))"
    Nav "$Base/pages/warehouses.php"
    [void](Eval "document.querySelector('[data-loc-new][data-warehouse-id=`"$wh2`"]').click()")
    Submit "const f = document.getElementById('locForm'); f.querySelector('[name=code]').value = 'DISPLAY'; f.querySelector('[name=name]').value = 'Fake'; f.requestSubmit()" 'reserved code'
    Check ((Text '#locForm #err-code') -like '*reserved*') "reserved code DISPLAY refused ($(Text '#locForm #err-code'))"
    Nav "$Base/pages/warehouses.php"
    [void](Eval "document.querySelector('.wh-card[data-warehouse=MAIN] tr[data-location=DAMAGED] [data-loc-edit]').click()")
    Submit "const f = document.getElementById('locForm'); f.querySelector('[name=name]').value = 'Damaged Units'; f.requestSubmit()" 'rename DAMAGED'
    Check ((Sql "SELECT CONCAT(code, '|', name) FROM storage_locations WHERE id = 6") -eq 'DAMAGED|Damaged Units') "rename system location DAMAGED ok ($(Text '.alert span'))"
    Check (Eval "!document.querySelector('.wh-card[data-warehouse=MAIN] tr[data-location=DAMAGED] [data-act=toggle]')") 'DAMAGED has no Deactivate button'
    $r = PostForm 'pages/warehouses.php' "action: 'toggle', type: 'location', id: '6', active: '0'"
    Check ($r -like '*system location*' -and (Sql 'SELECT is_active FROM storage_locations WHERE id = 6') -eq '1') 'deactivate DAMAGED (POST) refused: system location'
    $r = PostForm 'pages/warehouses.php' "action: 'toggle', type: 'location', id: '1', active: '0'"
    Check ($r -like '*default location*' -and (Sql 'SELECT is_active FROM storage_locations WHERE id = 1') -eq '1') 'deactivate the POS location refused'
    Shot '36-warehouses'

    # Transfer 3 Mouse GENERAL -> WH2/BIN-A
    $mStock = PStock 2; $mQb = BranchQty 2; $mAvg = BranchAvg 2; $mGen = [int](LocQty 2 1)
    DocPost 'type=transfer&preset=move' '1' $binA '' '[[2, 3, null]]' 'transfer'
    $trf = Sql 'SELECT MAX(id) FROM inventory_docs'
    Check ((Text '#docTitle') -eq "TRF-MAR-$year-000001" -and (Text '#docStatus') -eq 'Posted') "transfer posted as $(Text '#docTitle') ($(Text '.alert span'))"
    $st = Sql "SELECT CONCAT_WS('|', COUNT(*), SUM(quantity), SUM(type = 'transfer')) FROM stock_movements WHERE inventory_doc_id = $trf"
    Check ($st -eq '2|0|2') "transfer: 2 'transfer' movements, net 0 ($st)"
    $st = "$(PStock 2)|$(BranchQty 2)|$(BranchAvg 2)|$(LocQty 2 1)|$(LocQty 2 $binA)"
    Check ($st -eq "$mStock|$mQb|$mAvg|$($mGen - 3)|3") "transfer: company + branch qty and avg unchanged, GENERAL -3, BIN-A 3 ($st)"
    Nav "$Base/pages/inventory.php?location=$binA&search=ITM-0002"
    $st = Eval "(() => { const r = [...document.querySelectorAll('#inventoryTable tbody tr')].find(tr => tr.textContent.includes('ITM-0002')); return [...document.querySelectorAll('#inventoryTable th')].some(t => t.textContent.trim() === 'Stock here') + '|' + (r ? [...r.querySelectorAll('.badge')].map(b => b.textContent.trim()).join(',') : 'none') + '|' + document.getElementById('stockScope').textContent.trim(); })()"
    Check ($st -like 'true|*3*|*WH2 / BIN-A*') "inventory Location filter: Stock here = 3 at WH2 / BIN-A ($st)"
    Nav "$Base/pages/product-form.php?id=2"
    $st = Eval "document.getElementById('stockByLocation')?.textContent.replace(/\s+/g, ' ').trim() || ''"
    Check ($st -like '*WH2 / BIN-A*' -and $st -like '*MAIN / GENERAL*') "product page: Stock by location lists GENERAL and BIN-A ($st)"

    # Mark damaged (reason required), write-off from DAMAGED
    $docs0 = Sql 'SELECT COUNT(*) FROM inventory_docs'
    $r = PostForm 'pages/stock-doc-form.php?type=transfer&preset=damage' "from_location_id: '1', to_location_id: '6', reason: '', 'items[0][product_id]': '2', 'items[0][quantity]': '1'"
    Check ($r -like '*id="err-reason"*' -and (Sql 'SELECT COUNT(*) FROM inventory_docs') -eq $docs0) 'mark damaged without reason -> reason error, no document'
    $mGen = [int](LocQty 2 1); $mStock = PStock 2
    DocPost 'type=transfer&preset=damage' '1' '6' 'Cracked case' '[[2, 1, null]]' 'damage'
    Check ((Text '#docTitle') -eq "TRF-MAR-$year-000002" -and (Sql "SELECT purpose FROM inventory_docs WHERE doc_no = 'TRF-MAR-$year-000002'") -eq 'damage') "mark damaged posted ($(Text '#docTitle'), purpose damage)"
    $pos = Eval "fetch('$Base/api/pos/products.php').then(r => r.json()).then(d => d.products.find(p => Number(p.id) === 2).stock)"
    Check ("$(LocQty 2 1)|$(LocQty 2 6)|$(PStock 2)|$pos" -eq "$($mGen - 1)|1|$mStock|$($mGen - 1)") "damaged: POS stock $pos (GENERAL -1), DAMAGED 1, company stock unchanged"
    $mAvg = BranchAvg 2
    DocPost 'type=writeoff' '6' '' 'Beyond repair' '[[2, 1, null]]' 'writeoff'
    $wof = Sql 'SELECT MAX(id) FROM inventory_docs'
    Check ((Text '#docTitle') -eq "WOF-MAR-$year-000001") "write-off posted as $(Text '#docTitle') ($(Text '.alert span'))"
    $st = Sql "SELECT CONCAT_WS('|', l.quantity, l.adjust_qty, l.unit_cost, (SELECT GROUP_CONCAT(CONCAT(type, ':', quantity)) FROM stock_movements WHERE inventory_doc_id = d.id), d.total_qty) FROM inventory_docs d JOIN inventory_doc_lines l ON l.doc_id = d.id WHERE d.id = $wof"
    Check ($st -eq "1|-1|$mAvg|write_off:-1|1" -and (PStock 2) -eq "$([int]$mStock - 1)" -and (LocQty 2 6) -eq '0' -and (BranchAvg 2) -eq $mAvg) "write-off: stock -1, unit cost = branch avg $mAvg, avg unchanged ($st)"
    Check (Eval "!!document.getElementById('docTotalCost') && [...document.querySelectorAll('th')].some(t => t.textContent.trim() === 'Unit Cost')") 'branch admin (products.cost) sees the write-off cost'

    # Display + restore of a serial unit (SN-C1)
    $r = Eval "fetch('$Base/api/inventory/serials.php?product_id=$tp&location_id=1').then(r => r.text())"
    Check ($r -like '*"ok":true*' -and $r -like '*SN-C1*' -and $r -notmatch '(?i)cost') "inventory serials API: qty + serials, no cost ($($r.Length) chars)"
    $r = Eval "BB.api('inventory/serials.php?product_id=2&location_id=4').then(() => 200, e => e.status)"
    Check ($r -eq 404) "inventory serials API: DAV location from MAR -> $r"
    DocPost 'type=transfer&preset=display' '1' '7' 'Demo unit front counter' "[[$tp, 1, ['SN-C1']]]" 'display'
    Check ((Text '#docTitle') -eq "TRF-MAR-$year-000003" -and (Sql "SELECT CONCAT(location_id, '|', status) FROM product_serials WHERE serial_no = 'SN-C1'") -eq '7|in_stock') "display unit: SN-C1 moved to DISPLAY ($(Text '#docTitle'); $(Text '.alert span'))"
    $j = Eval "fetch('$Base/api/pos/serials.php?product_id=$tp').then(r => r.text())"
    Check ($j -notlike '*SN-C1*' -and $j -like '*SN-C3*') 'POS serial picker excludes the display unit'
    $r = Eval "BB.api('pos/checkout.php', {method: 'POST', body: {items: [{product_id: $tp, qty: 1, serial_ids: [$($sn['SN-C1'])]}], customer_id: null, payment_type: 'cash', discount_percent: '0', amount_paid: '99999.00'}}).then(d => 'ok', e => e.status + ':' + e.message)"
    Check ($r -like '409:*') "POS checkout with the display serial -> $r"
    $r = PostForm 'pages/stock-doc-form.php?type=transfer&preset=restore' "from_location_id: '6', to_location_id: '7', reason: 'x', 'items[0][product_id]': '2', 'items[0][quantity]': '1'"
    Check ($r -like '*can only go back to a stock location*' -and (Sql "SELECT location_id FROM product_serials WHERE serial_no = 'SN-C1'") -eq '7') 'damaged -> display refused'
    DocPost 'type=transfer&preset=restore' '7' '1' '' "[[$tp, 1, ['SN-C1']]]" 'restore'
    Check ((Text '#docTitle') -eq "TRF-MAR-$year-000004" -and (Sql "SELECT location_id FROM product_serials WHERE serial_no = 'SN-C1'") -eq '1') "restore: SN-C1 back at GENERAL ($(Text '#docTitle'))"

    # Internal use of a serial unit
    $docs0 = Sql 'SELECT COUNT(*) FROM inventory_docs'
    $r = PostForm 'pages/stock-doc-form.php?type=issue' "from_location_id: '1', reason: 'Service laptop', 'items[0][product_id]': '$tp', 'items[0][quantity]': '2', 'items[0][serial_ids][]': '$($sn['SN-C2'])'"
    Check ($r -like '*Choose exactly 2 serial numbers*' -and (Sql 'SELECT COUNT(*) FROM inventory_docs') -eq $docs0) 'issue: serial count != qty refused'
    $tStock = PStock $tp; $tAvg = BranchAvg $tp
    DocPost 'type=issue' '1' '' 'Laptop for the service technician' "[[$tp, 1, ['SN-C2']]]" 'issue'
    $iss = Sql 'SELECT MAX(id) FROM inventory_docs'
    $st = Sql "SELECT CONCAT_WS('|', (SELECT status FROM product_serials WHERE serial_no = 'SN-C2'), l.unit_cost, (SELECT GROUP_CONCAT(CONCAT(type, ':', quantity)) FROM stock_movements WHERE inventory_doc_id = d.id), (SELECT COUNT(*) FROM inventory_doc_serials WHERE line_id = l.id)) FROM inventory_docs d JOIN inventory_doc_lines l ON l.doc_id = d.id WHERE d.id = $iss"
    Check ((Text '#docTitle') -eq "ISS-MAR-$year-000001" -and $st -eq "removed|$tAvg|issue:-1|1" -and (PStock $tp) -eq "$([int]$tStock - 1)") "internal use $(Text '#docTitle'): serial removed, cost snapshot, stock -1 ($st)"
    $r = PostForm 'pages/stock-doc-form.php?type=issue' "from_location_id: '1', reason: 'Again', 'items[0][product_id]': '$tp', 'items[0][quantity]': '1', 'items[0][serial_ids][]': '$($sn['SN-C2'])'"
    Check ($r -like '*Serial SN-C2 is no longer at*' -and (Sql 'SELECT COUNT(*) FROM inventory_docs') -eq "$([int]$docs0 + 1)") 'issue of a removed serial refused'

    # Count at BIN-A by the branch admin: second open count 409, own approval refused, cancel
    Nav "$Base/pages/stock-docs.php"
    Check ((Eval "document.querySelectorAll('#opTiles .op-tile').length") -eq 7 -and (Eval "document.querySelectorAll('#docTable tbody tr .doc-no').length") -eq 6) 'stock operations: 7 New tiles, 6 documents listed'
    Shot '37-stock-docs'
    [void](Eval "document.getElementById('newCountBtn').click()")
    Check (Eval "document.getElementById('countDialog').open") 'New Count dialog opens'
    Submit "const f = document.getElementById('countForm'); f.location_id.value = '$binA'; f.requestSubmit()" 'count BIN-A'
    $cnt2 = Sql 'SELECT MAX(id) FROM inventory_docs'
    Check ((Text '#docTitle') -eq "CNT-MAR-$year-000001" -and (Text '#docStatus') -eq 'Open' -and (Sql "SELECT GROUP_CONCAT(CONCAT(product_id, ':', system_qty)) FROM inventory_doc_lines WHERE doc_id = $cnt2") -eq '2:3') "count created $(Text '#docTitle') with frozen Mouse 3"
    $r = PostForm 'pages/stock-docs.php' "action: 'count_create', location_id: '$binA'"
    Check ($r -like '*is still open at*' -and (Sql "SELECT COUNT(*) FROM inventory_docs WHERE doc_type = 'count'") -eq '1') 'second open count at the same location refused'
    $r = PostForm 'pages/warehouses.php' "action: 'toggle', type: 'location', id: '$binA', active: '0'"
    Check ($r -like '*Move its stock first*' -and (Sql "SELECT is_active FROM storage_locations WHERE id = $binA") -eq '1') 'deactivate BIN-A with stock refused'
    Nav "$Base/pages/stock-doc-view.php?id=$cnt2"
    [void](Eval "document.querySelector('[data-counted]').value = '5'")
    Submit "window.confirm = () => true; document.getElementById('submitCountBtn').click()" 'submit count BIN-A'
    Check ((Text '#docStatus') -eq 'Submitted' -and (Eval "!document.getElementById('approveBtn')")) "count submitted; creator has no Approve button ($(Text '.alert span'))"
    $r = PostForm "pages/stock-doc-view.php?id=$cnt2" "action: 'approve'"
    Check ($r -like '*approve a count you created or counted*' -and (Sql "SELECT status FROM inventory_docs WHERE id = $cnt2") -eq 'submitted') 'branch admin approving own count refused'
    Nav "$Base/pages/stock-doc-view.php?id=$cnt2"
    [void](Eval "document.getElementById('cancelCountBtn').click()")
    Submit "window.confirm = () => true; document.getElementById('cancelReason').value = 'Wrong location'; document.getElementById('cancelReason').form.requestSubmit()" 'cancel count'
    Check ((Text '#docStatus') -eq 'Cancelled' -and (LocQty 2 $binA) -eq '3' -and (Sql "SELECT COUNT(*) FROM stock_movements WHERE inventory_doc_id = $cnt2") -eq '0') "cancelled count leaves stock unchanged ($(Text '.alert span'))"
    Logout

    # Count at GENERAL by the super admin, POS sale during the count, approval by the branch admin
    Login 'admin' 'admin123'
    Nav "$Base/pages/stock-docs.php"
    [void](Eval "document.getElementById('newCountBtn').click()")
    Submit "const f = document.getElementById('countForm'); f.location_id.value = '1'; f.category_id.value = '1'; f.requestSubmit()" 'count GENERAL'
    $cnt1 = Sql 'SELECT MAX(id) FROM inventory_docs'
    $lapF = [int](Sql "SELECT system_qty FROM inventory_doc_lines WHERE doc_id = $cnt1 AND product_id = 1")
    $st = Sql "SELECT COUNT(*) FROM inventory_doc_serials s JOIN inventory_doc_lines l ON l.id = s.line_id WHERE l.doc_id = $cnt1 AND s.found = 0"
    Check ((Text '#docTitle') -eq "CNT-MAR-$year-000002" -and $lapF -eq [int](LocQty 1 1) -and $st -eq '5') "count $(Text '#docTitle'): Laptop frozen at $lapF, 5 expected ThinkPad serials ($st)"
    $r = ApiSale 1
    Check ($r -like 'ok:*' -and [int](LocQty 1 1) -eq $lapF - 1) "POS sale of a Laptop during the count ($r)"
    Nav "$Base/pages/stock-doc-view.php?id=$cnt1"
    [void](Eval "document.querySelectorAll('tr[data-count-line]').forEach(r => { const i = r.querySelector('[data-counted]'); if (i) i.value = r.textContent.includes('ITM-0001') ? String(Number(r.dataset.system) - 2) : r.dataset.system; r.querySelectorAll('[data-found]').forEach(c => { c.checked = c.closest('label').textContent.trim() !== 'SN-C4'; }); })")
    Submit "window.confirm = () => true; document.getElementById('submitCountBtn').click()" 'submit count GENERAL'
    Check ((Text '#docStatus') -eq 'Submitted') "count submitted by the super admin ($(Text '.alert span'))"
    $r = PostForm "pages/stock-doc-view.php?id=$cnt1" "action: 'approve'"
    Check ($r -like '*approve a count you created or counted*' -and (Sql "SELECT status FROM inventory_docs WHERE id = $cnt1") -eq 'submitted') 'super admin approving own count refused'
    Logout
    Login 'maradmin' $script:pw
    Nav "$Base/pages/stock-doc-view.php?id=$cnt1"
    [void](Eval "document.getElementById('approveBtn').click()")
    Check ((Eval "document.getElementById('approveDialog').open") -and (Eval "document.querySelectorAll('#approveTable tbody tr').length") -eq 2) 'approve dialog lists the 2 differences'
    Shot '38-count-approve'
    Submit "document.getElementById('approveSubmit').click()" 'approve count'
    $st = Sql "SELECT CONCAT_WS('|', d.status, d.posted_by = (SELECT id FROM users WHERE username = 'maradmin'), (SELECT GROUP_CONCAT(CONCAT(product_id, ':', type, ':', quantity) ORDER BY product_id) FROM stock_movements WHERE inventory_doc_id = d.id), (SELECT status FROM product_serials WHERE serial_no = 'SN-C4'), d.total_qty) FROM inventory_docs d WHERE d.id = $cnt1"
    Check ($st -eq "posted|1|1:count:-2,${tp}:count:-1|removed|3") "approve: Laptop -2 (counted - frozen), SN-C4 removed ($st; $(Text '.alert span'))"
    Check ([int](LocQty 1 1) -eq $lapF - 3) "Laptop at GENERAL = frozen $lapF - 1 sold - 2 = $(LocQty 1 1)"
    Nav "$Base/pages/serials.php?search=SN-C4"
    Check (Eval "document.body.textContent.includes('CNT-MAR-$year-000002')") 'serial lookup: SN-C4 shows the count as last stock document'

    # Empty BIN-A, then deactivate it
    DocPost 'type=transfer&preset=move' $binA '1' 'Back to the shelf' '[[2, 3, null]]' 'transfer back'
    Nav "$Base/pages/warehouses.php"
    Submit "window.confirm = () => true; document.querySelector('tr[data-location=BIN-A] [data-act=toggle]').closest('form').requestSubmit()" 'deactivate BIN-A'
    Check ((Sql "SELECT is_active FROM storage_locations WHERE id = $binA") -eq '0') "empty BIN-A deactivated ($(Text '.alert span'))"
    Nav "$Base/pages/stock-doc-form.php?type=transfer"
    $a = Eval "!document.querySelector('#fromLocation option[value=`"$binA`"], #toLocation option[value=`"$binA`"]')"
    Nav "$Base/pages/inventory.php"
    Check ($a -and (Eval "!!document.getElementById('locationFilter') && !document.querySelector('#locationFilter option[value=`"$binA`"]')")) 'inactive BIN-A is gone from the pickers and the Location filter'
    Logout

    # Technician granted inventory.issue (no products.cost): can issue, sees no cost
    [void](Sql "INSERT INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r JOIN permissions p ON p.perm_key = 'inventory.issue' WHERE r.code = 'technician'")
    Login 'martech' $script:pw 'job-orders.php'
    DocPost 'type=issue' '1' '' 'Office keyboard' '[[3, 1, null]]' 'tech issue'
    $issT = Sql 'SELECT MAX(id) FROM inventory_docs'
    Check ((Text '#docTitle') -eq "ISS-MAR-$year-000002" -and (Sql "SELECT total_cost IS NOT NULL FROM inventory_docs WHERE id = $issT") -eq '1') "technician with inventory.issue posts $(Text '#docTitle')"
    Check (Eval "!document.getElementById('docTotalCost') && ![...document.querySelectorAll('th')].some(t => /cost|value/i.test(t.textContent))") 'technician (no products.cost): no cost on the document'
    Nav "$Base/pages/stock-docs.php"
    Check (Eval "![...document.querySelectorAll('#docTable th')].some(t => t.textContent.trim() === 'Value') && document.querySelectorAll('#opTiles .op-tile').length === 2") 'technician list: no Value column, only Display / Internal Use tiles'
    $r = PostForm 'pages/stock-doc-form.php?type=writeoff' "from_location_id: '1', reason: 'hack', 'items[0][product_id]': '3', 'items[0][quantity]': '1'"
    Check ($r -like '403:*') "technician write-off (no inventory.damage) -> $($r.Substring(0, 3))"
    Logout
    [void](Sql "DELETE rp FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id WHERE r.code = 'technician' AND p.perm_key = 'inventory.issue'")

    # DAV branch admin: MAR documents / warehouses / locations are 404
    Login 'davadmin' $script:pw
    $st = "$(Status "pages/stock-doc-view.php?id=$trf"),$(Status "pages/stock-doc-view.php?id=$cnt1")"
    Check ($st -eq '404,404') "DAV admin: MAR transfer / count 404 ($st)"
    $r = PostForm 'pages/warehouses.php' "action: 'save_warehouse', id: '$wh2', name: 'Hacked'"
    $r2 = PostForm 'pages/warehouses.php' "action: 'toggle', type: 'location', id: '$binA', active: '1'"
    Check ($r -like '*not found*' -and (Sql "SELECT name FROM warehouses WHERE id = $wh2") -eq 'Back Storage' -and (Sql "SELECT is_active FROM storage_locations WHERE id = $binA") -eq '0') 'DAV admin: renaming the MAR warehouse / activating its location -> not found'
    $r = PostForm 'pages/stock-doc-form.php?type=transfer' "from_location_id: '1', to_location_id: '$binA', 'items[0][product_id]': '2', 'items[0][quantity]': '1'"
    Check ($r -like '*Choose an active location of this branch*' -and (Sql 'SELECT COUNT(*) FROM inventory_docs WHERE branch_id = 4') -eq '0') 'DAV admin: transfer between MAR locations refused'
    Nav "$Base/pages/stock-docs.php"
    Check (Eval "!document.body.textContent.includes('-MAR-')") 'DAV admin: stock operations list has no MAR documents'
    Nav "$Base/pages/warehouses.php"
    Check (Eval "!document.querySelector('[data-warehouse=WH2]') && !!document.querySelector('[data-warehouse=MAIN]')") 'DAV admin: warehouses tab shows only DAV'
    Logout

    # Super admin: RR cancel after a transfer, serial registration
    Login 'admin' 'admin123'
    Nav "$Base/pages/receiving-view.php?id=$rrC"
    Submit "document.getElementById('cancelReason').value = 'Wrong delivery'; document.getElementById('cancelReason').form.submit()" 'cancel rrC'
    Check ((Text '.alert--error span') -like '*cannot be cancelled*' -and (Sql "SELECT status FROM receiving_reports WHERE id = $rrC") -eq 'posted') "cancel RR after a transfer of its serials is blocked ($(Text '.alert span'))"
    $pq = Sql "SELECT GROUP_CONCAT(CONCAT(location_id, ':', qty)) FROM stock_balances WHERE product_id = 8 AND qty > 0"
    $pn = [int](LocQty 8 1); $mv = Sql 'SELECT COUNT(*) FROM stock_movements WHERE product_id = 8'
    Nav "$Base/pages/product-form.php?id=8"
    Check ($pq -eq "1:$pn" -and (Eval "!!document.getElementById('registerSerialsBtn')")) "Printer ($pq): Register Serials button"
    Nav (Eval "document.getElementById('registerSerialsBtn').href")
    Submit "window.confirm = () => true; document.querySelector('[name=`"serials[1]`"]').value = Array.from({length: $($pn - 1)}, (_, i) => 'PRN-' + (i + 1)).join('\n'); document.getElementById('registerForm').requestSubmit()" 'register short'
    Check ((Sql 'SELECT CONCAT(track_serial, (SELECT COUNT(*) FROM product_serials WHERE product_id = 8)) FROM products WHERE id = 8') -eq '00' -and (Eval "!!document.querySelector('.alert--error')")) "register $($pn - 1) serials for $pn units refused ($(Text '.alert span'))"
    Submit "window.confirm = () => true; document.querySelector('[name=`"serials[1]`"]').value = Array.from({length: $pn}, (_, i) => 'prn-' + (i + 1)).join('\n'); document.getElementById('registerForm').requestSubmit()" 'register exact'
    $st = Sql "SELECT CONCAT_WS('|', track_serial, (SELECT COUNT(*) FROM product_serials WHERE product_id = 8 AND status = 'in_stock' AND location_id = 1 AND serial_no LIKE 'PRN-%'), (SELECT COUNT(*) FROM stock_movements WHERE product_id = 8), stock) FROM products WHERE id = 8"
    Check ($st -eq "1|$pn|$mv|$pn" -and (Eval "!document.getElementById('registerSerialsBtn')")) "register $pn serials: tracking on, serials in stock, no movement ($st; $(Text '.alert span'))"
    Nav "$Base/pages/serial-register.php?id=11"
    Check (Eval "!!document.getElementById('registerScopeWarning') && document.getElementById('registerBtn').disabled") 'Webcam (stock at MAR + DAV) from MAR: scope warning, button disabled'
    $r = PostForm 'pages/serial-register.php?id=11' "'serials[1]': 'W1'"
    Check ($r -like '*Switch to All branches*' -and (Sql 'SELECT track_serial FROM products WHERE id = 11') -eq '0') 'Webcam registration from MAR scope refused'

    # Integrity page + layout at 1024px
    SwitchBranch 0
    Nav "$Base/pages/stock-integrity.php"
    Check ((Eval "document.getElementById('integritySummary').classList.contains('alert--success')") -and (Eval "document.querySelectorAll('.integrity-list .badge--danger').length") -eq 0) "stock integrity after 7b (All branches): $(Text '#integritySummary span')"
    SwitchBranch 1
    Size 1024 900
    foreach ($pgUrl in 'warehouses.php', 'stock-docs.php', "stock-doc-view.php?id=$cnt1", "stock-doc-view.php?id=$iss", 'stock-doc-form.php?type=transfer', 'serial-register.php?id=11', 'inventory.php?location=1', 'product-form.php?id=2') {
        Nav "$Base/pages/$pgUrl"
        $wide = Eval "[...document.querySelectorAll('main *')].filter(e => e.getBoundingClientRect().right > window.innerWidth + 1).slice(-3).map(e => e.tagName + '.' + e.className + '#' + e.id + ':' + Math.round(e.getBoundingClientRect().right)).join(' ')"
        Check (Eval 'document.documentElement.scrollWidth <= window.innerWidth') "$pgUrl : no horizontal page scroll at 1024px (scrollWidth $(Eval 'document.documentElement.scrollWidth') $wide)"
    }
    Size 1536 1024

    # --- Phase 8: branch-to-branch transfers (DAV requests from MAR; MAR approves + releases; DAV receives) ---
    $p8 = "'transfers.request','transfers.approve','transfers.release','transfers.receive'"
    Check ((Sql "SELECT COUNT(*) FROM permissions WHERE perm_key IN ($p8)") -eq '4' -and (Sql "SELECT COUNT(*) FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id WHERE p.perm_key IN ($p8) AND r.code = 'branch_admin'") -eq '4') 'permissions: 4 transfer keys, granted to branch_admin'
    Logout
    Login 'cashier' 'cashier123'
    $st = "$(Status 'pages/transfers.php'),$(Status 'pages/transfer-form.php'),$(Status 'pages/transfer-view.php?id=1')"
    Check ($st -eq '403,403,403' -and (Eval "!document.body.textContent.includes('Branch Transfers')")) "cashier: transfer pages 403, no menu item ($st)"
    Logout

    # DAV requests Mouse x3 + Printer (serials) x2 from MAR
    Login 'davadmin' $script:pw
    Nav "$Base/pages/transfer-form.php"
    Submit "window.confirm = () => true; const f = document.getElementById('transferForm'); f.from_branch_id.value = '1'; f.notes.value = 'E2E transfer'; const pick = (t, p, q) => { const s = t.querySelector('[data-product]'); s.value = p; s.dispatchEvent(new Event('change')); t.querySelector('[data-qty]').value = q; }; pick(document.querySelector('#transferLines tbody[data-line]'), '2', '3'); document.getElementById('addLine').click(); pick([...document.querySelectorAll('#transferLines tbody[data-line]')].pop(), '8', '2'); f.requestSubmit()" 'request transfer'
    $bt = Sql 'SELECT id FROM stock_transfers ORDER BY id DESC LIMIT 1'
    $btNo = Sql "SELECT transfer_no FROM stock_transfers WHERE id = $bt"
    $lm = Sql "SELECT id FROM stock_transfer_lines WHERE transfer_id = $bt AND product_id = 2"
    Check ($btNo -like 'BT-MAR-*-000001' -and (Text '#transferTitle') -eq $btNo -and (Sql "SELECT CONCAT_WS('|', status, from_branch_id, to_branch_id, total_qty) FROM stock_transfers WHERE id = $bt") -eq 'requested|1|4|5') "DAV requests $btNo from MAR ($(Text '.alert span'))"
    $r = PostForm 'pages/transfer-form.php' "from_branch_id: '4', 'items[0][product_id]': '2', 'items[0][quantity]': '1'"
    Check ($r -like '*Choose the branch to request from*' -and (Sql 'SELECT COUNT(*) FROM stock_transfers') -eq '1') 'request from own branch refused'
    $r = PostForm "pages/transfer-view.php?id=$bt" "action: 'approve'"
    Check ($r -like '*Only the sending branch can do this*' -and (Sql "SELECT status FROM stock_transfers WHERE id = $bt") -eq 'requested') 'requesting branch cannot approve'
    Logout

    # MAR admin approves Mouse 2 of 3
    Login 'maradmin' $script:pw
    Nav "$Base/pages/transfer-view.php?id=$bt"
    Check (Eval "document.querySelector('#transferActionForm [name=action]')?.value === 'approve' && document.body.textContent.includes('in stock here')") 'MAR admin: approve form with stock on hand'
    Submit "const f = document.getElementById('transferActionForm'); f.querySelector('[name=`"qty[$lm]`"]').value = '2'; f.requestSubmit()" 'approve transfer'
    Check ((Sql "SELECT CONCAT_WS('|', status, total_qty, (SELECT qty_approved FROM stock_transfer_lines WHERE id = $lm)) FROM stock_transfers WHERE id = $bt") -eq 'approved|4|2') "MAR approves 2 of 3 mice + 2 printers ($(Text '.alert span'))"
    Logout

    # Super admin releases from MAR: serial count enforced, then 2 printers + 2 mice leave MAR
    Login 'admin' 'admin123'
    $m0 = [int](LocQty 2 1); $p0 = [int](LocQty 8 1); $s0 = [int](PStock 2); $avgM = BranchAvg 2
    Nav "$Base/pages/transfer-view.php?id=$bt"
    $tick = "[...document.querySelectorAll('fieldset[data-pick] label')].forEach(lb => { const c = lb.querySelector('input'); if (['PRN-1', 'PRN-2'].slice(0, window.__n).includes(lb.textContent.trim()) && !c.checked) c.click(); });"
    Submit "window.__n = 1; $tick document.getElementById('transferActionForm').requestSubmit()" 'release with 1 serial'
    Check ((Text '.alert--error span') -like '*choose exactly 2 serial*' -and (Sql "SELECT status FROM stock_transfers WHERE id = $bt") -eq 'approved') "release with 1 of 2 serials refused ($(Text '.alert span'))"
    Submit "window.__n = 2; $tick document.getElementById('transferActionForm').requestSubmit()" 'release transfer'
    $st = Sql "SELECT CONCAT_WS('|', status, total_qty, total_cost IS NOT NULL, (SELECT SUM(quantity) FROM stock_movements WHERE stock_transfer_id = $bt AND type = 'transfer_out'), (SELECT GROUP_CONCAT(status ORDER BY serial_no) FROM product_serials WHERE serial_no IN ('PRN-1', 'PRN-2'))) FROM stock_transfers WHERE id = $bt"
    Check ($st -eq 'released|4|1|-4|in_transit,in_transit') "released: stock out of MAR, serials in transit ($st; $(Text '.alert span'))"
    Check ([int](LocQty 2 1) -eq ($m0 - 2) -and [int](LocQty 8 1) -eq ($p0 - 2) -and [int](PStock 2) -eq ($s0 - 2) -and (BranchAvg 2) -eq $avgM) "MAR mouse $m0 -> $(LocQty 2 1), printer $p0 -> $(LocQty 8 1), company mouse total $s0 -> $(PStock 2) (in transit), MAR average unchanged"
    Check ((Sql "SELECT unit_cost FROM stock_transfer_lines WHERE id = $lm") -eq $avgM -and (Eval "!!document.getElementById('transferTotalCost')")) "line cost = MAR average ($avgM), value shown to admin"
    $r = PostForm "pages/transfer-view.php?id=$bt" "action: 'cancel', reason: 'Too late'"
    Check ($r -like '*already released*' -and (Sql "SELECT status FROM stock_transfers WHERE id = $bt") -eq 'released') 'cancel after release refused'
    Logout

    # DAV receives: 1 of 2 mice, PRN-2 missing; a note is required
    Login 'davadmin' $script:pw
    $d0 = [int](LocQty 2 4)
    Nav "$Base/pages/transfer-view.php?id=$bt"
    Check ((Text '#transferStatus') -eq 'In Transit' -and (Eval "document.querySelector('#transferActionForm [name=action]')?.value === 'receive'")) 'DAV: transfer in transit, receive form'
    $recv = "const f = document.getElementById('transferActionForm'); f.querySelector('[name=`"qty[$lm]`"]').value = '1'; [...f.querySelectorAll('[name=`"arrived[]`"]')].forEach(c => { if (c.closest('label').textContent.trim() === 'PRN-2' && c.checked) c.click(); });"
    Submit "$recv f.requestSubmit()" 'receive short without note'
    Check ((Text '.alert--error span') -like '*short*' -and (Sql "SELECT status FROM stock_transfers WHERE id = $bt") -eq 'released') "short receipt without a note refused ($(Text '.alert span'))"
    Submit "$recv f.receive_note.value = 'One mouse and PRN-2 missing on arrival'; f.requestSubmit()" 'receive transfer'
    $st = Sql "SELECT CONCAT_WS('|', status, (SELECT SUM(quantity) FROM stock_movements WHERE stock_transfer_id = $bt AND type = 'transfer_in'), (SELECT CONCAT(status, '@', location_id) FROM product_serials WHERE serial_no = 'PRN-1'), (SELECT status FROM product_serials WHERE serial_no = 'PRN-2'), receive_note IS NOT NULL) FROM stock_transfers WHERE id = $bt"
    Check ($st -eq 'received|2|in_stock@4|removed|1') "received: 1 mouse + PRN-1 into DAV, PRN-2 removed ($st; $(Text '.alert span'))"
    Check ([int](LocQty 2 4) -eq ($d0 + 1) -and (Eval "document.body.textContent.includes('1 short')")) "DAV mouse $d0 -> $(LocQty 2 4), view shows the shortage"
    # Request + cancel by the requester
    $r = PostForm 'pages/transfer-form.php' "from_branch_id: '1', 'items[0][product_id]': '3', 'items[0][quantity]': '1'"
    $bt2 = Sql 'SELECT id FROM stock_transfers ORDER BY id DESC LIMIT 1'
    Nav "$Base/pages/transfer-view.php?id=$bt2"
    Submit "window.confirm = () => true; document.getElementById('cancelReason').value = 'Ordered from the supplier'; document.getElementById('cancelReason').form.submit()" 'cancel transfer'
    Check ((Sql "SELECT CONCAT_WS('|', status, cancel_reason) FROM stock_transfers WHERE id = $bt2") -eq 'cancelled|Ordered from the supplier') "requester cancels $(Sql "SELECT transfer_no FROM stock_transfers WHERE id = $bt2") ($(Text '.alert span'))"
    Nav "$Base/pages/transfers.php?direction=incoming"
    Check ((Eval "document.querySelectorAll('#transferTable tbody tr[data-transfer]').length") -eq 2) 'DAV incoming list: 2 transfers'
    Logout

    # Integrity (All branches) + layout at 1024px
    Login 'admin' 'admin123'
    SwitchBranch 0
    Nav "$Base/pages/stock-integrity.php"
    Check ((Eval "document.getElementById('integritySummary').classList.contains('alert--success')") -and (Eval "document.querySelectorAll('.integrity-list .badge--danger').length") -eq 0) "stock integrity after transfers (All branches): $(Text '#integritySummary span')"
    SwitchBranch 1
    Size 1024 900
    foreach ($pgUrl in 'transfers.php', "transfer-view.php?id=$bt", 'transfer-form.php') {
        Nav "$Base/pages/$pgUrl"
        Check (Eval 'document.documentElement.scrollWidth <= window.innerWidth') "$pgUrl : no horizontal page scroll at 1024px (scrollWidth $(Eval 'document.documentElement.scrollWidth'))"
    }
    Size 1536 1024
    Shot '39-transfer-view'

    # --- Phase 9: POS pricing (actual vs suggested price, role limits, approval at the till, cost toggle) ---
    $inv = [Globalization.CultureInfo]::InvariantCulture
    $p9 = "'pos.change_price','pos.discount','pos.price_override','pos.view_cost'"
    $lim = Sql "SELECT CONCAT_WS('|', (SELECT CONCAT(max_price_drop, '/', max_discount) FROM roles WHERE code = 'cashier'), (SELECT CONCAT(max_price_drop, '/', max_discount) FROM roles WHERE code = 'branch_admin'))"
    Check ((Sql "SELECT COUNT(*) FROM permissions WHERE perm_key IN ($p9)") -eq '4' -and $lim -eq '5.00/5.00|20.00/20.00') "pricing permissions + role limits ($lim)"
    Nav "$Base/pages/pos.php"
    WaitFor "document.querySelectorAll('.product-card').length > 0" 'pos products (admin)'
    Check (Eval "!document.getElementById('costToggle').hidden && getComputedStyle(document.querySelector('.cart-table th.c-cost')).display === 'none'") 'admin: cost toggle shown, cost column hidden by default'
    [void](Eval "document.getElementById('costToggle').click()")
    Check (Eval "getComputedStyle(document.querySelector('.cart-table th.c-cost')).display !== 'none' && document.getElementById('costToggle').getAttribute('aria-pressed') === 'true'") 'admin: cost toggle shows the Cost / Margin column'
    [void](Eval "document.getElementById('costToggle').click()")
    Logout

    Login 'cashier' 'cashier123'
    WaitFor "document.querySelectorAll('.product-card').length > 0" 'pos products (cashier)'
    $api = Eval "BB.api('pos/products.php').then(d => JSON.stringify(d.products).includes('cost') ? 'cost' : 'nocost')"
    Check ($api -eq 'nocost' -and (Eval "!document.getElementById('costToggle')")) "cashier: no cost in the products API, no cost toggle ($api)"
    $mp = [long](Sql 'SELECT ROUND(price * 100) FROM products WHERE id = 2')
    $p3 = [long][math]::Round($mp * 0.97); $p3s = ([decimal]$p3 / 100).ToString('0.00', $inv)
    ClickCard 'Mouse'
    [void](Eval "document.querySelector('#cartBody tr[data-id=`"2`"] [data-act=price]').click()")
    Check (Eval "document.getElementById('priceDialog').open") 'cashier: price button opens the Change Price dialog'
    [void](Eval "document.getElementById('priceInput').value = '$p3s'; document.getElementById('priceReason').value = ''; document.getElementById('priceForm').requestSubmit()")
    Check (Eval "document.getElementById('priceDialog').open && !document.getElementById('priceError').hidden") 'lower price without a reason refused in the dialog'
    [void](Eval "document.getElementById('priceReason').value = 'Loyal customer'; document.getElementById('priceForm').requestSubmit()")
    Check (Eval "!document.getElementById('priceDialog').open && document.querySelector('#cartBody tr[data-id=`"2`"]').classList.contains('is-repriced')") 'price applied, line marked as changed'
    $ls = Eval "localStorage.getItem(Object.keys(localStorage).find(k => k.startsWith('bb.pos.')))"
    Check ($ls -like "*`"price`":$p3,*Loyal customer*" -and $ls -notlike '*cost*') 'cart storage keeps the price + reason, never cost'
    $pay = "document.activeElement.blur(); { const s = document.getElementById('paymentSelect'); s.value = 'card'; s.dispatchEvent(new Event('change')); document.getElementById('btnSave').click(); document.getElementById('payForm').requestSubmit() }"
    [void](Eval $pay)
    WaitFor "document.getElementById('doneDialog').open" 'repriced sale done'
    $st = Sql "SELECT CONCAT_WS('|', ROUND(unit_price * 100), ROUND(suggested_price * 100), price_reason, price_approved_by IS NULL) FROM sale_items ORDER BY id DESC LIMIT 1"
    Check ($st -eq "$p3|$mp|Loyal customer|1") "3% lower within the cashier limit: saved with suggested price + reason, no approval ($st)"
    [void](Eval "document.getElementById('doneNew').click()")

    $p20 = [long][math]::Round($mp * 0.80); $p20s = ([decimal]$p20 / 100).ToString('0.00', $inv)
    ClickCard 'Mouse'
    [void](Eval "document.querySelector('#cartBody tr[data-id=`"2`"] [data-act=price]').click(); document.getElementById('priceInput').value = '$p20s'; document.getElementById('priceReason').value = 'Bulk order'; document.getElementById('priceForm').requestSubmit()")
    [void](Eval $pay)
    WaitFor "document.getElementById('approveDialog').open" 'approval dialog'
    Check ((Text '#approveList') -like '*Mouse*') "20% lower: approval dialog lists the Mouse ($(Text '#approveList'))"
    [void](Eval "document.getElementById('approveUser').value = 'cashier'; document.getElementById('approvePass').value = 'cashier123'; document.getElementById('approveForm').requestSubmit()")
    WaitFor "!document.getElementById('approveError').hidden" 'self approval refused'
    Check ((Text '#approveError') -like '*Another person*') "cashier cannot approve their own sale ($(Text '#approveError'))"
    [void](Eval "document.getElementById('approveUser').value = 'maradmin'; document.getElementById('approvePass').value = 'wrong-password'; document.getElementById('approveForm').requestSubmit()")
    WaitFor "(document.getElementById('approveError').textContent || '').includes('Invalid')" 'wrong approver password'
    Check (Eval "document.getElementById('approvePass').value === '' && !document.getElementById('doneDialog').open") 'wrong approver password refused, password field cleared'
    [void](Eval "document.getElementById('approveUser').value = 'maradmin'; document.getElementById('approvePass').value = '$script:pw'; document.getElementById('approveForm').requestSubmit()")
    WaitFor "document.getElementById('doneDialog').open" 'approved sale done'
    $sale9 = Sql 'SELECT MAX(id) FROM sales'
    $st = Sql "SELECT CONCAT_WS('|', ROUND(si.unit_price * 100), si.price_reason, u.username, (SELECT COUNT(*) FROM price_approvals pa WHERE pa.sale_id = si.sale_id AND pa.used_at IS NOT NULL)) FROM sale_items si JOIN users u ON u.id = si.price_approved_by WHERE si.sale_id = $sale9"
    Check ($st -eq "$p20|Bulk order|maradmin|1") "20% lower approved by maradmin at the till, token used once ($st)"
    [void](Eval "document.getElementById('doneNew').click()")

    ClickCard 'Keyboard'
    [void](Eval "{ const d = document.getElementById('discountInput'); d.value = '10'; d.dispatchEvent(new Event('input')); d.dispatchEvent(new Event('change')); } $pay")
    WaitFor "document.getElementById('approveDialog').open" 'discount approval dialog'
    Check ((Text '#approveList') -like '*Sale discount*10.00%*') "10% discount needs approval ($(Text '#approveList'))"
    [void](Eval "document.getElementById('approveUser').value = 'maradmin'; document.getElementById('approvePass').value = '$script:pw'; document.getElementById('approveForm').requestSubmit()")
    WaitFor "document.getElementById('doneDialog').open" 'discounted sale done'
    $st = Sql "SELECT CONCAT(s.discount_percent, '|', u.username) FROM sales s JOIN users u ON u.id = s.discount_approved_by WHERE s.id = (SELECT MAX(id) FROM sales)"
    Check ($st -eq '10.00|maradmin') "10% discount approved by maradmin ($st)"
    [void](Eval "document.getElementById('doneNew').click()")

    Nav "$Base/pages/sale-view.php?id=$sale9"
    Check ((Eval "document.querySelector('.price-was') !== null") -and (Text '.price-note') -like '*Bulk order*approved by Mara Admin*') "sale view: suggested price struck through, reason + approver ($(Text '.price-note'))"
    Nav "$Base/pages/receipt.php?id=$sale9"
    Check (Eval "!document.body.textContent.includes('Bulk order') && !document.querySelector('.price-was')") 'receipt shows the actual price only'
    Nav "$Base/pages/pos.php"
    Logout
    Login 'admin' 'admin123'

    # ---- Phase 10a: job orders ----
    $jPerm = Sql "SELECT GROUP_CONCAT(CONCAT(r.code, ':', p.perm_key) ORDER BY r.code, p.perm_key) FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id WHERE p.perm_key LIKE 'job_orders.%'"
    Check ($jPerm -eq 'branch_admin:job_orders.assign,branch_admin:job_orders.create,branch_admin:job_orders.release,branch_admin:job_orders.update,branch_admin:job_orders.view,cashier:job_orders.create,cashier:job_orders.release,cashier:job_orders.view,technician:job_orders.create,technician:job_orders.update') "job order permissions per role ($jPerm)"
    Nav "$Base/pages/settings.php"
    Submit "const f = document.getElementById('settingsForm'); f.job_quote_threshold.value = 'abc'; f.requestSubmit()" 'bad threshold'
    Check ((Eval "document.querySelector('[name=job_quote_threshold]').getAttribute('aria-invalid')") -eq 'true') 'settings: bad quotation threshold -> field error'
    Submit "const f = document.getElementById('settingsForm'); f.job_quote_threshold.value = '2,000'; f.requestSubmit()" 'threshold 2000'
    Check ((Sql "SELECT setting_value FROM settings WHERE setting_key = 'job_quote_threshold'") -eq '2000.00') 'settings: quotation threshold saved (2000.00)'
    Logout

    Login 'cashier' 'cashier123'
    Nav "$Base/pages/job-orders.php"
    Check (Eval "!!document.getElementById('newJobBtn') && [...document.querySelectorAll('.sidebar__nav .nav-link span')].some(s => s.textContent.trim() === 'Job Orders')") 'cashier: Job Orders menu + New Job Order'
    Nav "$Base/pages/job-form.php"
    Submit "document.getElementById('jobForm').requestSubmit()" 'empty job form'
    Check (Eval "['customer_name', 'customer_phone', 'problem', 'device_type_id'].every(n => document.querySelector('[name=' + n + ']').getAttribute('aria-invalid') === 'true') && !document.querySelector('[name=technician_id]')") 'empty intake: name / phone / problem / device type errors; cashier cannot assign'
    $dev = Sql "SELECT id FROM lookups WHERE list = 'device_type' AND name = 'Laptop'"
    Submit "const f = document.getElementById('jobForm'); f.customer_name.value = 'Pedro Penduko'; f.customer_phone.value = '0917 555 0101'; f.device_type_id.value = '$dev'; f.brand.value = 'Acer'; f.model.value = 'Aspire 5'; f.serial_no.value = 'ACR-77'; [...document.querySelectorAll('#accessoryChecks input')].find(b => b.value === 'Charger / Adapter').checked = true; f.accessories_other.value = 'Mouse pad'; [...document.querySelectorAll('#conditionChecks input')].find(b => b.value === 'Minor scratches').checked = true; [...document.querySelectorAll('#jobTypeChecks input[type=checkbox]')].slice(0, 2).forEach(b => b.checked = true); f.problem.value = 'Will not boot'; document.querySelector('[data-problem-pick]').click(); f.priority.value = 'high'; f.requestSubmit()" 'create job'
    $jo = "JO-MAR-$year-000001"
    $job1 = Sql "SELECT id FROM job_orders WHERE job_no = '$jo'"
    Check ((Text '#jobTitle') -eq $jo -and (Text '#jobStatus') -eq 'New' -and $job1 -ne '') "cashier created $jo (New)"
    $acc = Sql "SELECT CONCAT(accessories, ' | ', device_condition, ' | ', (SELECT COUNT(*) FROM job_order_types WHERE job_order_id = $job1), ' | ', problem LIKE 'Will not boot%') FROM job_orders WHERE id = $job1"
    Check ($acc -like 'Charger / Adapter, Mouse pad | Minor scratches | 2 | 1' -and (Sql "SELECT problem LIKE CONCAT('Will not boot', CHAR(10), '_%') FROM job_orders WHERE id = $job1") -eq '1' -and (Eval "document.querySelectorAll('#jobTypes .jo-chip').length") -eq 2) "intake checklists: accessories + other, condition, 2 job types, problem quick pick ($acc)"
    Check (Eval "!document.getElementById('takeJobBtn') && !document.getElementById('assignForm') && !!document.getElementById('editJobBtn') && !!document.getElementById('noteForm')") 'cashier: no Take / Assign; Edit and notes'
    Check (Eval "getComputedStyle(document.querySelector('.jo-ticket')).display === 'none' && document.querySelector('.jo-ticket').textContent.includes('CLAIM STUB') && document.querySelector('.jo-ticket').textContent.includes('ACR-77')") 'ticket + claim stub rendered, hidden on screen'
    [void](Cdp 'Emulation.setEmulatedMedia' @{ media = 'print' })
    Check (Eval "getComputedStyle(document.querySelector('.jo-ticket')).display === 'block' && getComputedStyle(document.querySelector('.jo-screen')).display === 'none'") 'print: only the ticket + claim stub'
    Shot '41-job-ticket-print'
    [void](Cdp 'Emulation.setEmulatedMedia' @{ media = '' })
    Logout

    Login 'davtech' $script:pw 'job-orders.php'
    Check ((Status "pages/job-view.php?id=$job1") -eq 404) 'DAV technician: MAR job 404'
    Logout

    Login 'martech' $script:pw 'job-orders.php'
    Check ((Text '[data-work=unassigned]') -eq '1' -and (Eval "!!document.getElementById('newJobBtn')")) 'technician lands on Job Orders: 1 unassigned job'
    Nav "$Base/pages/job-form.php"
    Check ((Eval "!document.querySelector('[data-add-customer]') && !document.getElementById('customerAddDialog')")) 'technician (no customers.edit): no + add customer on the job form'
    Nav "$Base/pages/job-view.php?id=$job1"
    Submit "document.getElementById('takeJobBtn').click()" 'take job'
    Check ((Text '#jobStatus') -eq 'Assigned' -and (Text '#jobTechnician') -like 'Marco Tech*') 'technician took the job'
    Submit "document.getElementById('startBtn').click()" 'start diagnosis'
    Submit "const f = document.getElementById('diagnoseForm'); f.diagnosis.value = 'Failed SSD'; f.estimate.value = '2,500'; f.requestSubmit()" 'diagnose'
    Check ((Text '#jobStatus') -eq 'For Approval' -and (Text '#jobEstimate') -like '*2,500.00') "estimate 2,500 above the 2,000 threshold -> For Approval ($(Text '#jobStatus'))"
    Logout

    Login 'cashier' 'cashier123'
    Nav "$Base/pages/job-view.php?id=$job1"
    Submit "const f = document.getElementById('decisionForm'); f.querySelector('[value=approve]').checked = true; f.requestSubmit()" 'decision without method'
    Check ((Eval "document.querySelector('#decisionForm [name=method]').getAttribute('aria-invalid')") -eq 'true') 'decision without "how" -> field error'
    Submit "const f = document.getElementById('decisionForm'); f.querySelector('[value=approve]').checked = true; f.method.value = 'phone'; f.requestSubmit()" 'approve quotation'
    Check ((Text '#jobStatus') -eq 'In Repair' -and (Text '#jobApproval') -like 'Approved*Pedro Penduko*phone call*') "front desk recorded the customer's approval ($(Text '#jobApproval'))"
    Check (Eval "!document.getElementById('toTestingBtn') && !document.getElementById('waitPartsBtn')") 'cashier cannot move the repair along'
    [void](PostForm "pages/job-view.php?id=$job1" "action: 'to_testing'")
    Check ((Sql "SELECT status FROM job_orders WHERE id = $job1") -eq 'in_repair') 'cashier POST to_testing refused (status unchanged)'
    Logout

    Login 'martech' $script:pw 'job-orders.php'
    Nav "$Base/pages/job-view.php?id=$job1"
    Submit "document.getElementById('waitPartsBtn').click(); const f = document.querySelector('#partsDialog form'); f.note.value = 'SSD 512GB from the warehouse'; f.requestSubmit()" 'waiting for parts'
    Check ((Text '#jobStatus') -eq 'Waiting for Parts') 'waiting for parts (with the parts needed)'
    Submit "document.getElementById('resumeBtn').click()" 'resume'
    Submit "document.getElementById('toTestingBtn').click()" 'to testing'
    Submit "document.getElementById('completeBtn').click(); document.getElementById('resolutionInput').value = 'ok'; document.getElementById('resolutionInput').form.requestSubmit()" 'complete too short'
    Check (Eval "document.getElementById('completeDialog').open && document.getElementById('resolutionInput').getAttribute('aria-invalid') === 'true'") 'complete without the work done -> dialog reopens with the error'
    Submit "document.getElementById('resolutionInput').value = 'Replaced the SSD, reinstalled the OS'; document.getElementById('resolutionInput').form.requestSubmit()" 'complete'
    Check ((Text '#jobStatus') -eq 'Completed' -and (Text '#resolutionCard') -like '*Replaced the SSD*') 'job completed with the work done'
    $n = Eval "document.querySelectorAll('#jobTimeline li').length"
    Check ($n -eq 9) "timeline: create, take, start, diagnose, decision, parts, resume, testing, complete ($n)"
    Shot '40-job-view'
    Logout

    Login 'maradmin' $script:pw
    $tech = Sql "SELECT id FROM users WHERE username = 'martech'"
    $mara = Sql "SELECT id FROM users WHERE username = 'maradmin'"
    Nav "$Base/pages/job-form.php"
    Submit "const f = document.getElementById('jobForm'); f.customer_id.value = '2'; f.customer_id.dispatchEvent(new Event('change')); f.device_type_id.value = '$dev'; f.problem.value = 'Printer jams'; f.technician_id.value = '$tech'; f.requestSubmit()" 'create assigned job'
    $job2 = Sql 'SELECT MAX(id) FROM job_orders'
    Check ((Text '#jobStatus') -eq 'Assigned' -and (Text '#jobTechnician') -like 'Marco Tech*' -and (Text '#jobCustomer') -eq 'Maria Santos') 'branch admin: job for a customer record, assigned at intake'
    Submit "const f = document.getElementById('assignForm'); f.technician_id.value = '$mara'; f.requestSubmit()" 'reassign'
    Check ((Text '#jobTechnician') -like 'Mara Admin*') 'branch admin reassigned the job'
    Submit "const f = document.getElementById('assignForm'); [...f.querySelectorAll('input[name=""helper_ids[]""]')].find(b => b.value === '$tech').checked = true; f.requestSubmit()" 'add a helper'
    Check ((Text '#jobTechnician') -like 'Mara Admin*Lead*Marco Tech*Helper*' -and (Sql "SELECT COUNT(*) FROM job_order_technicians WHERE job_order_id = $job2") -eq '1') "lead + helper: $(Text '#jobTechnician')"
    Submit "window.confirm = () => true; document.getElementById('cancelJobBtn').click(); document.getElementById('cancelReason').value = 'Customer changed their mind'; document.getElementById('cancelReason').form.requestSubmit()" 'cancel job'
    Check ((Text '#jobStatus') -eq 'Cancelled' -and (Sql "SELECT cancel_reason FROM job_orders WHERE id = $job2") -eq 'Customer changed their mind') 'branch admin cancelled the job with a reason'
    $a = Sql "SELECT COUNT(*) FROM audit_logs WHERE module = 'job_orders'"
    Check ([int]$a -ge 12) "job order actions are in the audit log ($a)"
    Logout
    # ---- Phase 10b: parts custody, billing, release, back-job ----
    Login 'maradmin' $script:pw
    Nav "$Base/pages/job-form.php"
    Submit "const f = document.getElementById('jobForm'); f.customer_name.value = 'Rosa Diaz'; f.customer_phone.value = '0918 222 3344'; f.device_type_id.value = '$dev'; f.problem.value = 'Needs more memory'; f.technician_id.value = '$tech'; f.requestSubmit()" 'create job for parts'
    $job3 = Sql 'SELECT MAX(id) FROM job_orders'
    $jo3  = Sql "SELECT job_no FROM job_orders WHERE id = $job3"
    Logout
    Login 'martech' $script:pw 'job-orders.php'
    Nav "$Base/pages/job-view.php?id=$job3"
    Submit "document.getElementById('startBtn').click()" 'start job 3'
    Submit "const f = document.getElementById('diagnoseForm'); f.diagnosis.value = 'Add 8GB RAM'; f.estimate.value = '500'; f.requestSubmit()" 'diagnose job 3'
    Submit "const f = document.getElementById('partsRequestForm'); f.product_id.value = '9'; f.quantity.value = '1'; f.requestSubmit()" 'request RAM'
    Check ((Sql "SELECT CONCAT(status, ':', qty_requested) FROM job_order_parts WHERE job_order_id = $job3") -eq 'requested:1' -and (Eval "!document.querySelector('[data-issue]')")) 'technician requested 1 RAM and cannot issue it'
    Logout

    Login 'maradmin' $script:pw
    Nav "$Base/pages/job-orders.php"
    Check ((Text '[data-work=parts-to-issue]') -eq '1') "branch admin: Parts to Issue tile ($(Text '[data-work=parts-to-issue]'))"
    $ram0 = [int](LocQty '9' '1')
    Nav "$Base/pages/job-view.php?id=$job3"
    Submit "document.querySelector('[data-issue]').click()" 'issue RAM'
    $ram1 = [int](LocQty '9' '1')
    Check ($ram1 -eq $ram0 - 1 -and (Sql "SELECT -SUM(quantity) FROM stock_movements WHERE job_order_id = $job3 AND type = 'job_issue'") -eq '1') "issued to job custody: MAR RAM $ram0 -> $ram1, job_issue movement"
    Logout

    Login 'martech' $script:pw 'job-orders.php'
    Nav "$Base/pages/job-view.php?id=$job3"
    Submit "document.querySelector('[data-use]').click()" 'use RAM'
    Check ((Sql "SELECT CONCAT(qty_used, ':', qty_issued - qty_used - qty_returned) FROM job_order_parts WHERE job_order_id = $job3") -eq '1:0') 'technician recorded the RAM as used (none left in custody)'
    Submit "document.getElementById('toTestingBtn').click()" 'job 3 to testing'
    Submit "document.getElementById('completeBtn').click(); document.getElementById('resolutionInput').value = 'Installed 8GB RAM, memtest passed'; document.getElementById('laborInput').value = '350'; document.getElementById('resolutionInput').form.requestSubmit()" 'complete job 3'
    Check ((Text '#jobStatus') -eq 'Completed' -and (Sql "SELECT labor FROM job_orders WHERE id = $job3") -eq '350.00') 'completed with a labour charge of 350.00'
    Logout

    Login 'cashier' 'cashier123'
    Nav "$Base/pages/job-view.php?id=$job3"
    $sub3 = Sql "SELECT FORMAT(price + 350, 2) FROM products WHERE id = 9"
    Check ((Text '#billSubtotal') -like "*$sub3" -and (Eval "document.querySelectorAll('#billLines tbody tr').length") -eq 2) "bill preview: RAM + labour = $sub3"
    Check (Eval "!document.getElementById('warrantyBtn')") 'cashier: no warranty release'
    Submit "window.confirm = () => true; const f = document.getElementById('billForm'); f.amount_paid.value = '5000'; f.requestSubmit()" 'bill without stub'
    Check (Eval "!!document.getElementById('err-stub') && document.getElementById('jobStatus').textContent.trim() === 'Completed'") 'bill without the claim stub or a note -> error'
    $mv = Sql 'SELECT COUNT(*) FROM stock_movements'
    Submit "window.confirm = () => true; const f = document.getElementById('billForm'); f.amount_paid.value = '5,000'; f.stub.checked = true; f.requestSubmit()" 'bill job 3'
    $sale3 = Sql "SELECT sale_id FROM job_orders WHERE id = $job3"
    $chk = Sql "SELECT CONCAT(s.job_order_id = $job3, s.total = ROUND((p.price + 350) * 1.12, 2), (SELECT GROUP_CONCAT(line_type ORDER BY id) FROM sale_items WHERE sale_id = s.id)) FROM sales s JOIN products p ON p.id = 9 WHERE s.id = '$sale3'"
    Check ((Text '#jobStatus') -eq 'Released' -and $chk -eq '11part,labor' -and (Sql 'SELECT COUNT(*) FROM stock_movements') -eq $mv) "billed + released: sale $sale3 linked, total incl. VAT, part + labour lines, no stock movement ($chk)"
    Check (Eval "!!document.getElementById('printReceiptBtn') && !!document.getElementById('backJobBtn')") 'released job: Print Receipt + New Back-Job'
    Shot '42-job-released'
    Nav "$Base/pages/receipt.php?id=$sale3"
    Check ((Text '#receiptJob') -eq $jo3 -and (Eval "document.body.textContent.includes('Labour / service')")) "receipt shows the job order + labour line ($(Text '#receiptJob'))"
    Nav "$Base/pages/job-form.php?parent=$job3"
    Check (Eval "document.querySelector('[name=customer_name]').value === 'Rosa Diaz' && !!document.getElementById('backJobNote') && document.querySelector('[name=problem]').value === ''") 'back-job form: customer + device from the released job, new problem'
    Submit "const f = document.getElementById('jobForm'); f.problem.value = 'Memory error again'; f.requestSubmit()" 'create back-job'
    Check ((Text '#parentJobLink') -eq $jo3) "back-job linked to $jo3"
    Logout

    Login 'maradmin' $script:pw
    Nav "$Base/pages/job-view.php?id=$job1"
    Submit "document.getElementById('warrantyBtn').click(); const f = document.querySelector('#warrantyDialog form'); f.stub.checked = true; f.release_note.value = 'Store warranty: SSD replaced free of charge'; f.requestSubmit()" 'warranty release'
    Check ((Text '#jobStatus') -eq 'Released' -and (Sql "SELECT CONCAT(release_type, '|', COALESCE(sale_id, 0)) FROM job_orders WHERE id = $job1") -eq 'warranty|0') 'branch admin released job 1 under warranty (no sale)'
    Logout

    Login 'admin' 'admin123'
    SwitchBranch 0
    Nav "$Base/pages/stock-integrity.php"
    Check ((Eval "document.getElementById('integritySummary').classList.contains('alert--success')") -and (Eval "document.querySelectorAll('.integrity-list .badge--danger').length") -eq 0) "stock integrity after job parts (All branches): $(Text '#integritySummary span')"
    SwitchBranch 1

    # ---- Phase 11: dashboard + reports ----
    Nav "$Base/pages/dashboard.php"
    Check (Eval "!!document.getElementById('dashToday') && !!document.getElementById('dashProfit') && !!document.getElementById('needsYou') && !document.getElementById('dashBranches')") 'dashboard (super admin, MAR): KPIs incl. profit, Needs You, no branch table'
    Check ((Eval "document.querySelectorAll('#dashWeek .barlist__row').length") -eq 7 -and (Eval "getComputedStyle(document.querySelector('#dashWeek .bar')).fill") -eq 'rgb(42, 120, 214)') 'dashboard: last 7 days, bars in the chart blue'
    Shot '43-dashboard'
    SwitchBranch 0
    Nav "$Base/pages/dashboard.php"
    Check ((Eval "document.querySelectorAll('#dashBranches tbody tr').length") -eq 5 -and (Eval "!document.getElementById('needsYou')")) 'dashboard (All branches): 5 branch rows, no Needs You'
    Nav "$Base/pages/report-branches.php"
    $net = Sql "SELECT FORMAT(COALESCE(SUM(total), 0), 2) FROM sales WHERE status = 'completed' AND created_at >= CURDATE() - INTERVAL 29 DAY"
    Check ((Text '#branchNetTotal') -like "*$net") "branch comparison: total of all branches = completed sales, last 30 days ($net)"
    Nav "$Base/pages/report-pricing.php"
    $ov = Sql "SELECT COUNT(*) FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE s.status = 'completed' AND si.unit_price < si.suggested_price AND s.created_at >= CURDATE() - INTERVAL 29 DAY"
    Check ([int]$ov -ge 2 -and (Eval "document.querySelectorAll('#ovLinesTable tbody tr').length") -eq [int]$ov) "price overrides report lists the $ov lowered lines"
    Nav "$Base/pages/report-profit.php"
    $pf = Sql "SELECT FORMAT(COALESCE(SUM(subtotal - discount_amount - cost_total), 0), 2) FROM sales WHERE status = 'completed' AND cost_total IS NOT NULL AND created_at >= CURDATE() - INTERVAL 29 DAY"
    Check ((Text '#profitTotal') -like "*$pf") "profit report (All branches): gross profit $pf ($(Text '#profitTotal'))"
    Nav "$Base/pages/report-jobs.php"
    $jr = Sql "SELECT COUNT(*) FROM job_orders WHERE created_at >= CURDATE() - INTERVAL 29 DAY"
    Check ((Text '#jobsReceived') -eq $jr -and (Eval "document.querySelectorAll('#jobTechnicians tbody tr[data-tech]').length") -ge 1) "jobs report: $jr received + technician rows"
    $csv = Eval "fetch('$Base/pages/report-jobs.php?export=csv').then(r => r.headers.get('content-type') + '|' + r.status)"
    Check ($csv -like 'text/csv*|200') "jobs report CSV ($csv)"
    SwitchBranch 1
    Logout
    Login 'davadmin' $script:pw 'dashboard.php'
    Nav "$Base/pages/reports.php"
    $tabs = Eval "[...document.querySelectorAll('.report-tab')].map(a => a.dataset.tab).join(',')"
    Check ($tabs -eq 'sales,profit,jobs,pricing' -and (Status 'pages/report-branches.php') -eq 403) "branch admin lands on the Dashboard; report tabs $tabs; branch comparison 403"
    Logout
    Login 'cashier' 'cashier123' 'pos.php'
    $st = "$(Status 'pages/dashboard.php'),$(Status 'pages/report-profit.php'),$(Status 'pages/report-jobs.php'),$(Status 'pages/report-pricing.php')"
    Check ($st -eq '403,403,403,403') "cashier still lands on the POS; dashboard and reports 403 ($st)"
    Logout
    Login 'admin' 'admin123' 'dashboard.php'

    # ---- Phase 12: security events, audit export, override audit ----
    Logout
    Nav "$Base/login.php"
    Submit "document.querySelector('[name=username]').value = 'cashier'; document.querySelector('[name=password]').value = 'wrong-pass-123'; document.querySelector('.login__form').submit()" 'failed login'
    Check ((Text '.alert span') -like '*Invalid username or password*') 'wrong password refused'
    Login 'admin' 'admin123' 'dashboard.php'
    $ev = Sql "SELECT CONCAT((SELECT COUNT(*) FROM audit_logs WHERE module = 'auth' AND action = 'login_failed' AND entity_ref = 'cashier' AND new_values LIKE '%wrong password%' AND new_values NOT LIKE '%wrong-pass%'), ':', (SELECT COUNT(*) FROM audit_logs WHERE module = 'auth' AND action = 'login' AND entity_ref = 'admin'), ':', (SELECT COUNT(*) FROM audit_logs WHERE module = 'auth' AND action = 'logout'), ':', (SELECT COUNT(*) FROM audit_logs WHERE module = 'auth' AND action = 'branch_switch'))"
    $evp = $ev.Split(':')
    Check ($evp[0] -eq '1' -and [int]$evp[1] -ge 2 -and [int]$evp[2] -ge 1 -and [int]$evp[3] -ge 1) "audit: failed login (no password), logins, logouts, branch switches ($ev)"
    $po = Sql "SELECT COUNT(*) FROM audit_logs WHERE module = 'sales' AND action = 'price_override'"
    Check ([int]$po -ge 3) "audit: POS sales with lowered prices / discounts recorded ($po)"
    Nav "$Base/pages/audit-log.php?module=auth"
    Check ((Eval "document.body.textContent.includes('login failed') || document.body.textContent.includes('login_failed')") -and (Eval "!!document.getElementById('auditCsv')")) 'audit log: Sign-in & Security filter shows the failed login; Export CSV'
    $csv = Eval "fetch(document.getElementById('auditCsv').href).then(r => r.text().then(t => r.headers.get('content-type') + '|' + t.includes('login_failed') + '|' + !t.includes('wrong-pass')))"
    Check ($csv -like 'text/csv*|true|true') "audit CSV export ($csv)"

    # ---- Phase 13a: purchasing (PR -> PO Internal -> receiving from the PO) ----
    Logout
    Login 'cashier' 'cashier123' 'pos.php'
    Nav "$Base/pages/pr-form.php"
    Submit "const f = document.getElementById('prForm'); f.purpose.value = 'Restock for the school season'; const t = document.querySelectorAll('#docLines tbody[data-line]')[0]; t.querySelector('[data-product]').value = '12'; t.querySelector('[name*=end_user]').value = 'PGB'; t.querySelector('[data-qty]').value = '5'; document.getElementById('addLine').click(); const u = [...document.querySelectorAll('#docLines tbody[data-line]')].pop(); u.querySelector('[data-product]').value = '6'; u.querySelector('[data-qty]').value = '3'; window.confirm = () => true; f.requestSubmit()" 'cashier sends a purchase request'
    $pr1 = Sql 'SELECT MAX(id) FROM purchase_requests'
    $prNo = Sql "SELECT pr_no FROM purchase_requests WHERE id = $pr1"
    Check ($prNo -like 'PR-MAR-*-000001' -and (Text '#prStatus') -eq 'For Approval' -and (Eval "!document.getElementById('prApproveForm') && !document.getElementById('prCreatePo')")) "cashier: $prNo for approval; cannot approve or order it"
    Nav "$Base/pages/purchase-requests.php"
    Check ((Status 'pages/purchase-orders.php') -eq 403 -and (Eval "[...document.querySelectorAll('.flow-tab')].map(a => a.dataset.tab).join(',')") -eq 'overview,requests') 'cashier: PO Internal 403, buying tabs only Overview + Requests'
    Logout

    Login 'maradmin' $script:pw
    Nav "$Base/pages/purchase-requests.php"
    Check ((Text '[data-work=to-approve]') -eq '1') "branch admin: To Approve tile ($(Text '[data-work=to-approve]'))"
    Nav "$Base/pages/pr-view.php?id=$pr1"
    $l6 = Sql "SELECT id FROM purchase_request_lines WHERE request_id = $pr1 AND product_id = 6"
    Submit "window.confirm = () => true; document.querySelector('[name=`"qty[$l6]`"]').value = '2'; document.getElementById('prApproveBtn').click()" 'approve PR'
    Check ((Text '#prStatus') -eq 'Approved' -and (Sql "SELECT GROUP_CONCAT(qty_approved ORDER BY product_id) FROM purchase_request_lines WHERE request_id = $pr1") -eq '2,5' -and (Eval "!!document.getElementById('prCreatePo')")) 'branch admin approved the PR (Network Switch 3 -> 2); Create PO offered'
    Nav (Eval "document.getElementById('prCreatePo').href")
    $pre = Eval "[...document.querySelectorAll('#docLines tbody[data-line]')].map(t => t.querySelector('[data-product]').value + 'x' + t.querySelector('[data-qty]').value + (t.querySelector('[data-links]').value ? 'L' : '')).sort().join(',')"
    Check ($pre -eq '12x5L,6x2L' -and (Eval "document.querySelector('[name=ship_to]').value.includes('Fortich') || document.querySelector('[name=ship_to]').value.includes('Maramag')")) "PO form from the PR: lines + request links ($pre), ship-to = branch address"
    Submit "window.confirm = () => true; const f = document.getElementById('poForm'); f.supplier_id.value = '1'; f.forwarder.value = 'AP Cargo - Air'; f.expected_date.value = new Date(Date.now() + 7 * 864e5).toISOString().slice(0, 10); document.querySelectorAll('#docLines [data-cost]').forEach((c, i) => { c.value = i === 0 ? '1,250.50' : '980'; c.dispatchEvent(new Event('input')); }); document.getElementById('submitPoBtn').click()" 'save PO and send for approval'
    $po1 = Sql 'SELECT MAX(id) FROM purchase_orders'
    $amt = Sql "SELECT total_amount FROM purchase_orders WHERE id = $po1"
    $expAmt = Sql "SELECT SUM(ROUND(qty_ordered * unit_cost, 2)) FROM purchase_order_lines WHERE po_id = $po1"
    Check ((Text '#poStatus') -eq 'For Approval' -and $amt -eq $expAmt -and (Sql "SELECT po_no IS NULL FROM purchase_orders WHERE id = $po1") -eq '1') "PO sent for approval, no number yet, total $amt"
    Check ((Sql "SELECT status FROM purchase_requests WHERE id = $pr1") -eq 'ordered') 'PR is Ordered (all approved units on the PO)'
    Check (Eval "!document.getElementById('poApprove')") 'the preparer cannot approve their own PO'
    Logout

    Login 'admin' 'admin123' 'dashboard.php'
    Nav "$Base/pages/dashboard.php"
    Check (Eval "document.getElementById('needsYou')?.textContent.includes('Purchase orders to approve')") 'dashboard: Purchase orders to approve'
    Nav "$Base/pages/po-view.php?id=$po1"
    Submit "document.getElementById('poReturnBtn').click(); document.getElementById('returnNote').value = 'Ask for free delivery'; document.getElementById('returnNote').form.requestSubmit()" 'return PO to draft'
    Check ((Text '#poStatus') -eq 'Draft' -and (Sql "SELECT return_note FROM purchase_orders WHERE id = $po1") -eq 'Ask for free delivery') 'approver returned the PO to draft with a note'
    Logout
    Login 'maradmin' $script:pw
    Nav "$Base/pages/po-form.php?id=$po1"
    Check ((Text '#poReturnNote') -like '*free delivery*') 'preparer sees the return note on the draft'
    Nav "$Base/pages/po-view.php?id=$po1"
    Submit "window.confirm = () => true; document.getElementById('poSubmit').click()" 'resend PO'
    Logout
    Login 'admin' 'admin123' 'dashboard.php'
    Nav "$Base/pages/po-view.php?id=$po1"
    Submit "window.confirm = () => true; document.getElementById('poApprove').click()" 'approve PO'
    $poNo = Sql "SELECT po_no FROM purchase_orders WHERE id = $po1"
    Check ($poNo -like 'PO-MAR-*-000001' -and (Text '#poStatus') -eq 'Approved' -and (Text '#poTitle') -eq $poNo) "super admin approved: $poNo"
    Nav "$Base/pages/po-print.php?id=$po1"
    WaitFor "document.querySelector('.lh__logo').complete" 'letterhead logo'
    Check ((Text '#poNo') -eq $poNo -and (Eval "!document.getElementById('poStamp')") -and (Eval "document.querySelector('.lh__logo').naturalWidth > 0") -and (Eval "document.querySelector('.lh__branches').textContent.includes('Perimeter Freedom Park')") -and (Text '#poPrintTotal') -like "*$(Sql "SELECT FORMAT(total_amount, 2) FROM purchase_orders WHERE id = $po1")") "printed PO: number, EXECOM logo + branch addresses, total ($(Text '#poPrintTotal'))"
    Check (Eval "document.querySelector('.doc__info').textContent.includes('AP Cargo')") 'printed PO: forwarder in the contact box'
    Shot '44-po-print'
    Nav "$Base/pages/po-view.php?id=$po1"
    Logout

    Login 'maradmin' $script:pw
    $h0 = [int](LocQty '12' '1'); $s0 = [int](LocQty '6' '1')
    Nav "$Base/pages/po-view.php?id=$po1"
    Nav (Eval "document.getElementById('poReceive').href")
    Check ((Eval "!!document.getElementById('rrPoNote') && document.querySelectorAll('#rrLines tbody[data-line]').length") -eq 2 -and (Eval "document.querySelector('#rrForm [name=supplier_id]').type") -eq 'hidden') 'receive from PO: PO note, 2 lines due, supplier fixed'
    Submit "window.confirm = () => true; const f = document.getElementById('rrForm'); f.reference_no.value = 'DR-PO-1'; const t = [...document.querySelectorAll('#rrLines tbody[data-line]')].find(x => x.querySelector('[data-product]').value === '12'); t.querySelector('[data-qty]').value = '3'; document.getElementById('postBtn').click()" 'post partial RR from PO'
    $rr1 = Sql 'SELECT MAX(id) FROM receiving_reports'
    Check ((Sql "SELECT CONCAT(status, ':', po_id) FROM receiving_reports WHERE id = $rr1") -eq "posted:$po1" -and (Text '#rrPo') -eq $poNo) "RR posted against $poNo"
    $h1 = [int](LocQty '12' '1'); $s1 = [int](LocQty '6' '1')
    Check ($h1 -eq $h0 + 3 -and $s1 -eq $s0 + 2 -and (Sql "SELECT CONCAT(status, ':', (SELECT GROUP_CONCAT(qty_received ORDER BY product_id) FROM purchase_order_lines WHERE po_id = $po1)) FROM purchase_orders WHERE id = $po1") -eq 'partial:2,3') "stock in: Headset $h0 -> $h1, Switch $s0 -> $s1; PO partially received"
    Nav "$Base/pages/receiving-form.php?po=$po1"
    Submit "window.confirm = () => true; const f = document.getElementById('rrForm'); document.querySelector('#rrLines [data-qty]').value = '5'; document.getElementById('postBtn').click()" 'over-receive'
    Check (Eval "document.body.textContent.includes('Only 2 still due') && !!document.querySelector('.alert--error')") 'receiving more than is due is refused (Only 2 still due)'
    Submit "window.confirm = () => true; document.querySelector('#rrLines [data-qty]').value = '2'; document.getElementById('postBtn').click()" 'receive the rest'
    $rr2 = Sql 'SELECT MAX(id) FROM receiving_reports'
    Check ((Sql "SELECT status FROM purchase_orders WHERE id = $po1") -eq 'received') 'PO fully received'
    Nav "$Base/pages/receiving-view.php?id=$rr2"
    Submit "window.confirm = () => true; document.getElementById('rrCancel').click(); document.getElementById('cancelReason').value = 'Wrong delivery receipt'; document.getElementById('cancelReason').form.requestSubmit()" 'cancel second RR'
    Check ((Sql "SELECT CONCAT(status, ':', (SELECT GROUP_CONCAT(qty_received ORDER BY product_id) FROM purchase_order_lines WHERE po_id = $po1)) FROM purchase_orders WHERE id = $po1") -eq 'partial:2,3') 'cancelling an RR puts the units back as due on the PO'
    Nav "$Base/pages/po-view.php?id=$po1"
    Check ((Eval "document.querySelectorAll('#poDeliveries li').length") -eq 2 -and (Text '#poProgress') -like '*5 of 7 received*') "PO tracking: 2 deliveries, $(Text '#poProgress')"
    Submit "window.confirm = () => true; document.getElementById('poCloseBtn').click(); const f = document.querySelector('#closeDialog form'); f.reason.value = 'Supplier is out of stock'; f.requestSubmit()" 'close PO'
    Check ((Text '#poStatus') -eq 'Closed' -and (Eval "!document.getElementById('poReceive')")) 'partially received PO closed; nothing more can be received'
    # A PO without a request: approved, then cancelled before anything arrives.
    Nav "$Base/pages/po-form.php"
    Submit "window.confirm = () => true; const f = document.getElementById('poForm'); f.supplier_id.value = '1'; const t = document.querySelector('#docLines tbody[data-line]'); t.querySelector('[data-product]').value = '6'; t.querySelector('[data-qty]').value = '1'; t.querySelector('[data-cost]').value = '975'; document.getElementById('submitPoBtn').click()" 'second PO'
    $po2 = Sql 'SELECT MAX(id) FROM purchase_orders'
    Logout
    Login 'admin' 'admin123' 'dashboard.php'
    Nav "$Base/pages/po-view.php?id=$po2"
    Submit "window.confirm = () => true; document.getElementById('poApprove').click()" 'approve second PO'
    Logout
    Login 'maradmin' $script:pw
    Nav "$Base/pages/po-view.php?id=$po2"
    Submit "window.confirm = () => true; document.getElementById('poCancelBtn').click(); const f = document.querySelector('#cancelDialog form'); f.reason.value = 'Supplier cannot deliver'; f.requestSubmit()" 'cancel second PO'
    Check ((Text '#poStatus') -eq 'Cancelled' -and (Sql "SELECT po_no FROM purchase_orders WHERE id = $po2") -like 'PO-MAR-*-000002') 'approved PO with nothing received cancelled (keeps its number)'
    Nav "$Base/pages/purchase-orders.php"
    Check ((Eval "document.querySelectorAll('#poTable tbody tr[data-po]').length") -eq 2) 'PO Internal list shows both purchase orders'
    $a = Sql "SELECT COUNT(*) FROM audit_logs WHERE module = 'purchasing' AND new_values NOT LIKE '%total_amount%'"
    Check ([int]$a -ge 10) "purchasing actions are in the audit log without amounts ($a)"
    Logout
    Login 'admin' 'admin123' 'dashboard.php'
    SwitchBranch 0
    Nav "$Base/pages/stock-integrity.php"
    Check ((Eval "document.getElementById('integritySummary').classList.contains('alert--success')") -and (Eval "document.querySelectorAll('.integrity-list .badge--danger').length") -eq 0) "stock integrity after purchasing (All branches): $(Text '#integritySummary span')"
    SwitchBranch 1

    # ---- Phase 13b: customer orders (PO Outgoing -> reservation -> delivery -> billing) ----
    $cust = Sql "SELECT id FROM customers WHERE name = 'DepEd Bukidnon'"
    Logout
    Login 'cashier' 'cashier123' 'pos.php'
    Nav "$Base/pages/co-form.php"
    Submit "window.confirm = () => true; const f = document.getElementById('coForm'); f.customer_id.value = '$cust'; f.customer_po_no.value = 'LGU-PO-2026-0457'; f.end_user.value = 'Engineering Office'; f.procurement_mode.value = 'Small Value Procurement'; f.due_date.value = new Date(Date.now() + 10 * 864e5).toISOString().slice(0, 10); const t = document.querySelectorAll('#docLines tbody[data-line]')[0]; t.querySelector('[data-product]').value = '4'; t.querySelector('[data-qty]').value = '5'; t.querySelector('[data-price-input]').value = '4,400'; t.querySelector('[data-reason]').value = 'Awarded bid price'; document.getElementById('addLine').click(); const u = [...document.querySelectorAll('#docLines tbody[data-line]')].pop(); u.querySelector('[data-product]').value = '8'; u.querySelector('[data-qty]').value = '1'; u.querySelector('[data-price-input]').value = '6500'; document.getElementById('submitCoBtn').click()" 'cashier enters a customer PO'
    $co1 = Sql 'SELECT MAX(id) FROM customer_orders'
    Check ((Text '#coStatus') -eq 'For Confirmation' -and (Sql "SELECT CONCAT(order_no IS NULL, ':', subtotal, ':', customer_po_no) FROM customer_orders WHERE id = $co1") -eq '1:28500.00:LGU-PO-2026-0457' -and (Eval "!document.getElementById('coConfirm')")) "customer PO for confirmation (subtotal 28,500, no number yet); the cashier cannot confirm it"
    Logout

    Login 'maradmin' $script:pw
    $m0 = [int](LocQty '4' '1'); $p0 = [int](LocQty '8' '1')
    Nav "$Base/pages/customer-orders.php"
    Check ((Text '[data-work=to-confirm]') -eq '1') "branch admin: To Confirm tile ($(Text '[data-work=to-confirm]'))"
    Nav "$Base/pages/co-view.php?id=$co1"
    Submit "window.confirm = () => true; document.getElementById('coConfirm').click()" 'confirm customer order'
    $coNo = Sql "SELECT order_no FROM customer_orders WHERE id = $co1"
    Check ($coNo -like 'CO-MAR-*-000001' -and (Text '#coStatus') -eq 'Confirmed' -and (Eval "!!document.getElementById('coReservedNote')")) "confirmed as ${coNo}: stock reserved"
    $posStock = Eval "fetch('$Base/api/pos/products.php').then(r => r.json()).then(d => d.products.find(p => p.id === 4).stock + ':' + d.products.find(p => p.id === 8).stock)"
    Check ($posStock -eq "$($m0 - 5):$($p0 - 1)") "POS shows only free stock: Monitor $m0 - 5 reserved, Printer $p0 - 1 ($posStock)"
    $r = Eval "BB.api('pos/checkout.php', {method: 'POST', body: {items: [{product_id: 4, qty: $($m0 - 4)}], customer_id: null, payment_type: 'cash', discount_percent: '0', amount_paid: '9999999.00'}}).then(d => 'ok', e => e.status + ':' + e.message)"
    Check ($r -like '409:*only*left*') "POS cannot sell reserved units ($r)"
    Nav "$Base/pages/dr-form.php?order=$co1"
    Submit "window.confirm = () => true; const rows = [...document.querySelectorAll('#drLines tbody tr')]; const m = rows.find(r => r.textContent.includes('Monitor')); m.querySelector('[data-pick-qty]').value = '3'; const p = rows.find(r => r.textContent.includes('Printer')); p.querySelector('.sn-check input').click(); document.getElementById('releaseDrBtn').click()" 'release DR 1'
    $dr1 = Sql 'SELECT MAX(id) FROM customer_deliveries'
    $drNo = Sql "SELECT dr_no FROM customer_deliveries WHERE id = $dr1"
    $m1 = [int](LocQty '4' '1'); $p1 = [int](LocQty '8' '1')
    Check ($drNo -like 'DR-MAR-*-000001' -and $m1 -eq $m0 - 3 -and $p1 -eq $p0 - 1 -and (Sql "SELECT COUNT(*) FROM product_serials ps JOIN customer_delivery_serials x ON x.serial_id = ps.id WHERE ps.status = 'delivered'") -eq '1') "$drNo released: Monitor $m0 -> $m1, Printer $p0 -> $p1, serial delivered"
    Check ((Sql "SELECT status FROM customer_orders WHERE id = $co1") -eq 'partial' -and (Sql "SELECT -SUM(quantity) FROM stock_movements WHERE customer_delivery_id = $dr1 AND type = 'delivery'") -eq '4') 'order partially delivered; delivery movements recorded'
    Submit "document.getElementById('drDeliveredBtn').click(); const f = document.querySelector('#deliveredDialog form'); f.received_by.value = 'Engr. Ramon Cruz, Supply Officer'; f.acceptance_ref.value = 'IAR-2026-118'; f.requestSubmit()" 'mark DR delivered'
    Check ((Text '#drStatus') -eq 'Delivered' -and (Sql "SELECT acceptance_ref FROM customer_deliveries WHERE id = $dr1") -eq 'IAR-2026-118') 'DR recorded as delivered with the IAR no.'
    Nav "$Base/pages/dr-print.php?id=$dr1"
    Check ((Text '#drNo') -eq $drNo -and (Eval "document.body.textContent.includes('LGU-PO-2026-0457') && document.body.textContent.includes('S/N:') && document.body.textContent.includes('Engr. Ramon Cruz')")) 'printed DR: number, customer PO, serial number, receiver'
    Shot '45-dr-print'
    Nav "$Base/pages/co-view.php?id=$co1"
    Logout

    Login 'cashier' 'cashier123' 'pos.php'
    Nav "$Base/pages/co-view.php?id=$co1"
    $mvb = Sql 'SELECT COUNT(*) FROM stock_movements'
    Submit "window.confirm = () => true; document.getElementById('coBillBtn').click(); const f = document.getElementById('billForm'); f.payment_type.value = 'charge'; f.payment_type.dispatchEvent(new Event('change')); f.requestSubmit()" 'bill DR 1 on account'
    $bill1 = Sql "SELECT sale_id FROM customer_deliveries WHERE id = $dr1"
    $chk = Sql "SELECT CONCAT(payment_type, ':', amount_paid, ':', total = ROUND((3 * 4400 + 6500) * 1.12, 2), ':', customer_order_id) FROM sales WHERE id = '$bill1'"
    Check ($chk -eq "charge:0.00:1:$co1") "billed on account: total = (3 x 4,400 + 6,500) + VAT, nothing paid yet ($chk)"
    Check ((Sql "SELECT GROUP_CONCAT(qty_billed ORDER BY product_id) FROM customer_order_lines WHERE order_id = $co1") -eq '3,1' -and (Sql 'SELECT COUNT(*) FROM stock_movements') -eq $mvb) 'order lines billed 3 + 1; billing wrote no stock movement'
    Nav "$Base/pages/bill-print.php?id=$bill1"
    Check ((Text '#billNo') -ne '' -and (Eval "!!document.getElementById('billDue') && document.body.textContent.includes('DR-MAR')")) 'billing statement: amount due + DR reference'
    Nav "$Base/pages/sales-history.php?payment=charge"
    Check ((Eval "document.body.textContent.includes('On account')")) 'sales history: On account payment filter'
    Logout

    Login 'maradmin' $script:pw
    Nav "$Base/pages/dr-form.php?order=$co1"
    Submit "window.confirm = () => true; document.getElementById('releaseDrBtn').click()" 'release DR 2 (the rest)'
    $dr2 = Sql 'SELECT MAX(id) FROM customer_deliveries'
    Check ((Sql "SELECT status FROM customer_orders WHERE id = $co1") -eq 'delivered' -and [int](LocQty '4' '1') -eq $m0 - 5) 'all delivered: order Delivered, Monitor stock down by 5 in total'
    Submit "window.confirm = () => true; document.getElementById('drCancelBtn').click(); document.getElementById('drCancelReason').value = 'Customer asked for a later date'; document.getElementById('drCancelReason').form.requestSubmit()" 'return DR 2 to stock'
    Check ((Text '#drStatus') -like 'Returned*' -and [int](LocQty '4' '1') -eq $m1 -and (Sql "SELECT status FROM customer_orders WHERE id = $co1") -eq 'partial') 'DR 2 returned to stock: Monitor back, order partial (2 reserved again)'
    Nav "$Base/pages/co-view.php?id=$co1"
    Submit "window.confirm = () => true; document.getElementById('coCloseBtn').click(); const f = document.querySelector('#closeDialog form'); f.reason.value = 'Customer reduced the order'; f.requestSubmit()" 'close order'
    $posStock = Eval "fetch('$Base/api/pos/products.php').then(r => r.json()).then(d => String(d.products.find(p => p.id === 4).stock))"
    Check ((Text '#coStatus') -eq 'Closed' -and [int]$posStock -eq $m1) "closed: nothing reserved any more, POS sees all $m1 Monitors ($posStock)"
    $mv = Sql 'SELECT COUNT(*) FROM stock_movements'
    Nav "$Base/pages/sale-view.php?id=$bill1"
    Submit "window.confirm = () => true; document.getElementById('voidBtn').click(); const f = document.querySelector('#voidDialog form'); f.reason.value = 'Wrong payment terms'; f.requestSubmit()" 'void the bill'
    Check ((Sql "SELECT COUNT(*) FROM customer_deliveries WHERE id = $dr1 AND sale_id IS NULL") -eq '1' -and (Sql 'SELECT COUNT(*) FROM stock_movements') -eq $mv -and (Sql "SELECT GROUP_CONCAT(qty_billed) FROM customer_order_lines WHERE order_id = $co1") -eq '0,0') 'voided bill: nothing restocked, DR 1 billable again'
    Nav "$Base/pages/co-view.php?id=$co1"
    Submit "window.confirm = () => true; document.getElementById('coBillBtn').click(); const f = document.getElementById('billForm'); f.payment_type.value = 'gcash'; f.requestSubmit()" 'bill again with GCash'
    Check ((Sql "SELECT CONCAT(s.payment_type, ':', s.amount_paid = s.total) FROM customer_deliveries d JOIN sales s ON s.id = d.sale_id WHERE d.id = $dr1") -eq 'gcash:1' -and (Text '#coStatus') -eq 'Closed') 'billed again (GCash, paid in full); the order stays Closed'
    Nav "$Base/pages/order-tracking.php"
    Check ((Eval "!!document.getElementById('trackOut') && !!document.getElementById('trackIn')")) 'order tracking: outgoing customer orders + incoming purchase orders'
    $a = Sql "SELECT COUNT(*) FROM audit_logs WHERE module = 'customer_orders'"
    Check ([int]$a -ge 8) "customer order actions are in the audit log ($a)"

    # ---- Phase 13c: quotation -> customer PO -> bill on account -> collection with withholding taxes ----
    Logout
    Login 'cashier' 'cashier123' 'pos.php'
    Nav "$Base/pages/quote-form.php"
    Submit "window.confirm = () => true; const f = document.getElementById('quoteForm'); f.customer_id.value = '$cust'; f.rfq_no.value = 'RFQ-2026-0311'; f.attention.value = 'BAC Secretariat'; const t = document.querySelectorAll('#docLines tbody[data-line]')[0]; t.querySelector('[data-product]').value = '7'; t.querySelector('[data-qty]').value = '2'; t.querySelector('[data-price-input]').value = '3200'; document.getElementById('addLine').click(); const u = [...document.querySelectorAll('#docLines tbody[data-line]')].pop(); u.querySelector('[data-product]').value = '2'; u.querySelector('[data-qty]').value = '4'; u.querySelector('[data-price-input]').value = '330'; u.querySelector('[data-reason]').value = 'Government price'; document.getElementById('sendQuoteBtn').click()" 'cashier prepares a quotation and marks it sent'
    $qt = Sql 'SELECT MAX(id) FROM quotations'
    $qtNo = Sql "SELECT quote_no FROM quotations WHERE id = $qt"
    Check ($qtNo -like 'QT-MAR-*-000001' -and (Text '#quoteStatus') -eq 'Sent' -and (Sql "SELECT subtotal FROM quotations WHERE id = $qt") -eq '7720.00') "quotation $qtNo sent (2 x 3,200 + 4 x 330 = 7,720 before VAT)"
    Nav "$Base/pages/quote-print.php?id=$qt"
    Check ((Text '#quoteNo') -eq $qtNo -and (Text '#quotePrintTotal') -like '*8,646.40' -and (Eval "document.body.textContent.includes('RFQ-2026-0311')")) "printed quotation: number, RFQ, total with VAT ($(Text '#quotePrintTotal'))"
    Shot '46-quote-print'
    Nav "$Base/pages/quote-view.php?id=$qt"
    Nav (Eval "document.getElementById('quoteOrder').href")
    Check ((Eval "!!document.getElementById('coFromQuote') && document.querySelectorAll('#docLines tbody[data-line]').length === 2")) 'Create Customer PO: order form prefilled from the quotation'
    Submit "window.confirm = () => true; const f = document.getElementById('coForm'); f.customer_po_no.value = 'DEPED-PO-2026-0099'; document.getElementById('submitCoBtn').click()" 'customer PO from the quotation'
    $co2 = Sql 'SELECT MAX(id) FROM customer_orders'
    Check ((Sql "SELECT CONCAT(q.status, ':', q.order_id = o.id, ':', o.quotation_id = q.id, ':', o.subtotal) FROM quotations q JOIN customer_orders o ON o.id = $co2 WHERE q.id = $qt") -eq 'won:1:1:7720.00') 'quotation won by the customer PO (linked both ways, same amount)'
    Logout

    Login 'maradmin' $script:pw
    Nav "$Base/pages/co-view.php?id=$co2"
    Submit "window.confirm = () => true; document.getElementById('coConfirm').click()" 'confirm the order from the quotation'
    Nav "$Base/pages/dr-form.php?order=$co2"
    Submit "window.confirm = () => true; document.getElementById('releaseDrBtn').click()" 'deliver everything'
    Nav "$Base/pages/co-view.php?id=$co2"
    Check ((Text '#coQuote') -eq $qtNo) 'order shows its quotation'
    Submit "window.confirm = () => true; document.getElementById('coBillBtn').click(); const f = document.getElementById('billForm'); f.payment_type.value = 'charge'; f.requestSubmit()" 'bill on account'
    $bill2 = Sql "SELECT MAX(id) FROM sales WHERE customer_order_id = $co2"
    Check ((Sql "SELECT CONCAT(payment_type, ':', total, ':', settled_amount) FROM sales WHERE id = $bill2") -eq 'charge:8646.40:0.00') 'bill on account 8,646.40, nothing collected'
    Nav "$Base/pages/collections.php"
    Check ((Eval "!!document.querySelector('#arTable tr[data-bill]') && document.getElementById('arTotal').textContent.includes('8,646.40')")) "receivables: the bill is open ($(Text '#arTotal'))"
    Shot '47-receivables'
    Nav "$Base/pages/collection-form.php?customer=$cust"
    Submit "window.confirm = () => true; document.getElementById('crEwtRate').value = '1'; document.getElementById('crVatRate').value = '5'; document.getElementById('crFillAll').click(); const f = document.getElementById('crForm'); f.method.value = 'check'; f.reference.value = 'LBP-778899'; f.bank_name.value = 'Landbank Maramag'; document.getElementById('crSubmit').click()" 'collect by check with 1% EWT + 5% VAT withheld'
    $cr = Sql 'SELECT MAX(id) FROM collections'
    $crNo = Sql "SELECT collection_no FROM collections WHERE id = $cr"
    Check ($crNo -like 'CR-MAR-*-000001' -and (Sql "SELECT CONCAT(amount_received, ':', ewt_total, ':', vat_withheld_total, ':', form_2307) FROM collections WHERE id = $cr") -eq '8183.20:77.20:386.00:pending') "${crNo}: cash 8,183.20 + EWT 77.20 (1% of 7,720) + VAT 386.00 (5%), 2307 pending"
    Check ((Sql "SELECT total = settled_amount FROM sales WHERE id = $bill2") -eq '1') 'the bill is fully settled'
    Nav "$Base/pages/sale-view.php?id=$bill2"
    Check ((Text '#saleBalance') -like '*0.00') "sale view: balance $(Text '#saleBalance')"
    Submit "window.confirm = () => true; document.getElementById('voidBtn').click(); const f = document.querySelector('#voidDialog form'); f.reason.value = 'Testing the guard'; f.requestSubmit()" 'try to void a collected bill'
    Check ((Sql "SELECT status FROM sales WHERE id = $bill2") -eq 'completed' -and (Eval "document.body.textContent.includes('has collections')")) 'a collected bill cannot be voided'
    Nav "$Base/pages/collection-view.php?id=$cr"
    Submit "document.getElementById('crFormBtn').click(); document.querySelector('#formDialog form').requestSubmit()" '2307 received'
    Check ((Sql "SELECT form_2307 FROM collections WHERE id = $cr") -eq 'received') 'withholding certificate recorded'
    Nav "$Base/pages/collection-print.php?id=$cr"
    Check ((Text '#crNo') -eq $crNo -and (Text '#crPrintReceived') -like '*8,183.20') 'printed collection receipt'
    Shot '48-collection-print'
    Nav "$Base/pages/collection-view.php?id=$cr"
    Submit "window.confirm = () => true; document.getElementById('crCancelBtn').click(); document.getElementById('crCancelReason').value = 'Check bounced (DAIF)'; document.getElementById('crCancelReason').form.requestSubmit()" 'cancel the collection'
    Check ((Text '#crStatus') -eq 'Cancelled' -and (Sql "SELECT settled_amount FROM sales WHERE id = $bill2") -eq '0.00') 'cancelled: the bill is open again'
    $a = Sql "SELECT COUNT(*) FROM audit_logs WHERE module = 'collections'"
    Check ([int]$a -ge 3) "collections are in the audit log ($a)"

    # ---- Phase 13d: credit terms, POS on account, check register, statement, payables + disbursement ----
    Nav "$Base/pages/customer-form.php?id=$cust"
    Submit "const f = document.getElementById('custCreditDays').form; f.credit_days.value = '30'; f.credit_limit.value = '50,000'; f.requestSubmit()" 'branch admin sets credit terms'
    Check ((Sql "SELECT CONCAT(credit_days, ':', credit_limit) FROM customers WHERE id = $cust") -eq '30:50000.00') 'credit terms saved: 30 days, limit 50,000'
    Nav "$Base/pages/pos.php"
    $r = Eval "BB.api('pos/checkout.php', {method: 'POST', body: {items: [{product_id: 3, qty: 1}], customer_id: $cust, payment_type: 'charge', discount_percent: '0', amount_paid: null}}).then(d => 'ok:' + d.sale.sale_no, e => e.status + ':' + e.message)"
    $chargeSale = Sql "SELECT MAX(id) FROM sales WHERE payment_type = 'charge' AND customer_order_id IS NULL"
    Check ($r -like 'ok:*' -and (Sql "SELECT CONCAT(amount_paid, ':', due_date = CURDATE() + INTERVAL 30 DAY) FROM sales WHERE id = '$chargeSale'") -eq '0.00:1') "POS sale on account, due in 30 days ($r)"
    $r = Eval "BB.api('pos/checkout.php', {method: 'POST', body: {items: [{product_id: 3, qty: 1}], customer_id: null, payment_type: 'charge', discount_percent: '0', amount_paid: null}}).then(d => 'ok', e => e.status + ':' + e.message)"
    Check ($r -like '422:*registered customer*') "walk-in cannot buy on account ($r)"
    Nav "$Base/pages/collections.php?aging=current"
    Check ((Eval "document.querySelectorAll('#arTable tr[data-bill]').length >= 1")) 'bills not yet due listed'
    Nav "$Base/pages/collection-form.php?customer=$cust"
    Submit "window.confirm = () => true; document.getElementById('crEwtRate').value = '0'; document.getElementById('crVatRate').value = '0'; document.getElementById('crFillAll').click(); const f = document.getElementById('crForm'); f.method.value = 'check'; f.reference.value = 'MBTC-4455'; f.bank_name.value = 'Metrobank'; document.getElementById('crSubmit').click()" 'collect everything by check'
    $cr2 = Sql 'SELECT MAX(id) FROM collections'
    Check ((Sql "SELECT check_status FROM collections WHERE id = $cr2") -eq 'on_hand') 'the check is on hand'
    Nav "$Base/pages/checks.php"
    Submit "document.querySelector('tr[data-check=""MBTC-4455""] [data-check-act=deposit]').click()" 'deposit the check'
    Nav "$Base/pages/checks.php?check=deposited"
    Submit "document.querySelector('tr[data-check=""MBTC-4455""] [data-check-act=clear]').click()" 'check cleared'
    Check ((Sql "SELECT CONCAT(check_status, ':', deposited_at IS NOT NULL, ':', cleared_at IS NOT NULL) FROM collections WHERE id = $cr2") -eq 'cleared:1:1') 'check register: on hand -> deposited -> cleared'
    Nav "$Base/pages/soa.php"
    Check ((Eval "!!document.getElementById('soaTable')")) 'statement of account list'
    Nav "$Base/pages/soa-print.php?customer=$cust"
    Check ((Text '#soaCustomer') -eq 'DepEd Bukidnon' -and (Eval "!!document.getElementById('soaDue')")) "printed statement of account (due $(Text '#soaDue'))"
    Shot '49-soa-print'

    Nav "$Base/pages/payables.php"
    $rrToInv = Eval "(document.querySelector('[data-invoice-rr]') || {}).dataset ? document.querySelector('[data-invoice-rr]').dataset.invoiceRr : ''"
    Check ($rrToInv -ne '') "payables: receiving reports to invoice ($rrToInv)"
    Shot '50-payables'
    Nav "$Base/pages/ap-form.php?rr=$rrToInv"
    Submit "window.confirm = () => true; const f = document.getElementById('apForm'); f.invoice_no.value = 'SI-E2E-0001'; document.getElementById('apSubmit').click()" 'record the supplier invoice'
    $ap = Sql 'SELECT MAX(id) FROM supplier_invoices'
    $apNo = Sql "SELECT ap_no FROM supplier_invoices WHERE id = $ap"
    $apSup = Sql "SELECT supplier_id FROM supplier_invoices WHERE id = $ap"
    Check ($apNo -like 'AP-MAR-*-000001' -and (Sql "SELECT amount = (SELECT total_cost FROM receiving_reports WHERE id = $rrToInv) FROM supplier_invoices WHERE id = $ap") -eq '1' -and (Text '#apStatus') -eq 'Unpaid') "$apNo recorded at the receiving report total"
    Nav "$Base/pages/dv-form.php?supplier=$apSup"
    Submit "window.confirm = () => true; document.getElementById('dvEwtRate').value = '1'; [...document.querySelectorAll('#dvLines tr[data-dv-line]')].forEach(r => { if (r.textContent.includes('$apNo')) r.querySelector('[data-dv-full]').click(); }); const f = document.getElementById('dvForm'); f.method.value = 'check'; f.reference.value = 'BDO-000777'; f.bank_name.value = 'BDO Maramag'; document.getElementById('dvSubmit').click()" 'pay the supplier by check with 1% EWT'
    $dv = Sql 'SELECT MAX(id) FROM disbursements'
    $dvNo = Sql "SELECT dv_no FROM disbursements WHERE id = $dv"
    Check ($dvNo -like 'DV-MAR-*-000001' -and (Sql "SELECT status FROM supplier_invoices WHERE id = $ap") -eq 'paid' -and (Sql "SELECT ewt_total > 0 AND check_status = 'issued' FROM disbursements WHERE id = $dv") -eq '1') "${dvNo}: invoice paid, EWT withheld, check issued"
    Nav "$Base/pages/dv-print.php?id=$dv"
    Check ((Text '#dvNo') -eq $dvNo -and (Eval "document.body.textContent.includes('Pesos and')")) 'printed disbursement voucher with the amount in words'
    Shot '51-dv-print'
    Nav "$Base/pages/dv-view.php?id=$dv"
    Submit "document.getElementById('dvClearBtn').click(); document.querySelector('#clearDialog form').requestSubmit()" 'check cleared'
    Check ((Sql "SELECT check_status FROM disbursements WHERE id = $dv") -eq 'cleared') 'issued check cleared'
    $a = Sql "SELECT COUNT(*) FROM audit_logs WHERE module = 'payables'"
    Check ([int]$a -ge 3) "payables are in the audit log ($a)"
    Logout
    Login 'admin' 'admin123' 'dashboard.php'
    SwitchBranch 0
    Nav "$Base/pages/stock-integrity.php"
    Check ((Eval "document.getElementById('integritySummary').classList.contains('alert--success')") -and (Eval "document.querySelectorAll('.integrity-list .badge--danger').length") -eq 0) "stock integrity after customer orders (All branches): $(Text '#integritySummary span')"
    SwitchBranch 1

    # Attachments (migration 016), payment status, grouped sidebar, Buying / Selling overview, document chain
    $poA = Sql "SELECT MAX(po_id) FROM receiving_reports WHERE branch_id = 1 AND status = 'posted' AND po_id IS NOT NULL"
    Nav "$Base/pages/po-view.php?id=$poA"
    $upJs = "(blob, name, label) => { const f = new FormData(); f.append('_csrf', document.querySelector('meta[name=csrf-token]').content); f.append('action', 'upload'); f.append('doc_type', 'purchase_order'); f.append('doc_id', '$poA'); f.append('label', label); f.append('return', 'po-view.php?id=$poA'); f.append('file', blob, name); return fetch('$Base/pages/attachments.php', {method: 'POST', body: f}).then(r => r.text()).then(t => t.includes('alert--error') ? 'refused' : (t.includes('Attachment added') ? 'added' : 'other')); }"
    $r1 = Eval "new Promise(res => { const c = document.createElement('canvas'); c.width = 40; c.height = 30; c.getContext('2d').fillRect(0, 0, 40, 30); c.toBlob(b => res(($upJs)(b, 'signed-po.png', 'Signed PO')), 'image/png'); })"
    $r2 = Eval "($upJs)(new Blob(['%PDF-1.4\n%%EOF\n'], {type: 'application/pdf'}), 'quote.pdf', 'Supplier quotation')"
    $r3 = Eval "($upJs)(new Blob(['<?php echo 1; ?>'], {type: 'image/png'}), 'evil.png', 'Signed PO')"
    $r4 = Eval "($upJs)(new Blob(['%PDF-1.4'], {type: 'application/pdf'}), 'x.pdf', 'Not a label')"
    $attN = Sql "SELECT COUNT(*) FROM document_attachments WHERE doc_type = 'purchase_order' AND doc_id = $poA AND deleted_at IS NULL"
    Check ($r1 -eq 'added' -and $r2 -eq 'added' -and $r3 -eq 'refused' -and $r4 -eq 'refused' -and $attN -eq '2') "attachments: photo + PDF added, fake image and unknown label refused ($r1 $r2 $r3 $r4, $attN on file)"
    $attImg = Sql "SELECT id FROM document_attachments WHERE doc_type = 'purchase_order' AND doc_id = $poA AND mime = 'image/png' ORDER BY id DESC LIMIT 1"
    $attPdf = Sql "SELECT id FROM document_attachments WHERE doc_type = 'purchase_order' AND doc_id = $poA AND mime = 'application/pdf' ORDER BY id DESC LIMIT 1"
    $attFile = Sql "SELECT filename FROM document_attachments WHERE id = $attImg"
    Nav "$Base/pages/po-view.php?id=$poA"
    $ct = Eval "fetch('$Base/pages/attachment.php?id=$attImg').then(r => r.status + '|' + r.headers.get('content-type'))"
    $direct = Status "storage/attachments/$attFile"
    Check ((Eval "document.querySelectorAll('#attachments [data-attachment]').length") -eq 2 -and $ct -eq '200|image/png' -and $direct -eq 403) "attachments card lists 2 files; served after the access check ($ct); storage URL $direct"
    $r = PostForm 'pages/attachments.php' "action: 'delete', id: '$attImg', return: 'po-view.php?id=$poA'"
    Check ((Sql "SELECT deleted_at IS NOT NULL FROM document_attachments WHERE id = $attImg") -eq '1' -and (Status "pages/attachment.php?id=$attImg") -eq 404 -and (Sql "SELECT COUNT(*) FROM audit_logs WHERE action IN ('attachment_add', 'attachment_delete')") -ge 3) 'attachment deleted (row kept, file gone, audited)'
    Shot '52-po-attachments'
    Check ((Eval "document.querySelectorAll('#docChain .doc-chain__stage').length") -eq 5 -and (Text '#docChain .doc-chain__stage.is-current .doc-chain__label') -eq 'PO Internal' -and (Eval "!!document.getElementById('payCard')")) 'PO page: document chain (5 steps, PO current) + payment card'
    Nav "$Base/pages/purchase-orders.php"
    Check ((Text '#poTable thead th:last-child') -eq 'Payment' -and (Eval "document.querySelectorAll('#poTable td[data-payment]').length") -gt 0) 'PO Internal list: Payment column'
    Nav "$Base/pages/co-view.php?id=$co1"
    Check ((Eval "!!document.getElementById('payCard') && !!document.getElementById('attachments') && document.querySelectorAll('#docChain .doc-chain__stage').length === 5")) 'customer order page: payment card, attachments, document chain'
    Nav "$Base/pages/buying.php"
    $bs = Eval "[...document.querySelectorAll('[data-stage]')].map(s => s.dataset.stage).join(',')"
    Check ($bs -like 'pr_approve,*to_pay,checks_out' -and (Eval "document.querySelector('.flow-tab.is-active').dataset.tab") -eq 'overview') "buying overview stages ($bs)"
    Nav "$Base/pages/selling.php"
    $ss = Eval "[...document.querySelectorAll('[data-stage]')].map(s => s.dataset.stage).join(',')"
    Check ($ss -like 'quotes,*forms') "selling overview stages ($ss)"
    Shot '53-selling-overview'
    $groups = Eval "[...document.querySelectorAll('.sidebar__nav .nav-group')].map(g => g.firstElementChild.textContent.trim()).join(',')"
    Check ($groups -eq 'Selling,Service,Buying,Stock,Admin') "sidebar sections ($groups)"

    # Collapsible sidebar: desktop icon rail (remembered in a cookie, rendered by the server), mobile slide-in
    Size 1536 1024
    $w0 = Eval "Math.round(document.getElementById('sidebar').getBoundingClientRect().width)"
    $c0 = Eval "Math.round(document.getElementById('main').getBoundingClientRect().width)"
    [void](Eval "document.querySelector('.topbar__toggle').click()")
    Start-Sleep -Milliseconds 400
    $w1 = Eval "Math.round(document.getElementById('sidebar').getBoundingClientRect().width)"
    $c1 = Eval "Math.round(document.getElementById('main').getBoundingClientRect().width)"
    $icons = Eval "[...document.querySelectorAll('.sidebar__nav .nav-link')].every(a => a.querySelector('svg').getBoundingClientRect().width > 0 && a.querySelector('span').getBoundingClientRect().width === 0)"
    Check ($w0 -eq 218 -and $w1 -eq 72 -and $c1 -gt $c0 -and $icons -and (Eval "document.cookie.includes('execom_sidebar=collapsed') && document.querySelector('.topbar__toggle').getAttribute('aria-expanded') === 'false'")) "sidebar collapses to icons ($w0 -> $w1 px), content widens ($c0 -> $c1 px), state saved"
    Nav "$Base/pages/selling.php"
    Check ((Eval "document.body.classList.contains('sidebar-collapsed') && Math.round(document.getElementById('sidebar').getBoundingClientRect().width) === 72")) 'collapsed sidebar remembered on the next page (server-rendered)'
    [void](Eval "document.querySelector('.topbar__toggle').click()")
    Start-Sleep -Milliseconds 400
    Check ((Eval "!document.body.classList.contains('sidebar-collapsed') && !document.cookie.includes('execom_sidebar=collapsed') && Math.round(document.getElementById('sidebar').getBoundingClientRect().width) === 218")) 'sidebar expanded again (labels back, cookie cleared)'
    Size 900 900
    [void](Eval "document.querySelector('.topbar__toggle').click()")
    Start-Sleep -Milliseconds 300
    Check ((Eval "document.body.classList.contains('sidebar-open') && !document.body.classList.contains('sidebar-collapsed') && document.querySelector('.topbar__toggle').getAttribute('aria-expanded') === 'true'")) 'mobile: the menu button opens the slide-in sidebar'
    [void](Eval "document.querySelector('.sidebar-backdrop').click()")
    Check ((Eval "!document.body.classList.contains('sidebar-open')")) 'mobile: backdrop closes it'
    # Phone: app-style bottom tab bar (4 main pages + Menu); hidden on desktop
    Size 412 860
    Nav "$Base/pages/dashboard.php"
    $tabs = Eval "[...document.querySelectorAll('#bottomNav .bottom-nav__item span')].map(s => s.textContent).join('|')"
    $bn = Eval "(() => { const n = document.getElementById('bottomNav').getBoundingClientRect(); return getComputedStyle(document.getElementById('bottomNav')).display !== 'none' && Math.round(n.bottom) === window.innerHeight && getComputedStyle(document.querySelector('.topbar__toggle')).display === 'none'; })()"
    Check ($tabs -eq 'Home|POS|Jobs|Stock|Menu' -and $bn -and (Eval "document.querySelector('#bottomNav [data-tab=dashboard]').classList.contains('is-active')")) "phone: bottom tab bar fixed at the bottom ($tabs), Home active, topbar menu button hidden"
    [void](Eval "document.querySelector('#bottomNav [data-tab=menu]').click()")
    Start-Sleep -Milliseconds 300
    Check ((Eval "document.body.classList.contains('sidebar-open') && document.getElementById('sidebar').getBoundingClientRect().bottom <= document.getElementById('bottomNav').getBoundingClientRect().top + 1 && Math.round(document.getElementById('sidebar').getBoundingClientRect().width) === window.innerWidth && getComputedStyle(document.querySelector('.sidebar__nav')).gridTemplateColumns.split(' ').length === 2 && !!document.querySelector('.sidebar__signout button').offsetParent")) 'phone: Menu tab opens a full-page menu (2-column tiles, Sign out) above the tab bar'
    Shot '58-phone-bottom-nav'
    [void](Eval "document.querySelector('#bottomNav [data-tab=menu]').click()")
    Nav "$Base/pages/inventory.php"
    Check ((Eval "document.querySelector('#bottomNav [data-tab=inventory]').classList.contains('is-active') && document.documentElement.scrollWidth <= window.innerWidth")) 'phone: Stock tab active on Inventory, no horizontal scroll'
    Size 1536 1024
    Check ((Eval "getComputedStyle(document.getElementById('bottomNav')).display") -eq 'none') 'desktop: no bottom tab bar'
    Size 1536 1024

    # Notifications (migration 018): made from the audit log of the flows above
    $nCount = Sql 'SELECT COUNT(*) FROM notifications'
    $self = Sql 'SELECT COUNT(*) FROM notification_recipients r JOIN notifications n ON n.id = r.notification_id WHERE r.user_id = n.actor_id'
    $outside = Sql "SELECT COUNT(*) FROM notification_recipients r JOIN notifications n ON n.id = r.notification_id JOIN users u ON u.id = r.user_id JOIN roles ro ON ro.code = u.role
                     WHERE n.branch_id IS NOT NULL AND ro.is_super = 0 AND u.branch_id <> n.branch_id AND r.user_id <> COALESCE(n.actor_id, 0)
                       AND NOT EXISTS (SELECT 1 FROM user_branches ub WHERE ub.user_id = u.id AND ub.branch_id = n.branch_id)
                       AND NOT EXISTS (SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ro.id AND p.perm_key = 'branches.access_all')
                       AND n.event NOT IN ('purchasing.pr_approve', 'purchasing.pr_reject', 'purchasing.po_approve', 'purchasing.po_return', 'transfers.approve', 'transfers.release', 'transfers.receive', 'transfers.cancel',
                                           'customer_orders.return', 'customer_orders.confirm', 'job_orders.create', 'job_orders.assign', 'job_orders.decision', 'job_orders.parts_issue', 'job_orders.cancel', 'inventory.count_post')"
    Check ([int]$nCount -gt 5 -and $self -eq '0' -and $outside -eq '0') "notifications created for the flows ($nCount); never to the person who did it; never outside the branch (except the people involved)"
    Nav "$Base/pages/dashboard.php"
    $badge = Eval "Number(document.getElementById('notifBadge').hidden ? 0 : document.getElementById('notifBadge').textContent.replace('+', ''))"
    [void](Eval "document.getElementById('notifBell').click()")
    WaitFor "document.querySelectorAll('#notifList .notif-item').length > 0 || !document.getElementById('notifState').hidden && !/^Loading/.test(document.getElementById('notifState').textContent)" 'notification panel loaded'
    $items = Eval "document.querySelectorAll('#notifList .notif-item').length"
    Check ([int]$badge -gt 0 -and [int]$items -gt 0 -and (Eval "!document.getElementById('notifPanel').hidden && !!document.querySelector('#notifList .notif-item strong')")) "bell: $badge unread; panel lists $items notifications (document no. in bold)"
    Shot '54-notifications'
    $first = Eval "document.querySelector('#notifList .notif-item.is-unread').dataset.notification"
    Submit "document.querySelector('#notifList .notif-item.is-unread').click()" 'open a notification'
    $after = Sql "SELECT COUNT(*) FROM notification_recipients WHERE user_id = 1 AND read_at IS NULL"
    Check ((Sql "SELECT read_at IS NOT NULL FROM notification_recipients WHERE user_id = 1 AND notification_id = $first") -eq '1' -and [int]$after -eq [int]$badge - 1 -and (Eval "!location.pathname.endsWith('/dashboard.php')")) "opening a notification marks it read and opens its document ($(Eval 'location.pathname'))"
    $r = Eval "fetch('$Base/api/notifications/read-all.php', {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name=csrf-token]').content}, body: '{}'}).then(r => r.json()).then(d => d.ok + ':' + d.unread)"
    Check ($r -eq 'true:0' -and (Sql 'SELECT COUNT(*) FROM notification_recipients WHERE user_id = 1 AND read_at IS NULL') -eq '0') "mark all read ($r)"
    Nav "$Base/pages/notifications.php?filter=unread"
    Check ((Eval "document.querySelectorAll('#notifPageList .notif-item').length") -eq 0 -and (Eval "document.querySelector('.notif-empty') !== null")) 'notifications page: nothing unread after mark all read'

    # Dashboard: money position, sales pace + target, coming up, sales mix, stock health, service, people
    [void](Sql 'UPDATE branches SET monthly_target = 1000000 WHERE id = 1')
    Nav "$Base/pages/dashboard.php"
    $sections = Eval "['dashMoney','dashReceivable','dashPayable','dashChecks','dashCollected','dashPace','dashTarget','dashUpcoming','dashTopItems','dashToday2','dashSlow','dashStockCat','dashService','dashCustomers','dashNotes'].filter(id => !document.getElementById(id)).join(',')"
    Check ($sections -eq '' -and (Eval "document.querySelectorAll('#dashReceivable .dash-aging-legend li').length") -eq 5) "dashboard: every new section is there (missing: '$sections'), receivables aging in 5 buckets"
    Check ((Eval "document.querySelector('.topbar').scrollWidth <= document.querySelector('.topbar').clientWidth + 1 && document.documentElement.scrollWidth <= window.innerWidth")) 'topbar fits at 1536px (bell + logout visible, no page scroll)'
    Shot '55-dashboard-more'
    [void](Sql 'UPDATE branches SET monthly_target = NULL WHERE id = 1')
    Logout
    Login 'cashier' 'cashier123' 'pos.php'
    Check ((Status 'pages/dashboard.php') -eq 403) 'cashier: no dashboard (money position stays with admins)'
    Logout
    Login 'admin' 'admin123' 'dashboard.php'
    Logout
    Login 'cashier' 'cashier123' 'pos.php'
    $st = "$(Status "pages/attachment.php?id=$attPdf"),$(Status 'pages/buying.php'),$(Status 'pages/selling.php'),$(Status "pages/po-view.php?id=$poA")"
    Check ($st -eq '403,200,200,403') "cashier: PO attachment 403, both overviews 200, PO 403 ($st)"
    Nav "$Base/pages/buying.php"
    Check ((Eval "[...document.querySelectorAll('[data-stage]')].map(s => s.dataset.stage).join(',')") -eq 'pr_approve,pr_order') 'cashier buying overview: only the purchase request steps'
    Logout
    Login 'admin' 'admin123' 'dashboard.php'

    # Branch prices (migration 020): a different selling price per branch; blank = the company price
    Check ((Sql "SELECT COUNT(*) FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id WHERE p.perm_key = 'products.branch_price' AND r.code = 'branch_admin'") -eq '1') 'branch prices: permission granted to branch_admin'
    SwitchBranch 1
    Nav "$Base/pages/branch-prices.php?search=ITM-0002"
    Submit "document.querySelector('[name=price_2]').value = '420.00'; document.getElementById('branchPricesForm').requestSubmit()" 'save MAR price of Mouse'
    Check ((Sql 'SELECT price FROM product_branch_prices WHERE product_id = 2 AND branch_id = 1') -eq '420.00' -and (Text '.alert--success span') -like '1 price saved*') "branch prices: Mouse at MAR = 420.00 ($(Text '.alert span'))"
    Check ((Sql "SELECT COUNT(*) FROM audit_logs WHERE module = 'products' AND action = 'branch_price' AND entity_id = 1") -eq '1' -and (Sql "SELECT COUNT(*) FROM notifications WHERE event = 'products.branch_price'") -eq '1') 'branch prices: audit record + notification'
    $pp = Eval "BB.api('pos/products.php').then(d => d.products.find(p => p.id === 2).price_cents)"
    SwitchBranch 2
    $pp2 = Eval "BB.api('pos/products.php').then(d => d.products.find(p => p.id === 2).price_cents)"
    Check ($pp -eq '42000' -and $pp2 -eq '35000') "POS: Mouse 420.00 at MAR, company price 350.00 at MLB ($pp / $pp2)"
    SwitchBranch 1
    $sale = ApiSale 2
    $line = Sql "SELECT CONCAT(si.unit_price, '/', si.suggested_price) FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE s.sale_no = '$($sale -replace '^ok:', '')' AND si.product_id = 2"
    Check ($sale -like 'ok:*' -and $line -eq '420.00/420.00') "POS sale at MAR uses the branch price ($sale, $line)"
    Nav "$Base/pages/inventory.php?search=ITM-0002"
    Check ((Eval "[...document.querySelectorAll('#inventoryTable tbody tr')].some(tr => tr.textContent.includes('420.00') && tr.textContent.includes('branch price'))")) 'inventory list: branch price with its tag'
    Nav "$Base/pages/branch-prices.php?search=ITM-0002"
    Submit "document.querySelector('[name=price_2]').value = 'abc'; document.getElementById('branchPricesForm').requestSubmit()" 'invalid price'
    Check ((Sql 'SELECT price FROM product_branch_prices WHERE product_id = 2 AND branch_id = 1') -eq '420.00' -and (Eval "!!document.querySelector('[name=price_2].is-invalid, [name=price_2][aria-invalid=true]')")) 'branch prices: invalid price refused, field marked'
    Nav "$Base/pages/product-form.php?id=2"
    $nIn = Eval "document.querySelectorAll('#branchPrices .bp-input').length"
    Check ([int]$nIn -eq [int](Sql 'SELECT COUNT(*) FROM branches WHERE is_active = 1') -and (Eval "document.querySelector('[name=branch_price_1]').value") -eq '420.00') "product form: Branch prices card, one box per branch ($nIn)"
    Submit "document.querySelector('[name=branch_price_1]').value = ''; document.querySelector('[name=branch_price_2]').value = '399.50'; document.getElementById('saveBranchPrices').click()" 'product form branch prices'
    Check ((Sql 'SELECT COUNT(*) FROM product_branch_prices WHERE product_id = 2 AND branch_id = 1') -eq '0' -and (Sql 'SELECT price FROM product_branch_prices WHERE product_id = 2 AND branch_id = 2') -eq '399.50') 'product form: MAR back to the company price, MLB 399.50'
    $pp = Eval "BB.api('pos/products.php').then(d => d.products.find(p => p.id === 2).price_cents)"
    Check ($pp -eq '35000' -and (Sql "SELECT si.unit_price FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE s.sale_no = '$($sale -replace '^ok:', '')' AND si.product_id = 2") -eq '420.00') "blank = company price on the POS again ($pp); the earlier sale keeps 420.00"
    Shot '56-branch-prices'
    # Quick add customer (+) next to the customer select of the quotation / customer PO / job order forms
    Nav "$Base/pages/quote-form.php"
    [void](Eval "document.querySelector('[data-add-customer=qtCustomer]').click()")
    Check ((Eval "document.getElementById('customerAddDialog').open")) 'quotation form: + opens the Add Customer dialog'
    [void](Eval "{ const f0 = document.getElementById('customerAddForm'); f0.elements.name.value = 'Quick Add Office'; f0.elements.phone.value = '09175550199'; f0.elements.address.value = 'Capitol Compound'; f0.requestSubmit(); } true")
    WaitFor "!document.getElementById('customerAddDialog').open" 'quick add saved'
    $qa = Sql "SELECT CONCAT(c.id, ':', c.branch_id, ':', (SELECT COUNT(*) FROM customer_branches cb WHERE cb.customer_id = c.id AND cb.branch_id = 1)) FROM customers c WHERE c.name = 'Quick Add Office'"
    $sel = Eval "document.getElementById('qtCustomer').value"
    Check ($qa -like "${sel}:1:1" -and (Eval "document.getElementById('qtCustomer').selectedOptions[0].textContent") -eq 'Quick Add Office') "quick add: customer saved at MAR and selected in the form ($qa / sel $sel / $(Eval "document.getElementById('qtCustomer').selectedOptions[0].textContent"))"
    Nav "$Base/pages/job-form.php"
    [void](Eval "{ document.querySelector('[data-add-customer=customerId]').click(); const f1 = document.getElementById('customerAddForm'); f1.elements.name.value = 'Quick Walk Two'; f1.elements.phone.value = '09175550288'; f1.requestSubmit(); } true")
    WaitFor "!document.getElementById('customerAddDialog').open" 'quick add on job form'
    Check ((Eval "document.getElementById('customerName').value === 'Quick Walk Two' && document.getElementById('customerId').selectedOptions[0].textContent.includes('09175550288')")) 'job form: new customer selected, name + phone filled in'
    [void](Eval "{ document.querySelector('[data-add-customer=customerId]').click(); const f2 = document.getElementById('customerAddForm'); f2.elements.name.value = 'Dup Phone'; f2.elements.phone.value = '09175550288'; f2.requestSubmit(); } true")
    WaitFor "!document.getElementById('customerAddError').hidden" 'duplicate phone error'
    Check ((Text '#customerAddError') -like '*already belongs to Quick Walk Two*' -and (Sql "SELECT COUNT(*) FROM customers WHERE name = 'Dup Phone'") -eq '0') "quick add: duplicate phone refused in the dialog ($(Text '#customerAddError'))"
    [void](Eval "document.getElementById('customerAddDialog').close()")
    Logout
    Login 'davadmin' $script:pw
    Nav "$Base/pages/product-form.php?id=2"
    $ids = Eval "[...document.querySelectorAll('#branchPrices .bp-input')].map(i => i.name).join(',')"
    $r = PostForm 'pages/product-form.php?id=2' "form: 'branch_prices', branch_price_2: '1.00', orig_price_2: '399.50', branch_price_4: '360.00', orig_price_4: ''"
    Check ($ids -eq 'branch_price_4' -and (Sql 'SELECT price FROM product_branch_prices WHERE product_id = 2 AND branch_id = 2') -eq '399.50' -and (Sql 'SELECT price FROM product_branch_prices WHERE product_id = 2 AND branch_id = 4') -eq '360.00') "branch admin: only their own branch box ($ids); a forged MLB price is ignored, DAV saved"
    Logout
    Login 'davcash' $script:pw 'pos.php'
    Check ((Status 'pages/branch-prices.php') -eq 403) 'cashier: no Branch Prices page'
    Check ((Eval "[...document.querySelectorAll('#bottomNav .bottom-nav__item span')].map(s => s.textContent).join('|')") -eq 'POS|Jobs|Stock|Orders|Menu') 'cashier: bottom tab bar POS / Jobs / Stock / Orders / Menu'
    $r = PostForm 'pages/product-form.php?id=2' "form: 'branch_prices', branch_price_4: '1.00', orig_price_4: '360.00'"
    Check ($r -like '403:*' -and (Sql 'SELECT price FROM product_branch_prices WHERE product_id = 2 AND branch_id = 4') -eq '360.00') "cashier: posting a branch price is refused ($($r.Substring(0, 3)))"
    # How It Works page: any signed-in user; steps the user can do are marked and linked
    Nav "$Base/pages/workflow.php"
    $wf = Eval "document.querySelectorAll('.wf-flow').length + '/' + document.querySelectorAll('.wf-step').length + '/' + document.querySelectorAll('#wf-pos .wf-step.is-mine').length + '/' + document.querySelectorAll('#wf-buy .wf-step.is-mine').length"
    Check ($wf -like '6/3*/*/1' -and (Text '.wf-role.is-mine strong') -eq 'Cashier' -and (Eval "[...document.querySelectorAll('.wf-step__link')].every(a => !/payables|purchase-orders|receiving/.test(a.href))")) "cashier: How It Works (flows/steps/mine POS/mine buying = $wf), role highlighted, no links to buying pages"
    Check ((Eval "document.documentElement.scrollWidth <= window.innerWidth")) 'How It Works: no horizontal scroll'
    Shot '57-workflow'
    Logout
    Login 'admin' 'admin123' 'dashboard.php'

    # Sprite validity
    $n = Eval "fetch('$Base/assets/img/icons.svg').then(r => r.text()).then(t => { const d = new DOMParser().parseFromString(t, 'image/svg+xml'); return d.querySelector('parsererror') ? -1 : d.querySelectorAll('symbol').length; })"
    Check ($n -gt 30) "icons.svg valid XML ($n icons)"

    if ($script:problems.Count) { Write-Output 'BROWSER PROBLEMS:'; $script:problems | ForEach-Object { Write-Output "  $_" }; $script:fails++ } else { Write-Output 'PASS  no JS errors / CSP violations' }
} catch {
    Write-Output "ERROR $($_.Exception.Message)"
    try { Shot 'error' } catch {}
    $script:problems | ForEach-Object { Write-Output "  $_" }
    $script:fails++
} finally {
    try { $ws.Dispose() } catch {}
    Stop-Process -Id $proc.Id -Force -ErrorAction SilentlyContinue
    Get-CimInstance Win32_Process -Filter "Name='msedge.exe'" | Where-Object { $_.CommandLine -like "*$prof*" } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
}

# --- FORCE_HTTPS (Phase 12) on the test copy only: http -> https 301 for GET, 308 for POST ---
try {
    $envCopy = Join-Path $e2eDir '.env'
    $saved = [IO.File]::ReadAllText($envCopy)
    [IO.File]::WriteAllText($envCopy, $saved + "`nFORCE_HTTPS=true`n", (New-Object Text.UTF8Encoding $false))
    $g = & curl.exe -s -o NUL -w '%{http_code} %{redirect_url}' "$Base/login.php"
    $p = & curl.exe -s -o NUL -w '%{http_code}' -X POST "$Base/login.php"
    [IO.File]::WriteAllText($envCopy, $saved, (New-Object Text.UTF8Encoding $false))
    $h = & curl.exe -s -o NUL -w '%{http_code}' "$Base/login.php"
    Check ($g -like '301 https://localhost/*/login.php' -and $p -eq '308' -and $h -eq '200') "FORCE_HTTPS: GET 301 to https, POST 308; off again: 200 ($g / $p / $h)"
} catch { Write-Output "FAIL  FORCE_HTTPS check: $($_.Exception.Message)"; $script:fails++ }

# --- Stock integrity in the test DB (after the browser part) ---
try {
    $bad = Sql 'SELECT COUNT(*) FROM products p WHERE p.stock <> (SELECT COALESCE(SUM(b.qty), 0) FROM stock_balances b WHERE b.product_id = p.id) OR p.stock <> (SELECT COALESCE(SUM(m.quantity), 0) FROM stock_movements m WHERE m.product_id = p.id)'
    Check ($bad -eq '0') "integrity: products.stock = SUM(balances) = SUM(movements) for every product (mismatches: $bad)"
    $bad = Sql 'SELECT COUNT(*) FROM stock_movements WHERE branch_id IS NULL OR warehouse_id IS NULL OR location_id IS NULL'
    Check ($bad -eq '0') "integrity: every movement has branch/warehouse/location ($bad without)"
    $w = Sql "SELECT CONCAT(branch_id, ':', qty) FROM stock_balances WHERE product_id = 11 ORDER BY branch_id"
    Check ($w -eq '1:16 4:2') "integrity: Webcam balances MAR 16, DAV 2 ($w)"
    $w = Sql 'SELECT COUNT(*) FROM stock_movements WHERE product_id = 11 AND branch_id = 4'
    Check ($w -eq '2') "integrity: 2 DAV ledger rows for Webcam (adjust + sale) ($w)"
} catch { Write-Output "FAIL  integrity SQL: $($_.Exception.Message)"; $script:fails++ }

if ($script:fails -or $Keep) {
    Write-Output "Test copy kept: $e2eDir  ($Base, DB $testDb)"
} else {
    try { Remove-TestCopy } catch { Write-Output "Could not remove ${e2eDir}: $($_.Exception.Message)" }
}
Write-Output "FAILS: $script:fails  (screenshots: $Out)"
$lock.Dispose()
if ($script:fails) { exit 1 }
