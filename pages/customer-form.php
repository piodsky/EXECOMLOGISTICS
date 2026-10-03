<?php
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('customers');

$canEdit  = Auth::can('customers.edit');
$id       = input_int($_GET, 'id', 1);
$customer = null;
if ($id !== null) {
    // null when the customer isn't visible at the current branch
    $customer = Customers::find($id) ?? throw new HttpException(404, 'Customer not found.');
} elseif (!$canEdit) {
    abort(403, 'You do not have permission to add customers.');
}
$ro = $canEdit ? '' : ' disabled'; // read-only view for customers.view only
$page['title'] = $customer ? $customer['name'] : 'Add Customer';
$returnTo = safe_return($_POST['return'] ?? $_GET['return'] ?? null, 'customers.php');
$self     = 'customer-form.php?' . http_build_query(array_filter(['id' => $id, 'return' => $returnTo]));

// ---------------------------------------------------------------------
// Save
// ---------------------------------------------------------------------
if (is_post()) {
    Csrf::verifyRequest();
    if (!$canEdit) {
        abort(403, 'You do not have permission to edit customers.');
    }
    [$data, $errors] = Customers::check($_POST, $id);
    [$contacts, $contactErrors] = Contacts::parse($_POST);
    $errors += $contactErrors;

    if ($errors) {
        flash_old(array_filter($_POST, 'is_string') + Contacts::flatOld($_POST));
        flash_errors($errors);
        flash('error', 'Please fix the highlighted fields.');
        redirect('pages/' . $self);
    }

    try {
        if ($customer) {
            Customers::update($id, $data, $contacts);
            flash('success', "{$data['name']} was updated.");
        } else {
            Customers::create($data, $contacts); // added at the current branch
            flash('success', "{$data['name']} was added.");
        }
    } catch (HttpException $e) {
        flash_old(array_filter($_POST, 'is_string') + Contacts::flatOld($_POST));
        flash('error', $e->getMessage());
        redirect('pages/' . $self);
    }
    redirect('pages/' . $returnTo);
}

// ---------------------------------------------------------------------
// Form + purchase history
// ---------------------------------------------------------------------
$stats  = $customer ? Customers::stats($id) : null;
$recent = $customer ? Customers::recentSales($id, 10) : [];
$val    = static fn (string $key): string => old($key, (string) ($customer[$key] ?? ''));
$paymentLabels = Sales::PAYMENT_TYPES;
$types       = MasterData::options('customer-types', isset($customer['customer_type_id']) ? (int) $customer['customer_type_id'] : null);
$contactRows = $canEdit ? Contacts::formRows($customer['contacts'] ?? []) : ($customer['contacts'] ?? []);

require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <a class="back-link" href="<?= e(url('pages/' . $returnTo)) ?>"><?= icon('arrow-left') ?> Customers</a>
        <h1><?= e($customer ? $customer['name'] : 'Add Customer') ?></h1>
        <?php if ($customer): ?>
            <p class="muted">
                Customer since <?= e(date('M j, Y', strtotime($customer['created_at']))) ?>
                · Home branch <?= e($customer['branch_code'] . ' · ' . $customer['branch_name']) ?>
                <?php if ((int) $customer['is_active'] !== 1): ?> · <span class="badge">Inactive</span><?php endif; ?>
            </p>
        <?php endif; ?>
    </div>
</div>

