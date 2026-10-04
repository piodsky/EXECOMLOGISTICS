<?php
/**
 * Collections → Collection Receipts (collections.manage / collections.cancel): posted and cancelled collection
 * receipts in the branch scope, filters (search, period, method, status, certificates still to receive) and the
 * posted totals for the filters: cash received, EWT (2307), VAT withheld (2306), total credited.
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('collections');

$filters = [
    'search' => input_string($_GET, 'search', 100),
    'status' => is_string($_GET['status'] ?? null) && isset(Collections::STATUSES[$_GET['status']]) ? $_GET['status'] : '',
    'method' => is_string($_GET['method'] ?? null) && isset(Collections::METHODS[$_GET['method']]) ? $_GET['method'] : '',
    'forms'  => ($_GET['forms'] ?? '') === 'pending' ? 'pending' : '',
    'from'   => input_date($_GET, 'from'),
    'to'     => input_date($_GET, 'to'),
];
if ($filters['from'] !== null && $filters['to'] !== null && $filters['from'] > $filters['to']) {
    [$filters['from'], $filters['to']] = [$filters['to'], $filters['from']];
}
$pgQuery = array_filter($filters, static fn ($v) => $v !== '' && $v !== null);

$sum      = Collections::summary($filters);
$pg       = paginate(Collections::count($filters), 25);
$rows     = Collections::search($filters, $pg['per_page'], $pg['offset']);
$pgPath   = 'pages/collection-receipts.php';
$returnTo = 'collection-receipts.php' . (($pgQuery || $pg['page'] > 1) ? '?' . http_build_query($pgQuery + ['page' => $pg['page']]) : '');
$showBranch = Branch::current() === Branch::ALL;
$cols = 8 + ($showBranch ? 1 : 0);

$collectionsTab = 'receipts';
$page['title'] = 'Collection Receipts';
$pageStyles  = ['css/sales.css', 'css/stock-docs.css', 'css/transfers.css', 'css/reports.css', 'css/purchasing.css'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Billing &amp; Collections</h1>
        <p class="muted">Collection receipts: payments received on bills on account, with the taxes the customers withheld.</p>
    </div>
    <div class="page-actions">
        <span class="badge badge--period badge--branch"><?= icon('store') ?> <?= e(Branch::label()) ?></span>
        <?php if (Branch::isConcrete() && Auth::can('collections.manage')): ?>
            <a class="btn btn--primary" href="<?= e(url('pages/collection-form.php')) ?>"><?= icon('plus') ?> Record Collection</a>
        <?php endif; ?>
    </div>
</div>

<?php require ROOT_PATH . '/includes/collections-nav.php'; ?>

<section class="stats" aria-label="Totals" id="crTotals">
    <div class="stat kpi"><span class="stat__icon"><?= icon('wallet') ?></span>
        <div><p class="stat__label">Cash / check received</p><p class="stat__value" id="crCash"><?= e(money($sum['cash'])) ?></p>
            <p class="kpi__sub"><?= number_format((int) $sum['receipts']) ?> receipt<?= (int) $sum['receipts'] === 1 ? '' : 's' ?></p></div></div>
    <div class="stat kpi"><span class="stat__icon"><?= icon('file') ?></span>
        <div><p class="stat__label">EWT withheld (2307)</p><p class="stat__value" id="crEwt"><?= e(money($sum['ewt'])) ?></p>
            <p class="kpi__sub">Creditable against income tax</p></div></div>
    <div class="stat kpi"><span class="stat__icon"><?= icon('file') ?></span>
        <div><p class="stat__label">VAT withheld (2306)</p><p class="stat__value" id="crVat"><?= e(money($sum['vat'])) ?></p>
            <p class="kpi__sub">Final VAT by government</p></div></div>
    <div class="stat kpi"><span class="stat__icon"><?= icon('check') ?></span>
        <div><p class="stat__label">Total credited</p><p class="stat__value" id="crCredited"><?= e(money($sum['credited'])) ?></p>
            <p class="kpi__sub">Taken off the bills</p></div></div>
</section>

<section class="card">
    <form class="toolbar" method="get" action="<?= e(url('pages/collection-receipts.php')) ?>" role="search">
        <label class="toolbar__search">
            <?= icon('search') ?>
            <input class="form-input" type="search" name="search" maxlength="100" placeholder="Receipt no., customer, check / reference no., bill no."
                   value="<?= e($filters['search']) ?>" aria-label="Search collection receipts">
        </label>
        <label class="date-field"><span>From</span><input class="form-input" type="date" name="from" value="<?= e((string) $filters['from']) ?>"></label>
        <label class="date-field"><span>To</span><input class="form-input" type="date" name="to" value="<?= e((string) $filters['to']) ?>"></label>
        <select class="form-input" name="method" aria-label="Method">
            <option value="">Any method</option>
            <?php foreach (Collections::METHODS as $k => $lbl): ?><option value="<?= e($k) ?>"<?= $filters['method'] === $k ? ' selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
        </select>
        <select class="form-input" name="status" aria-label="Status">
            <option value="">All statuses</option>
            <?php foreach (Collections::STATUSES as $k => $lbl): ?><option value="<?= e($k) ?>"<?= $filters['status'] === $k ? ' selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
        </select>
        <label class="chip"><input type="checkbox" name="forms" value="pending"<?= $filters['forms'] === 'pending' ? ' checked' : '' ?>> 2307 to receive</label>
        <button type="submit" class="btn btn--primary">Filter</button>
        <?php if ($pgQuery): ?><a class="btn btn--light" href="<?= e(url('pages/collection-receipts.php')) ?>">Reset</a><?php endif; ?>
    </form>

    <div class="table-wrap">
        <table class="table table--list pu-table" id="crTable">
            <thead>
            <tr>
                <th>Receipt</th>
                <th>Customer</th>
                <?php if ($showBranch): ?><th class="col-opt">Branch</th><?php endif; ?>
                <th class="col-opt">Method</th>
                <th class="num">Received</th>
                <th class="num col-opt">EWT</th>
                <th class="num col-opt">VAT w/h</th>
                <th class="col-opt">2307</th>
                <th>Status</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $c): ?>
                <tr class="<?= $c['status'] === 'cancelled' ? 'is-void' : '' ?>" data-cr="<?= e($c['collection_no']) ?>">
                    <td>
                        <a class="item-cell__name doc-no" href="<?= e(url('pages/collection-view.php?id=' . (int) $c['id'] . '&return=' . rawurlencode($returnTo))) ?>"><?= e($c['collection_no']) ?></a>
                        <small class="muted block"><?= e(date('M j, Y', strtotime($c['collection_date']))) ?> · <?= e($c['created_by_name']) ?></small>
                    </td>
                    <td><?= e($c['customer_name']) ?><small class="muted block"><?= (int) $c['bills'] ?> bill<?= (int) $c['bills'] === 1 ? '' : 's' ?></small></td>
                    <?php if ($showBranch): ?><td class="col-opt"><span class="badge badge--branch" title="<?= e($c['branch_name']) ?>"><?= e($c['branch_code']) ?></span></td><?php endif; ?>
                    <td class="col-opt"><?= e(Collections::METHODS[$c['method']] ?? $c['method']) ?><?= $c['reference'] ? '<small class="muted block">' . e($c['reference']) . '</small>' : '' ?></td>
                    <td class="num doc-value"><?= e(money($c['amount_received'])) ?></td>
                    <td class="num col-opt"><?= (float) $c['ewt_total'] > 0 ? e(money($c['ewt_total'])) : '<span class="muted">—</span>' ?></td>
                    <td class="num col-opt"><?= (float) $c['vat_withheld_total'] > 0 ? e(money($c['vat_withheld_total'])) : '<span class="muted">—</span>' ?></td>
                    <td class="col-opt"><?php if ($c['form_2307'] === 'none'): ?><span class="muted">—</span><?php else: ?><span class="badge <?= $c['form_2307'] === 'pending' ? 'badge--warning' : 'badge--success' ?>"><?= e(Collections::FORM_2307[$c['form_2307']]) ?></span><?php endif; ?></td>
                    <td><span class="badge <?= e(Collections::BADGES[$c['status']] ?? '') ?>"><?= e(Collections::STATUSES[$c['status']] ?? $c['status']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="<?= $cols ?>" class="empty">No collection receipts found<?= $pgQuery ? ' for these filters' : ' yet' ?>.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php require ROOT_PATH . '/includes/pagination.php'; ?>
</section>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
