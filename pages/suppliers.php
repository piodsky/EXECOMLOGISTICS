<?php
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('master-data');
Auth::requirePermission('suppliers.view');
$page['title'] = 'Suppliers';
$canManage = Auth::can('suppliers.manage');

// ---------------------------------------------------------------------
// Row actions (suppliers.manage, checked again in Suppliers::)
// ---------------------------------------------------------------------
if (is_post()) {
    Csrf::verifyRequest();
    $back = safe_return($_POST['return'] ?? null, 'suppliers.php');
    $id   = input_int($_POST, 'id', 1) ?? 0;
    try {
        switch (input_string($_POST, 'action', 20)) {
            case 'toggle':
                $s = Suppliers::toggleActive($id);
                flash('success', $s['is_active'] ? "{$s['name']} is active again." : "{$s['name']} was deactivated.");
                break;
            case 'delete':
                $s = Suppliers::delete($id);
                flash('success', "{$s['name']} was deleted.");
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
$statuses = ['all' => 'All suppliers', 'active' => 'Active', 'inactive' => 'Inactive'];
$filters = [
    'q'      => input_string($_GET, 'search', 100),
    'status' => is_string($_GET['status'] ?? null) && array_key_exists($_GET['status'], $statuses) ? $_GET['status'] : 'all',
];
$pgQuery = array_filter([
    'search' => $filters['q'],
    'status' => $filters['status'] !== 'all' ? $filters['status'] : null,
], static fn ($v) => $v !== '' && $v !== null);

$pg        = paginate(Suppliers::count($filters), 15);
$suppliers = Suppliers::search($filters, $pg['per_page'], $pg['offset']);
$returnTo  = 'suppliers.php' . (($pgQuery || $pg['page'] > 1) ? '?' . http_build_query($pgQuery + ['page' => $pg['page']]) : '');
$pgPath    = 'pages/suppliers.php';
$mdTab     = 'suppliers';

$pageStyles = ['css/settings.css', 'css/master-data.css'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Master Data</h1>
        <p class="muted">Suppliers you buy stock from, with their contact persons and payment terms.</p>
    </div>
    <?php if ($canManage): ?>
        <a class="btn btn--primary" href="<?= e(url('pages/supplier-form.php?return=' . rawurlencode($returnTo))) ?>" id="addSupplier">
            <?= icon('plus') ?> Add Supplier
        </a>
    <?php endif; ?>
</div>

<?php require ROOT_PATH . '/includes/master-data-nav.php'; ?>

<section class="card">
    <form class="toolbar" method="get" action="<?= e(url('pages/suppliers.php')) ?>" role="search">
        <label class="toolbar__search">
            <?= icon('search') ?>
            <input class="form-input" type="search" name="search" maxlength="100" placeholder="Code, name, phone, email or TIN"
                   value="<?= e($filters['q']) ?>" aria-label="Search suppliers">
        </label>
        <select class="form-input" name="status" aria-label="Status">
            <?php foreach ($statuses as $value => $label): ?>
                <option value="<?= e($value) ?>"<?= $filters['status'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn--primary">Filter</button>
        <?php if ($pgQuery): ?>
            <a class="btn btn--light" href="<?= e(url('pages/suppliers.php')) ?>">Reset</a>
        <?php endif; ?>
    </form>

    <div class="table-wrap">
        <table class="table table--list suppliers-table" id="suppliersTable">
            <thead>
            <tr>
                <th>Supplier</th>
                <th>Contact</th>
                <th class="col-opt">TIN</th>
                <th class="col-opt">Payment terms</th>
                <th>Status</th>
                <th class="actions-col">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($suppliers as $s): ?>
                <?php
                $active  = (int) $s['is_active'] === 1;
                $editUrl = url('pages/supplier-form.php?id=' . (int) $s['id'] . '&return=' . rawurlencode($returnTo));
                ?>
                <tr class="<?= $active ? '' : 'is-inactive' ?>" data-supplier="<?= e($s['code']) ?>">
                    <td>
                        <div class="item-cell">
                            <span class="supplier-code"><?= e($s['code']) ?></span>
                            <a class="item-cell__name" href="<?= e($editUrl) ?>"><?= e($s['name']) ?></a>
                        </div>
                    </td>
                    <td>
                        <?php if ($s['contact_name']): ?>
                            <?= e($s['contact_name']) ?><?= (int) $s['contacts'] > 1 ? ' <small class="muted">+' . ((int) $s['contacts'] - 1) . '</small>' : '' ?>
                        <?php endif; ?>
                        <?php // the primary contact's phone/email, else the supplier's own ?>
                        <span class="cell-sub"><?= e((string) ($s['contact_reach'] ?: implode(' · ', array_filter([$s['phone'] ?? '', $s['email'] ?? ''])))) ?: '—' ?></span>
                    </td>
                    <td class="col-opt"><?= e($s['tin'] ?? '—') ?></td>
                    <td class="col-opt"><?= e($s['payment_terms'] ?? '—') ?></td>
                    <td><span class="badge<?= $active ? ' badge--success' : '' ?>"><?= $active ? 'Active' : 'Inactive' ?></span></td>
                    <td class="actions-col">
                        <div class="row-actions">
                            <a class="icon-btn" href="<?= e($editUrl) ?>" title="<?= $canManage ? 'Edit' : 'View' ?>"
                               aria-label="<?= $canManage ? 'Edit' : 'View' ?> <?= e($s['name']) ?>"><?= icon($canManage ? 'edit' : 'eye') ?></a>
                            <?php if ($canManage): ?>
                                <form method="post">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                                    <input type="hidden" name="return" value="<?= e($returnTo) ?>">
                                    <?php $label = $active ? 'Deactivate' : 'Activate'; ?>
                                    <button type="submit" class="icon-btn<?= $active ? '' : ' icon-btn--success' ?>" data-act="toggle"
                                            title="<?= $label ?>" aria-label="<?= $label ?> <?= e($s['name']) ?>"><?= icon('power') ?></button>
                                </form>
                                <form method="post" data-confirm="Delete <?= e($s['name']) ?> permanently?">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                                    <input type="hidden" name="return" value="<?= e($returnTo) ?>">
                                    <button type="submit" class="icon-btn icon-btn--danger-outline" data-act="delete" title="Delete"
                                            aria-label="Delete <?= e($s['name']) ?>"><?= icon('trash') ?></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$suppliers): ?>
                <tr><td colspan="6" class="empty">No suppliers found<?= $pgQuery ? ' for these filters' : '' ?>.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php require ROOT_PATH . '/includes/pagination.php'; ?>
</section>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
