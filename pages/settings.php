<?php
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = settings_page('company', 'Settings'); // settings.manage

// ---------------------------------------------------------------------
// Save (PRG)
// ---------------------------------------------------------------------
if (is_post()) {
    Csrf::verifyRequest();
    [$data, $errors] = Settings::validate($_POST);
    if ($errors) {
        flash_old(array_filter($_POST, 'is_string'));
        flash_errors($errors);
        flash('error', 'Please fix the highlighted fields.');
        redirect('pages/settings.php');
    }
    $oldVat = setting('vat_rate', '12.00');
    Settings::save($data);
    flash('success', 'Company and receipt settings were saved.'
        . ($oldVat !== $data['vat_rate'] ? ' The new VAT rate applies to new sales; past sales keep the rate they were made with.' : ''));
    redirect('pages/settings.php');
}

// ---------------------------------------------------------------------
// Form
// ---------------------------------------------------------------------
$saved = Settings::all();
$val   = static fn (string $key): string => old($key, $saved[$key]);
$vatLabel = rtrim(rtrim($saved['vat_rate'], '0'), '.');
$settingsTab = 'company';

$pageStyles = ['css/settings.css'];
require ROOT_PATH . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Settings</h1>
        <p class="muted">Company details on receipts, VAT, and who can sign in.</p>
    </div>
</div>

<?php require ROOT_PATH . '/includes/settings-nav.php'; ?>

<?php if (Settings::hasPlaceholders() && !has_old()): ?>
    <div class="alert alert--warning" role="status">
        <?= icon('alert') ?>
        <span>Receipts still show the sample address and phone number. Replace them with your company's details below.</span>
    </div>
<?php endif; ?>

<div class="form-layout">
    <form class="card card--pad" method="post" action="<?= e(url('pages/settings.php')) ?>" novalidate id="settingsForm">
        <?= Csrf::field() ?>
        <h2 class="card__title">Company Details</h2>
        <div class="form-grid">
            <label class="form-field form-field--full">
                <span class="form-label">Company name *</span>
                <input class="form-input" name="shop_name" maxlength="100" required value="<?= e($val('shop_name')) ?>"<?= invalid('shop_name') ?>>
                <?= field_error('shop_name') ?>
            </label>
            <label class="form-field form-field--full">
                <span class="form-label">Address *</span>
                <input class="form-input" name="shop_address" maxlength="255" required value="<?= e($val('shop_address')) ?>"<?= invalid('shop_address') ?>
                       placeholder="Street, Barangay, City, Province">
                <?= field_error('shop_address') ?>
            </label>
            <label class="form-field">
                <span class="form-label">Phone</span>
                <input class="form-input" name="shop_phone" maxlength="30" inputmode="tel" value="<?= e($val('shop_phone')) ?>"<?= invalid('shop_phone') ?>
                       placeholder="(088) 123-4567">
                <?= field_error('shop_phone') ?>
            </label>
            <label class="form-field">
                <span class="form-label">VAT Reg. TIN</span>
                <input class="form-input form-input--mono" name="shop_tin" maxlength="20" value="<?= e($val('shop_tin')) ?>"<?= invalid('shop_tin') ?>
                       placeholder="000-000-000-000">
                <?= field_error('shop_tin') ?>
                <p class="form-hint">Leave empty to hide the TIN line on receipts.</p>
            </label>
        </div>

        <h2 class="card__title card__title--spaced">Sales &amp; Receipt</h2>
        <div class="form-grid">
            <label class="form-field">
                <span class="form-label">VAT rate (%) *</span>
                <input class="form-input" name="vat_rate" inputmode="decimal" maxlength="6" required value="<?= e($val('vat_rate')) ?>"<?= invalid('vat_rate') ?>>
                <?= field_error('vat_rate') ?>
                <p class="form-hint">Added on (subtotal − discount). Changing it affects new sales only.</p>
            </label>
            <label class="form-field form-field--full">
                <span class="form-label">Receipt footer</span>
                <textarea class="form-input" name="receipt_footer" maxlength="255" rows="2"<?= invalid('receipt_footer') ?>><?= e($val('receipt_footer')) ?></textarea>
                <?= field_error('receipt_footer') ?>
                <p class="form-hint">Printed at the bottom of every receipt (e.g. thank-you note, warranty policy).</p>
            </label>
        </div>

        <h2 class="card__title card__title--spaced">Job Orders</h2>
        <div class="form-grid">
            <label class="form-field">
                <span class="form-label">Quotation approval above (₱) *</span>
                <input class="form-input" name="job_quote_threshold" inputmode="decimal" maxlength="12" required
                       value="<?= e($val('job_quote_threshold')) ?>"<?= invalid('job_quote_threshold') ?>>
                <?= field_error('job_quote_threshold') ?>
                <p class="form-hint">A repair estimate above this amount needs the customer's approval before work starts. 0 = ask for every repair with a charge.</p>
            </label>
        </div>

        <div class="form-actions form-actions--inline">
            <button type="submit" class="btn btn--primary"><?= icon('save') ?> Save Settings</button>
        </div>
    </form>

    <aside class="form-side">
        <section class="card card--pad">
            <h2 class="card__title">Receipt Preview</h2>
            <p class="form-hint preview-hint">How the saved details print on receipts.</p>
            <div class="receipt-preview" id="receiptPreview">
                <strong><?= e($saved['shop_name']) ?></strong>
                <span><?= e($saved['shop_address']) ?></span>
                <?php if ($saved['shop_phone'] !== ''): ?><span>Tel: <?= e($saved['shop_phone']) ?></span><?php endif; ?>
                <?php if ($saved['shop_tin'] !== ''): ?><span>VAT Reg TIN: <?= e($saved['shop_tin']) ?></span><?php endif; ?>
                <hr>
                <span class="receipt-preview__row"><span>Subtotal</span><span>1,000.00</span></span>
                <span class="receipt-preview__row"><span>VAT (<?= e($vatLabel) ?>%)</span><span><?= e(number_format(1000 * (float) $saved['vat_rate'] / 100, 2)) ?></span></span>
                <span class="receipt-preview__row receipt-preview__total"><span>TOTAL</span><span><?= e(money(1000 + 1000 * (float) $saved['vat_rate'] / 100)) ?></span></span>
                <hr>
                <?php if ($saved['receipt_footer'] !== ''): ?><span><?= e($saved['receipt_footer']) ?></span><?php endif; ?>
            </div>
        </section>
    </aside>
</div>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
