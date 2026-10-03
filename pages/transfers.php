<?php
/**
 * Branch Transfers (any of Transfers::VIEW_PERMISSIONS): transfers where either branch is in the current
 * scope, work lists for the current branch (to approve / to release / incoming), "Request Stock"
 * (transfers.request, concrete branch). Value column only with products.cost.
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('transfers');

$concrete = Branch::isConcrete();
$filters = [
    'search'    => input_string($_GET, 'search', 100),
    'status'    => is_string($_GET['status'] ?? null) && isset(Transfers::STATUSES[$_GET['status']]) ? $_GET['status'] : '',
    'direction' => $concrete && is_string($_GET['direction'] ?? null) && in_array($_GET['direction'], ['incoming', 'outgoing'], true) ? $_GET['direction'] : '',
];
$pgQuery = array_filter($filters, static fn ($v) => $v !== '');

$pg        = paginate(Transfers::count($filters), 20);
$transfers = Transfers::search($filters, $pg['per_page'], $pg['offset']);
$pgPath    = 'pages/transfers.php';
$returnTo  = 'transfers.php' . (($pgQuery || $pg['page'] > 1) ? '?' . http_build_query($pgQuery + ['page' => $pg['page']]) : '');

$canCost = Auth::can('products.cost');
$current = Branch::current();
$work    = Transfers::workCounts();
$cols    = 7 + ($canCost ? 1 : 0);

$listUrl = static fn (array $q): string => url('pages/transfers.php?' . http_build_query($q));
$tiles = [
    ['To Approve', 'Requests for this branch\'s stock', 'check', $work['approve'], ['direction' => 'outgoing', 'status' => 'requested'], 'transfers.approve'],
    ['To Release', 'Approved, ready to send',            'truck', $work['release'], ['direction' => 'outgoing', 'status' => 'approved'],  'transfers.release'],
    ['Incoming',   'In transit to this branch',          'box',   $work['receive'], ['direction' => 'incoming', 'status' => 'released'],  'transfers.receive'],
];
$tiles = array_values(array_filter($tiles, static fn (array $t): bool => Auth::can($t[5])));

$statusBadge = ['requested' => 'badge--info', 'approved' => 'badge--warning', 'released' => 'badge--warning',
                'received' => 'badge--success', 'cancelled' => 'badge--danger'];

$pageStyles  = ['css/sales.css', 'css/stock-docs.css', 'css/transfers.css'];
$pageScripts = ['js/transfers.js'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Branch Transfers</h1>
        <p class="muted">Request stock from another branch, approve and release requests for your stock, and receive incoming transfers.</p>
    </div>
    <div class="page-actions">
        <span class="badge badge--period badge--branch"><?= icon('store') ?> <?= e(Branch::label()) ?></span>
        <?php if ($concrete && Auth::can('transfers.request')): ?>
            <a class="btn btn--primary" href="<?= e(url('pages/transfer-form.php?return=' . rawurlencode($returnTo))) ?>" id="newTransferBtn"><?= icon('plus') ?> Request Stock</a>
        <?php endif; ?>
    </div>
</div>

<?php if (!$concrete): ?>
    <div class="alert alert--info" role="status">
        <?= icon('info') ?>
        <span>Showing the transfers of all branches. Choose a branch in the top bar to request, approve, release or receive.</span>
    </div>
<?php elseif ($tiles): ?>
    <nav class="op-tiles bt-tiles" aria-label="Work lists">
        <?php foreach ($tiles as [$label, $hint, $ic, $count, $q]): ?>
            <a class="op-tile<?= $count > 0 ? ' has-work' : '' ?>" href="<?= e($listUrl($q)) ?>">
                <span class="op-tile__icon"><?= icon($ic) ?></span>
                <span><strong><?= e($label) ?> <span class="bt-count" data-work="<?= e(strtolower(str_replace(' ', '-', $label))) ?>"><?= $count ?></span></strong><small><?= e($hint) ?></small></span>
            </a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>

<section class="card">
    <form class="toolbar" method="get" action="<?= e(url('pages/transfers.php')) ?>" role="search">
        <label class="toolbar__search">
            <?= icon('search') ?>
            <input class="form-input" type="search" name="search" maxlength="100" placeholder="Transfer no., note, item code or name"
                   value="<?= e($filters['search']) ?>" aria-label="Search transfers">
        </label>
        <?php if ($concrete): ?>
            <select class="form-input" name="direction" aria-label="Direction">
                <option value="">Incoming and outgoing</option>
                <option value="incoming"<?= $filters['direction'] === 'incoming' ? ' selected' : '' ?>>Incoming (to this branch)</option>
                <option value="outgoing"<?= $filters['direction'] === 'outgoing' ? ' selected' : '' ?>>Outgoing (from this branch)</option>
            </select>
        <?php endif; ?>
        <select class="form-input" name="status" aria-label="Status">
            <option value="">All statuses</option>
            <?php foreach (Transfers::STATUSES as $value => $label): ?>
                <option value="<?= e($value) ?>"<?= $filters['status'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn--primary">Filter</button>
        <?php if ($pgQuery): ?>
            <a class="btn btn--light" href="<?= e(url('pages/transfers.php')) ?>">Reset</a>
        <?php endif; ?>
    </form>

    <div class="table-wrap">
        <table class="table table--list bt-table" id="transferTable">
            <thead>
            <tr>
                <th>Transfer</th>
                <th>Requested</th>
                <th>From → To</th>
                <th class="col-opt">Note</th>
                <th class="num col-opt">Lines</th>
                <th class="num">Qty</th>
                <?php if ($canCost): ?><th class="num col-opt">Value</th><?php endif; ?>
                <th>Status</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($transfers as $t): ?>
                <?php
                $viewUrl = url('pages/transfer-view.php?id=' . (int) $t['id'] . '&return=' . rawurlencode($returnTo));
                $dir = $current === (int) $t['to_branch_id'] ? 'Incoming' : ($current === (int) $t['from_branch_id'] ? 'Outgoing' : null);
                ?>
                <tr class="<?= $t['status'] === 'cancelled' ? 'is-void' : '' ?>" data-transfer="<?= e($t['transfer_no']) ?>">
                    <td>
                        <a class="item-cell__name doc-no" href="<?= e($viewUrl) ?>"><?= e($t['transfer_no']) ?></a>
                        <small class="muted block"><?= e($dir !== null ? $dir . ' · ' : '') ?><?= e($t['requested_by_name']) ?></small>
                    </td>
                    <td class="nowrap"><?= e(date('M j, Y', strtotime($t['requested_at']))) ?><small class="muted block"><?= e(date('g:i A', strtotime($t['requested_at']))) ?></small></td>
                    <td class="doc-route">
                        <span class="badge badge--branch" title="<?= e($t['from_name']) ?>"><?= e($t['from_code']) ?></span>
                        <span class="doc-route__arrow" aria-label="to">→</span>
                        <span class="badge badge--branch" title="<?= e($t['to_name']) ?>"><?= e($t['to_code']) ?></span>
                    </td>
                    <td class="col-opt"><?= $t['notes'] !== null ? '<small class="doc-reason">' . e($t['notes']) . '</small>' : '<span class="muted">—</span>' ?></td>
                    <td class="num col-opt"><?= number_format((int) $t['line_count']) ?></td>
                    <td class="num"><?= number_format((int) $t['total_qty']) ?></td>
                    <?php if ($canCost): ?>
                        <td class="num col-opt doc-value"><?= ($t['total_cost'] ?? null) !== null ? e(money($t['total_cost'])) : '<span class="muted">—</span>' ?></td>
                    <?php endif; ?>
                    <td><span class="badge <?= e($statusBadge[$t['status']] ?? '') ?>"><?= e(Transfers::STATUSES[$t['status']] ?? $t['status']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$transfers): ?>
                <tr><td colspan="<?= $cols ?>" class="empty">No branch transfers found<?= $pgQuery ? ' for these filters' : ' yet' ?>.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php require ROOT_PATH . '/includes/pagination.php'; ?>
    <p class="doc-foot muted"><?= icon('info') ?> <span>Stock leaves the sending branch when the transfer is released and is "in transit" (in neither branch)
        until the receiving branch receives it. Approval does not reserve stock.</span></p>
</section>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
