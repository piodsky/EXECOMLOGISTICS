<?php
/**
 * Payables → Supplier Invoices (Payables::canView: payables.manage / .cancel + products.cost): aging tiles of unpaid
 * invoices by days overdue, "To invoice" = posted receiving reports of the branch without a supplier invoice
 * (Record Invoice → ap-form.php?rr=ID), and the invoice list (unpaid / overdue / paid / cancelled / all, search).
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('payables');
Payables::requireView();

$concrete = Branch::isConcrete();
$filters = [
    'search' => input_string($_GET, 'search', 100),
    'status' => is_string($_GET['status'] ?? null) && isset(Payables::FILTERS[$_GET['status']]) ? $_GET['status'] : 'open',
    'aging'  => is_string($_GET['aging'] ?? null) && isset(Collections::AGING[$_GET['aging']]) ? $_GET['aging'] : '',
];
$pgQuery = array_filter($filters, static fn ($v, $k) => $v !== '' && !($k === 'status' && $v === 'open'), ARRAY_FILTER_USE_BOTH);

$aging   = Payables::aging($filters);
$pg      = paginate(Payables::count($filters), 25);
$rows    = Payables::search($filters, $pg['per_page'], $pg['offset']);
$pgPath  = 'pages/payables.php';
$todo    = Payables::toInvoice(30);
$canManage = $concrete && Auth::can('payables.manage');
$showBranch = Branch::current() === Branch::ALL;
$cols    = 7 + ($showBranch ? 1 : 0);
$listUrl = static fn (array $q): string => url('pages/payables.php?' . http_build_query($q));

$payablesTab = 'invoices';
$page['title'] = 'Payables';
$pageStyles  = ['css/sales.css', 'css/stock-docs.css', 'css/transfers.css', 'css/reports.css', 'css/purchasing.css'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Payables</h1>
        <p class="muted">What EXECOM owes its suppliers: invoices from the receiving reports, by due date. Pay them with a disbursement voucher.</p>
    </div>
    <div class="page-actions">
        <span class="badge badge--period badge--branch"><?= icon('store') ?> <?= e(Branch::label()) ?></span>
        <?php if ($canManage): ?>
            <a class="btn btn--primary" href="<?= e(url('pages/dv-form.php')) ?>" id="newDvBtn"><?= icon('plus') ?> Pay Supplier</a>
        <?php endif; ?>
    </div>
</div>

<?php require ROOT_PATH . '/includes/payables-nav.php'; ?>

<section class="stats ar-aging" aria-label="Unpaid by age" id="apAging">
    <a class="stat kpi stat--link<?= $filters['aging'] === '' ? ' is-active' : '' ?>" href="<?= e($listUrl(array_diff_key($pgQuery, ['aging' => 1]))) ?>">
        <span class="stat__icon"><?= icon('clipboard') ?></span>
        <div><p class="stat__label">Total payable</p><p class="stat__value" id="apTotal"><?= e(money(from_cents($aging['cents']))) ?></p>
            <p class="kpi__sub"><?= number_format($aging['count']) ?> unpaid invoice<?= $aging['count'] === 1 ? '' : 's' ?></p></div>
    </a>
    <?php foreach (Collections::AGING as $key => [$label]): ?>
        <?php $b = $aging['buckets'][$key]; ?>
        <a class="stat kpi stat--link<?= $filters['aging'] === $key ? ' is-active' : '' ?><?= $key !== 'current' && $b['count'] > 0 ? ' stat--warn' : '' ?>" href="<?= e($listUrl(['aging' => $key] + $pgQuery)) ?>" data-aging="<?= e($key) ?>">
            <span class="stat__icon"><?= icon('clock') ?></span>
            <div><p class="stat__label"><?= e($label) ?></p><p class="stat__value"><?= e(money(from_cents($b['cents']))) ?></p>
                <p class="kpi__sub"><?= number_format($b['count']) ?> invoice<?= $b['count'] === 1 ? '' : 's' ?></p></div>
        </a>
    <?php endforeach; ?>
</section>

<?php if ($todo): ?>
    <section class="card" id="toInvoice">
        <header class="card__head"><h2><?= icon('truck') ?> Receiving reports to invoice</h2><span class="muted"><?= count($todo) ?> without a supplier invoice</span></header>
        <div class="table-wrap">
            <table class="table table--list pu-table" id="toInvoiceTable">
                <thead><tr><th>Receiving report</th><th>Supplier</th><th class="col-opt">Received</th><th class="num">Total cost</th><th><span class="visually-hidden">Record</span></th></tr></thead>
                <tbody>
                <?php foreach ($todo as $r): ?>
                    <tr data-rr="<?= e($r['rr_no']) ?>">
                        <td><a class="item-cell__name doc-no" href="<?= e(url('pages/receiving-view.php?id=' . (int) $r['id'])) ?>"><?= e($r['rr_no']) ?></a>
                            <small class="muted block"><?= $r['po_no'] ? e($r['po_no']) . ' · ' : '' ?><?= $r['reference_no'] ? 'Ref ' . e($r['reference_no']) : '' ?></small></td>
                        <td><?= e($r['supplier_name']) ?><small class="muted block"><?= (int) $r['terms_days'] > 0 ? (int) $r['terms_days'] . ' days' : 'Due on receipt' ?></small></td>
                        <td class="col-opt nowrap"><?= e(date('M j, Y', strtotime($r['received_date']))) ?></td>
                        <td class="num doc-value"><?= $r['total_cost'] !== null ? e(money($r['total_cost'])) : '—' ?></td>
                        <td class="c-act"><?php if ($canManage): ?><a class="btn btn--light btn--sm" href="<?= e(url('pages/ap-form.php?rr=' . (int) $r['id'])) ?>" data-invoice-rr="<?= (int) $r['id'] ?>"><?= icon('plus') ?> Record Invoice</a><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<section class="card ap-list">
    <form class="toolbar" method="get" action="<?= e(url('pages/payables.php')) ?>" role="search">
        <label class="toolbar__search">
            <?= icon('search') ?>
            <input class="form-input" type="search" name="search" maxlength="100" placeholder="AP no., supplier invoice no., supplier, RR no." value="<?= e($filters['search']) ?>" aria-label="Search supplier invoices">
        </label>
        <select class="form-input" name="status" aria-label="Status">
            <?php foreach (Payables::FILTERS as $k => $lbl): ?><option value="<?= e($k) ?>"<?= $filters['status'] === $k ? ' selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn--primary">Filter</button>
        <?php if ($pgQuery): ?><a class="btn btn--light" href="<?= e(url('pages/payables.php')) ?>">Reset</a><?php endif; ?>
    </form>

    <div class="table-wrap">
        <table class="table table--list pu-table" id="apTable">
            <thead>
            <tr><th>Invoice</th><th>Supplier</th><?php if ($showBranch): ?><th class="col-opt">Branch</th><?php endif; ?><th>Due</th><th class="num col-opt">Amount</th><th class="num col-opt">Paid</th><th class="num">Balance</th><th>Status</th></tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $i): ?>
                <?php $late = $i['status'] === 'open' && (int) $i['days'] > 0; $partial = $i['status'] === 'open' && (float) $i['paid_amount'] > 0; ?>
                <tr class="<?= $i['status'] === 'cancelled' ? 'is-void' : '' ?>" data-ap="<?= e($i['ap_no']) ?>">
                    <td><a class="item-cell__name doc-no" href="<?= e(url('pages/ap-view.php?id=' . (int) $i['id'])) ?>"><?= e($i['ap_no']) ?></a>
                        <small class="muted block">Inv. <?= e($i['invoice_no']) ?> · <?= e($i['rr_no']) ?></small></td>
                    <td><?= e($i['supplier_name']) ?></td>
                    <?php if ($showBranch): ?><td class="col-opt"><span class="badge badge--branch"><?= e($i['branch_code']) ?></span></td><?php endif; ?>
                    <td class="nowrap<?= $late ? ' text-danger' : '' ?>"><?= e(date('M j, Y', strtotime($i['due_date']))) ?><?= $late ? ' <small>(' . (int) $i['days'] . ' days late)</small>' : '' ?></td>
                    <td class="num col-opt"><?= e(money($i['amount'])) ?></td>
                    <td class="num col-opt"><?= (float) $i['paid_amount'] > 0 ? e(money($i['paid_amount'])) : '<span class="muted">—</span>' ?></td>
                    <td class="num doc-value"><?= $i['status'] === 'open' ? e(money($i['balance'])) : '<span class="muted">—</span>' ?></td>
                    <td><span class="badge <?= e($partial ? 'badge--info' : (Payables::BADGES[$i['status']] ?? '')) ?>"><?= e($partial ? 'Partial' : (Payables::STATUSES[$i['status']] ?? $i['status'])) ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="<?= $cols ?>" class="empty">No supplier invoices found<?= $pgQuery ? ' for these filters' : ' yet' ?>.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php require ROOT_PATH . '/includes/pagination.php'; ?>
</section>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
