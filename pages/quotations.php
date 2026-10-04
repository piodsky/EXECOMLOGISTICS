<?php
/**
 * Customer Orders → Quotations (any of CustomerOrders::VIEW_PERMISSIONS): price quotations for customers' RFQs in the
 * branch scope, work lists (drafts / sent, waiting for an answer / expired), "New Quotation" (customer_orders.manage,
 * concrete branch). A won quotation links to the customer order made from it.
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('customer-orders');

$concrete = Branch::isConcrete();
$statuses = Quotations::STATUSES + ['open' => 'Draft or sent', 'expired' => 'Sent, expired'];
$filters = [
    'search' => input_string($_GET, 'search', 100),
    'status' => is_string($_GET['status'] ?? null) && isset($statuses[$_GET['status']]) ? $_GET['status'] : '',
];
$pgQuery = array_filter($filters, static fn ($v) => $v !== '' && $v !== null);

$pg       = paginate(Quotations::count($filters), 20);
$quotes   = Quotations::search($filters, $pg['per_page'], $pg['offset']);
$pgPath   = 'pages/quotations.php';
$returnTo = 'quotations.php' . (($pgQuery || $pg['page'] > 1) ? '?' . http_build_query($pgQuery + ['page' => $pg['page']]) : '');
$showBranch = Branch::current() === Branch::ALL;
$work  = Quotations::workCounts();
$cols  = 6 + ($showBranch ? 1 : 0);
$today = date('Y-m-d');

$listUrl = static fn (array $q): string => url('pages/quotations.php?' . http_build_query($q));
$tiles = [
    ['Drafts',  'Not given to the customer yet', 'edit',  $work['drafts'],  ['status' => 'draft']],
    ['Sent',    'Waiting for the customer',      'clock', $work['sent'],    ['status' => 'sent']],
    ['Expired', 'Sent, past the validity date',  'alert', $work['expired'], ['status' => 'expired']],
];

$ordersTab = 'quotes';
$page['title'] = 'Quotations';
$pageStyles  = ['css/sales.css', 'css/stock-docs.css', 'css/transfers.css', 'css/reports.css', 'css/purchasing.css'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Customer Orders</h1>
        <p class="muted">Quotations: our prices for a customer's request for quotation (RFQ). When the customer awards it, make the customer PO from it.</p>
    </div>
    <div class="page-actions">
        <span class="badge badge--period badge--branch"><?= icon('store') ?> <?= e(Branch::label()) ?></span>
        <?php if ($concrete && Auth::can('customer_orders.manage')): ?>
            <a class="btn btn--primary" href="<?= e(url('pages/quote-form.php?return=' . rawurlencode($returnTo))) ?>" id="newQuoteBtn"><?= icon('plus') ?> New Quotation</a>
        <?php endif; ?>
    </div>
</div>

<?php require ROOT_PATH . '/includes/orders-nav.php'; ?>

<?php if (!$concrete): ?>
    <div class="alert alert--info" role="status">
        <?= icon('info') ?>
        <span>Showing the quotations of all branches. Choose a branch in the top bar to prepare one.</span>
    </div>
<?php else: ?>
    <nav class="op-tiles bt-tiles" aria-label="Work lists">
        <?php foreach ($tiles as [$label, $hint, $ic, $count, $q]): ?>
            <a class="op-tile<?= $count > 0 ? ' has-work' : '' ?>" href="<?= e($listUrl($q)) ?>">
                <span class="op-tile__icon"><?= icon($ic) ?></span>
                <span><strong><?= e($label) ?> <span class="bt-count" data-work="<?= e(strtolower($label)) ?>"><?= $count ?></span></strong><small><?= e($hint) ?></small></span>
            </a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>

<section class="card">
    <form class="toolbar" method="get" action="<?= e(url('pages/quotations.php')) ?>" role="search">
        <label class="toolbar__search">
            <?= icon('search') ?>
            <input class="form-input" type="search" name="search" maxlength="100" placeholder="Quotation no., customer, RFQ no., end-user, item"
                   value="<?= e($filters['search']) ?>" aria-label="Search quotations">
        </label>
        <select class="form-input" name="status" aria-label="Status">
            <option value="">All statuses</option>
            <?php foreach ($statuses as $value => $label): ?>
                <option value="<?= e($value) ?>"<?= $filters['status'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn--primary">Filter</button>
        <?php if ($pgQuery): ?>
            <a class="btn btn--light" href="<?= e(url('pages/quotations.php')) ?>">Reset</a>
        <?php endif; ?>
    </form>

    <div class="table-wrap">
        <table class="table table--list pu-table" id="quoteTable">
            <thead>
            <tr>
                <th>Quotation</th>
                <th>Customer / RFQ</th>
                <?php if ($showBranch): ?><th class="col-opt">Branch</th><?php endif; ?>
                <th class="col-opt">Valid until</th>
                <th class="num">Amount</th>
                <th>Status</th>
                <th class="col-opt">Order</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($quotes as $q): ?>
                <?php $expired = $q['status'] === 'sent' && $q['valid_until'] !== null && $q['valid_until'] < $today; ?>
                <tr class="<?= $q['status'] === 'cancelled' ? 'is-void' : '' ?>" data-quote="<?= e($q['quote_no']) ?>">
                    <td>
                        <a class="item-cell__name doc-no" href="<?= e(url('pages/quote-view.php?id=' . (int) $q['id'] . '&return=' . rawurlencode($returnTo))) ?>"><?= e($q['quote_no']) ?></a>
                        <small class="muted block"><?= e(date('M j, Y', strtotime($q['quote_date']))) ?> · <?= e($q['created_by_name']) ?></small>
                    </td>
                    <td><?= e($q['customer_name']) ?><small class="muted block"><?= $q['rfq_no'] ? 'RFQ ' . e($q['rfq_no']) : '' ?><?= $q['rfq_no'] && $q['end_user'] ? ' · ' : '' ?><?= e((string) $q['end_user']) ?></small></td>
                    <?php if ($showBranch): ?><td class="col-opt"><span class="badge badge--branch" title="<?= e($q['branch_name']) ?>"><?= e($q['branch_code']) ?></span></td><?php endif; ?>
                    <td class="col-opt nowrap<?= $expired ? ' text-danger' : '' ?>"><?= $q['valid_until'] !== null ? e(date('M j, Y', strtotime($q['valid_until']))) . ($expired ? ' <small>(expired)</small>' : '') : '<span class="muted">—</span>' ?></td>
                    <td class="num doc-value"><?= e(money($q['subtotal'])) ?></td>
                    <td><span class="badge <?= e(Quotations::BADGES[$q['status']] ?? '') ?>"><?= e(Quotations::STATUSES[$q['status']] ?? $q['status']) ?></span></td>
                    <td class="col-opt"><?php if ($q['order_id'] !== null): ?><a href="<?= e(url('pages/co-view.php?id=' . (int) $q['order_id'])) ?>"><?= e($q['order_no'] ?? 'Draft Order #' . $q['order_id']) ?></a><?php else: ?><span class="muted">—</span><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$quotes): ?>
                <tr><td colspan="<?= $cols ?>" class="empty">No quotations found<?= $pgQuery ? ' for these filters' : ' yet' ?>.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php require ROOT_PATH . '/includes/pagination.php'; ?>
    <p class="doc-foot muted"><?= icon('info') ?> <span>Amounts are the quoted prices before VAT. A quotation reserves no stock: stock is reserved when the customer PO made from it is confirmed.</span></p>
</section>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
