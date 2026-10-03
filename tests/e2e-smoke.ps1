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
        elseif ($txt -match '"method":"Log.entryAdded"' -and $txt -match '"level":"(error|warning)"' -and $txt -notmatch 'nope.php' -and -not ($txt -match 'status of 4(03|04|09)' -and $txt -match '(reports|settings|roles|branches|receipt|sale-view|pos|checkout|user-form|switch-branch|master-data|suppliers|supplier-form|customer-form)\.php')) { [void]$script:problems.Add('log: ' + $txt.Substring(0, [Math]::Min(400, $txt.Length))) }
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
    Check ($menu -eq 'POS Sales|Sales History|Inventory|Customers|Master Data|Reports|Settings') "branch admin menu: $menu"
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
    Submit "const f = document.querySelector('[name=unit_cost]').form; const b = document.getElementById('productBrand'); b.value = '$lenovo'; b.dispatchEvent(new Event('change')); document.getElementById('productModel').value = '$thinkpad'; document.getElementById('productUnit').value = '1'; f.unit_cost.value = '21234.56'; f.warranty_days.value = '365'; f.specs.value = 'Core i5, 8GB RAM, 512GB SSD'; f.track_serial.checked = true; f.requestSubmit()" 'save product master fields'
    Check ((Text '.alert--success span') -eq 'Laptop was updated.') "product saved ($(Text '.alert span'))"
    $p = Sql "SELECT CONCAT_WS('|', b.name, m.name, u.code, p.unit_cost, p.warranty_days, p.track_serial, p.specs) FROM products p LEFT JOIN brands b ON b.id = p.brand_id LEFT JOIN product_models m ON m.id = p.model_id LEFT JOIN units u ON u.id = p.unit_id WHERE p.id = 1"
    Check ($p -eq 'Lenovo|ThinkPad E14|PC|21234.56|365|1|Core i5, 8GB RAM, 512GB SSD') "product fields in DB ($p)"
    Nav "$Base/pages/product-form.php?id=1"
    Submit "const m = document.getElementById('productModel'); const o = m.querySelector('option[value=`"$probook`"]'); o.disabled = false; o.hidden = false; m.disabled = false; m.value = '$probook'; m.form.requestSubmit()" 'save model of another brand'
    Check ((Text '#err-model_id') -eq 'Choose a model of the selected brand.' -and (Sql 'SELECT model_id FROM products WHERE id = 1') -eq $thinkpad) "product: model of another brand rejected ($(Text '#err-model_id'))"
    Nav "$Base/pages/inventory.php?brand=$lenovo"
    Check ((Eval "[...document.querySelectorAll('#inventoryTable tbody tr')].filter(r => r.querySelector('[data-adjust]')).length") -eq 1 -and (Eval "document.querySelector('#inventoryTable tbody').textContent.includes('ThinkPad E14')")) 'inventory brand filter shows the Lenovo laptop with its model'
    Check (Eval "[...document.querySelectorAll('#inventoryTable th')].some(t => t.textContent.trim() === 'Unit cost') && document.querySelector('#inventoryTable tbody').textContent.includes('21,234.56')") 'admin (products.cost) sees the unit cost column'
    Nav "$Base/pages/master-data.php?list=brands"
    $r = Eval "fetch('$Base/pages/master-data.php?list=brands', {method: 'POST', body: new URLSearchParams({_csrf: document.querySelector('meta[name=csrf-token]').content, action: 'delete', id: '$lenovo', return: 'master-data.php?list=brands'})}).then(r => r.text()).then(t => t.includes('Deactivate it instead') ? 'blocked' : 'not blocked')"
    Check ($r -eq 'blocked' -and (Sql "SELECT COUNT(*) FROM brands WHERE id = $lenovo") -eq '1') "md: deleting brand used by a product is blocked ($r)"

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
