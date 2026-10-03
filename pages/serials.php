<?php
/**
 * Serial Lookup (serials.view): find serial numbers by text or product in the current branch scope,
 * with status, branch/location, the RR that brought it in (receiving.view) and its sale (sales.view).
 * ?id=N shows one serial's history (404 outside the branch scope).
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('serials');

$search    = input_string($_GET, 'search', 60);
$productId = input_int($_GET, 'product', 1);
$serialId  = input_int($_GET, 'id', 1);
$canRr     = Auth::can('receiving.view');
$canSales  = Auth::can('sales.view');
$canItems  = Auth::can('inventory.view');
$canDocs   = Auth::canAny(...InventoryDocs::VIEW_PERMISSIONS);

$results = ($search !== '' || $productId !== null) ? Serials::lookup($search, 100, $productId) : [];
$history = $serialId !== null ? Serials::history($serialId) : null;

$stmt = db()->prepare('SELECT id, code, name, is_active FROM products WHERE track_serial = ? ORDER BY code');
$stmt->execute([1]);
$products = $stmt->fetchAll();

$statusLabel = ['in_stock' => 'In stock', 'sold' => 'Sold', 'removed' => 'Removed'];
$statusBadge = ['in_stock' => 'badge--success', 'sold' => 'badge--info', 'removed' => 'badge--danger'];
$showBranch  = Branch::current() === Branch::ALL;
$listQuery   = array_filter(['search' => $search, 'product' => $productId], static fn ($v) => $v !== '' && $v !== null);
$selfUrl     = static fn (array $extra = []): string => url('pages/serials.php') . (($listQuery + $extra) ? '?' . http_build_query($listQuery + $extra) : '');

$pageStyles = ['css/sales.css', 'css/receiving.css'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Serial Lookup</h1>
        <p class="muted">Find a unit by its serial number: where it is, which receiving report brought it in and where it was sold.</p>
    </div>
    <div class="page-actions">
        <span class="badge badge--period badge--branch"><?= icon('store') ?> <?= e(Branch::label()) ?></span>
    </div>
</div>

<section class="card">
    <form class="toolbar" method="get" action="<?= e(url('pages/serials.php')) ?>" role="search">
        <label class="toolbar__search">
            <?= icon('barcode') ?>
            <input class="form-input form-input--mono" type="search" name="search" maxlength="60" placeholder="Scan or type a serial number (or part of it)"
                   value="<?= e($search) ?>" aria-label="Serial number" autocomplete="off" autofocus>
        </label>
        <select class="form-input" name="product" aria-label="Product">
            <option value="">All serial-tracked products</option>
            <?php foreach ($products as $p): ?>
                <option value="<?= (int) $p['id'] ?>"<?= $productId === (int) $p['id'] ? ' selected' : '' ?>><?= e($p['code'] . ' · ' . $p['name']) ?><?= (int) $p['is_active'] === 1 ? '' : ' (inactive)' ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn--primary"><?= icon('search') ?> Look Up</button>
        <?php if ($listQuery): ?>
            <a class="btn btn--light" href="<?= e(url('pages/serials.php')) ?>">Reset</a>
        <?php endif; ?>
    </form>

    <div class="table-wrap">
        <table class="table table--list serial-table" id="serialResults">
            <thead>
            <tr>
                <th>Serial No.</th>
                <th>Product</th>
                <th>Status</th>
                <th class="col-opt"><?= $showBranch ? 'Branch / Location' : 'Location' ?></th>
                <th>Received</th>
                <th>Last Sale</th>
                <th class="col-opt">Last Stock Document</th>
                <th class="actions-col"><span class="visually-hidden">History</span></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($results as $s): ?>
                <tr>
                    <td class="serial-cell"><?= e($s['serial_no']) ?></td>
                    <td>
                        <?php if ($canItems): ?>
                            <a class="item-cell__name" href="<?= e(url('pages/product-form.php?id=' . (int) $s['product_id'])) ?>"><?= e($s['product_name']) ?></a>
                        <?php else: ?>
                            <strong><?= e($s['product_name']) ?></strong>
                        <?php endif; ?>
                        <small class="muted block"><?= e($s['product_code']) ?></small>
                    </td>
                    <td><span class="badge <?= e($statusBadge[$s['status']] ?? '') ?>"><?= e($statusLabel[$s['status']] ?? $s['status']) ?></span></td>
                    <td class="nowrap col-opt">
                        <?php if ($showBranch): ?><span class="badge badge--branch" title="<?= e($s['branch_name']) ?>"><?= e($s['branch_code']) ?></span><?php endif; ?>
                        <?= e($s['warehouse_code'] . ' / ' . $s['location_code']) ?>
                    </td>
                    <td class="nowrap">
                        <?php if ($s['rr_no'] !== null && $canRr): ?>
                            <a href="<?= e(url('pages/receiving-view.php?id=' . (int) $s['receiving_id'])) ?>"><?= e($s['rr_no']) ?></a>
                        <?php else: ?>
                            <?= e($s['rr_no'] ?? '—') ?>
                        <?php endif; ?>
                    </td>
                    <td class="nowrap">
                        <?php if ($s['sale_no'] === null): ?>
                            <span class="muted">—</span>
                        <?php else: ?>
                            <?php $saleText = 'Sale No. ' . $s['sale_no'] . ($s['sale_status'] === 'cancelled' ? ' (voided)' : ''); ?>
                            <?php if ($canSales): ?>
                                <a href="<?= e(url('pages/sale-view.php?id=' . (int) $s['sale_id'])) ?>"><?= e($saleText) ?></a>
                            <?php else: ?>
                                <?= e($saleText) ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td class="nowrap col-opt">
                        <?php if ($s['last_doc_no'] === null): ?>
                            <span class="muted">—</span>
                        <?php elseif ($canDocs): ?>
                            <a href="<?= e(url('pages/stock-doc-view.php?id=' . (int) $s['last_doc_id'])) ?>"><?= e($s['last_doc_no']) ?></a>
                        <?php else: ?>
                            <?= e($s['last_doc_no']) ?>
                        <?php endif; ?>
                    </td>
                    <td class="actions-col">
                        <div class="row-actions">
                            <a class="icon-btn" href="<?= e($selfUrl(['id' => (int) $s['id']])) ?>" title="History" aria-label="History of serial <?= e($s['serial_no']) ?>"><?= icon('clock') ?></a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$results): ?>
                <tr><td colspan="8" class="empty"><?= $listQuery ? 'No serial numbers found at ' . e(Branch::label()) . '.' : 'Scan or type a serial number, or choose a product.' ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if (count($results) >= 100): ?>
        <p class="form-hint rr-lines__hint">Showing the first 100 matches. Type more of the serial number to narrow the list.</p>
    <?php endif; ?>
</section>

<?php if ($history !== null): ?>
    <section class="card serial-history" id="serialHistory">
        <header class="card__head">
            <h2><?= icon('clock') ?> <span class="serial-cell"><?= e($history['serial_no']) ?></span></h2>
            <span class="badge <?= e($statusBadge[$history['status']] ?? '') ?>"><?= e($statusLabel[$history['status']] ?? $history['status']) ?></span>
        </header>
        <p class="muted rr-lines__hint"><?= e($history['product_code'] . ' · ' . $history['product_name']) ?> · now at <?= e($history['branch_name'] . ' / ' . $history['location_code']) ?></p>
        <?php
        // One timeline: received / registered, sales (+ voids) and posted stock documents, oldest first.
        $events = [];
        $events[] = [
            'ts' => $history['posted_at'] ?? $history['created_at'], 'badge' => 'badge--success',
            'label' => $history['rr_no'] !== null ? 'Received' : 'Registered',
            'text' => $history['rr_no'] ?? 'Existing stock, serial number registered',
            'url' => $history['rr_no'] !== null && $canRr ? url('pages/receiving-view.php?id=' . (int) $history['receiving_id']) : null,
        ];
        foreach ($history['sales'] as $sale) {
            $events[] = [
                'ts' => $sale['created_at'], 'badge' => 'badge--info', 'label' => 'Sold',
                'text' => 'Sale No. ' . $sale['sale_no'] . ' · ' . $sale['branch_name'],
                'url' => $canSales ? url('pages/sale-view.php?id=' . (int) $sale['sale_id']) : null,
            ];
            if ($sale['status'] === 'cancelled') {
                $events[] = [
                    'ts' => $sale['voided_at'], 'badge' => 'badge--danger', 'label' => 'Voided',
                    'text' => 'Sale No. ' . $sale['sale_no'] . ' was voided; the unit went back to stock.', 'url' => null,
                ];
            }
        }
        foreach ($history['documents'] ?? [] as $doc) {
            $fromLoc = $doc['from_warehouse_code'] . ' / ' . $doc['from_location_code'];
            $toLoc   = $doc['to_location_code'] !== null ? $doc['to_warehouse_code'] . ' / ' . $doc['to_location_code'] : null;
            [$badge, $label, $what] = match (true) {
                $doc['doc_type'] === 'transfer' => ['badge--info', InventoryDocs::PURPOSES[$doc['purpose']] ?? 'Transfer', "moved {$fromLoc} → {$toLoc}"],
                $doc['doc_type'] === 'issue'    => ['badge--warning', 'Internal use', "issued from {$fromLoc}; removed from stock"],
                $doc['doc_type'] === 'writeoff' => ['badge--danger', 'Written off', "written off at {$fromLoc}; removed from stock"],
                (int) $doc['found'] === 1       => ['badge--success', 'Counted', "found at {$fromLoc}"],
                default                         => ['badge--danger', 'Missing', "not found when counting {$fromLoc}; removed from stock"],
            };
            $events[] = [
                'ts' => $doc['posted_at'], 'badge' => $badge, 'label' => $label,
                'text' => $doc['doc_no'] . ' · ' . $what,
                'url' => $canDocs ? url('pages/stock-doc-view.php?id=' . (int) $doc['doc_id']) : null,
            ];
        }
        usort($events, static fn (array $a, array $b): int => strcmp((string) $a['ts'], (string) $b['ts']));
        ?>
        <ol class="serial-events">
            <?php foreach ($events as $ev): ?>
                <li>
                    <time><?= e($ev['ts'] ? date('M j, Y g:i A', strtotime($ev['ts'])) : '—') ?></time>
                    <span class="badge <?= e($ev['badge']) ?>"><?= e($ev['label']) ?></span>
                    <?php if ($ev['url'] !== null): ?>
                        <a href="<?= e($ev['url']) ?>"><?= e($ev['text']) ?></a>
                    <?php else: ?>
                        <span><?= e($ev['text']) ?></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    </section>
<?php endif; ?>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
