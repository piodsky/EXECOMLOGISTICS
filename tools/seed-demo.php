<?php
/**
 * Demo data: about four months of activity at MAR / MLB / CDO so every module, dashboard and report has something
 * to show. Everything goes through the domain classes (stock, average cost, serials, reservations, document numbers
 * and the audit log follow the real rules); MariaDB's session `SET timestamp` backdates each step, so NOW() and the
 * DEFAULT CURRENT_TIMESTAMP columns get the simulated time.
 *
 *   C:\xampp\php\php.exe tools\seed-demo.php --password=<demo users' password> [--env=C:\path\scratch.env] [--days=120]
 *
 * CLI only. Runs once: refuses when purchase orders, job orders or customer orders already exist.
 * BACK UP FIRST (tools\backup.ps1); the only undo is restoring that backup.
 * Demo users (maradmin, mlbadmin, cdoadmin, mlbcashier, cdocashier, martech, martech2, mlbtech) get --password
 * (never stored in this file: the repository is public).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$opts = getopt('', ['env:', 'days:', 'password:']);
if (strlen((string) ($opts['password'] ?? '')) < 8) {
    fwrite(STDERR, "Usage: php tools/seed-demo.php --password=<8+ characters for the demo users> [--env=file] [--days=120]\n");
    exit(1);
}
define('DEMO_PASSWORD', (string) $opts['password']);
require __DIR__ . '/../system/bootstrap.php';
set_exception_handler(static function (Throwable $e): void {
    fwrite(STDERR, 'STOPPED at ' . ($GLOBALS['NOW'] ?? '-') . ': ' . get_class($e) . ': ' . $e->getMessage() . "\n"
        . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
});
if (!empty($opts['env'])) {
    Env::load((string) $opts['env']); // scratch DB: later values override the live .env
}
$DAYS = max(30, min(200, (int) ($opts['days'] ?? 120)));
define('DEMO_LAST', $DAYS);

// ---------------------------------------------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------------------------------------------

function q(string $sql, array $p = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($p);
    return $st;
}
function one(string $sql, array $p = []): mixed
{
    return q($sql, $p)->fetchColumn();
}
function lastId(string $table): int
{
    return (int) one("SELECT MAX(id) FROM {$table}");
}
function say(string $msg): void
{
    fwrite(STDOUT, $msg . PHP_EOL);
}

/** Simulated clock: NOW() / CURRENT_TIMESTAMP in this DB session. */
function clock(string $dt): void
{
    $GLOBALS['NOW'] = $dt;
    q('SET timestamp = UNIX_TIMESTAMP(?)', [$dt]);
}
function dayDate(int $d): string
{
    return date('Y-m-d', strtotime($GLOBALS['START'] . " +{$d} days"));
}
function today(): string
{
    return substr($GLOBALS['NOW'], 0, 10);
}

/** Act as a user working in a branch (fresh Auth / Branch caches). */
function actAs(string $username, int $branchId): int
{
    static $ids = [];
    $ids[$username] ??= (int) one('SELECT id FROM users WHERE username = ?', [$username]);
    $id = $ids[$username] ?: throw new RuntimeException("No user {$username}");
    $_SESSION['auth'] = ['id' => $id, 'pw' => hash('sha256', (string) one('SELECT password_hash FROM users WHERE id = ?', [$id]))];
    foreach (['user' => null, 'loaded' => false, 'permissions' => null] as $prop => $value) {
        (new ReflectionProperty(Auth::class, $prop))->setValue(null, $value);
    }
    Branch::reset();
    if (!Branch::switchTo($branchId)) {
        throw new RuntimeException("{$username} cannot work in branch {$branchId}");
    }
    return $id;
}

/** [$data, $errors] (or [$data, $contacts, $errors]) → data, else stop with the errors. */
function valid(array $res, string $what): array
{
    $errors = end($res);
    if ($errors) {
        throw new RuntimeException("{$what}: " . json_encode($errors, JSON_UNESCAPED_UNICODE));
    }
    return $res[0];
}

function chance(float $p): bool
{
    return mt_rand() / mt_getrandmax() < $p;
}
function pick(array $a): mixed
{
    return $a[array_rand($a)];
}
/** @param array<int|string, float> $weights */
function weighted(array $weights): int|string
{
    $r = mt_rand() / mt_getrandmax() * array_sum($weights);
    foreach ($weights as $k => $w) {
        if (($r -= $w) <= 0) {
            return $k;
        }
    }
    return array_key_last($weights);
}
function s(int|float $v): string
{
    return (string) $v;
}

function loc(int $branchId): int
{
    static $c = [];
    return $c[$branchId] ??= (int) Branch::defaultLocation($branchId)['id'];
}
function balance(int $pid, int $branchId): int
{
    return (int) one('SELECT COALESCE(SUM(qty), 0) FROM stock_balances WHERE product_id = ? AND location_id = ?', [$pid, loc($branchId)]);
}
function free(int $pid, int $branchId): int
{
    return max(0, balance($pid, $branchId) - Stock::reserved($pid, loc($branchId)));
}
/** In-stock serial ids at the branch POS location, oldest first. */
function serialIds(int $pid, int $branchId, int $n): array
{
    $st = q('SELECT id FROM product_serials WHERE product_id = ? AND status = ? AND location_id = ? ORDER BY id LIMIT ' . $n,
        [$pid, 'in_stock', loc($branchId)]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}
function newSerials(int $pid, int $n): string
{
    static $prefix = [13 => 'LNV5CD', 14 => 'HPDK4'];
    $p = $prefix[$pid] ?? ('SN' . $pid . 'X');
    $next = (int) one('SELECT COUNT(*) FROM product_serials WHERE product_id = ?', [$pid]) + 1;
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $out[] = $p . sprintf('%06d', 3100 + $next + $i);
    }
    return implode("\n", $out);
}

// ---------------------------------------------------------------------------------------------------------------
// Guards
// ---------------------------------------------------------------------------------------------------------------

foreach (['purchase_orders', 'job_orders', 'customer_orders'] as $t) {
    if ((int) one("SELECT COUNT(*) FROM {$t}") > 0) {
        fwrite(STDERR, "Refusing: {$t} already has rows (demo data runs once, on a database without activity).\n");
        exit(1);
    }
}
say('Database: ' . one('SELECT DATABASE()'));

mt_srand(20261004);
$REAL_NOW = date('Y-m-d H:i:s', time() - 300);
$START    = date('Y-m-d', strtotime("-{$DAYS} days"));
$RUN_T0   = date('Y-m-d H:i:s');

const MAR = 1;
const MLB = 2;
const CDO = 3;
const ADMIN = 'admin';
$STAFF = [
    MAR => ['admin' => 'maradmin', 'cashier' => 'cashier',    'techs' => ['martech', 'martech2']],
    MLB => ['admin' => 'mlbadmin', 'cashier' => 'mlbcashier', 'techs' => ['mlbtech']],
    CDO => ['admin' => 'cdoadmin', 'cashier' => 'cdocashier', 'techs' => []],
];

// ---------------------------------------------------------------------------------------------------------------
// Day 0: people, master data, products, suppliers, customers
// ---------------------------------------------------------------------------------------------------------------

clock(dayDate(0) . ' 08:05:00');
$superId = actAs(ADMIN, MAR);

$users = [
    ['maradmin',   'Ramon Villanueva',  'branch_admin', MAR],
    ['mlbadmin',   'Grace Lagunday',    'branch_admin', MLB],
    ['cdoadmin',   'Paolo Abellana',    'branch_admin', CDO],
    ['mlbcashier', 'Joy Pacana',        'cashier',      MLB],
    ['cdocashier', 'Kevin Sumalinog',   'cashier',      CDO],
    ['martech',    'Arnel Bacus',       'technician',   MAR],
    ['martech2',   'Jessa Mae Ocampo',  'technician',   MAR],
    ['mlbtech',    'Dennis Tagalog',    'technician',   MLB],
];
foreach ($users as [$un, $name, $role, $br]) {
    if (one('SELECT id FROM users WHERE username = ?', [$un])) {
        continue;
    }
    $d = valid(Users::validate(['username' => $un, 'full_name' => $name, 'role' => $role, 'branch_id' => s($br),
        'password' => DEMO_PASSWORD, 'password_confirm' => DEMO_PASSWORD, 'is_active' => '1', 'branches' => []], null, $superId), "user {$un}");
    Users::create($d);
}
say('Users ready.');

// Brands
$brandDef = MasterData::def('brands');
$brandIds = [];
foreach (['Acer', 'Lenovo', 'HP', 'Epson', 'Logitech', 'A4Tech', 'Samsung', 'APC', 'TP-Link', 'Seagate', 'Kingston'] as $b) {
    $id = one('SELECT id FROM brands WHERE name = ?', [$b]);
    if (!$id) {
        $id = MasterData::save($brandDef, valid(MasterData::validate($brandDef, ['name' => $b, 'is_active' => '1'], null), "brand {$b}"), null);
    }
    $brandIds[$b] = (int) $id;
}

