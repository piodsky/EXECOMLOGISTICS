<?php
/**
 * Billing & Collections → Statement of Account (collections.manage / collections.cancel): open balance per customer in
 * the branch scope (bills, balance, overdue part, earliest due date, credit terms / limit), with "Print Statement"
 * (soa-print.php?customer=ID) and "Collect" (concrete branch, collections.manage).
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('collections');

$search = input_string($_GET, 'search', 100);
$rows   = Collections::balancesByCustomer($search);
$canCollect = Branch::isConcrete() && Auth::can('collections.manage');
$totals = ['balance' => 0, 'overdue' => 0];
foreach ($rows as $r) {
    $totals['balance'] += to_cents($r['balance']);
    $totals['overdue'] += to_cents($r['overdue']);
}

$collectionsTab = 'statement';
$page['title'] = 'Statement of Account';
$pageStyles  = ['css/sales.css', 'css/stock-docs.css', 'css/transfers.css', 'css/reports.css', 'css/purchasing.css'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Billing &amp; Collections</h1>
        <p class="muted">What each customer owes. Print a statement of account to send with your collection follow-up.</p>
    </div>
    <div class="page-actions"><span class="badge badge--period badge--branch"><?= icon('store') ?> <?= e(Branch::label()) ?></span></div>
</div>

<?php require ROOT_PATH . '/includes/collections-nav.php'; ?>

<section class="card">
    <form class="toolbar" method="get" action="<?= e(url('pages/soa.php')) ?>" role="search">
        <label class="toolbar__search">
            <?= icon('search') ?>
            <input class="form-input" type="search" name="search" maxlength="100" placeholder="Customer name" value="<?= e($search) ?>" aria-label="Search customers">
        </label>
        <button type="submit" class="btn btn--primary">Filter</button>
    </form>
    <div class="table-wrap">
        <table class="table table--list pu-table" id="soaTable">
            <thead>
            <tr><th>Customer</th><th class="num">Bills</th><th class="num">Balance</th><th class="num">Overdue</th><th class="col-opt">Earliest due</th><th class="col-opt">Terms</th><th><span class="visually-hidden">Actions</span></th></tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr data-soa="<?= (int) $r['id'] ?>">
                    <td><a class="item-cell__name" href="<?= e(url('pages/soa-print.php?customer=' . (int) $r['id'])) ?>" target="_blank" rel="noopener"><?= e($r['name']) ?></a><small class="muted block"><?= e((string) $r['type_name']) ?></small></td>
                    <td class="num"><?= number_format((int) $r['bills']) ?></td>
                    <td class="num doc-value"><?= e(money($r['balance'])) ?></td>
                    <td class="num<?= (float) $r['overdue'] > 0 ? ' text-danger' : '' ?>"><?= (float) $r['overdue'] > 0 ? e(money($r['overdue'])) : '<span class="muted">—</span>' ?></td>
                    <td class="col-opt nowrap"><?= $r['first_due'] ? e(date('M j, Y', strtotime($r['first_due']))) : '—' ?></td>
                    <td class="col-opt"><small><?= (int) $r['credit_days'] > 0 ? (int) $r['credit_days'] . ' days' : 'Cash' ?><?= $r['credit_limit'] !== null ? ' · limit ' . e(money($r['credit_limit'])) : '' ?></small></td>
                    <td class="c-act nowrap">
                        <a class="btn btn--light btn--sm" href="<?= e(url('pages/soa-print.php?customer=' . (int) $r['id'])) ?>" target="_blank" rel="noopener"><?= icon('printer') ?> Statement</a>
                        <?php if ($canCollect): ?><a class="btn btn--light btn--sm" href="<?= e(url('pages/collection-form.php?customer=' . (int) $r['id'])) ?>"><?= icon('wallet') ?> Collect</a><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="7" class="empty"><?= icon('check') ?> No customer owes anything<?= $search !== '' ? ' for this search' : '' ?>.</td></tr><?php endif; ?>
            </tbody>
            <?php if ($rows): ?>
                <tfoot><tr><th>Total</th><th></th><th class="num" id="soaTotal"><?= e(money(from_cents($totals['balance']))) ?></th><th class="num"><?= e(money(from_cents($totals['overdue']))) ?></th><th colspan="3"></th></tr></tfoot>
            <?php endif; ?>
        </table>
    </div>
</section>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
