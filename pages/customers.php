<?php
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('customers');
$canEdit   = Auth::can('customers.edit');
$canDelete = Auth::can('customers.delete');

// ---------------------------------------------------------------------
// Row actions — customers.delete (deactivate / delete); adding and editing need customers.edit
// ---------------------------------------------------------------------
if (is_post()) {
    Csrf::verifyRequest();
    if (!$canDelete) {
        abort(403, 'You do not have permission to deactivate or delete customers.');
    }
    $back = safe_return($_POST['return'] ?? null, 'customers.php');
    $id   = input_int($_POST, 'id', 1) ?? 0;

    try {
        switch (input_string($_POST, 'action', 20)) {
            case 'toggle':
                $c = Customers::toggleActive($id);
                flash('success', $c['is_active']
                    ? "{$c['name']} is active again."
                    : "{$c['name']} was deactivated and is hidden from the POS.");
                break;
            case 'delete':
                $c = Customers::delete($id);
                flash('success', "{$c['name']} was deleted.");
                break;
            default:
                throw new HttpException(400, 'Unknown action.');
        }
    } catch (HttpException $e) {
        flash('error', $e->getMessage());
    }
    redirect('pages/' . $back);
}

// ---------------------------------------------------------------------
// List
// ---------------------------------------------------------------------
$statuses = ['all' => 'All customers', 'active' => 'Active', 'inactive' => 'Inactive'];
$filters = [
    'q'      => input_string($_GET, 'search', 100),
    'status' => is_string($_GET['status'] ?? null) && array_key_exists($_GET['status'], $statuses) ? $_GET['status'] : 'all',
];
$pgQuery = array_filter([
    'search' => $filters['q'],
    'status' => $filters['status'] !== 'all' ? $filters['status'] : null,
], static fn ($v) => $v !== '' && $v !== null);

$pg        = paginate(Customers::count($filters), 15);
$customers = Customers::search($filters, $pg['per_page'], $pg['offset']);
$returnTo  = 'customers.php' . (($pgQuery || $pg['page'] > 1) ? '?' . http_build_query($pgQuery + ['page' => $pg['page']]) : '');
$pgPath    = 'pages/customers.php';

require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Customers</h1>
        <p class="muted">Regular customers and what they've bought · <?= e(Branch::label()) ?>.</p>
    </div>
    <?php if ($canEdit && Branch::isConcrete()): ?>
        <a class="btn btn--primary" href="<?= e(url('pages/customer-form.php?return=' . rawurlencode($returnTo))) ?>">
            <?= icon('plus') ?> Add Customer
        </a>
    <?php endif; ?>
</div>

<section class="card">
    <form class="toolbar" method="get" action="<?= e(url('pages/customers.php')) ?>" role="search">
        <label class="toolbar__search">
            <?= icon('search') ?>
            <input class="form-input" type="search" name="search" maxlength="100" placeholder="Name, phone or email"
                   value="<?= e($filters['q']) ?>" aria-label="Search customers">
        </label>
        <select class="form-input" name="status" aria-label="Status">
            <?php foreach ($statuses as $value => $label): ?>
                <option value="<?= e($value) ?>"<?= $filters['status'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn--primary">Filter</button>
        <?php if ($pgQuery): ?>
            <a class="btn btn--light" href="<?= e(url('pages/customers.php')) ?>">Reset</a>
        <?php endif; ?>
    </form>

    <div class="table-wrap">
        <table class="table table--list">
            <thead>
            <tr>
                <th>Customer</th>
                <th>Phone</th>
                <th class="num">Visits</th>
                <th class="num">Total Spent</th>
                <th>Last Visit</th>
                <th>Status</th>
                <th class="actions-col">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($customers as $c): ?>
                <?php
                $active  = (int) $c['is_active'] === 1;
                $editUrl = url('pages/customer-form.php?id=' . (int) $c['id'] . '&return=' . rawurlencode($returnTo));
                ?>
                <tr class="<?= $active ? '' : 'is-inactive' ?>">
                    <td>
                        <div class="item-cell">
                            <span class="avatar avatar--sm"><?= e(mb_strtoupper(mb_substr($c['name'], 0, 1))) ?></span>
                            <span>
                                <a class="item-cell__name" href="<?= e($editUrl) ?>"><?= e($c['name']) ?></a>
                                <?php $sub = array_filter([$c['email'] ?? '', (int) $c['branch_id'] !== Branch::current() ? 'Home: ' . $c['branch_code'] : '']); ?>
                                <small class="muted block"><?= e(implode(' · ', $sub)) ?></small>
                            </span>
                        </div>
                    </td>
                    <td><?= e($c['phone'] ?? '—') ?></td>
                    <td class="num"><?= (int) $c['visits'] ?></td>
                    <td class="num"><?= e(money($c['spent'])) ?></td>
                    <td><?= $c['last_visit'] ? e(date('M j, Y', strtotime($c['last_visit']))) : '<span class="muted">Never</span>' ?></td>
                    <td><span class="badge<?= $active ? ' badge--success' : '' ?>"><?= $active ? 'Active' : 'Inactive' ?></span></td>
                    <td class="actions-col">
                        <div class="row-actions">
                            <a class="icon-btn" title="<?= $canEdit ? 'View / edit' : 'View' ?>" aria-label="<?= $canEdit ? 'Edit' : 'View' ?> <?= e($c['name']) ?>" href="<?= e($editUrl) ?>"><?= icon($canEdit ? 'edit' : 'eye') ?></a>
                            <?php if ($canDelete): ?>
                                <form method="post">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                    <input type="hidden" name="return" value="<?= e($returnTo) ?>">
                                    <?php $label = $active ? 'Deactivate' : 'Activate'; ?>
                                    <button type="submit" class="icon-btn<?= $active ? '' : ' icon-btn--success' ?>"
                                            title="<?= $label ?>" aria-label="<?= $label ?> <?= e($c['name']) ?>"><?= icon('power') ?></button>
                                </form>
                                <?php if ((int) $c['all_sales'] === 0): ?>
                                    <form method="post" data-confirm="Delete <?= e($c['name']) ?> permanently? This cannot be undone.">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                        <input type="hidden" name="return" value="<?= e($returnTo) ?>">
                                        <button type="submit" class="icon-btn icon-btn--danger-outline" title="Delete"
                                                aria-label="Delete <?= e($c['name']) ?>"><?= icon('trash') ?></button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$customers): ?>
                <tr><td colspan="7" class="empty">No customers found<?= $pgQuery ? ' for these filters' : '' ?>.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php require ROOT_PATH . '/includes/pagination.php'; ?>
</section>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
