<?php
/**
 * New purchase request (purchasing.request, current branch): needed-by date, purpose, optional open job order,
 * item lines (product + quantity + end-user). Numbered at once; a branch admin (not the requester) approves it.
 * PRG: errors come back through flash_old / flash_errors (line errors keyed items.N.field).
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('purchasing');
Auth::requirePermission('purchasing.request');

$returnTo = safe_return($_POST['return'] ?? $_GET['return'] ?? null, 'purchase-requests.php');
$self     = 'pr-form.php?' . http_build_query(['return' => $returnTo]);
$page['title'] = 'New Purchase Request';

if (is_post()) {
    Csrf::verifyRequest();
    $keepForm = static function (): void {
        $items = [];
        foreach (is_array($_POST['items'] ?? null) ? array_values($_POST['items']) : [] as $line) {
            $items[] = is_array($line) ? array_filter($line, 'is_string') : [];
        }
        flash_old(array_filter($_POST, 'is_string') + ['items' => $items]);
    };
    try {
        [$data, $errors] = PurchaseRequests::validate($_POST);
        if ($errors) {
            $keepForm();
            flash_errors($errors);
            flash('error', $errors['items'] ?? 'Please fix the highlighted fields.');
            redirect('pages/' . $self);
        }
        $res = PurchaseRequests::create($data, (int) Auth::id());
    } catch (HttpException $e) {
        if ($e->status === 403) {
            throw $e;
        }
        $keepForm();
        flash('error', $e->getMessage());
        redirect('pages/' . $self);
    }
    flash('success', "{$res['pr_no']} was sent for approval.");
    redirect('pages/pr-view.php?' . http_build_query(['id' => $res['id'], 'return' => $returnTo]));
}

$concrete = Branch::isConcrete();
$jobs     = PurchaseRequests::openJobs();
$old  = has_old() ? old_input() : [];
$rows = is_array($old['items'] ?? null) ? array_values($old['items']) : [];
if (!$rows) {
    $rows = [[]];
}
$rowVal = static fn (array $row, string $key): string => is_string($row[$key] ?? null) ? $row[$key] : '';
$jobId  = old('job_order_id', (string) (input_int($_GET, 'job', 1) ?? ''));

$stmt = db()->prepare(
    'SELECT p.id, p.code, p.barcode, p.name, p.track_serial FROM products p WHERE p.is_active = ? ORDER BY p.code'
);
$stmt->execute([1]);
$products = $stmt->fetchAll();
$errors   = form_errors();

$pageStyles  = ['css/sales.css', 'css/receiving.css', 'css/stock-docs.css', 'css/transfers.css', 'css/purchasing.css'];
$pageScripts = ['js/purchasing.js'];
require ROOT_PATH . '/includes/header.php';

/** One line; $i = 'N' or '__i__' in the template. */
$renderLine = static function (string $i, array $row, int $n) use ($products, $rowVal): void {
    $pid    = (int) $rowVal($row, 'product_id');
    $field  = static fn (string $f): string => "items[{$i}][{$f}]";
    $errKey = static fn (string $f): string => "items.{$i}.{$f}";
    ?>
    <tbody class="rr-line" data-line>
    <tr>
        <td class="rr-line__n muted" data-line-no><?= $n ?></td>
        <td class="rr-line__item">
            <div class="rr-item-pick">
                <input class="form-input form-input--mono rr-code" type="text" maxlength="60" placeholder="Code / barcode"
                       aria-label="Product code or barcode" data-code-input autocomplete="off">
                <select class="form-input" name="<?= e($field('product_id')) ?>" aria-label="Product" data-product<?= invalid($errKey('product_id')) ?>>
                    <option value="">Choose a product…</option>
                    <?php foreach ($products as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" data-code="<?= e($p['code']) ?>" data-barcode="<?= e((string) $p['barcode']) ?>"<?= $pid === (int) $p['id'] ? ' selected' : '' ?>>
                            <?= e($p['code'] . ' · ' . $p['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?= field_error($errKey('product_id')) ?>
        </td>
        <td class="pu-line__user">
            <input class="form-input" type="text" name="<?= e($field('end_user')) ?>" maxlength="100" placeholder="e.g. PGB, Accounting"
                   aria-label="End-user" value="<?= e($rowVal($row, 'end_user')) ?>"<?= invalid($errKey('end_user')) ?>>
            <?= field_error($errKey('end_user')) ?>
        </td>
        <td class="rr-line__qty">
            <input class="form-input num" type="number" name="<?= e($field('quantity')) ?>" min="1" max="<?= Products::MAX_STOCK ?>" step="1"
                   inputmode="numeric" aria-label="Quantity" data-qty value="<?= e($rowVal($row, 'quantity')) ?>"<?= invalid($errKey('quantity')) ?>>
            <?= field_error($errKey('quantity')) ?>
        </td>
        <td class="c-act">
            <button type="button" class="icon-btn icon-btn--danger-outline" data-remove-line aria-label="Remove line" title="Remove line"><?= icon('trash') ?></button>
        </td>
    </tr>
    </tbody>
    <?php
};
?>

<div class="page-head">
    <div>
        <a class="back-link" href="<?= e(url('pages/' . $returnTo)) ?>"><?= icon('arrow-left') ?> Purchase Requests</a>
        <h1>New Purchase Request</h1>
        <p class="muted">List what the branch needs. A branch admin approves it, then it is ordered from a supplier.</p>
    </div>
    <div class="page-actions">
        <span class="badge badge--period badge--branch"><?= icon('store') ?> <?= e(Branch::label()) ?></span>
    </div>
</div>
<?php if (!$concrete): ?>
    <div class="alert alert--warning" role="status">
        <?= icon('info') ?>
        <span>Choose your branch in the top bar first: the request is for the branch you work in.</span>
    </div>
<?php endif; ?>

<form class="form-layout rr-form" method="post" action="<?= e(url('pages/' . $self)) ?>" novalidate id="prForm"
      data-lines-form data-max-lines="<?= PurchaseRequests::MAX_LINES ?>" data-confirm="Send this purchase request for approval?">
    <?= Csrf::field() ?>
    <input type="hidden" name="return" value="<?= e($returnTo) ?>">

    <div class="form-stack">
        <section class="card card--pad">
            <h2 class="card__title">Request</h2>
            <div class="form-grid">
                <label class="form-field">
                    <span class="form-label">Needed by <small class="muted">(optional)</small></span>
                    <input class="form-input" type="date" name="needed_by" min="<?= e(date('Y-m-d')) ?>" value="<?= e(old('needed_by')) ?>"<?= invalid('needed_by') ?>>
                    <?= field_error('needed_by') ?>
                </label>
                <label class="form-field">
                    <span class="form-label">For job order <small class="muted">(optional)</small></span>
                    <select class="form-input" name="job_order_id"<?= invalid('job_order_id') ?>>
                        <option value="">Not for a job order</option>
                        <?php foreach ($jobs as $j): ?>
                            <option value="<?= (int) $j['id'] ?>"<?= $jobId === (string) $j['id'] ? ' selected' : '' ?>><?= e($j['job_no'] . ' · ' . $j['customer_name'] . (trim($j['brand'] . ' ' . $j['model']) !== '' ? ' · ' . trim($j['brand'] . ' ' . $j['model']) : '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= field_error('job_order_id') ?>
                </label>
                <label class="form-field form-field--full">
                    <span class="form-label">Purpose <small class="muted">(optional)</small></span>
                    <input class="form-input" name="purpose" maxlength="255" value="<?= e(old('purpose')) ?>"
                           placeholder="e.g. Restock for the school season"<?= invalid('purpose') ?>>
                    <?= field_error('purpose') ?>
                </label>
            </div>
        </section>

        <section class="card">
            <header class="card__head">
                <h2><?= icon('box') ?> Items</h2>
                <button type="button" class="btn btn--light btn--sm" id="addLine"><?= icon('plus') ?> Add Line</button>
            </header>
            <?php if (isset($errors['items'])): ?>
                <p class="form-error rr-items-error" id="err-items"><?= e($errors['items']) ?></p>
            <?php endif; ?>
            <div class="table-wrap">
                <table class="table rr-lines" id="docLines">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Item</th>
                        <th>End-user</th>
                        <th class="num">Qty</th>
                        <th><span class="visually-hidden">Remove</span></th>
                    </tr>
                    </thead>
                    <?php foreach ($rows as $n => $row): ?>
                        <?php $renderLine((string) $n, is_array($row) ? $row : [], $n + 1); ?>
                    <?php endforeach; ?>
                </table>
            </div>
            <p class="form-hint rr-lines__hint">Type a code or scan a barcode in the first box, or pick the product. One line per product,
                up to <?= PurchaseRequests::MAX_LINES ?> lines. End-user = who the item is for (optional).</p>
        </section>
    </div>

    <aside class="form-side">
        <section class="card card--pad">
            <h2 class="card__title">Summary</h2>
            <dl class="detail-list">
                <div><dt>Branch</dt><dd><?= e(Branch::label()) ?></dd></div>
                <div><dt>Lines</dt><dd id="sumLines">0</dd></div>
                <div><dt>Total quantity</dt><dd id="sumQty">0</dd></div>
            </dl>
            <p class="form-hint doc-side-hint"><?= icon('info') ?> A request does not order or move stock. After approval it is put on a purchase order to a supplier.</p>
        </section>
    </aside>

    <div class="form-actions">
        <a class="btn btn--light" href="<?= e(url('pages/' . $returnTo)) ?>">Cancel</a>
        <button type="submit" class="btn btn--primary" id="sendPrBtn"<?= $concrete ? '' : ' disabled' ?>><?= icon('check') ?> Send for Approval</button>
    </div>
</form>

<template id="lineTpl"><?php $renderLine('__i__', [], 0); ?></template>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
