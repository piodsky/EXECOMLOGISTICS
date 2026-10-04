<?php
/**
 * Record a supplier invoice from a posted receiving report (payables.manage + products.cost, working in its branch):
 * the supplier's invoice no., invoice date, due date (default invoice date + the supplier's terms days), amount
 * (default the RR total cost), notes. pages/ap-form.php?rr=ID
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('payables');
Payables::requireView();
$rrId = input_int($_GET, 'rr', 1) ?? throw new HttpException(404, 'Receiving report not found.');
$self = 'ap-form.php?rr=' . $rrId;
try {
    $rr = Payables::receivingFor($rrId);
} catch (HttpException $e) {
    if ($e->status !== 409) {
        throw $e;
    }
    flash('error', $e->getMessage());
    redirect('pages/payables.php');
}

if (is_post()) {
    Csrf::verifyRequest();
    [$data, $errors] = Payables::validateInvoice($_POST, $rr);
    if ($errors) {
        flash_old(array_filter($_POST, 'is_string'));
        flash_errors($errors);
        flash('error', 'Please fix the highlighted fields.');
        redirect('pages/' . $self);
    }
    try {
        $res = Payables::createInvoice($rrId, $data, (int) Auth::id());
    } catch (HttpException $e) {
        if ($e->status === 403) {
            throw $e;
        }
        flash_old(array_filter($_POST, 'is_string'));
        flash('error', $e->getMessage());
        redirect('pages/' . $self);
    }
    flash('success', "Supplier invoice {$res['ap_no']} was recorded.");
    redirect('pages/ap-view.php?id=' . $res['id']);
}

$today   = date('Y-m-d');
$invDate = old('invoice_date', $rr['received_date']);
$due     = old('due_date', date('Y-m-d', strtotime($invDate . ' +' . (int) $rr['terms_days'] . ' days')));
$page['title'] = 'Record Supplier Invoice';
$pageStyles  = ['css/sales.css', 'css/receiving.css', 'css/stock-docs.css', 'css/transfers.css', 'css/purchasing.css'];
$pageScripts = ['js/purchasing.js'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <a class="back-link" href="<?= e(url('pages/payables.php')) ?>"><?= icon('arrow-left') ?> Payables</a>
        <h1 class="sale-title">Record Supplier Invoice</h1>
        <p class="muted"><?= e($rr['supplier_name']) ?> · <?= e($rr['rr_no']) ?><?= $rr['po_no'] ? ' · ' . e($rr['po_no']) : '' ?> · received <?= e(date('M j, Y', strtotime($rr['received_date']))) ?></p>
    </div>
</div>

<form class="form-layout" method="post" action="<?= e(url('pages/' . $self)) ?>" novalidate id="apForm" data-confirm="Record this supplier invoice?">
    <?= Csrf::field() ?>
    <div class="form-stack">
        <section class="card card--pad">
            <h2 class="card__title">Supplier's invoice</h2>
            <div class="form-grid">
                <label class="form-field">
                    <span class="form-label">Supplier invoice no. *</span>
                    <input class="form-input form-input--mono" name="invoice_no" maxlength="60" required value="<?= e(old('invoice_no', (string) $rr['reference_no'])) ?>"<?= invalid('invoice_no') ?>>
                    <?= field_error('invoice_no') ?>
                </label>
                <label class="form-field">
                    <span class="form-label">Amount *</span>
                    <input class="form-input num" name="amount" inputmode="decimal" maxlength="15" value="<?= e(old('amount', $rr['total_cost'] !== null ? number_format((float) $rr['total_cost'], 2, '.', '') : '')) ?>"<?= invalid('amount') ?>>
                    <?= field_error('amount') ?>
                    <small class="form-hint">Receiving report total: <?= $rr['total_cost'] !== null ? e(money($rr['total_cost'])) : '—' ?>. Change it if the supplier's invoice differs.</small>
                </label>
                <label class="form-field">
                    <span class="form-label">Invoice date *</span>
                    <input class="form-input" type="date" name="invoice_date" max="<?= e($today) ?>" value="<?= e($invDate) ?>"<?= invalid('invoice_date') ?>>
                    <?= field_error('invoice_date') ?>
                </label>
                <label class="form-field">
                    <span class="form-label">Due date *</span>
                    <input class="form-input" type="date" name="due_date" value="<?= e($due) ?>"<?= invalid('due_date') ?>>
                    <?= field_error('due_date') ?>
                    <small class="form-hint">Supplier terms: <?= (int) $rr['terms_days'] > 0 ? (int) $rr['terms_days'] . ' days' : 'due on receipt' ?> (Master Data → Suppliers).</small>
                </label>
                <label class="form-field form-field--full">
                    <span class="form-label">Notes</span>
                    <input class="form-input" name="notes" maxlength="255" value="<?= e(old('notes')) ?>"<?= invalid('notes') ?>>
                    <?= field_error('notes') ?>
                </label>
            </div>
        </section>
    </div>
    <div class="form-actions">
        <a class="btn btn--light" href="<?= e(url('pages/payables.php')) ?>">Cancel</a>
        <button type="submit" class="btn btn--primary" id="apSubmit"><?= icon('save') ?> Record Invoice</button>
    </div>
</form>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