// Existing products: brand, warranty, default cost. New products (two serial-tracked).
$unitId = static fn (string $code): string => s((int) one('SELECT id FROM units WHERE code = ?', [$code]));
$catalog = [ // id|null => [code, name, cat, brand, price, cost, warranty, reorder, unit, track]
    1  => ['ITM-0001', 'Laptop',                               1, 'Acer',     25000, 20500, 365, 3,  'PC',   0],
    2  => ['ITM-0002', 'Mouse',                                2, 'Logitech', 350,   210,   180, 10, 'PC',   0],
    3  => ['ITM-0003', 'Keyboard',                             2, 'A4Tech',   550,   360,   180, 10, 'PC',   0],
    4  => ['ITM-0004', 'Monitor 24"',                          2, 'Samsung',  4500,  3600,  365, 5,  'PC',   0],
    5  => ['ITM-0005', 'UPS 1000VA',                           3, 'APC',      3800,  2950,  365, 3,  'PC',   0],
    6  => ['ITM-0006', 'Network Switch',                       4, 'TP-Link',  2500,  1850,  365, 3,  'PC',   0],
    7  => ['ITM-0007', 'External HDD 1TB',                     3, 'Seagate',  3200,  2500,  365, 5,  'PC',   0],
    8  => ['ITM-0008', 'Printer',                              5, 'Epson',    6500,  5200,  365, 3,  'PC',   0],
    9  => ['ITM-0009', 'RAM 8GB',                              3, 'Kingston', 1800,  1300,  365, 8,  'PC',   0],
    10 => ['ITM-0010', 'SSD 512GB',                            3, 'Kingston', 2800,  2050,  365, 5,  'PC',   0],
    11 => ['ITM-0011', 'Webcam',                               2, 'Logitech', 1200,  820,   180, 5,  'PC',   0],
    12 => ['ITM-0012', 'Headset',                              2, 'A4Tech',   1500,  1050,  180, 5,  'PC',   0],
    13 => ['ITM-0013', 'Lenovo IdeaPad Slim 3 i5 8GB/512GB',   1, 'Lenovo',   32500, 27800, 365, 2,  'PC',   1],
    14 => ['ITM-0014', 'HP Desktop Package Core i5 w/ 22" LED', 1, 'HP',      38000, 31500, 365, 1,  'SET',  1],
    15 => ['ITM-0015', 'Bond Paper A4 70gsm',                  5, null,       260,   195,   0,   20, 'REAM', 0],
    16 => ['ITM-0016', 'Ink Bottle 003 Black',                 5, 'Epson',    380,   270,   0,   10, 'PC',   0],
    17 => ['ITM-0017', 'Cat6 UTP Cable 305m',                  4, null,       5200,  4100,  0,   2,  'BOX',  0],
    18 => ['ITM-0018', 'Wireless Router AC1200',               4, 'TP-Link',  1950,  1450,  365, 4,  'PC',   0],
];
$COST = [];
foreach ($catalog as $pid => [$code, $name, $cat, $brand, $price, $cost, $warranty, $reorder, $unit, $track]) {
    $COST[$pid] = $cost;
    $existing = Products::find($pid);
    $in = [
        'category_id' => s($cat), 'code' => $code, 'barcode' => $existing['barcode'] ?? '', 'name' => $existing['name'] ?? $name,
        'description' => $existing['description'] ?? '', 'price' => s($price), 'reorder_level' => s($reorder), 'is_active' => '1',
        'brand_id' => $brand !== null ? s($brandIds[$brand]) : '', 'model_id' => '', 'unit_id' => $unitId($unit),
        'specs' => $existing['specs'] ?? '', 'warranty_days' => s($warranty), 'unit_cost' => s($cost), 'stock' => '0',
    ];
    if ($track) {
        $in['track_serial'] = '1';
    }
    if ($existing) {
        Products::update($pid, valid(Products::validate($in, $pid), "product {$code}"), $existing['image'] ?? null);
    } else {
        $newId = Products::create(valid(Products::validate($in, null), "product {$code}"), null, $superId);
        if ($newId !== $pid) {
            throw new RuntimeException("Expected product id {$pid}, got {$newId}");
        }
    }
}
// Bundled illustrations for the new items (assets/uploads/products/sample-itm-0013..0018.png).
q("UPDATE products SET image = CONCAT('sample-', LOWER(code), '.png') WHERE id BETWEEN 13 AND 18 AND image IS NULL");
// The starter stock at MAR came in at cost 0: give it the default cost (data fix; qty is untouched).
q('UPDATE product_branches pb JOIN products p ON p.id = pb.product_id SET pb.avg_cost = p.unit_cost WHERE pb.branch_id = ? AND pb.avg_cost = 0',
    [MAR]);
say('Brands + 18 products ready.');

// Suppliers
$SUP = [];
foreach ([
    'tech'    => ['Mindanao Techline Distributors Inc.', '004-551-223-000', 'Lapasan, Cagayan de Oro City', '(088) 856 2210', '30 days', 30],
    'digital' => ['Davao Digital Supply Corp.',          '005-318-774-000', 'Bajada, Davao City',           '(082) 224 7781', '15 days PDC', 15],
    'office'  => ['Northmin Office Essentials',          '006-102-559-000', 'Sayre Highway, Malaybalay City', '(088) 813 4402', 'COD', 0],
    'network' => ['Prime Network Solutions Trading',     '007-640-118-000', 'Carmen, Cagayan de Oro City',  '(088) 858 9930', '30 days', 30],
] as $key => [$name, $tin, $addr, $phone, $terms, $termsDays]) {
    $id = one('SELECT id FROM suppliers WHERE name = ?', [$name]);
    if (!$id) {
        $d = valid(Suppliers::validate(['code' => '', 'name' => $name, 'tin' => $tin, 'address' => $addr, 'phone' => $phone,
            'email' => '', 'payment_terms' => $terms, 'terms_days' => s($termsDays), 'notes' => '', 'is_active' => '1'], null), "supplier {$name}");
        $id = Suppliers::save($d, [], null);
    }
    $SUP[$key] = (int) $id;
}
$supplierFor = static fn (int $pid): int => match (true) {
    in_array($pid, [6, 17, 18], true) => $SUP['network'],
    in_array($pid, [8, 15, 16], true) => $SUP['office'],
    in_array($pid, [2, 3, 11, 12], true) => $SUP['digital'],
    default => $SUP['tech'],
};

// Customers per branch (visible where they were added)
$CUST = [MAR => [], MLB => [], CDO => []];
$GOV = [];
$CREDIT = [1 => [0, ''], 2 => [30, ''], 3 => [30, '200000'], 4 => [15, '40000'], 5 => [30, '150000']]; // type => [days, limit]
foreach ([
    MAR => [
        ['LGU Maramag (Municipal Government)', '088 238 1101', 'Poblacion, Maramag, Bukidnon', '001-882-117-000', 2, 'gov'],
        ['Maramag National High School',       '088 238 1450', 'South Poblacion, Maramag, Bukidnon', '', 5, 'school'],
        ['Bukidnon Agri Supply Corp.',         '088 238 3377', 'Camp 1, Maramag, Bukidnon', '008-221-640-000', 3, 'private'],
        ['JM Computer Shop',                   '0917 552 3184', 'Dologon, Maramag, Bukidnon', '', 4, null],
        ['Rosalie Ebarle',                     '0926 441 2093', 'Base Camp, Maramag, Bukidnon', '', 1, null],
        ['Mark Anthony Gumapac',               '0935 118 7720', 'Anahawon, Maramag, Bukidnon', '', 1, null],
    ],
    MLB => [
        ['DepEd Schools Division of Bukidnon', '088 813 3634', 'Fortich St., Malaybalay City', '000-695-311-000', 2, 'gov'],
        ['Provincial Engineering Office',      '088 813 2210', 'Capitol Compound, Malaybalay City', '000-695-022-000', 2, 'gov2'],
        ['Malaybalay Doctors Clinic',          '088 221 4508', 'Sumpong, Malaybalay City', '009-412-883-000', 3, null],
        ['Liza Dumalagan',                     '0918 663 0247', 'Casisang, Malaybalay City', '', 1, null],
    ],
    CDO => [
        ['City Health Office - CDO',           '088 857 1960', 'Burgos St., Cagayan de Oro City', '000-874-503-000', 2, 'gov'],
        ['Xavier Heights Review Center',       '088 851 7712', 'Corrales Ave., Cagayan de Oro City', '010-337-215-000', 3, 'private'],
        ['RVM Printing Services',              '0927 840 1135', 'Lapasan, Cagayan de Oro City', '', 4, null],
        ['Noel Abragan',                       '0956 210 7784', 'Carmen, Cagayan de Oro City', '', 1, null],
    ],
] as $br => $list) {
    actAs($STAFF[$br]['admin'], $br);
    foreach ($list as [$name, $phone, $addr, $tin, $type, $tag]) {
        $id = one('SELECT id FROM customers WHERE name = ?', [$name]);
        if (!$id) {
            [$days, $limit] = $CREDIT[$type]; // credit terms: government, schools, companies and resellers buy on account
            $d = valid(Customers::check(['name' => $name, 'phone' => $phone, 'email' => '', 'address' => $addr, 'tin' => $tin,
                'customer_type_id' => s($type), 'is_active' => '1', 'credit_days' => s($days), 'credit_limit' => $limit], null), "customer {$name}");
            Customers::create($d, []);
            $id = lastId('customers');
        }
        $CUST[$br][] = (int) $id;
        if ($tag !== null) {
            $GOV[$br][$tag] = (int) $id;
        }
    }
}
foreach ([1, 2, 3, 4] as $old) { // the starter customers belong to MAR
    if (one('SELECT is_active FROM customers WHERE id = ?', [$old])) {
        $CUST[MAR][] = $old;
    }
}
say('Suppliers + customers ready.');

// ---------------------------------------------------------------------------------------------------------------
// Building blocks
// ---------------------------------------------------------------------------------------------------------------

$STATS = ['sales' => 0, 'skipped' => 0, 'voids' => 0, 'pos' => 0, 'rrs' => 0, 'transfers' => 0, 'jobs' => 0, 'orders' => 0,
          'quotes' => 0, 'collections' => 0, 'invoices' => 0, 'vouchers' => 0];

