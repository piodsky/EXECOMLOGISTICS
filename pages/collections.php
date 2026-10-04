<?php
/**
 * Billing & Collections → Bills (collections.manage / collections.cancel): bills on account in the branch scope by due
 * date, aging tiles by days overdue (not yet due / 1–30 / 31–60 / 61–90 / over 90), filter unpaid / paid / all,
 * search, "Collect" per customer (collection-form.php?customer=ID, concrete branch, collections.manage).
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('collections');

$concrete = Branch::isConcrete();
$filters = [
    'search'   => input_string($_GET, 'search', 100),
    'aging'    => is_string($_GET['aging'] ?? null) && isset(Collections::AGING[$_GET['aging']]) ? $_GET['aging'] : '',
    'customer' => input_int($_GET, 'customer', 1),
    'bills'    => is_string($_GET['bills'] ?? null) && isset(Collections::BILL_STATUSES[$_GET['bills']]) ? $_GET['bills'] : 'open',
];
$pgQuery = array_filter($filters, static fn ($v, $k) => $v !== '' && $v !== null && !($k === 'bills' && $v === 'open'), ARRAY_FILTER_USE_BOTH);

$aging  = Collections::aging($filters);
$pg     = paginate(Collections::receivableCount($filters), 25);
$bills  = Collections::receivables($filters, $pg['per_page'], $pg['offset']);
$pgPath = 'pages/collections.php';
$showBranch = Branch::current() === Branch::ALL;
$canCollect = $concrete && Auth::can('collections.manage');
$canSale    = Auth::can('sales.view');
$work   = Collections::workCounts();
$cols   = 8 + ($showBranch ? 1 : 0) + ($canCollect ? 1 : 0);
$today  = date('Y-m-d');
$listUrl = static fn (array $q): string => url('pages/collections.php?' . http_build_query($q));

$collectionsTab = 'receivables';
$page['title'] = 'Billing & Collections';
$pageStyles  = ['css/sales.css', 'css/stock-docs.css', 'css/transfers.css', 'css/reports.css', 'css/purchasing.css'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Billing &amp; Collections</h1>
        <p class="muted">Bills on account (customer orders, POS and job bills of credit customers): what each customer owes and when it is due.</p>
    </div>
    <div class="page-actions">
        <span class="badge badge--period badge--branch"><?= icon('store') ?> <?= e(Branch::label()) ?></span>
        <?php if ($canCollect): ?>
            <a class="btn btn--primary" href="<?= e(url('pages/collection-form.php')) ?>" id="newCollectionBtn"><?= icon('plus') ?> Record Collection</a>
        <?php endif; ?>
    </div>
</div>

<?php require ROOT_PATH . '/includes/collections-nav.php'; ?>

<?php if (!$concrete): ?>
    <div class="alert alert--info" role="status"><?= icon('info') ?><span>Showing the bills of all branches. Choose a branch in the top bar to record collections.</span></div>
<?php elseif ($work['forms'] > 0): ?>
    <div class="alert alert--warning" role="status" id="formsNote"><?= icon('alert') ?>
        <span><?= $work['forms'] ?> collection<?= $work['forms'] === 1 ? ' is' : 's are' ?> still waiting for the customer's BIR 2307 / 2306 certificate.
            <a href="<?= e(url('pages/collection-receipts.php?forms=pending')) ?>">Show them</a></span></div>
<?php endif; ?>

<section class="stats ar-aging" aria-label="Receivables by age" id="agingTiles">
    <a class="stat kpi stat--link<?= $filters['aging'] === '' ? ' is-active' : '' ?>" href="<?= e($listUrl(array_diff_key($pgQuery, ['aging' => 1]))) ?>">
        <span class="stat__icon"><?= icon('wallet') ?></span>
        <div><p class="stat__label">Total receivable</p><p class="stat__value" id="arTotal"><?= e(money(from_cents($aging['cents']))) ?></p>
            <p class="kpi__sub"><?= number_format($aging['count']) ?> unpaid bill<?= $aging['count'] === 1 ? '' : 's' ?></p></div>
    </a>
    <?php foreach (Collections::AGING as $key => [$label]): ?>
        <?php $b = $aging['buckets'][$key]; ?>
        <a class="stat kpi stat--link<?= $filters['aging'] === $key ? ' is-active' : '' ?><?= $key !== 'current' && $b['count'] > 0 ? ' stat--warn' : '' ?>" href="<?= e($listUrl(['aging' => $key] + $pgQuery)) ?>" data-aging="<?= e($key) ?>">
            <span class="stat__icon"><?= icon('clock') ?></span>
            <div><p class="stat__label"><?= e($label) ?></p><p class="stat__value"><?= e(money(from_cents($b['cents']))) ?></p>
                <p class="kpi__sub"><?= number_format($b['count']) ?> bill<?= $b['count'] === 1 ? '' : 's' ?></p></div>
        </a>
    <?php endforeach; ?>
</section>

<section class="card">
    <form class="toolbar" method="get" action="<?= e(url('pages/collections.php')) ?>" role="search">
        <label class="toolbar__search">
            <?= icon('search') ?>
            <input class="form-input" type="search" name="search" maxlength="100" placeholder="Bill no., customer, order no., customer PO no."
                   value="<?= e($filters['search']) ?>" aria-label="Search receivables">
        </label>
        <select class="form-input" name="bills" aria-label="Bills">
            <?php foreach (Collections::BILL_STATUSES as $key => $label): ?>
                <option value="<?= e($key) ?>"<?= $filters['bills'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-input" name="aging" aria-label="Age">
            <option value="">Any age</option>
            <?php foreach (Collections::AGING as $key => [$label]): ?>
                <option value="<?= e($key) ?>"<?= $filters['aging'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn--primary">Filter</button>
        <?php if ($pgQuery): ?>
            <a class="btn btn--light" href="<?= e(url('pages/collections.php')) ?>">Reset</a>
        <?php endif; ?>
    </form>

    <div class="table-wrap">
        <table class="table table--list pu-table" id="arTable">
            <thead>
            <tr>
                <th>Bill</th>
                <th>Customer / order</th>
                <?php if ($showBranch): ?><th class="col-opt">Branch</th><?php endif; ?>
                <th class="col-opt">Due</th>
                <th class="num">Overdue</th>
                <th class="num col-opt">Total</th>
                <th class="num col-opt">Paid</th>
                <th class="num">Balance</th>
                <th class="col-opt">Terms</th>
                <?php if ($canCollect): ?><th><span class="visually-hidden">Collect</span></th><?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($bills as $s): ?>
                <?php $days = (int) $s['days']; $bucket = Collections::bucket($days); $paid = (float) $s['balance'] <= 0; ?>
                <tr data-bill="<?= e($s['sale_no']) ?>" class="<?= $paid ? 'ar-paid' : '' ?>">
                    <td>
                        <?php if ($canSale): ?><a class="item-cell__name doc-no" href="<?= e(url('pages/sale-view.php?id=' . (int) $s['id'])) ?>">No. <?= e($s['sale_no']) ?></a><?php else: ?><span class="doc-no">No. <?= e($s['sale_no']) ?></span><?php endif; ?>
                        <small class="muted block"><?= e(date('M j, Y', strtotime($s['created_at']))) ?></small>
                    </td>
                    <td><?= e((string) $s['customer_name']) ?><small class="muted block"><?= $s['order_no'] ? e($s['order_no']) . ' · PO ' . e((string) $s['customer_po_no']) : '' ?></small></td>
                    <?php if ($showBranch): ?><td class="col-opt"><span class="badge badge--branch" title="<?= e($s['branch_name']) ?>"><?= e($s['branch_code']) ?></span></td><?php endif; ?>
                    <td class="col-opt nowrap"><?= $s['due_date'] ? e(date('M j, Y', strtotime($s['due_date']))) : '<span class="muted">—</span>' ?></td>
                    <td class="num<?= !$paid && $days > 30 ? ' text-danger' : '' ?>"><?= $paid ? '<span class="badge badge--success">Paid</span>' : ($days > 0 ? number_format($days) . ' days' : '<span class="muted">not yet</span>') ?></td>
                    <td class="num col-opt"><?= e(money($s['total'])) ?></td>
                    <td class="num col-opt"><?= (float) $s['settled_amount'] > 0 ? e(money($s['settled_amount'])) : '<span class="muted">—</span>' ?></td>
                    <td class="num doc-value"><?= $paid ? '<span class="muted">—</span>' : e(money($s['balance'])) ?></td>
                    <td class="col-opt"><small><?= e((string) ($s['payment_term'] ?? '—')) ?></small></td>
                    <?php if ($canCollect): ?>
                        <td class="c-act"><?php if (!$paid && Branch::current() === (int) $s['branch_id'] && $s['customer_id'] !== null): ?>
                            <a class="btn btn--light btn--sm" href="<?= e(url('pages/collection-form.php?customer=' . (int) $s['customer_id'])) ?>" data-collect="<?= (int) $s['customer_id'] ?>"><?= icon('wallet') ?> Collect</a>
                        <?php endif; ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (!$bills): ?>
                <tr><td colspan="<?= $cols ?>" class="empty"><?= $pgQuery ? 'No open bills for these filters.' : icon('check') . ' Nothing to collect: every bill on account is paid.' ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php require ROOT_PATH . '/includes/pagination.php'; ?>
    <p class="doc-foot muted"><?= icon('info') ?> <span>Due = bill date + the customer's credit terms (customer orders: 30 days when none). Balance = total (VAT included) minus what was collected, including the taxes the customer withheld.</span></p>
</section>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
