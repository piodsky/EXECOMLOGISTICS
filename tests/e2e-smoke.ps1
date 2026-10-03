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
    $null = & robocopy $root $e2eDir /E /XD (Join-Path $root '.git') (Join-Path $root '.claude') (Join-Path $root 'assets\uploads\products') (Join-Path $root 'storage\logs') /XF .env /NFL /NDL /NJH /NJS /NP
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
    $mysqlArgs = @('-h', (EnvValue 'DB_HOST' '127.0.0.1'), '-P', (EnvValue 'DB_PORT' '3306'), '-u', (EnvValue 'DB_USER' 'root'), '--default-character-set=utf8mb4')
    try {
        $env:MYSQL_PWD = EnvValue 'DB_PASS' ''
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
        elseif ($txt -match '"method":"Log.entryAdded"' -and $txt -match '"level":"(error|warning)"' -and $txt -notmatch 'nope.php' -and -not ($txt -match 'status of 4(03|04|09|22)' -and $txt -match '(reports|settings|roles|branches|receipt|sale-view|pos|checkout|user-form|switch-branch|master-data|suppliers|supplier-form|customer-form|receiving|receiving-view|receiving-form|serials|product-form|stock-integrity|stock-docs|stock-doc-form|stock-doc-view|warehouses|serial-register)\.php')) { [void]$script:problems.Add('log: ' + $txt.Substring(0, [Math]::Min(400, $txt.Length))) }
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
function Login([string]$u, [string]$p, [string]$landing = 'pos.php') {
    Nav "$Base/login.php"
    [void](Eval 'localStorage.clear()')
    [void](Eval "document.querySelector('[name=username]').value='$u'; document.querySelector('[name=password]').value='$p'; document.querySelector('.login__form').submit()")
    Start-Sleep -Milliseconds 500
    WaitFor "location.pathname.endsWith('/pages/$landing') && document.readyState==='complete'" "redirect to $landing"
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
        $env:MYSQL_PWD = EnvValue 'DB_PASS' ''
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
    Check (Eval "document.body.textContent.includes('Unit 5, IT Center') && document.body.textContent.includes('VAT Reg TIN: 123-456-789-000')") 'receipt prints the new company details'
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
    Login 'admin' 'admin123'
    $sel = Eval "(() => { const s = document.getElementById('branchSelect'); return s ? s.options[s.selectedIndex].textContent : ''; })()"
    Check ($sel -like 'MAR*Maramag City') "admin branch chip shows MAR ($sel)"
    Check ((Eval "[...document.querySelectorAll('#branchSelect option')].map(o => o.value).sort().join(',')") -eq '0,1,2,3,4,5') 'switcher: All (0) + 5 branches'
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
    Check ($menu -eq 'POS Sales|Sales History|Inventory|Receiving|Stock Operations|Serial Lookup|Customers|Master Data|Reports|Settings') "branch admin menu: $menu"
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
    Login 'davtech' $script:pw 'inventory.php'
    Check $true 'technician lands on Inventory'
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
    Login 'davtech' $script:pw 'inventory.php'
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
    Check ($j -match '"track_serial":true' -and $j -notmatch '(?i)cost') 'POS products API: track_serial flag, no cost key'
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
    Check ($j -like '*SN-A1*' -and $j -notmatch '(?i)cost') 'cashier: POS products / serials / scan JSON have no cost'
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
    foreach ($u in @(@('cashier', 'cashier123', 'pos.php'), @('martech', $script:pw, 'inventory.php'))) {
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
    Check ($menu -like '*Receiving|Stock Operations|Serial Lookup*') "MAR branch admin menu has Stock Operations ($menu)"
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
    Login 'martech' $script:pw 'inventory.php'
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