/** Purchase request (cashier) → approved (admin) → PO (admin) → approved (super admin). Returns the PO id. */
$purchase = static function (int $br, array $qtys, string $purpose, int $supplierId, int $expectInDays, bool $approvePo = true)
    use (&$STAFF, &$COST, &$STATS): int {
    $cashier = actAs($STAFF[$br]['cashier'], $br);
    $items = [];
    foreach ($qtys as $pid => $q) {
        $items[] = ['product_id' => s($pid), 'quantity' => s($q), 'end_user' => ''];
    }
    $prData = valid(PurchaseRequests::validate(['purpose' => $purpose, 'needed_by' => '', 'job_order_id' => '', 'items' => $items]), 'PR');
    PurchaseRequests::create($prData, $cashier);
    $prId = lastId('purchase_requests');
    $lines = q('SELECT id, product_id, qty_requested FROM purchase_request_lines WHERE request_id = ? ORDER BY id', [$prId])->fetchAll();

    $admin = actAs($STAFF[$br]['admin'], $br);
    PurchaseRequests::approve($prId, array_combine(array_column($lines, 'id'), array_map('strval', array_column($lines, 'qty_requested'))), null, $admin);

    $poItems = [];
    foreach ($lines as $l) {
        $cost = $COST[(int) $l['product_id']] * (1 + mt_rand(-3, 4) / 100);
        $poItems[] = ['product_id' => s((int) $l['product_id']), 'quantity' => s((int) $l['qty_requested']), 'end_user' => '',
            'unit_cost' => number_format($cost, 2, '.', ''), 'links' => $l['id'] . ':' . $l['qty_requested']];
    }
    $poData = valid(PurchaseOrders::validate(['supplier_id' => s($supplierId), 'order_date' => today(),
        'expected_date' => date('Y-m-d', strtotime(today() . " +{$expectInDays} days")), 'payment_terms' => '30 days',
        'delivery_terms' => 'Deliver to branch', 'notes' => '', 'items' => $poItems]), 'PO');
    $poId = PurchaseOrders::saveDraft(null, $poData, $admin);
    PurchaseOrders::submit($poId, $admin);
    if ($approvePo) {
        actAs(ADMIN, $br);
        PurchaseOrders::approve($poId, (int) one('SELECT id FROM users WHERE username = ?', [ADMIN]));
    }
    $STATS['pos']++;
    return $poId;
};

