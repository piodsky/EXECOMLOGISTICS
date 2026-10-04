<?php
/**
 * Payables → Disbursements (Payables::canView): disbursement vouchers in the branch scope with filters (search, period,
 * method, status, checks issued not cleared) and the posted totals (paid, EWT withheld, settled).
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('payables');
Payables::requireView();

$filters = [
    'search' => input_string($_GET, 'search', 100),
    'status' => is_string($_GET['status'] ?? null) && isset(Payables::DV_STATUSES[$_GET['status']]) ? $_GET['status'] : '',
    'method' => is_string($_GET['method'] ?? null) && isset(Collections::METHODS[$_GET['method']]) ? $_GET['method'] : '',
    'checks' => ($_GET['checks'] ?? '') === 'issued' ? 'issued' : '',
    'from'   => input_date($_GET, 'from'),
    'to'     => input_date($_GET, 'to'),
];
$pgQuery = array_filter($filters, static fn ($v) => $v !== '' && $v !== null);
$sum    = Payables::dvSummary($filters);
$pg     = paginate(Payables::dvCount($filters), 25);
$rows   = Payables::dvSearch($filters, $pg['per_page'], $pg['offset']);
$pgPath = 'pages/disbursements.php';
$showBranch = Branch::current() === Branch::ALL;
$cols = 7 + ($showBranch ? 1 : 0);

$payablesTab = 'disbursements';
$page['title'] = 'Disbursements';
$pageStyles  = ['css/sales.css', 'css/stock-docs.css', 'css/transfers.css', 'css/reports.css', 'css/purchasing.css'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Payables</h1>
        <p class="muted">Disbursement vouchers: payments to suppliers, with the EWT EXECOM withheld.</p>
    </div>
    <div class="page-actions">
        <span class="badge badge--period badge--branch"><?= icon('store') ?> <?= e(Branch::label()) ?></span>
        <?php if (Branch::isConcrete() && Auth::can('payables.manage')): ?><a class="btn btn--primary" href="<?= e(url('pages/dv-form.php')) ?>"><?= icon('plus') ?> Pay Supplier</a><?php endif; ?>
    </div>
</div>

<?php require ROOT_PATH . '/includes/payables-nav.php'; ?>

<section class="stats" aria-label="Totals" id="dvTotals">
    <div class="stat kpi"><span class="stat__icon"><?= icon('wallet') ?></span><div><p class="stat__label">Paid out</p><p class="stat__value" id="dvPaid"><?= e(money($sum['paid'])) ?></p>
        <p class="kpi__sub"><?= number_format((int) $sum['vouchers']) ?> voucher<?= (int) $sum['vouchers'] === 1 ? '' : 's' ?></p></div></div>
    <div class="stat kpi"><span class="stat__icon"><?= icon('file') ?></span><div><p class="stat__label">EWT withheld</p><p class="stat__value"><?= e(money($sum['ewt'])) ?></p>
        <p class="kpi__sub">Issue BIR 2307 to the suppliers</p></div></div>
    <div class="stat kpi"><span class="stat__icon"><?= icon('check') ?></span><div><p class="stat__label">Invoices settled</p><p class="stat__value"><?= e(money($sum['settled'])) ?></p>
        <p class="kpi__sub">Paid + withheld</p></div></div>
</section>

<section class="card">
    <form class="toolbar" method="get" action="<?= e(url('pages/disbursements.php')) ?>" role="search">
        <label class="toolbar__search"><?= icon('search') ?>
            <input class="form-input" type="search" name="search" maxlength="100" placeholder="DV no., supplier, check no., AP / invoice no." value="<?= e($filters['search']) ?>" aria-label="Search disbursements"></label>
        <label class="date-field"><span>From</span><input class="form-input" type="date" name="from" value="<?= e((string) $filters['from']) ?>"></label>
        <label class="date-field"><span>To</span><input class="form-input" type="date" name="to" value="<?= e((string) $filters['to']) ?>"></label>
        <select class="form-input" name="method" aria-label="Method"><option value="">Any method</option>
            <?php foreach (Collections::METHODS as $k => $lbl): ?><option value="<?= e($k) ?>"<?= $filters['method'] === $k ? ' selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?></select>
        <select class="form-input" name="status" aria-label="Status"><option value="">All statuses</option>
            <?php foreach (Payables::DV_STATUSES as $k => $lbl): ?><option value="<?= e($k) ?>"<?= $filters['status'] === $k ? ' selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?></select>
        <label class="chip"><input type="checkbox" name="checks" value="issued"<?= $filters['checks'] === 'issued' ? ' checked' : '' ?>> Checks not cleared</label>
        <button type="submit" class="btn btn--primary">Filter</button>
        <?php if ($pgQuery): ?><a class="btn btn--light" href="<?= e(url('pages/disbursements.php')) ?>">Reset</a><?php endif; ?>
    </form>
    <div class="table-wrap">
        <table class="table table--list pu-table" id="dvTable">
            <thead><tr><th>Voucher</th><th>Supplier</th><?php if ($showBranch): ?><th class="col-opt">Branch</th><?php endif; ?><th class="col-opt">Method</th><th class="num">Paid</th><th class="num col-opt">EWT</th><th class="col-opt">Check</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $d): ?>
                <tr class="<?= $d['status'] === 'cancelled' ? 'is-void' : '' ?>" data-dv="<?= e($d['dv_no']) ?>">
                    <td><a class="item-cell__name doc-no" href="<?= e(url('pages/dv-view.php?id=' . (int) $d['id'])) ?>"><?= e($d['dv_no']) ?></a>
                        <small class="muted block"><?= e(date('M j, Y', strtotime($d['payment_date']))) ?> · <?= e($d['created_by_name']) ?></small></td>
                    <td><?= e($d['supplier_name']) ?><small class="muted block"><?= (int) $d['invoices'] ?> invoice<?= (int) $d['invoices'] === 1 ? '' : 's' ?></small></td>
                    <?php if ($showBranch): ?><td class="col-opt"><span class="badge badge--branch"><?= e($d['branch_code']) ?></span></td><?php endif; ?>
                    <td class="col-opt"><?= e(Collections::METHODS[$d['method']] ?? $d['method']) ?><?= $d['reference'] ? '<small class="muted block">' . e($d['reference']) . '</small>' : '' ?></td>
                    <td class="num doc-value"><?= e(money($d['amount_paid'])) ?></td>
                    <td class="num col-opt"><?= (float) $d['ewt_total'] > 0 ? e(money($d['ewt_total'])) : '<span class="muted">—</span>' ?></td>
                    <td class="col-opt"><?php if ($d['check_status'] !== 'none'): ?><span class="badge <?= $d['check_status'] === 'issued' ? 'badge--warning' : 'badge--success' ?>"><?= e(Payables::CHECK_STATUSES[$d['check_status']]) ?></span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
                    <td><span class="badge <?= e(Payables::DV_BADGES[$d['status']] ?? '') ?>"><?= e(Payables::DV_STATUSES[$d['status']] ?? $d['status']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="<?= $cols ?>" class="empty">No disbursements found<?= $pgQuery ? ' for these filters' : ' yet' ?>.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php require ROOT_PATH . '/includes/pagination.php'; ?>
</section>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