<div class="customer-layout<?= $customer ? '' : ' customer-layout--single' ?>">
    <form class="card card--pad" method="post" action="<?= e(url('pages/' . $self)) ?>" novalidate>
        <?= Csrf::field() ?>
        <input type="hidden" name="return" value="<?= e($returnTo) ?>">
        <h2 class="card__title">Details</h2>

        <div class="form-grid">
            <label class="form-field form-field--full">
                <span class="form-label">Name *</span>
                <input class="form-input" name="name" maxlength="100" required value="<?= e($val('name')) ?>"<?= invalid('name') ?><?= $ro ?>>
                <?= field_error('name') ?>
            </label>
            <label class="form-field">
                <span class="form-label">Phone</span>
                <input class="form-input" name="phone" maxlength="30" inputmode="tel" placeholder="0917 123 4567"
                       value="<?= e($val('phone')) ?>"<?= invalid('phone') ?><?= $ro ?>>
                <?= field_error('phone') ?>
            </label>
            <label class="form-field">
                <span class="form-label">Email</span>
                <input class="form-input" type="email" name="email" maxlength="120" value="<?= e($val('email')) ?>"<?= invalid('email') ?><?= $ro ?>>
                <?= field_error('email') ?>
            </label>
            <label class="form-field form-field--full">
                <span class="form-label">Address</span>
                <input class="form-input" name="address" maxlength="255" value="<?= e($val('address')) ?>"<?= invalid('address') ?><?= $ro ?>>
                <?= field_error('address') ?>
            </label>
            <label class="form-field">
                <span class="form-label">Customer type</span>
                <select class="form-input" name="customer_type_id"<?= invalid('customer_type_id') ?><?= $ro ?>>
                    <option value="">Not set</option>
                    <?php foreach ($types as $t): ?>
                        <option value="<?= (int) $t['id'] ?>"<?= $val('customer_type_id') === (string) $t['id'] ? ' selected' : '' ?>><?= e($t['name']) ?><?= (int) $t['is_active'] === 1 ? '' : ' (inactive)' ?></option>
                    <?php endforeach; ?>
                </select>
                <?= field_error('customer_type_id') ?>
            </label>
            <label class="form-field">
                <span class="form-label">TIN</span>
                <input class="form-input form-input--mono" name="tin" maxlength="20" placeholder="000-000-000-000"
                       value="<?= e($val('tin')) ?>"<?= invalid('tin') ?><?= $ro ?>>
                <?= field_error('tin') ?>
            </label>
        </div>

        <h3 class="form-section-title">Contact Persons</h3>
        <?php require ROOT_PATH . '/includes/contact-rows.php'; ?>
        <?php if ($canEdit): ?>
            <p class="form-hint">For companies, schools and government offices. Up to <?= Contacts::MAX ?>; empty rows are ignored.</p>
        <?php endif; ?>

        <div class="form-actions form-actions--inline">
            <a class="btn btn--light" href="<?= e(url('pages/' . $returnTo)) ?>"><?= $canEdit ? 'Cancel' : 'Back' ?></a>
            <?php if ($canEdit): ?>
                <button type="submit" class="btn btn--primary"><?= icon('save') ?> <?= $customer ? 'Save Changes' : 'Add Customer' ?></button>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($customer): ?>
        <section class="card">
            <header class="card__head"><h2><?= icon('receipt') ?> Purchase History</h2></header>
            <div class="mini-stats">
                <div><small>Visits</small><strong><?= (int) $stats['visits'] ?></strong></div>
                <div><small>Total spent</small><strong><?= e(money($stats['spent'])) ?></strong></div>
                <div><small>Average</small><strong><?= e(money($stats['average'])) ?></strong></div>
                <div><small>Last visit</small><strong><?= $stats['last_visit'] ? e(date('M j, Y', strtotime($stats['last_visit']))) : '—' ?></strong></div>
            </div>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Sale No.</th><th>Date</th><th class="num">Items</th><th>Payment</th><th class="num">Total</th><th></th></tr></thead>
                    <tbody>
                    <?php $canSales = Auth::can('sales.view'); ?>
                    <?php foreach ($recent as $s): ?>
                        <tr>
                            <td><?php if ($canSales): ?><a class="item-cell__name" href="<?= e(url('pages/sale-view.php?id=' . (int) $s['id'])) ?>"><?= e($s['sale_no']) ?></a><?php else: ?><?= e($s['sale_no']) ?><?php endif; ?><?= $s['status'] === 'cancelled' ? ' <span class="badge badge--danger">Voided</span>' : '' ?></td>
                            <td><?= e(date('M j, Y g:i A', strtotime($s['created_at']))) ?></td>
                            <td class="num"><?= (int) $s['items'] ?></td>
                            <td><span class="badge"><?= e($paymentLabels[$s['payment_type']] ?? $s['payment_type']) ?></span></td>
                            <td class="num"><?= e(money($s['total'])) ?></td>
                            <td class="num">
                                <?php if ($canSales): ?>
                                    <a class="icon-btn icon-btn--sm" href="<?= e(url('pages/receipt.php?id=' . (int) $s['id'])) ?>" target="_blank" rel="noopener"
                                       title="Open receipt" aria-label="Open receipt <?= e($s['sale_no']) ?>"><?= icon('external') ?></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$recent): ?>
                        <tr><td colspan="6" class="empty">No purchases yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</div>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
