<?php
/**
 * Pay a supplier (payables.manage + products.cost, concrete branch): choose the supplier (one with unpaid invoices at
 * the branch), then the payment (date, method, check / reference no., bank, check date, particulars) and per invoice
 * the cash paid + the EWT EXECOM withholds (assets/js/payables.js fills "Full"; the base for EWT is the amount before
 * 12% VAT). Posts at once (DV-<branch>-<year>-NNNNNN). pages/dv-form.php[?supplier=ID]
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('payables');
Payables::requireView();
Auth::requirePermission('payables.manage');

$supplierId = input_int($_GET, 'supplier', 1);
$self = 'dv-form.php' . ($supplierId !== null ? '?supplier=' . $supplierId : '');

if (is_post()) {
    Csrf::verifyRequest();
    [$data, $errors] = Payables::validateDv($_POST);
    $keep = static function (): void {
        $lines = [];
        foreach (is_array($_POST['lines'] ?? null) ? $_POST['lines'] : [] as $iid => $l) {
            if (is_array($l)) {
                $lines[(string) (int) $iid] = array_filter($l, 'is_string');
            }
        }
        flash_old(array_filter($_POST, 'is_string') + ['lines' => $lines]);
    };
    if ($errors) {
        $keep();
        flash_errors($errors);
        flash('error', $errors['lines'] ?? 'Please fix the highlighted fields.');
        redirect('pages/' . $self);
    }
    try {
        $res = Payables::postDv($data, (int) Auth::id());
    } catch (HttpException $e) {
        if ($e->status === 403) {
            throw $e;
        }
        $keep();
        if (is_array($e->details['errors'] ?? null)) {
            flash_errors($e->details['errors']);
        }
        flash('error', $e->getMessage());
        redirect('pages/' . $self);
    }
    flash('success', "Disbursement voucher {$res['dv_no']} was recorded.");
    redirect('pages/dv-view.php?id=' . $res['id']);
}

$suppliers = Payables::suppliersWithBalance();
$supplier  = null;
foreach ($suppliers as $s) {
    if ((int) $s['id'] === $supplierId) {
        $supplier = $s;
    }
}
$invoices = $supplier !== null ? Payables::openInvoices((int) $supplier['id']) : [];
$old      = has_old() ? old_input() : [];
$oldLine  = static fn (int $iid, string $k): string => is_string($old['lines'][(string) $iid][$k] ?? null) ? $old['lines'][(string) $iid][$k] : '';
$method   = old('method', 'check');
$errors   = form_errors();
$vatRate  = (float) setting('vat_rate', '12');
$page['title'] = 'Pay Supplier';
$pageStyles  = ['css/sales.css', 'css/receiving.css', 'css/stock-docs.css', 'css/transfers.css', 'css/purchasing.css'];
$pageScripts = ['js/payables.js', 'js/purchasing.js'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <a class="back-link" href="<?= e(url('pages/disbursements.php')) ?>"><?= icon('arrow-left') ?> Disbursements</a>
        <h1 class="sale-title">Pay Supplier</h1>
        <p class="muted">A disbursement voucher at <strong><?= e(Branch::label()) ?></strong>, paying the supplier's unpaid invoices.</p>
    </div>
</div>

<?php if (!Branch::isConcrete()): ?>
    <div class="alert alert--warning" role="status"><?= icon('info') ?><span>Choose a branch in the top bar first: invoices are paid by the branch that received the goods.</span></div>
<?php endif; ?>

<section class="card card--pad cr-pick">
    <form method="get" action="<?= e(url('pages/dv-form.php')) ?>" class="toolbar cr-pick__form">
        <label class="form-field cr-pick__field">
            <span class="form-label">Supplier</span>
            <select class="form-input" name="supplier" id="dvSupplier" data-autosubmit>
                <option value="">Choose a supplier with unpaid invoices…</option>
                <?php foreach ($suppliers as $s): ?>
                    <option value="<?= (int) $s['id'] ?>"<?= (int) $s['id'] === $supplierId ? ' selected' : '' ?>><?= e($s['name']) ?> · <?= (int) $s['invoices'] ?> invoice<?= (int) $s['invoices'] === 1 ? '' : 's' ?> · <?= e(money($s['balance'])) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit" class="btn btn--light">Show Invoices</button>
    </form>
    <?php if (!$suppliers && Branch::isConcrete()): ?><p class="muted"><?= icon('check') ?> Nothing to pay: record supplier invoices first (Payables).</p><?php endif; ?>
</section>

<?php if ($supplier !== null): ?>
<form class="form-layout rr-form" method="post" action="<?= e(url('pages/' . $self)) ?>" novalidate id="dvForm"
      data-currency="<?= e(config('app.currency')) ?>" data-vat="<?= e((string) $vatRate) ?>" data-confirm="Record this payment? The invoices are updated at once.">
    <?= Csrf::field() ?>
    <input type="hidden" name="supplier_id" value="<?= (int) $supplier['id'] ?>">
    <div class="form-stack">
        <section class="card card--pad">
            <h2 class="card__title">Payment to <?= e($supplier['name']) ?></h2>
            <div class="form-grid">
                <label class="form-field"><span class="form-label">Payment date *</span>
                    <input class="form-input" type="date" name="payment_date" max="<?= e(date('Y-m-d')) ?>" value="<?= e(old('payment_date', date('Y-m-d'))) ?>"<?= invalid('payment_date') ?>>
                    <?= field_error('payment_date') ?></label>
                <label class="form-field"><span class="form-label">Method *</span>
                    <select class="form-input" name="method" id="crMethod"<?= invalid('method') ?>>
                        <?php foreach (Collections::METHODS as $k => $lbl): ?><option value="<?= e($k) ?>"<?= $method === $k ? ' selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
                    </select><?= field_error('method') ?></label>
                <label class="form-field" data-method="check bank gcash"><span class="form-label" id="crRefLabel">Check / reference no.</span>
                    <input class="form-input form-input--mono" name="reference" maxlength="60" value="<?= e(old('reference')) ?>"<?= invalid('reference') ?>>
                    <?= field_error('reference') ?></label>
                <label class="form-field" data-method="check bank"><span class="form-label">Bank</span>
                    <input class="form-input" name="bank_name" maxlength="60" placeholder="e.g. BDO Maramag" value="<?= e(old('bank_name')) ?>"<?= invalid('bank_name') ?>>
                    <?= field_error('bank_name') ?></label>
                <label class="form-field" data-method="check"><span class="form-label">Check date</span>
                    <input class="form-input" type="date" name="check_date" value="<?= e(old('check_date')) ?>"<?= invalid('check_date') ?>>
                    <?= field_error('check_date') ?></label>
                <label class="form-field form-field--full"><span class="form-label">Particulars</span>
                    <input class="form-input" name="particulars" maxlength="255" placeholder="e.g. Payment of SI 20871 and 20890" value="<?= e(old('particulars')) ?>"<?= invalid('particulars') ?>>
                    <?= field_error('particulars') ?></label>
            </div>
        </section>

        <section class="card">
            <header class="card__head">
                <h2><?= icon('clipboard') ?> Unpaid invoices</h2>
                <div class="cr-rates">
                    <label>EWT <select class="form-input form-input--sm" id="dvEwtRate" aria-label="Withholding tax rate">
                        <?php foreach (['0' => 'None', '1' => '1% (goods)', '2' => '2% (services)'] as $k => $lbl): ?><option value="<?= $k ?>"><?= e($lbl) ?></option><?php endforeach; ?>
                    </select></label>
                    <button type="button" class="btn btn--light btn--sm" id="dvFillAll"><?= icon('check') ?> Pay All in Full</button>
                </div>
            </header>
            <?php if (isset($errors['lines'])): ?><p class="form-error rr-items-error" id="err-lines"><?= e($errors['lines']) ?></p><?php endif; ?>
            <div class="table-wrap">
                <table class="table doc-lines cr-lines" id="dvLines">
                    <thead><tr><th>Invoice</th><th class="num">Balance</th><th class="num">Cash paid</th><th class="num">EWT withheld</th><th class="num">Left</th><th><span class="visually-hidden">Fill</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($invoices as $inv): ?>
                        <?php $iid = (int) $inv['id']; $bal = to_cents($inv['balance']); ?>
                        <tr data-dv-line data-balance="<?= $bal ?>">
                            <td><strong class="doc-no"><?= e($inv['ap_no']) ?></strong>
                                <small class="muted block">Inv. <?= e($inv['invoice_no']) ?> · <?= e($inv['rr_no']) ?> · due <?= e(date('M j, Y', strtotime($inv['due_date']))) ?><?= (int) $inv['days'] > 0 ? ' · ' . (int) $inv['days'] . ' days late' : '' ?></small></td>
                            <td class="num"><?= e(money($inv['balance'])) ?></td>
                            <?php foreach (['amount' => 'Cash paid', 'ewt' => 'EWT withheld'] as $k => $lbl): ?>
                                <td class="num"><input class="form-input num cr-amt" name="lines[<?= $iid ?>][<?= $k ?>]" inputmode="decimal" maxlength="15" placeholder="0.00"
                                           aria-label="<?= e($lbl) ?> for <?= e($inv['ap_no']) ?>" data-dv="<?= $k ?>" value="<?= e($oldLine($iid, $k)) ?>"<?= invalid("lines.{$iid}.{$k}") ?>>
                                    <?= field_error("lines.{$iid}.{$k}") ?></td>
                            <?php endforeach; ?>
                            <td class="num" data-dv-left>—</td>
                            <td class="c-act"><button type="button" class="btn btn--light btn--sm" data-dv-full>Full</button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="form-hint rr-lines__hint">EWT is computed on the invoice amount before <?= e(rtrim(rtrim(number_format($vatRate, 2), '0'), '.')) ?>% VAT. When EXECOM withholds, give the supplier BIR Form 2307.</p>
        </section>
    </div>
    <aside class="form-side">
        <section class="card card--pad">
            <h2 class="card__title">Summary</h2>
            <dl class="detail-list">
                <div><dt>Cash / check paid</dt><dd id="dvSumPaid">—</dd></div>
                <div><dt>EWT withheld</dt><dd id="dvSumEwt">—</dd></div>
                <div class="cr-sum-total"><dt>Invoices settled</dt><dd id="dvSumTotal">—</dd></div>
            </dl>
        </section>
    </aside>
    <div class="form-actions">
        <a class="btn btn--light" href="<?= e(url('pages/payables.php')) ?>">Cancel</a>
        <button type="submit" class="btn btn--primary" id="dvSubmit"><?= icon('save') ?> Record Payment</button>
    </div>
</form>
<?php endif; ?>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