/** Receive what is still due on a PO (share 0..1 of each line), posted by the branch admin. */
$receivePo = static function (int $poId, float $share = 1.0) use (&$STAFF, &$STATS): void {
    $po = q('SELECT branch_id, po_no FROM purchase_orders WHERE id = ?', [$poId])->fetch();
    $br = (int) $po['branch_id'];
    $admin = actAs($STAFF[$br]['admin'], $br);
    $items = [];
    foreach (q('SELECT l.product_id, l.qty_ordered - l.qty_received AS due, l.unit_cost, p.track_serial
                  FROM purchase_order_lines l JOIN products p ON p.id = l.product_id WHERE l.po_id = ? ORDER BY l.id', [$poId]) as $l) {
        $qty = (int) max(1, min((int) $l['due'], round((int) $l['due'] * $share)));
        if ((int) $l['due'] < 1) {
            continue;
        }
        $items[] = ['product_id' => s((int) $l['product_id']), 'quantity' => s($qty), 'unit_cost' => (string) $l['unit_cost'],
            'serials' => (int) $l['track_serial'] === 1 ? newSerials((int) $l['product_id'], $qty) : ''];
    }
    if (!$items) {
        return;
    }
    $d = valid(Receiving::validate(['received_date' => today(), 'reference_no' => 'SI-' . mt_rand(10000, 99999), 'notes' => '',
        'items' => $items], $poId), "RR for {$po['po_no']}");
    $rrId = Receiving::saveDraft(null, $d, $admin);
    Receiving::post($rrId, $admin);
    $STATS['rrs']++;
};

/** Receiving without a PO (opening stock of a branch). */
$receiveDirect = static function (int $br, int $supplierId, array $qtys, string $ref) use (&$STAFF, &$COST, &$STATS): void {
    $admin = actAs($STAFF[$br]['admin'], $br);
    $items = [];
    foreach ($qtys as $pid => $q) {
        $items[] = ['product_id' => s($pid), 'quantity' => s($q), 'unit_cost' => number_format($COST[$pid], 2, '.', ''),
            'serials' => in_array($pid, [13, 14], true) ? newSerials($pid, $q) : ''];
    }
    $d = valid(Receiving::validate(['supplier_id' => s($supplierId), 'received_date' => today(), 'reference_no' => $ref, 'notes' => 'Opening stock',
        'items' => $items]), "RR {$ref}");
    Receiving::post(Receiving::saveDraft(null, $d, $admin), $admin);
    $STATS['rrs']++;
};

/** Branch transfer: $to requests from $from; approve + release by $from; receive by $to unless $receive = false. */
$transfer = static function (int $from, int $to, array $qtys, string $notes, bool $approve = true, bool $release = true, bool $receive = true)
    use (&$STAFF, &$STATS): void {
    $u = actAs($STAFF[$to]['admin'], $to);
    $items = [];
    foreach ($qtys as $pid => $q) {
        $q = min($q, free($pid, $from));
        if ($q > 0) {
            $items[] = ['product_id' => s($pid), 'quantity' => s($q)];
        }
    }
    if (!$items) {
        return;
    }
    Transfers::request(valid(Transfers::validateRequest(['from_branch_id' => s($from), 'notes' => $notes, 'items' => $items]), 'transfer'), $u);
    $id = lastId('stock_transfers');
    $STATS['transfers']++;
    if (!$approve) {
        return;
    }
    $lines = q('SELECT l.id, l.product_id, l.qty_requested, p.track_serial FROM stock_transfer_lines l JOIN products p ON p.id = l.product_id
                 WHERE l.transfer_id = ? ORDER BY l.id', [$id])->fetchAll();
    $a = actAs($STAFF[$from]['admin'], $from);
    Transfers::approve($id, array_combine(array_column($lines, 'id'), array_map('strval', array_column($lines, 'qty_requested'))), $a);
    if (!$release) {
        return;
    }
    $serials = [];
    foreach ($lines as $l) {
        if ((int) $l['track_serial'] === 1) {
            $serials[(int) $l['id']] = serialIds((int) $l['product_id'], $from, (int) $l['qty_requested']);
        }
    }
    Transfers::release($id, $serials, $a);
    if (!$receive) {
        return;
    }
    clock(date('Y-m-d H:i:s', strtotime($GLOBALS['NOW'] . ' +1 day +2 hours')));
    $r = actAs($STAFF[$to]['admin'], $to);
    $got = q('SELECT id, qty_released FROM stock_transfer_lines WHERE transfer_id = ?', [$id])->fetchAll();
    $arrived = array_map('intval', q('SELECT s.serial_id FROM stock_transfer_serials s JOIN stock_transfer_lines l ON l.id = s.line_id
                                       WHERE l.transfer_id = ?', [$id])->fetchAll(PDO::FETCH_COLUMN));
    Transfers::receive($id, array_combine(array_column($got, 'id'), array_map('strval', array_column($got, 'qty_released'))), $arrived, null, $r);
};

// POS sales ------------------------------------------------------------------------------------------------------
$POPULAR = [2 => 10, 3 => 7, 12 => 5, 11 => 4, 9 => 4, 10 => 4, 7 => 3, 4 => 3, 5 => 2, 6 => 1.2, 8 => 1.2, 1 => 0.8,
            13 => 0.8, 14 => 0.25, 15 => 6, 16 => 4, 17 => 0.3, 18 => 2];
$MAXQ = [2 => 3, 3 => 2, 15 => 5, 16 => 3, 12 => 2, 11 => 2, 9 => 2];
$REASONS = ['Loyal customer', 'Price match with nearby shop', 'Bulk purchase', 'Old stock / box opened', 'Suki discount'];

$sale = static function (int $br) use (&$STAFF, &$CUST, &$POPULAR, &$MAXQ, &$REASONS, &$STATS): ?int {
    $isAdmin = chance(0.25);
    $uid = actAs($isAdmin ? $STAFF[$br]['admin'] : $STAFF[$br]['cashier'], $br);
    $lines = weighted([1 => 62, 2 => 28, 3 => 10]);
    $qty = $serials = $pricing = [];
    $subtotal = 0;
    for ($i = 0; $i < 6 && count($qty) < $lines; $i++) {
        $pid = (int) weighted(array_diff_key($POPULAR, $qty));
        $avail = free($pid, $br);
        if ($avail < 1) {
            continue;
        }
        $q = min($avail, mt_rand(1, $MAXQ[$pid] ?? 1));
        $price = (int) round((float) one('SELECT price FROM products WHERE id = ?', [$pid]) * 100);
        if (in_array($pid, [13, 14], true)) {
            $serials[$pid] = serialIds($pid, $br, $q);
            if (count($serials[$pid]) < $q) {
                unset($serials[$pid]);
                continue;
            }
        }
        if (chance($isAdmin ? 0.12 : 0.06)) {
            $drop = mt_rand(1, $isAdmin ? 10 : 5);
            $price = (int) (round($price * (100 - $drop) / 100 / 100) * 100); // whole pesos
            $pricing[$pid] = ['price' => $price, 'reason' => pick($REASONS)];
        }
        $qty[$pid] = $q;
        $subtotal += $price * $q;
    }
    if (!$qty) {
        $STATS['skipped']++;
        return null;
    }
    $discount = chance(0.05) ? (float) mt_rand(2, 5) : 0.0;
    $payment  = (string) weighted(['cash' => 64, 'gcash' => 26, 'card' => 10]);
    $total    = $subtotal * (1 - $discount / 100) * 1.12;
    $paid     = $payment === 'cash' ? (int) (ceil(($total + 100) / 10000) * 10000) : null;
    $customer = chance(0.3) && $CUST[$br] ? pick($CUST[$br]) : null;
    if ($isAdmin && chance(0.2)) { // on account (sales.charge): a company / reseller / school with credit terms
        $credit = array_map('intval', q('SELECT c.id FROM customers c JOIN customer_branches cb ON cb.customer_id = c.id
                                          WHERE cb.branch_id = ? AND c.credit_days > 0 AND c.customer_type_id IN (3, 4, 5)', [$br])->fetchAll(PDO::FETCH_COLUMN));
        if ($credit) {
            [$payment, $paid, $customer] = ['charge', null, pick($credit)];
        }
    }
    try {
        Sales::complete($uid, $qty, $customer, $payment, $discount, $paid, $serials, $pricing);
        $STATS['sales']++;
        return lastId('sales');
    } catch (HttpException $e) {
        $STATS['skipped']++;
        return null;
    }
};

/** Re-order what runs low at a branch (PR → PO), received a few days later. */
$restock = static function (int $br, int $day) use (&$purchase, &$supplierFor, &$QUEUE): void {
    $sold = [];
    foreach (q("SELECT si.product_id, SUM(si.quantity) q FROM sale_items si JOIN sales s ON s.id = si.sale_id
                 WHERE s.branch_id = ? AND s.status = 'completed' AND s.created_at >= NOW() - INTERVAL 14 DAY AND si.product_id IS NOT NULL
                 GROUP BY si.product_id", [$br]) as $r) {
        $sold[(int) $r['product_id']] = (int) $r['q'];
    }
    $open = array_map('intval', q("SELECT DISTINCT l.product_id FROM purchase_order_lines l JOIN purchase_orders o ON o.id = l.po_id
                                    WHERE o.branch_id = ? AND o.status IN ('draft','pending','approved','partial')", [$br])->fetchAll(PDO::FETCH_COLUMN));
    $bySupplier = [];
    foreach (q('SELECT id, reorder_level FROM products WHERE is_active = 1 ORDER BY id') as $p) {
        $pid = (int) $p['id'];
        if (in_array($pid, $open, true) || ($br !== MAR && in_array($pid, [14, 17], true))) {
            continue;
        }
        $have   = balance($pid, $br);
        $weekly = (int) ceil(($sold[$pid] ?? 0) / 2);
        $target = max((int) $p['reorder_level'] * 3, (int) ceil($weekly * 3.2));
        if ($weekly === 0 && $have > 0) {
            continue;
        }
        if ($have <= max((int) $p['reorder_level'], $weekly) && $target - $have > 0) {
            $bySupplier[$supplierFor($pid)][$pid] = $target - $have;
        }
    }
    foreach ($bySupplier as $sup => $qtys) {
        $poId = $purchase($br, $qtys, 'Replenishment: low stock', $sup, 4);
        $in = $day + mt_rand(2, 5);
        if (chance(0.2)) {
            $QUEUE[$in][] = ['10:30', static fn () => $GLOBALS['receivePo']($poId, 0.6)];
            $QUEUE[$in + mt_rand(3, 6)][] = ['14:10', static fn () => $GLOBALS['receivePo']($poId, 1.0)];
        } else {
            $QUEUE[$in][] = ['10:30', static fn () => $GLOBALS['receivePo']($poId, 1.0)];
        }
    }
};
$GLOBALS['receivePo'] = $receivePo;

// Job orders --------------------------------------------------------------------------------------------------------
$PROBLEMS = [
    [1, 7, 'Acer', 'Aspire 5', 'No display, power LED on', 'Faulty RAM module; reseated and replaced 8GB RAM', 9, 650, true],
    [1, 7, 'Lenovo', 'IdeaPad 3', 'Very slow, takes 10 minutes to boot', 'HDD failing (bad sectors); cloned to new SSD 512GB', 10, 800, true],
    [2, 8, 'Generic', 'Core i3 tower', 'Overheating and shuts down', 'Dusty heatsink, dried thermal paste; cleaned and repasted', null, 600, false],
    [3, 7, 'Epson', 'L3210', 'Prints with lines / faded', 'Clogged print head; head cleaning + ink flush', 16, 450, false],
    [1, 10, 'HP', '14s', 'Wi-Fi keeps disconnecting', 'Outdated driver and power-saving setting; updated drivers', null, 350, false],
    [2, 9, 'Generic', 'Office PC', 'Install Windows + MS Office, transfer files', 'OS + Office installed, data transferred', null, 900, false],
    [4, 7, 'Samsung', 'S24R350', 'Flickering screen', 'Loose cable and faulty power adapter; adapter replaced', null, 500, false],
    [5, 7, 'TP-Link', 'Archer C6', 'No internet on LAN ports', 'Reset and reconfigured; firmware updated', null, 300, false],
    [1, 7, 'Acer', 'Nitro 5', 'Laptop not charging', 'Charging port loose; resoldered DC jack', null, 1200, false],
    [2, 7, 'Generic', 'Ryzen 5 build', 'Random restarts', 'Weak PSU tested; advised replacement UPS 1000VA', 5, 400, true],
];

/** One job's life, step by step on later days; steps after "now" never happen (open jobs at the end). */
$job = static function (int $br, int $day) use (&$STAFF, &$CUST, &$PROBLEMS, &$QUEUE, &$STATS, $RUN_T0): void {
    [$dev, $type, $brand, $model, $problem, $fix, $partPid, $labor, $quote] = pick($PROBLEMS);
    $cashier = actAs($STAFF[$br]['cashier'], $br);
    $cust = chance(0.6) && $CUST[$br] ? pick($CUST[$br]) : null;
    $in = ['device_type_id' => s($dev), 'job_type_id' => s($type), 'brand' => $brand, 'model' => $model,
        'serial_no' => chance(0.7) ? strtoupper(substr(md5((string) mt_rand()), 0, 10)) : '', 'problem' => $problem,
        'accessories' => pick(['Charger', 'Charger + bag', 'None', 'Power cable']), 'device_condition' => pick(['Minor scratches', 'Good', 'Cracked bezel']),
        'priority' => (string) weighted(['normal' => 70, 'high' => 20, 'urgent' => 5, 'low' => 5]), 'service_location' => 'in_shop', 'remarks' => ''];
    if ($cust !== null) {
        $in['customer_id'] = s($cust);
    } else {
        $in['customer_name'] = pick(['Ronald Saldua', 'Cherry Mae Lumantas', 'Jun Rey Ebdane', 'Analyn Pabillore', 'Ricky Dagondon']);
        $in['customer_phone'] = '09' . mt_rand(10, 99) . ' ' . mt_rand(100, 999) . ' ' . mt_rand(1000, 9999);
    }
    JobOrders::create($in, $cashier);
    $id = lastId('job_orders');
    $STATS['jobs']++;
    $techs = $STAFF[$br]['techs'];
    $tech  = pick($techs);
    $fixTimes = static function () use ($id, $RUN_T0): void { // JobOrders stamps these columns from PHP's clock
        foreach (['assigned_at', 'diagnosed_at', 'approval_at', 'completed_at', 'cancelled_at'] as $c) {
            q("UPDATE job_orders SET {$c} = NOW() WHERE id = ? AND {$c} >= ?", [$id, $RUN_T0]);
        }
    };
    $act = static function (string $user, string $action, array $in = []) use ($id, $br, $fixTimes): void {
        JobOrders::act($id, $action, $in, actAs($user, $br));
        $fixTimes();
    };
    $estimate = $labor + ($partPid ? (int) one('SELECT price FROM products WHERE id = ?', [$partPid]) : 0);
    $quote = $quote || $day > DEMO_LAST - 4; // the last jobs wait for the customer's answer
    $declined = $quote && chance(0.15);
    $steps = [
        [1, '08:40', static fn () => $act($tech, 'take')],
        [0, '09:05', static fn () => $act($tech, 'start')],
        [0, '14:00', static fn () => $act($tech, 'diagnose', ['diagnosis' => $fix, 'estimate' => s($estimate), 'ask_customer' => $quote ? '1' : ''])],
    ];
    $asked = $quote || $estimate > 1000;
    if ($asked) {
        $steps[] = [1, '09:40', static fn () => $act($STAFF[$br]['cashier'], 'decision', ['decision' => $declined ? 'decline' : 'approve',
            'method' => pick(['phone', 'sms', 'in_person']), 'by_name' => 'Customer', 'note' => ''])];
    }
    if (!$declined) {
        if ($partPid) {
            $steps[] = [$asked ? 0 : 1, '11:00', static function () use ($id, $br, $tech, $partPid, $STAFF, $act, $day): int {
                if (free($partPid, $br) < 1 || $day > DEMO_LAST - 9) { // nothing free: wait for the supplier, then repair without a part from stock
                    $act($tech, 'wait_parts', ['note' => 'Waiting for ' . one('SELECT name FROM products WHERE id = ?', [$partPid]) . ' from the supplier']);
                    return mt_rand(3, 6);
                }
                JobParts::request($id, ['product_id' => s($partPid), 'quantity' => '1', 'note' => 'For the repair'], actAs($tech, $br));
                $part = lastId('job_order_parts');
                JobParts::issue($part, ['quantity' => '1'], actAs($STAFF[$br]['admin'], $br));
                JobParts::useParts($part, ['quantity' => '1'], actAs($tech, $br));
                return 0;
            }];
        }
        $steps[] = [1, '10:15', static function () use ($id, $act, $tech): void {
            if (one('SELECT status FROM job_orders WHERE id = ?', [$id]) === 'waiting_parts') {
                $act($tech, 'resume', ['note' => 'Part arrived, repair continues']);
            }
            $act($tech, 'to_testing');
        }];
        $steps[] = [0, '16:00', static fn () => $act($tech, 'complete', ['resolution' => $fix . '. Tested OK.', 'labor' => s($labor)])];
        $steps[] = [mt_rand(1, 3), '11:30', static function () use ($id, $br, $STAFF): void {
            if (chance(0.1)) {
                JobBilling::releaseFree($id, 'warranty', ['released_to' => (string) one('SELECT customer_name FROM job_orders WHERE id = ?', [$id]), 'release_note' => 'Covered by store warranty', 'stub' => '1'],
                    actAs($STAFF[$br]['admin'], $br));
            } else {
                $u = actAs($STAFF[$br]['cashier'], $br);
                $pay = (string) weighted(['cash' => 70, 'gcash' => 25, 'card' => 5]);
                JobBilling::bill($id, ['payment_type' => $pay, 'discount_percent' => '', 'amount_paid' => $pay === 'cash' ? '50000' : '',
                    'released_to' => (string) one('SELECT customer_name FROM job_orders WHERE id = ?', [$id]), 'release_note' => '', 'stub' => '1'], $u);
            }
        }];
    } else {
        $steps[] = [1, '15:00', static function () use ($id, $br, $STAFF): void {
            JobBilling::releaseFree($id, 'no_charge', ['released_to' => (string) one('SELECT customer_name FROM job_orders WHERE id = ?', [$id]), 'release_note' => 'Declined the quotation, unit returned', 'stub' => '1'],
                actAs($STAFF[$br]['cashier'], $br));
        }];
    }
    // Steps run in order; each one schedules the next (a step may return extra days, e.g. waiting for parts).
    $chain = static function (int $i, int $fromDay) use (&$chain, $steps, &$QUEUE): void {
        if (!isset($steps[$i])) {
            return;
        }
        [$gap, $hm, $fn] = $steps[$i];
        $QUEUE[$fromDay + $gap][] = [$hm, static function () use (&$chain, $fn, $i, $fromDay, $gap): void {
            $extra = (int) ($fn() ?? 0);
            $chain($i + 1, $fromDay + $gap + $extra);
        }];
    };
    $chain(0, $day);
};

// Customer orders ----------------------------------------------------------------------------------------------------
/** Order (cashier) → confirmed (admin) unless $stopAt. Quantities shrink to the free stock. Returns the order id or null. */
$order = static function (int $br, int $customerId, array $lines, array $head, string $stopAt = 'confirmed') use (&$STAFF, &$STATS): ?int {
    $u = actAs($STAFF[$br]['cashier'], $br);
    $items = [];
    foreach ($lines as $pid => [$q, $price, $reason]) {
        $q = min($q, free($pid, $br));
        if ($q > 0) {
            $items[] = ['product_id' => s($pid), 'quantity' => s($q), 'unit_price' => s($price ?? (float) one('SELECT price FROM products WHERE id = ?', [$pid])), 'price_reason' => $reason ?? ''];
        }
    }
    if (!$items) {
        return null;
    }
    $c = q('SELECT address FROM customers WHERE id = ?', [$customerId])->fetch();
    $d = valid(CustomerOrders::validate($head + ['customer_id' => s($customerId), 'place_of_delivery' => (string) $c['address'],
        'delivery_term' => 'Within 15 calendar days', 'notes' => '', 'items' => $items]), 'customer order');
    $id = CustomerOrders::saveDraft(null, $d, $u);
    $STATS['orders']++;
    if ($stopAt === 'draft') {
        return $id;
    }
    CustomerOrders::submit($id, $u);
    if ($stopAt === 'pending') {
        return $id;
    }
    CustomerOrders::confirm($id, actAs($STAFF[$br]['admin'], $br));
    return $id;
};
/** Delivery receipt for $share of what is still due (admin), optionally marked delivered the next day. */
$deliver = static function (int $orderId, float $share = 1.0) use (&$STAFF): int {
    $br = (int) one('SELECT branch_id FROM customer_orders WHERE id = ?', [$orderId]);
    $u = actAs($STAFF[$br]['admin'], $br);
    $qtys = $serials = [];
    foreach (q('SELECT l.id, l.product_id, l.qty_ordered - l.qty_delivered AS due, p.track_serial FROM customer_order_lines l
                  JOIN products p ON p.id = l.product_id WHERE l.order_id = ? ORDER BY l.id', [$orderId]) as $l) {
        $q = (int) min((int) $l['due'], max(1, round((int) $l['due'] * $share)));
        if ((int) $l['due'] < 1) {
            continue;
        }
        $qtys[(int) $l['id']] = s($q);
        if ((int) $l['track_serial'] === 1) {
            $serials[(int) $l['id']] = array_map('strval', serialIds((int) $l['product_id'], $br, $q));
        }
    }
    CustomerDeliveries::release($orderId, $qtys, $serials, pick(['Company truck (Jun Bacalso)', 'Lalamove', 'Branch staff (motorcycle)']), null, $u);
    return lastId('customer_deliveries');
};
$received = static function (int $drId, string $by, string $iar) use (&$STAFF): void {
    $br = (int) one('SELECT o.branch_id FROM customer_deliveries d JOIN customer_orders o ON o.id = d.order_id WHERE d.id = ?', [$drId]);
    CustomerDeliveries::markDelivered($drId, ['received_by' => $by, 'received_date' => today(), 'acceptance_ref' => $iar], actAs($STAFF[$br]['admin'], $br));
};
$billOrder = static function (int $orderId, string $payment) use (&$STAFF): void {
    $br  = (int) one('SELECT branch_id FROM customer_orders WHERE id = ?', [$orderId]);
    $drs = array_map('intval', q("SELECT id FROM customer_deliveries WHERE order_id = ? AND status <> 'cancelled' AND sale_id IS NULL", [$orderId])->fetchAll(PDO::FETCH_COLUMN));
    if ($drs) {
        CustomerOrders::bill($orderId, $drs, ['payment_type' => $payment, 'amount_paid' => $payment === 'cash' ? '9999999' : ''],
            actAs($STAFF[$br]['cashier'], $br));
    }
};

// Quotations ---------------------------------------------------------------------------------------------------------
/** Quotation by the cashier, sent unless $stopAt = 'draft'. $lines: [pid => [qty, price|null, reason|null]]. Returns its id. */
$quote = static function (int $br, int $customerId, array $lines, array $head, string $stopAt = 'sent') use (&$STAFF, &$STATS): int {
    $u = actAs($STAFF[$br]['cashier'], $br);
    $items = [];
    foreach ($lines as $pid => [$q, $price, $reason]) {
        $items[] = ['product_id' => s($pid), 'quantity' => s($q), 'unit_price' => s($price ?? (float) one('SELECT price FROM products WHERE id = ?', [$pid])),
            'price_reason' => $reason ?? ''];
    }
    $id = Quotations::save(null, valid(Quotations::validate($head + ['customer_id' => s($customerId), 'quote_date' => today(),
        'valid_until' => date('Y-m-d', strtotime(today() . ' +15 days')), 'attention' => '', 'rfq_no' => '', 'rfq_date' => '', 'end_user' => '',
        'delivery_term' => 'Within 15 calendar days upon receipt of PO', 'payment_term' => '30 days after acceptance',
        'warranty' => '1 year parts and service', 'notes' => '', 'items' => $items]), 'quotation'), $u);
    $STATS['quotes']++;
    if ($stopAt !== 'draft') {
        Quotations::send($id, $u);
    }
    return $id;
};
$QT = [];

// Billing & payables sweeps ------------------------------------------------------------------------------------------
$CHECK_NO = 100200;
/** Collect on-account bills that are old enough (government after ~25 days with EWT 1% + VAT 5% withheld, others ~12). */
$collect = static function (int $br) use (&$STAFF, &$GOV, &$STATS, &$CHECK_NO): void {
    $slow = $GOV[MLB]['gov'] ?? 0; // never pays in the demo: a 90+ days overdue receivable
    $byCustomer = [];
    foreach (q("SELECT s.id, s.customer_id, s.total, s.settled_amount, DATEDIFF(NOW(), s.created_at) AS age, c.customer_type_id
                  FROM sales s JOIN customers c ON c.id = s.customer_id
                 WHERE s.branch_id = ? AND s.payment_type = 'charge' AND s.status = 'completed' AND s.settled_amount < s.total
                 ORDER BY s.id", [$br]) as $b) {
        $gov = (int) $b['customer_type_id'] === 2;
        if ((int) $b['customer_id'] === $slow || (int) $b['age'] < ($gov ? 18 : 12) || !chance(0.55)) {
            continue;
        }
        $byCustomer[(int) $b['customer_id']][] = $b + ['gov' => $gov];
    }
    if (!$byCustomer) {
        return;
    }
    $u = actAs($STAFF[$br]['cashier'], $br);
    foreach ($byCustomer as $cid => $bills) {
        $gov = $bills[0]['gov'];
        $lines = [];
        foreach ($bills as $b) {
            $bal = to_cents((string) $b['total']) - to_cents((string) $b['settled_amount']);
            if ($gov && to_cents((string) $b['settled_amount']) === 0) {
                $base = (int) round(to_cents((string) $b['total']) / 1.12);
                $ewt  = (int) round($base * 0.01);
                $vat  = (int) round($base * 0.05);
                $lines[$b['id']] = ['amount' => from_cents($bal - $ewt - $vat), 'ewt' => from_cents($ewt), 'vat' => from_cents($vat)];
            } elseif (!$gov && $bal > 300000 && chance(0.25)) { // partial payment
                $lines[$b['id']] = ['amount' => from_cents(intdiv($bal, 2)), 'ewt' => '', 'vat' => ''];
            } else {
                $lines[$b['id']] = ['amount' => from_cents($bal), 'ewt' => '', 'vat' => ''];
            }
        }
        $method = (string) ($gov ? weighted(['check' => 70, 'bank' => 30]) : weighted(['cash' => 35, 'gcash' => 20, 'check' => 30, 'bank' => 15]));
        $ref = match ($method) {
            'check' => (string) ($CHECK_NO += mt_rand(3, 40)),
            'bank'  => 'DEP-' . mt_rand(100000, 999999),
            'gcash' => '10' . mt_rand(10000000, 99999999),
            default => '',
        };
        Collections::post(valid(Collections::validate(['customer_id' => s($cid), 'collection_date' => today(), 'method' => $method,
            'reference' => $ref, 'bank_name' => in_array($method, ['check', 'bank'], true) ? ($gov ? 'Land Bank' : pick(['BPI', 'BDO', 'Metrobank', 'DBP'])) : '',
            'check_date' => '', 'notes' => '', 'lines' => $lines]), 'collection'), $u);
        $STATS['collections']++;
    }
};
/** Daily: deposit checks on hand, clear deposited ones (one bounces), receive withholding certificates, clear issued checks. */
$BOUNCED = false;
$dailyFinance = static function (int $br) use (&$STAFF, &$BOUNCED): void {
    $u = actAs($STAFF[$br]['cashier'], $br);
    foreach (q("SELECT c.id, c.check_status, c.collection_date, c.deposited_at, cu.customer_type_id FROM collections c JOIN customers cu ON cu.id = c.customer_id
                 WHERE c.branch_id = ? AND c.status = 'posted' AND c.check_status IN ('on_hand', 'deposited') ORDER BY c.id", [$br])->fetchAll() as $c) {
        if ($c['check_status'] === 'on_hand' && $c['collection_date'] <= date('Y-m-d', strtotime(today() . ' -2 days')) && chance(0.5)) {
            Collections::checkAction((int) $c['id'], 'deposit', today(), null, $u);
        } elseif ($c['check_status'] === 'deposited' && $c['deposited_at'] <= date('Y-m-d', strtotime(today() . ' -3 days'))) {
            if (!$BOUNCED && (int) $c['customer_type_id'] !== 2) {
                $BOUNCED = true;
                Collections::checkAction((int) $c['id'], 'bounce', null, 'Drawn against insufficient funds', actAs($STAFF[$br]['admin'], $br));
                $u = actAs($STAFF[$br]['cashier'], $br);
            } else {
                Collections::checkAction((int) $c['id'], 'clear', today(), null, $u);
            }
        }
    }
    foreach (q("SELECT id FROM collections WHERE branch_id = ? AND status = 'posted' AND form_2307 = 'pending' AND collection_date <= CURDATE() - INTERVAL 14 DAY",
            [$br])->fetchAll(PDO::FETCH_COLUMN) as $id) {
        if (chance(0.25)) {
            Collections::receiveForm((int) $id, today(), $u);
        }
    }
    $issued = q("SELECT id FROM disbursements WHERE branch_id = ? AND status = 'posted' AND check_status = 'issued' AND check_date <= CURDATE() - INTERVAL 3 DAY",
        [$br])->fetchAll(PDO::FETCH_COLUMN);
    if ($issued) {
        $a = actAs($STAFF[$br]['admin'], $br);
        foreach ($issued as $id) {
            if (chance(0.5)) {
                Payables::clearCheck((int) $id, today(), $a);
            }
        }
    }
};
/** Supplier invoices for posted receiving reports (most within a few days; the last days stay "to invoice"). */
$invoiceRrs = static function (int $br) use (&$STAFF, &$STATS): void {
    $rrs = q("SELECT rr.id, rr.received_date, rr.reference_no, rr.total_cost, s.terms_days FROM receiving_reports rr JOIN suppliers s ON s.id = rr.supplier_id
               WHERE rr.branch_id = ? AND rr.status = 'posted' AND rr.received_date < CURDATE()
                 AND NOT EXISTS (SELECT 1 FROM supplier_invoices si WHERE si.receiving_id = rr.id AND si.status <> 'cancelled') ORDER BY rr.id", [$br])->fetchAll();
    if (!$rrs) {
        return;
    }
    $u = actAs($STAFF[$br]['admin'], $br);
    foreach ($rrs as $rr) {
        if (!chance(0.5)) {
            continue;
        }
        $no = str_starts_with((string) $rr['reference_no'], 'SI-') ? (string) $rr['reference_no'] : 'CI-' . mt_rand(10000, 99999);
        $d = valid(Payables::validateInvoice(['invoice_no' => $no, 'invoice_date' => $rr['received_date'],
            'due_date' => date('Y-m-d', strtotime($rr['received_date'] . ' +' . (int) $rr['terms_days'] . ' days')),
            'amount' => (string) $rr['total_cost'], 'notes' => ''], $rr), 'supplier invoice');
        Payables::createInvoice((int) $rr['id'], $d, $u);
        $STATS['invoices']++;
    }
};
/** Friday: pay supplier invoices due within a week (EWT 1% on goods for the corporations). MLB falls behind at the end. */
$payInvoices = static function (int $br, int $day) use (&$STAFF, &$SUP, &$STATS, &$CHECK_NO): void {
    if ($br === MLB && $day > DEMO_LAST - 14) {
        return;
    }
    $bySupplier = [];
    foreach (q("SELECT id, supplier_id, amount, paid_amount FROM supplier_invoices
                 WHERE branch_id = ? AND status = 'open' AND due_date <= CURDATE() + INTERVAL 7 DAY ORDER BY id", [$br]) as $i) {
        if (chance(0.8)) {
            $bySupplier[(int) $i['supplier_id']][] = $i;
        }
    }
    if (!$bySupplier) {
        return;
    }
    $u = actAs($STAFF[$br]['admin'], $br);
    foreach ($bySupplier as $sid => $invoices) {
        $withhold = in_array($sid, [$SUP['tech'], $SUP['network']], true);
        $lines = [];
        foreach ($invoices as $i) {
            $bal = to_cents((string) $i['amount']) - to_cents((string) $i['paid_amount']);
            $ewt = $withhold && to_cents((string) $i['paid_amount']) === 0 ? (int) round(to_cents((string) $i['amount']) / 1.12 * 0.01) : 0;
            $lines[$i['id']] = ['amount' => from_cents($bal - $ewt), 'ewt' => $ewt ? from_cents($ewt) : ''];
        }
        $method = $sid === $SUP['office'] ? 'cash' : (string) weighted(['check' => 65, 'bank' => 35]);
        Payables::postDv(valid(Payables::validateDv(['supplier_id' => s($sid), 'payment_date' => today(), 'method' => $method,
            'reference' => match ($method) { 'check' => (string) ($CHECK_NO += mt_rand(3, 40)), 'bank' => 'OBT-' . mt_rand(100000, 999999), default => '' },
            'bank_name' => $method === 'cash' ? '' : 'BDO', 'check_date' => '', 'particulars' => 'Payment of supplier invoices',
            'lines' => $lines]), 'disbursement voucher'), $u);
        $STATS['vouchers']++;
    }
};

// ---------------------------------------------------------------------------------------------------------------
// Scripted events: [day => [[time, fn], ...]]
// ---------------------------------------------------------------------------------------------------------------
$QUEUE = [];
$at = static function (int $day, string $hm, callable $fn) use (&$QUEUE): void {
    $QUEUE[$day][] = [$hm, $fn];
};

// Opening stock: new items at MAR via PR → PO → RR; MLB from MAR (transfer) + a direct RR; CDO direct RRs.
$at(1, '09:10', static function () use ($purchase, $SUP, &$OPEN_PO): void {
    $OPEN_PO = $purchase(MAR, [13 => 8, 14 => 4, 15 => 80, 16 => 30, 17 => 4, 18 => 12], 'Stock for new items (laptops, desktop packages, supplies)', $SUP['tech'], 3);
});
$at(4, '10:00', static function () use (&$OPEN_PO, $receivePo): void { $receivePo($OPEN_PO); });
$at(2, '08:30', static fn () => $transfer(MAR, MLB, [2 => 14, 3 => 10, 4 => 6, 7 => 6, 9 => 10, 10 => 6, 11 => 5, 12 => 5, 5 => 3],
    'Opening stock for Malaybalay'));
$at(2, '11:00', static fn () => $receiveDirect(CDO, $SUP['tech'], [1 => 4, 4 => 8, 5 => 4, 7 => 6, 9 => 10, 10 => 8], 'DR-TL-20871'));
$at(2, '11:20', static fn () => $receiveDirect(CDO, $SUP['digital'], [2 => 25, 3 => 18, 11 => 6, 12 => 8], 'SI-DDS-11302'));
$at(2, '11:40', static fn () => $receiveDirect(CDO, $SUP['network'], [6 => 6, 17 => 3, 18 => 8], 'SI-PNS-0457'));
$at(3, '09:30', static fn () => $receiveDirect(MLB, $SUP['office'], [8 => 3, 15 => 40, 16 => 15], 'SI-NOE-2291'));
$at(3, '09:50', static fn () => $receiveDirect(MLB, $SUP['tech'], [1 => 3, 6 => 3], 'DR-TL-20890'));
$at(5, '14:00', static fn () => $receiveDirect(CDO, $SUP['office'], [8 => 3, 15 => 40, 16 => 20], 'SI-NOE-2302'));
$at(6, '10:00', static fn () => $transfer(MAR, CDO, [13 => 2], 'Two Lenovo units for the CDO display'));

// Periodic branch transfers
foreach ([24, 45, 66, 87] as $d) {
    $at($d, '09:00', static fn () => $transfer(MAR, MLB, [2 => 8, 3 => 6, 15 => 15, 13 => 1], 'Weekly top-up from Maramag'));
}
$at(52, '09:30', static fn () => $transfer(CDO, MLB, [18 => 2, 6 => 1], 'Routers for a Malaybalay client'));

// Stock operations at MAR + a count at MLB + an adjustment at CDO
$at(20, '16:30', static function () use ($STAFF): void {
    $u = actAs($STAFF[MAR]['admin'], MAR);
    if (free(2, MAR) < 2) {
        return;
    }
    $damaged = (int) one("SELECT l.id FROM storage_locations l JOIN warehouses w ON w.id = l.warehouse_id WHERE w.branch_id = ? AND l.kind = 'damaged'", [MAR]);
    InventoryDocs::transfer(valid(InventoryDocs::validate('transfer', ['from_location_id' => s(loc(MAR)), 'to_location_id' => s($damaged),
        'reason' => 'Scroll wheel defective (customer return)', 'items' => [['product_id' => '2', 'quantity' => '2']]]), 'damage'), $u);
});
$at(25, '10:00', static function () use ($STAFF): void {
    $u = actAs($STAFF[MAR]['admin'], MAR);
    if (!serialIds(13, MAR, 1)) {
        return;
    }
    $display = (int) one("SELECT l.id FROM storage_locations l JOIN warehouses w ON w.id = l.warehouse_id WHERE w.branch_id = ? AND l.kind = 'display'", [MAR]);
    InventoryDocs::transfer(valid(InventoryDocs::validate('transfer', ['from_location_id' => s(loc(MAR)), 'to_location_id' => s($display),
        'reason' => 'Demo unit for the showroom', 'items' => [['product_id' => '13', 'quantity' => '1', 'serial_ids' => array_map('strval', serialIds(13, MAR, 1))]]]), 'display'), $u);
});
$at(30, '17:00', static function () use ($STAFF): void {
    $u = actAs($STAFF[MAR]['admin'], MAR);
    if (free(15, MAR) < 3) {
        return;
    }
    InventoryDocs::issue(valid(InventoryDocs::validate('issue', ['from_location_id' => s(loc(MAR)), 'reason' => 'Office use: receipts and forms',
        'items' => [['product_id' => '15', 'quantity' => '3']]]), 'issue'), $u);
});
$at(41, '15:00', static function () use ($STAFF): void {
    $u = actAs($STAFF[MAR]['admin'], MAR);
    $damaged = (int) one("SELECT l.id FROM storage_locations l JOIN warehouses w ON w.id = l.warehouse_id WHERE w.branch_id = ? AND l.kind = 'damaged'", [MAR]);
    if ((int) one('SELECT COALESCE(SUM(qty), 0) FROM stock_balances WHERE product_id = 2 AND location_id = ?', [$damaged]) < 2) {
        return;
    }
    InventoryDocs::writeOff(valid(InventoryDocs::validate('writeoff', ['from_location_id' => s($damaged),
        'reason' => 'Beyond repair; supplier refused the RMA', 'items' => [['product_id' => '2', 'quantity' => '2']]]), 'write-off'), $u);
});
$at(60, '17:30', static function () use ($STAFF): void {
    $u = actAs($STAFF[MLB]['admin'], MLB);
    $doc = InventoryDocs::createCount(loc(MLB), 2, false, $u);
    $counts = [];
    foreach (q('SELECT id, product_id, system_qty FROM inventory_doc_lines WHERE doc_id = ?', [$doc['id']]) as $l) {
        $counts[(int) $l['id']] = s(max(0, (int) $l['system_qty'] - ((int) $l['product_id'] === 2 ? 1 : 0)));
    }
    InventoryDocs::saveCounts((int) $doc['id'], $counts, [], $u);
    InventoryDocs::submit((int) $doc['id'], $u);
    InventoryDocs::approve((int) $doc['id'], actAs(ADMIN, MLB));
});
$at(73, '11:00', static function () use ($STAFF): void {
    Products::adjustStock(12, 1, 'return', 'Unit returned by customer, sealed', actAs($STAFF[CDO]['admin'], CDO));
});

// Quotations: three are won (the orders below are made from them), one lost, one cancelled, one expired, open ones at the end.
$at(33, '14:00', static function () use ($quote, &$GOV, &$QT): void {
    $QT['lgu'] = $quote(MAR, $GOV[MAR]['gov'], [13 => [3, 31800, 'Public bidding award price'], 8 => [2, null, null], 18 => [2, null, null]],
        ['rfq_no' => 'RFQ-LGU-2026-0655', 'rfq_date' => today(), 'attention' => 'BAC Secretariat', 'end_user' => 'Municipal Treasurer\'s Office']);
});
$at(40, '10:20', static function () use ($quote, &$GOV, $at, $STAFF): void {
    $id = $quote(MLB, $GOV[MLB]['gov2'], [4 => [3, 4350, 'Government price'], 6 => [2, null, null], 17 => [1, null, null]],
        ['rfq_no' => 'PEO-RFQ-2026-071', 'rfq_date' => today(), 'attention' => 'Engr. Lito Sabanal', 'end_user' => 'Planning & Design Section']);
    $at(55, '15:00', static fn () => Quotations::close($id, 'lost', 'Awarded to a lower bidder', actAs($STAFF[MLB]['cashier'], MLB)));
});
$at(44, '09:45', static fn () => $quote(MLB, $GOV[MLB]['gov'], [1 => [5, 24200, 'Government price'], 5 => [5, null, null]],
    ['rfq_no' => 'SDO-RFQ-2026-140', 'rfq_date' => today(), 'attention' => 'Supply Unit', 'end_user' => 'Schools Division ICT Unit'])); // never answered: expired
$at(61, '11:10', static function () use ($quote, &$GOV, $STAFF): void {
    $id = $quote(MAR, $GOV[MAR]['school'], [15 => [40, 250, 'School price'], 16 => [10, null, null]], ['attention' => 'Principal\'s Office'], 'draft');
    Quotations::close($id, 'cancelled', 'Duplicate; replaced by a revised quotation', actAs($STAFF[MAR]['cashier'], MAR));
});
$at(80, '13:30', static function () use ($quote, &$GOV, &$QT): void {
    $QT['cho'] = $quote(CDO, $GOV[CDO]['gov'], [9 => [6, 1750, 'Government price per canvass'], 10 => [6, null, null], 2 => [10, null, null]],
        ['rfq_no' => 'RFQ-CHO-0819', 'rfq_date' => today(), 'attention' => 'Dr. Annaliza Ong', 'end_user' => 'CHO Records Section']);
});
$at(DEMO_LAST - 28, '10:00', static function () use ($quote, &$GOV, &$QT, $STAFF): void {
    $QT['mnhs'] = $quote(MAR, $GOV[MAR]['school'], [14 => [2, null, null], 5 => [2, null, null], 15 => [20, 260, null]], ['attention' => 'ICT Coordinator']);
    $u = actAs($STAFF[MAR]['cashier'], MAR); // the school asked for a better paper price: revised and sent again
    Quotations::revise($QT['mnhs'], $u);
    $q = Quotations::find($QT['mnhs']);
    $items = array_map(static fn (array $l): array => ['product_id' => s((int) $l['product_id']), 'quantity' => s((int) $l['quantity']),
        'unit_price' => (int) $l['product_id'] === 15 ? '250' : (string) $l['unit_price'], 'price_reason' => (int) $l['product_id'] === 15 ? 'School price' : ''], $q['lines']);
    Quotations::save($QT['mnhs'], valid(Quotations::validate(['customer_id' => s((int) $q['customer_id']), 'quote_date' => today(),
        'valid_until' => date('Y-m-d', strtotime(today() . ' +15 days')), 'attention' => 'ICT Coordinator', 'rfq_no' => '', 'rfq_date' => '', 'end_user' => '',
        'delivery_term' => 'Within 15 calendar days upon receipt of PO', 'payment_term' => '30 days after acceptance', 'warranty' => '1 year parts and service',
        'notes' => 'Revised: school price on bond paper', 'items' => $items]), 'quotation revision'), $u);
    Quotations::send($QT['mnhs'], $u);
});
$at(DEMO_LAST - 6, '15:20', static fn () => $quote(CDO, $GOV[CDO]['private'], [13 => [4, 31500, 'Volume order'], 18 => [4, null, null]],
    ['attention' => 'Admin Office', 'end_user' => 'Review Center Admin Office']));
$at(DEMO_LAST - 1, '11:00', static fn () => $quote(MLB, $CUST[MLB][2], [8 => [1, null, null], 16 => [6, null, null]], ['attention' => 'Clinic Manager'], 'draft'));

// Customer orders
$at(14, '10:00', static function () use ($order, $deliver, $received, $billOrder, &$GOV, $at): void {
    $id = $order(MLB, $GOV[MLB]['gov'], [4 => [4, 4400, 'Government price per quotation'], 5 => [2, null, null], 7 => [3, null, null]], [
        'customer_po_no' => 'PO-2026-06-0457', 'customer_po_date' => today(), 'end_user' => 'Schools Division ICT Unit',
        'due_date' => date('Y-m-d', strtotime(today() . ' +15 days')), 'payment_term' => '30 days after acceptance',
        'procurement_mode' => 'Small Value Procurement', 'award_ref' => 'NOA-2026-118']);
    if ($id === null) {
        return;
    }
    $at(19, '09:00', static function () use ($id, $deliver, $received, $billOrder, $at): void {
        $dr = $deliver($id);
        $at(21, '10:00', static fn () => $received($dr, 'Engr. Ramon Cruz, Supply Officer', 'IAR-2026-0611'));
        $at(26, '14:00', static fn () => $billOrder($id, 'charge'));
    });
});
$at(38, '10:30', static function () use ($order, $deliver, $received, $billOrder, &$GOV, &$QT, $at, $STAFF): void {
    $id = $order(MAR, $GOV[MAR]['gov'], [13 => [3, 31800, 'Public bidding award price'], 8 => [2, null, null], 18 => [2, null, null]], [
        'customer_po_no' => 'LGU-MAR-2026-0712', 'customer_po_date' => today(), 'end_user' => 'Municipal Treasurer\'s Office',
        'due_date' => date('Y-m-d', strtotime(today() . ' +20 days')), 'payment_term' => '30 days after acceptance',
        'procurement_mode' => 'Public Bidding', 'award_ref' => 'BAC Res. 2026-041', 'quotation_id' => s($QT['lgu'] ?? 0)]);
    if ($id === null) {
        return;
    }
    $at(43, '09:00', static function () use ($id, $deliver, $received): void {
        $received($deliver($id, 0.6), 'Lorna Sagaral, MTO', 'IAR-MAR-2026-188');
    });
    $at(49, '09:00', static function () use ($id, $deliver, $at, $STAFF): void {
        $dr = $deliver($id);
        $at(50, '08:30', static fn () => CustomerDeliveries::cancel($dr, 'Wrong unit loaded; redelivering', actAs($STAFF[MAR]['admin'], MAR)));
    });
    $at(51, '09:00', static function () use ($id, $deliver, $received, $billOrder, $at): void {
        $dr = $deliver($id);
        $at(52, '10:00', static fn () => $received($dr, 'Lorna Sagaral, MTO', 'IAR-MAR-2026-201'));
        $at(58, '15:00', static fn () => $billOrder($id, 'charge'));
    });
});
$at(50, '13:00', static function () use ($order, &$GOV, $STAFF): void {
    $id = $order(MAR, $GOV[MAR]['private'], [1 => [2, null, null], 5 => [2, null, null]], [
        'customer_po_no' => 'BASC-PO-3381', 'customer_po_date' => today(), 'end_user' => 'Accounting Dept.',
        'due_date' => date('Y-m-d', strtotime(today() . ' +10 days')), 'payment_term' => 'COD', 'procurement_mode' => 'Direct purchase (private)', 'award_ref' => '']);
    if ($id !== null) {
        CustomerOrders::cancel($id, 'Customer cancelled; budget realigned', actAs($STAFF[MAR]['admin'], MAR));
    }
});
$at(68, '11:00', static function () use ($order, $deliver, $received, $billOrder, &$GOV, $at): void {
    $id = $order(CDO, $GOV[CDO]['private'], [1 => [2, 24500, 'Volume order'], 12 => [4, null, null], 11 => [4, null, null]], [
        'customer_po_no' => 'XHRC-PO-0091', 'customer_po_date' => today(), 'end_user' => 'Review Center Admin Office',
        'due_date' => date('Y-m-d', strtotime(today() . ' +7 days')), 'payment_term' => 'Upon delivery', 'procurement_mode' => 'Direct purchase (private)', 'award_ref' => '']);
    if ($id === null) {
        return;
    }
    $at(72, '13:00', static function () use ($id, $deliver, $received, $billOrder): void {
        $received($deliver($id), 'Ma. Teresa Uy', 'Signed DR');
        $billOrder($id, 'card');
    });
});
$at(84, '09:30', static function () use ($order, $deliver, $received, $billOrder, &$GOV, &$QT, $at): void {
    $id = $order(CDO, $GOV[CDO]['gov'], [9 => [6, 1750, 'Government price per canvass'], 10 => [6, null, null], 2 => [10, null, null]], [
        'customer_po_no' => 'CHO-PO-2026-221', 'customer_po_date' => today(), 'end_user' => 'CHO Records Section',
        'due_date' => date('Y-m-d', strtotime(today() . ' +10 days')), 'payment_term' => '30 days after acceptance',
        'procurement_mode' => 'Shopping', 'award_ref' => 'RFQ-CHO-0819', 'quotation_id' => s($QT['cho'] ?? 0)]);
    if ($id === null) {
        return;
    }
    $at(88, '10:00', static function () use ($id, $deliver, $received, $billOrder, $at): void {
        $dr = $deliver($id);
        $at(89, '11:00', static fn () => $received($dr, 'Dr. Annaliza Ong', 'IAR-CHO-2026-077'));
        $at(92, '15:30', static fn () => $billOrder($id, 'charge'));
    });
});
$at(DEMO_LAST - 25, '10:00', static function () use ($order, $deliver, $received, $billOrder, &$GOV, &$QT, $at): void {
    $id = $order(MAR, $GOV[MAR]['school'], [14 => [2, null, null], 5 => [2, null, null], 15 => [20, 250, 'School price']], [
        'customer_po_no' => 'MNHS-2026-044', 'customer_po_date' => today(), 'end_user' => 'ICT Coordinator',
        'due_date' => date('Y-m-d', strtotime(today() . ' +14 days')), 'payment_term' => '30 days after acceptance',
        'procurement_mode' => 'Shopping', 'award_ref' => 'PR-MNHS-0091', 'quotation_id' => s($QT['mnhs'] ?? 0)]);
    if ($id === null) {
        return;
    }
    $at(DEMO_LAST - 20, '09:00', static function () use ($id, $deliver, $received, $billOrder): void {
        $received($deliver($id, 0.5), 'Mr. Dante Lumayag', 'IAR-MNHS-2026-12');
        $billOrder($id, 'charge');
    });
});
$at(DEMO_LAST - 8, '14:00', static fn () => $order(MLB, $GOV[MLB]['gov2'], [4 => [2, null, null], 10 => [3, null, null], 5 => [1, null, null]], [
    'customer_po_no' => 'PEO-PO-2026-138', 'customer_po_date' => today(), 'end_user' => 'Planning & Design Section',
    'due_date' => date('Y-m-d', strtotime(today() . ' +12 days')), 'payment_term' => '30 days after acceptance',
    'procurement_mode' => 'Small Value Procurement', 'award_ref' => 'NOA-PEO-2026-33']));
$at(DEMO_LAST - 2, '10:30', static fn () => $order(CDO, $GOV[CDO]['gov'], [18 => [3, null, null], 6 => [2, null, null]], [
    'customer_po_no' => 'CHO-PO-2026-260', 'customer_po_date' => today(), 'end_user' => 'CHO Admin',
    'due_date' => date('Y-m-d', strtotime(today() . ' +15 days')), 'payment_term' => '30 days after acceptance',
    'procurement_mode' => 'Shopping', 'award_ref' => ''], 'pending'));
$at(DEMO_LAST, '09:15', static fn () => $order(MAR, $GOV[MAR]['gov'], [16 => [12, null, null], 15 => [30, null, null]], [
    'customer_po_no' => 'LGU-MAR-2026-0930', 'customer_po_date' => today(), 'end_user' => 'MSWDO',
    'due_date' => '', 'payment_term' => '30 days after acceptance', 'procurement_mode' => 'Small Value Procurement', 'award_ref' => ''], 'draft'));

// Open purchasing at the end: overdue PO, PO waiting for approval, PR waiting, RR draft, transfers in progress.
$at(DEMO_LAST - 12, '10:00', static fn () => $purchase(CDO, [13 => 2, 9 => 6], 'Laptops for walk-in demand', $SUP['tech'], 5));
$at(DEMO_LAST - 1, '15:00', static fn () => $purchase(MLB, [8 => 2, 16 => 10], 'Printers + ink', $SUP['office'], 5, false));
$at(DEMO_LAST, '08:40', static function () use ($STAFF): void {
    $u = actAs($STAFF[MAR]['cashier'], MAR);
    PurchaseRequests::create(valid(PurchaseRequests::validate(['purpose' => 'Accessories for the holiday season', 'needed_by' => '', 'job_order_id' => '',
        'items' => [['product_id' => '11', 'quantity' => '10', 'end_user' => ''], ['product_id' => '12', 'quantity' => '10', 'end_user' => '']]]), 'PR'), $u);
});
$at(DEMO_LAST, '09:00', static function () use ($STAFF, $SUP, $COST): void {
    $u = actAs($STAFF[MLB]['admin'], MLB);
    Receiving::saveDraft(null, valid(Receiving::validate(['supplier_id' => s($SUP['digital']), 'received_date' => today(), 'reference_no' => 'SI-DDS-11877',
        'notes' => 'Waiting for the supplier invoice', 'items' => [['product_id' => '2', 'quantity' => '12', 'unit_cost' => s($COST[2]), 'serials' => '']]]), 'RR draft'), $u);
});
$at(DEMO_LAST - 1, '09:00', static fn () => $transfer(MAR, MLB, [12 => 3, 11 => 2], 'Headsets + webcams for MLB', true, true, false));
$at(DEMO_LAST, '10:00', static fn () => $transfer(MAR, CDO, [7 => 3, 16 => 6], 'External HDDs + ink for CDO', false));

// ---------------------------------------------------------------------------------------------------------------
// Run day by day
// ---------------------------------------------------------------------------------------------------------------
$JOB_EVERY = [MAR => 4, MLB => 7];
for ($d = 1; $d <= $DAYS; $d++) {
    $date = dayDate($d);
    $dow  = (int) date('N', strtotime($date));
    $todo = $QUEUE[$d] ?? [];
    unset($QUEUE[$d]);

    $volume = [MAR => mt_rand(4, 8), MLB => mt_rand(2, 5), CDO => mt_rand(3, 6)];
    foreach ($volume as $br => $n) {
        if ($d < 4 && $br !== MAR) {
            continue; // MLB / CDO open on day 4
        }
        $n = $dow === 7 ? intdiv($n, 2) : $n;
        for ($i = 0; $i < $n; $i++) {
            $todo[] = [sprintf('%02d:%02d', mt_rand(8, 17), mt_rand(0, 59)), static fn () => $sale($br)];
        }
        if ($d % 13 === $br) {
            $todo[] = ['18:10', static function () use ($br, $STAFF, &$STATS): void {
                $id = one("SELECT id FROM sales WHERE branch_id = ? AND status = 'completed' AND job_order_id IS NULL AND customer_order_id IS NULL
                            AND DATE(created_at) = CURDATE() ORDER BY RAND() LIMIT 1", [$br]);
                if ($id) {
                    Sales::void((int) $id, actAs($STAFF[$br]['admin'], $br), pick(['Wrong item rung up', 'Customer changed mind before leaving', 'Duplicate transaction']));
                    $STATS['voids']++;
                }
            }];
        }
    }
    foreach ($JOB_EVERY as $br => $every) {
        if (($d >= 3 && $d % $every === 0) || ($d > $DAYS - 8 && ($br === MAR || $d % 2 === 0))) { // more open jobs at the end
            $todo[] = [sprintf('%02d:%02d', mt_rand(9, 15), mt_rand(0, 59)), static fn () => $job($br, $d)];
        }
    }
    if ($dow === 1 && $d >= 7 && $d < $DAYS) {
        foreach ([MAR, MLB, CDO] as $br) {
            $todo[] = ['18:30', static fn () => $restock($br, $d)];
        }
    }
    if ($d >= 5) {
        foreach ([MAR, MLB, CDO] as $br) {
            $todo[] = ['08:20', static fn () => $dailyFinance($br)];
            if ($d < $DAYS - 2) {
                $todo[] = ['16:45', static fn () => $invoiceRrs($br)];
            }
            if ($dow === 2 || $dow === 4) {
                $todo[] = ['14:20', static fn () => $collect($br)];
            }
            if ($dow === 5) {
                $todo[] = ['15:30', static fn () => $payInvoices($br, $d)];
            }
        }
    }

    while ($todo) {
        usort($todo, static fn ($a, $b) => strcmp($a[0], $b[0]));
        [$hm, $fn] = array_shift($todo);
        $ts = "{$date} {$hm}:" . sprintf('%02d', mt_rand(0, 59));
        if ($ts < $GLOBALS['NOW']) {
            $ts = date('Y-m-d H:i:s', strtotime($GLOBALS['NOW']) + mt_rand(120, 900)); // added during the day: later than now
        }
        if ($ts > $REAL_NOW) {
            continue; // not yet happened
        }
        clock($ts);
        $fn();
        if (!empty($QUEUE[$d])) { // steps scheduled for later today
            array_push($todo, ...$QUEUE[$d]);
            unset($QUEUE[$d]);
        }
    }
    if ($d % 10 === 0) {
        say("Day {$d}/{$DAYS} ({$date}): " . json_encode($STATS));
    }
}

q('SET timestamp = DEFAULT');
// Collections::chargeTerms() dates on-account bills from PHP's clock (today): date them from the simulated sale day instead.
q("UPDATE sales s LEFT JOIN customers c ON c.id = s.customer_id
      SET s.due_date = DATE_ADD(DATE(s.created_at), INTERVAL IF(COALESCE(c.credit_days, 0) > 0, c.credit_days, 30) DAY)
    WHERE s.payment_type = 'charge' AND s.created_at < ?", [$RUN_T0]);
say('Done: ' . json_encode($STATS));
actAs(ADMIN, Branch::ALL);
$checks = Integrity::run();
foreach ($checks as $c) {
    if ($c['rows']) {
        say("INTEGRITY PROBLEM {$c['label']}: " . count($c['rows']) . ' row(s)');
    }
}
say('Stock integrity (All branches): ' . Integrity::problemCount($checks) . ' problem(s) in ' . count($checks) . ' checks.');
