<?php
/**
 * Stock Operations (any of InventoryDocs::VIEW_PERMISSIONS): list of stock documents (transfers,
 * damaged / display units, internal use, write-offs, stock counts) in the current branch scope,
 * "New" shortcuts per permission (concrete branch only) and the New Count dialog (counts.create).
 * Value column only with products.cost (InventoryDocs::search leaves total_cost out otherwise).
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('stock-docs');

// ---------------------------------------------------------------------
// New count (dialog, PRG)
// ---------------------------------------------------------------------
if (is_post()) {
    Csrf::verifyRequest();
    if (input_string($_POST, 'action', 20) !== 'count_create') {
        throw new HttpException(400, 'Unknown action.');
    }
    Auth::requirePermission('counts.create');
    $locationId = input_int($_POST, 'location_id', 1);
    $categoryId = input_int($_POST, 'category_id', 1);
    $keepForm   = static fn () => flash_old(array_filter($_POST, 'is_string') + ['include_zero' => isset($_POST['include_zero']) ? '1' : '0']);
    if ($locationId === null) {
        $keepForm();
        flash_errors(['location_id' => 'Choose the location to count.']);
        flash('error', 'Choose the location to count.');
        redirect('pages/stock-docs.php');
    }
    try {
        $doc = InventoryDocs::createCount($locationId, $categoryId, isset($_POST['include_zero']), (int) Auth::id());
    } catch (HttpException $e) {
        if ($e->status === 403) {
            throw $e;
        }
        $keepForm();
        flash('error', $e->getMessage());
        redirect('pages/stock-docs.php');
    }
    flash('success', "Stock count {$doc['doc_no']} was created. Enter the counted quantities, then submit it for approval.");
    redirect('pages/stock-doc-view.php?id=' . (int) $doc['id']);
}

// ---------------------------------------------------------------------
// Filters + list
// ---------------------------------------------------------------------
$filters = [
    'search' => input_string($_GET, 'search', 100),
    'type'   => is_string($_GET['type'] ?? null) && isset(InventoryDocs::TYPES[$_GET['type']]) ? $_GET['type'] : '',
    'status' => is_string($_GET['status'] ?? null) && isset(InventoryDocs::STATUSES[$_GET['status']]) ? $_GET['status'] : '',
    'from'   => input_date($_GET, 'from'),
    'to'     => input_date($_GET, 'to'),
];
if ($filters['from'] !== null && $filters['to'] !== null && $filters['from'] > $filters['to']) {
    [$filters['from'], $filters['to']] = [$filters['to'], $filters['from']];
}
$pgQuery = array_filter($filters, static fn ($v) => $v !== '' && $v !== null);

$pg       = paginate(InventoryDocs::count($filters), 20);
$docs     = InventoryDocs::search($filters, $pg['per_page'], $pg['offset']);
$pgPath   = 'pages/stock-docs.php';
$returnTo = 'stock-docs.php' . (($pgQuery || $pg['page'] > 1) ? '?' . http_build_query($pgQuery + ['page' => $pg['page']]) : '');

$canCost    = Auth::can('products.cost');
$concrete   = Branch::isConcrete();
$showBranch = Branch::current() === Branch::ALL;
$today      = date('Y-m-d');
$cols       = 7 + ($canCost ? 1 : 0) + ($showBranch ? 1 : 0);

// "New" shortcuts: [label, hint, icon, url or dialog, permission]
$formUrl = static fn (array $q): string => url('pages/stock-doc-form.php?' . http_build_query($q + ['return' => $returnTo]));
$ops = [
    ['Transfer',      'Move stock to another location',    'truck',     $formUrl(['type' => 'transfer', 'preset' => 'move']),    'inventory.transfer'],
    ['Mark Damaged',  'Move units to DAMAGED',              'alert',     $formUrl(['type' => 'transfer', 'preset' => 'damage']),  'inventory.damage'],
    ['Display Unit',  'Put units on DISPLAY / demo',        'laptop',    $formUrl(['type' => 'transfer', 'preset' => 'display']), 'inventory.issue'],
    ['Restore',       'Damaged or display back to stock',   'stock',     $formUrl(['type' => 'transfer', 'preset' => 'restore']), 'inventory.transfer'],
    ['Internal Use',  'Issue stock for company use',        'tag',       $formUrl(['type' => 'issue']),                           'inventory.issue'],
    ['Write-off',     'Remove damaged or lost stock',       'trash',     $formUrl(['type' => 'writeoff']),                        'inventory.damage'],
    ['Stock Count',   'Count a location and post variances', 'clipboard', null,                                                   'counts.create'],
];
$ops = array_values(array_filter($ops, static fn (array $op): bool => Auth::can($op[4])));

// New count dialog data (current branch only).
$countLocations = [];
$categories     = [];
if ($concrete && Auth::can('counts.create')) {
    $countLocations = Warehouses::pickerLocations((int) Branch::current());
    $categories     = Products::categories();
}
$reopenCount = has_old() && old('action') === 'count_create';

$statusBadge = ['open' => 'badge--info', 'submitted' => 'badge--warning', 'posted' => 'badge--success', 'cancelled' => 'badge--danger'];
$typeIcon    = ['transfer' => 'truck', 'issue' => 'tag', 'writeoff' => 'trash', 'count' => 'clipboard'];

$pageStyles  = ['css/sales.css', 'css/stock-docs.css'];
$pageScripts = ['js/stock-docs.js'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Stock Operations</h1>
        <p class="muted">Transfers between locations, damaged and display units, internal use, write-offs and stock counts.</p>
    </div>
    <div class="page-actions">
        <span class="badge badge--period badge--branch"><?= icon('store') ?> <?= e(Branch::label()) ?></span>
    </div>
</div>

<?php if ($ops && !$concrete): ?>
    <div class="alert alert--info" role="status">
        <?= icon('info') ?>
        <span>Showing the stock documents of all branches. Choose a branch in the top bar to move or count its stock.</span>
    </div>
<?php elseif ($ops): ?>
    <nav class="op-tiles" aria-label="New stock document" id="opTiles">
        <?php foreach ($ops as [$label, $hint, $ic, $href]): ?>
            <?php if ($href === null): ?>
                <button type="button" class="op-tile" data-open="countDialog" id="newCountBtn">
                    <span class="op-tile__icon"><?= icon($ic) ?></span>
                    <span><strong><?= e($label) ?></strong><small><?= e($hint) ?></small></span>
                </button>
            <?php else: ?>
                <a class="op-tile" href="<?= e($href) ?>">
                    <span class="op-tile__icon"><?= icon($ic) ?></span>
                    <span><strong><?= e($label) ?></strong><small><?= e($hint) ?></small></span>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>

<section class="card">
    <form class="toolbar" method="get" action="<?= e(url('pages/stock-docs.php')) ?>" role="search">
        <label class="toolbar__search">
            <?= icon('search') ?>
            <input class="form-input" type="search" name="search" maxlength="100" placeholder="Document no., reason, item code or name"
                   value="<?= e($filters['search']) ?>" aria-label="Search stock documents">
        </label>
        <label class="date-field">
            <span>From</span>
            <input class="form-input" type="date" name="from" value="<?= e($filters['from'] ?? '') ?>" max="<?= e($today) ?>">
        </label>
        <label class="date-field">
            <span>To</span>
            <input class="form-input" type="date" name="to" value="<?= e($filters['to'] ?? '') ?>" max="<?= e($today) ?>">
        </label>
        <select class="form-input" name="type" aria-label="Type">
            <option value="">All types</option>
            <?php foreach (InventoryDocs::TYPES as $value => $label): ?>
                <option value="<?= e($value) ?>"<?= $filters['type'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-input" name="status" aria-label="Status">
            <option value="">All statuses</option>
            <?php foreach (InventoryDocs::STATUSES as $value => $label): ?>
                <option value="<?= e($value) ?>"<?= $filters['status'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn--primary">Filter</button>
        <?php if ($pgQuery): ?>
            <a class="btn btn--light" href="<?= e(url('pages/stock-docs.php')) ?>">Reset</a>
        <?php endif; ?>
    </form>

    <div class="table-wrap">
        <table class="table table--list doc-table" id="docTable">
            <thead>
            <tr>
                <th>Document</th>
                <th>Date</th>
                <th>Type</th>
                <th>Location</th>
                <?php if ($showBranch): ?><th class="col-opt">Branch</th><?php endif; ?>
                <th class="num col-opt">Lines</th>
                <th class="num">Qty</th>
                <?php if ($canCost): ?><th class="num col-opt">Value</th><?php endif; ?>
                <th>Status</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($docs as $d): ?>
                <?php
                $viewUrl = url('pages/stock-doc-view.php?id=' . (int) $d['id'] . '&return=' . rawurlencode($returnTo));
                $from    = $d['from_warehouse_code'] . ' / ' . $d['from_location_code'];
                $to      = $d['to_location_code'] !== null ? $d['to_warehouse_code'] . ' / ' . $d['to_location_code'] : null;
                ?>
                <tr class="<?= $d['status'] === 'cancelled' ? 'is-void' : '' ?>">
                    <td>
                        <a class="item-cell__name doc-no" href="<?= e($viewUrl) ?>"><?= e($d['doc_no']) ?></a>
                        <small class="muted block"><?= e($d['created_by_name']) ?></small>
                    </td>
                    <td class="nowrap"><?= e(date('M j, Y', strtotime($d['created_at']))) ?><small class="muted block"><?= e(date('g:i A', strtotime($d['created_at']))) ?></small></td>
                    <td>
                        <span class="doc-type"><?= icon($typeIcon[$d['doc_type']] ?? 'file') ?> <?= e(InventoryDocs::typeLabel($d)) ?></span>
                        <?php if ($d['reason'] !== null && $d['reason'] !== ''): ?><small class="muted block doc-reason"><?= e($d['reason']) ?></small><?php endif; ?>
                    </td>
                    <td class="doc-route">
                        <span class="loc-code"><?= e($from) ?></span>
                        <?php if ($to !== null): ?><span class="doc-route__arrow" aria-label="to">→</span> <span class="loc-code"><?= e($to) ?></span><?php endif; ?>
                    </td>
                    <?php if ($showBranch): ?><td class="col-opt"><span class="badge badge--branch" title="<?= e($d['branch_name']) ?>"><?= e($d['branch_code']) ?></span></td><?php endif; ?>
                    <td class="num col-opt"><?= number_format((int) $d['line_count']) ?></td>
                    <td class="num"><?= $d['status'] === 'posted' || $d['doc_type'] !== 'count' ? number_format((int) $d['total_qty']) : '<span class="muted">—</span>' ?></td>
                    <?php if ($canCost): ?>
                        <td class="num col-opt doc-value"><?= ($d['total_cost'] ?? null) !== null ? e(money($d['total_cost'])) : '<span class="muted">—</span>' ?></td>
                    <?php endif; ?>
                    <td><span class="badge <?= e($statusBadge[$d['status']] ?? '') ?>"><?= e(InventoryDocs::STATUSES[$d['status']] ?? $d['status']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$docs): ?>
                <tr><td colspan="<?= $cols ?>" class="empty">No stock documents found<?= $pgQuery ? ' for these filters' : ' yet' ?>.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php require ROOT_PATH . '/includes/pagination.php'; ?>
    <p class="doc-foot muted"><?= icon('info') ?> <span>Transfers, internal use and write-offs change stock as soon as they are saved.
        A stock count changes stock only when an approver who did not create or count it approves it.</span></p>
</section>

<?php if ($concrete && Auth::can('counts.create')): ?>
    <dialog class="modal" id="countDialog" aria-labelledby="countTitle"<?= $reopenCount ? ' data-reopen' : '' ?>>
        <form class="modal__body" method="post" action="<?= e(url('pages/stock-docs.php')) ?>" novalidate id="countForm">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="count_create">
            <header class="modal__head">
                <h2 id="countTitle">New Stock Count</h2>
                <button type="button" class="modal__close" data-close aria-label="Close"><?= icon('x') ?></button>
            </header>
            <p class="muted">The current quantities are frozen now. Sales made while counting are taken into account when the count is approved.</p>
            <div class="form-grid form-grid--single">
                <label class="form-field">
                    <span class="form-label">Location to count *</span>
                    <select class="form-input" name="location_id" id="countLocation" required<?= invalid('location_id') ?>>
                        <option value="">Choose a location…</option>
                        <?php $lastWh = null; ?>
                        <?php foreach ($countLocations as $l): ?>
                            <?php if ($lastWh !== $l['warehouse_id']): ?>
                                <?php if ($lastWh !== null): ?></optgroup><?php endif; ?>
                                <optgroup label="<?= e($l['warehouse_code'] . ' · ' . $l['warehouse_name']) ?>">
                                <?php $lastWh = $l['warehouse_id']; ?>
                            <?php endif; ?>
                            <option value="<?= $l['id'] ?>"<?= old('location_id') === (string) $l['id'] ? ' selected' : '' ?>><?= e($l['code'] . ' - ' . $l['name']) ?><?= $l['is_default'] ? ' (POS)' : '' ?></option>
                        <?php endforeach; ?>
                        <?php if ($lastWh !== null): ?></optgroup><?php endif; ?>
                    </select>
                    <?= field_error('location_id') ?>
                </label>
                <label class="form-field">
                    <span class="form-label">Category</span>
                    <select class="form-input" name="category_id" id="countCategory">
                        <option value="">All categories</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= (int) $c['id'] ?>"<?= old('category_id') === (string) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="form-hint">Up to <?= InventoryDocs::MAX_COUNT_LINES ?> items per count; narrow by category for big locations.</p>
                </label>
                <label class="check">
                    <input type="checkbox" name="include_zero" value="1" id="countZero"<?= old('include_zero') === '1' ? ' checked' : '' ?>>
                    <span><strong>Include items with zero stock</strong><small class="muted block">To find units that are there but not on record.</small></span>
                </label>
            </div>
            <footer class="modal__foot">
                <button type="button" class="btn btn--light" data-close>Cancel</button>
                <button type="submit" class="btn btn--primary"><?= icon('clipboard') ?> Start Count</button>
            </footer>
        </form>
    </dialog>
<?php endif; ?>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
