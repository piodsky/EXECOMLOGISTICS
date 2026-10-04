<?php
/**
 * Record a collection (collections.manage, concrete branch): choose the customer (one with open bills on account at
 * the branch), then the payment (date, method, check / reference no., bank, check date, notes) and per open bill the
 * cash applied, the expanded withholding tax (BIR 2307) and the VAT withheld (BIR 2306). Helper buttons fill a full
 * payment with the chosen withholding rates (assets/js/collections.js); the server re-checks every amount against
 * the bill balance. Posts at once (CR-<branch>-<year>-NNNNNN). pages/collection-form.php[?customer=ID]
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('collections');
Auth::requirePermission('collections.manage');

$customerId = input_int($_GET, 'customer', 1);
$self = 'collection-form.php' . ($customerId !== null ? '?customer=' . $customerId : '');

if (is_post()) {
    Csrf::verifyRequest();
    [$data, $errors] = Collections::validate($_POST);
    $keep = static function (): void {
        $lines = [];
        foreach (is_array($_POST['lines'] ?? null) ? $_POST['lines'] : [] as $sid => $l) {
            if (is_array($l)) {
                $lines[(string) (int) $sid] = array_filter($l, 'is_string');
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
        $res = Collections::post($data, (int) Auth::id());
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
    flash('success', "Collection {$res['collection_no']} was recorded.");
    redirect('pages/collection-view.php?id=' . $res['id']);
}

$concrete  = Branch::isConcrete();
$customers = Collections::customersWithBalance();
$customer  = null;
foreach ($customers as $c) {
    if ((int) $c['id'] === $customerId) {
        $customer = $c;
    }
}
$bills  = $customer !== null ? Collections::openBills((int) $customer['id']) : [];
$gov    = $customer !== null && stripos((string) $customer['type_name'], 'government') !== false;
$old    = has_old() ? old_input() : [];
$oldLine = static fn (int $sid, string $k): string => is_string($old['lines'][(string) $sid][$k] ?? null) ? $old['lines'][(string) $sid][$k] : '';
$method = old('method', 'check');
$errors = form_errors();
$page['title'] = 'Record Collection';

$pageStyles  = ['css/sales.css', 'css/receiving.css', 'css/stock-docs.css', 'css/transfers.css', 'css/purchasing.css'];
$pageScripts = ['js/collections.js', 'js/purchasing.js'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <a class="back-link" href="<?= e(url('pages/collections.php')) ?>"><?= icon('arrow-left') ?> Collections</a>
        <h1 class="sale-title">Record Collection</h1>
        <p class="muted">A payment received from a customer on bills on account at <strong><?= e(Branch::label()) ?></strong>.</p>
    </div>
</div>

<?php if (!$concrete): ?>
    <div class="alert alert--warning" role="status"><?= icon('info') ?><span>Choose a branch in the top bar first: bills are collected at the branch that billed them.</span></div>
<?php endif; ?>

<section class="card card--pad cr-pick">
    <form method="get" action="<?= e(url('pages/collection-form.php')) ?>" class="toolbar cr-pick__form">
        <label class="form-field cr-pick__field">
            <span class="form-label">Customer</span>
            <select class="form-input" name="customer" id="crCustomer" data-autosubmit>
                <option value="">Choose a customer with open bills…</option>
                <?php foreach ($customers as $c): ?>
                    <option value="<?= (int) $c['id'] ?>"<?= (int) $c['id'] === $customerId ? ' selected' : '' ?>><?= e($c['name']) ?> · <?= (int) $c['bills'] ?> bill<?= (int) $c['bills'] === 1 ? '' : 's' ?> · <?= e(money($c['balance'])) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit" class="btn btn--light">Show Bills</button>
    </form>
    <?php if (!$customers && $concrete): ?><p class="muted"><?= icon('check') ?> No customer owes anything at this branch.</p><?php endif; ?>
</section>

<?php if ($customer !== null): ?>
<form class="form-layout rr-form" method="post" action="<?= e(url('pages/' . $self)) ?>" novalidate id="crForm"
      data-currency="<?= e(config('app.currency')) ?>" data-confirm="Record this collection? The bills are updated at once.">
    <?= Csrf::field() ?>
    <input type="hidden" name="customer_id" value="<?= (int) $customer['id'] ?>">

    <div class="form-stack">
        <section class="card card--pad">
            <h2 class="card__title">Payment from <?= e($customer['name']) ?></h2>
            <div class="form-grid">
                <label class="form-field">
                    <span class="form-label">Date received *</span>
                    <input class="form-input" type="date" name="collection_date" max="<?= e(date('Y-m-d')) ?>" value="<?= e(old('collection_date', date('Y-m-d'))) ?>"<?= invalid('collection_date') ?>>
                    <?= field_error('collection_date') ?>
                </label>
                <label class="form-field">
                    <span class="form-label">Method *</span>
                    <select class="form-input" name="method" id="crMethod"<?= invalid('method') ?>>
                        <?php foreach (Collections::METHODS as $k => $lbl): ?><option value="<?= e($k) ?>"<?= $method === $k ? ' selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
                    </select>
                    <?= field_error('method') ?>
                </label>
                <label class="form-field" data-method="check bank gcash">
                    <span class="form-label" id="crRefLabel">Check / reference no.</span>
                    <input class="form-input form-input--mono" name="reference" maxlength="60" value="<?= e(old('reference')) ?>"<?= invalid('reference') ?>>
                    <?= field_error('reference') ?>
                </label>
                <label class="form-field" data-method="check bank">
                    <span class="form-label">Bank</span>
                    <input class="form-input" name="bank_name" maxlength="60" placeholder="e.g. Landbank Maramag" value="<?= e(old('bank_name')) ?>"<?= invalid('bank_name') ?>>
                    <?= field_error('bank_name') ?>
                </label>
                <label class="form-field" data-method="check">
                    <span class="form-label">Check date</span>
                    <input class="form-input" type="date" name="check_date" value="<?= e(old('check_date')) ?>"<?= invalid('check_date') ?>>
                    <?= field_error('check_date') ?>
                </label>
                <label class="form-field form-field--full">
                    <span class="form-label">Notes</span>
                    <input class="form-input" name="notes" maxlength="255" placeholder="e.g. LDDAP-ADA no., OR to follow" value="<?= e(old('notes')) ?>"<?= invalid('notes') ?>>
                    <?= field_error('notes') ?>
                </label>
            </div>
        </section>

        <section class="card">
            <header class="card__head">
                <h2><?= icon('receipt') ?> Open bills</h2>
                <div class="cr-rates">
                    <label>EWT <select class="form-input form-input--sm" id="crEwtRate" aria-label="Expanded withholding tax rate">
                        <?php foreach (['0' => 'None', '1' => '1% (goods)', '2' => '2% (services)'] as $k => $lbl): ?><option value="<?= $k ?>"<?= ($gov ? '1' : '0') === $k ? ' selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
                    </select></label>
                    <label>VAT w/h <select class="form-input form-input--sm" id="crVatRate" aria-label="VAT withholding rate">
                        <?php foreach (['0' => 'None', '5' => '5% (government)'] as $k => $lbl): ?><option value="<?= $k ?>"<?= ($gov ? '5' : '0') === $k ? ' selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
                    </select></label>
                    <button type="button" class="btn btn--light btn--sm" id="crFillAll"><?= icon('check') ?> Pay All in Full</button>
                </div>
            </header>
            <?php if (isset($errors['lines'])): ?><p class="form-error rr-items-error" id="err-lines"><?= e($errors['lines']) ?></p><?php endif; ?>
            <div class="table-wrap">
                <table class="table doc-lines cr-lines" id="crLines">
                    <thead>
                    <tr><th>Bill</th><th class="num">Balance</th><th class="num">Cash applied</th><th class="num">EWT (2307)</th><th class="num">VAT w/h (2306)</th><th class="num">Left</th><th><span class="visually-hidden">Fill</span></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($bills as $b): ?>
                        <?php
                        $sid = (int) $b['id'];
                        $bal = to_cents($b['balance']);
                        $tot = to_cents($b['total']);
                        $base = $tot > 0 ? (int) round(($tot - to_cents($b['vat_amount'])) * $bal / $tot) : 0; // net of VAT, for what is still due
                        ?>
                        <tr data-cr-line data-balance="<?= $bal ?>" data-base="<?= $base ?>">
                            <td><strong class="doc-no">No. <?= e($b['sale_no']) ?></strong>
                                <small class="muted block"><?= e(date('M j, Y', strtotime($b['created_at']))) ?> · <?= number_format((int) $b['days']) ?> days<?= $b['order_no'] ? ' · ' . e($b['order_no']) : '' ?><?= $b['customer_po_no'] ? ' · PO ' . e($b['customer_po_no']) : '' ?></small></td>
                            <td class="num"><?= e(money($b['balance'])) ?></td>
                            <?php foreach (['amount' => 'Cash applied', 'ewt' => 'EWT', 'vat' => 'VAT withheld'] as $k => $lbl): ?>
                                <td class="num"><input class="form-input num cr-amt" name="lines[<?= $sid ?>][<?= $k ?>]" inputmode="decimal" maxlength="15" placeholder="0.00"
                                           aria-label="<?= e($lbl) ?> for bill No. <?= e($b['sale_no']) ?>" data-cr="<?= $k ?>" value="<?= e($oldLine($sid, $k)) ?>"<?= invalid("lines.{$sid}.{$k}") ?>>
                                    <?= field_error("lines.{$sid}.{$k}") ?></td>
                            <?php endforeach; ?>
                            <td class="num" data-cr-left>—</td>
                            <td class="c-act"><button type="button" class="btn btn--light btn--sm" data-cr-full title="Pay this bill in full, with the withholding rates above">Full</button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="form-hint rr-lines__hint">Withholding is computed on the amount before VAT: EWT 1% for goods / 2% for services (BIR 2307), and 5% final VAT
                for government offices (BIR 2306). Change any amount to match the customer's voucher.</p>
        </section>
    </div>

    <aside class="form-side">
        <section class="card card--pad">
            <h2 class="card__title">Summary</h2>
            <dl class="detail-list">
                <div><dt>Cash / check received</dt><dd id="crSumCash">—</dd></div>
                <div><dt>EWT withheld</dt><dd id="crSumEwt">—</dd></div>
                <div><dt>VAT withheld</dt><dd id="crSumVat">—</dd></div>
                <div class="cr-sum-total"><dt>Total credited</dt><dd id="crSumTotal">—</dd></div>
            </dl>
            <p class="form-hint doc-side-hint"><?= icon('info') ?> With taxes withheld, the receipt waits for the customer's 2307 / 2306 certificate until you mark it received.</p>
        </section>
    </aside>

    <div class="form-actions">
        <a class="btn btn--light" href="<?= e(url('pages/collections.php')) ?>">Cancel</a>
        <button type="submit" class="btn btn--primary" id="crSubmit"><?= icon('save') ?> Record Collection</button>
    </div>
</form>
<?php endif; ?>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
