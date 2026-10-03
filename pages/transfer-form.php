<?php
/**
 * Request stock from another branch (transfers.request, current branch = receiving branch):
 * source branch, note, item lines (product + quantity). Serials are chosen by the sending branch at release.
 * PRG: errors come back through flash_old / flash_errors (line errors keyed items.N.field).
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
$page = require_page('transfers');
Auth::requirePermission('transfers.request');

$returnTo = safe_return($_POST['return'] ?? $_GET['return'] ?? null, 'transfers.php');
$self     = 'transfer-form.php?' . http_build_query(['return' => $returnTo]);
$page['title'] = 'Request Stock';

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
        [$data, $errors] = Transfers::validateRequest($_POST);
        if ($errors) {
            $keepForm();
            flash_errors($errors);
            flash('error', $errors['items'] ?? $errors['from_branch_id'] ?? 'Please fix the highlighted fields.');
            redirect('pages/' . $self);
        }
        $res = Transfers::request($data, (int) Auth::id());
    } catch (HttpException $e) {
        if ($e->status === 403) {
            throw $e;
        }
        $keepForm();
        flash('error', $e->getMessage());
        redirect('pages/' . $self);
    }
    flash('success', "Transfer {$res['transfer_no']} was requested. The sending branch approves and releases it.");
    redirect('pages/transfer-view.php?' . http_build_query(['id' => $res['id'], 'return' => $returnTo]));
}

$concrete = Branch::isConcrete();
$sources  = $concrete ? Transfers::sourceBranches() : [];

$old  = has_old() ? old_input() : [];
$rows = is_array($old['items'] ?? null) ? array_values($old['items']) : [];
if (!$rows) {
    $rows = [[]];
}
$rowVal = static fn (array $row, string $key): string => is_string($row[$key] ?? null) ? $row[$key] : '';
$fromId = old('from_branch_id');

$stmt = db()->prepare(
    'SELECT p.id, p.code, p.barcode, p.name, p.track_serial, un.code AS unit_code
       FROM products p LEFT JOIN units un ON un.id = p.unit_id
      WHERE p.is_active = ? ORDER BY p.code'
);
$stmt->execute([1]);
$products = $stmt->fetchAll();
$errors   = form_errors();

$pageStyles  = ['css/sales.css', 'css/receiving.css', 'css/stock-docs.css', 'css/transfers.css'];
$pageScripts = ['js/transfers.js'];
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
                            <?= e($p['code'] . ' · ' . $p['name']) ?><?= (int) $p['track_serial'] === 1 ? ' · S/N' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?= field_error($errKey('product_id')) ?>
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
        <a class="back-link" href="<?= e(url('pages/' . $returnTo)) ?>"><?= icon('arrow-left') ?> Branch Transfers</a>
        <h1>Request Stock</h1>
        <p class="muted">Ask another branch for items. They approve and release it; you receive it here when it arrives.</p>
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

<form class="form-layout rr-form" method="post" action="<?= e(url('pages/' . $self)) ?>" novalidate id="transferForm"
      data-max-lines="<?= Transfers::MAX_LINES ?>" data-confirm="Send this request to the other branch?">
    <?= Csrf::field() ?>
    <input type="hidden" name="return" value="<?= e($returnTo) ?>">

    <div class="form-stack">
        <section class="card card--pad">
            <h2 class="card__title">Request</h2>
            <div class="form-grid">
                <label class="form-field">
                    <span class="form-label">Request from branch *</span>
                    <select class="form-input" name="from_branch_id" id="fromBranch" required<?= invalid('from_branch_id') ?>>
                        <option value="">Choose a branch…</option>
                        <?php foreach ($sources as $b): ?>
                            <option value="<?= (int) $b['id'] ?>"<?= $fromId === (string) $b['id'] ? ' selected' : '' ?>><?= e($b['code'] . ' · ' . $b['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= field_error('from_branch_id') ?>
                </label>
                <label class="form-field">
                    <span class="form-label">Deliver to</span>
                    <input class="form-input" value="<?= e(Branch::label()) ?>" readonly aria-readonly="true">
                </label>
                <label class="form-field form-field--full">
                    <span class="form-label">Note <small class="muted">(optional)</small></span>
                    <input class="form-input" name="notes" maxlength="255" value="<?= e(old('notes')) ?>"
                           placeholder="e.g. For the school order on Friday"<?= invalid('notes') ?>>
                    <?= field_error('notes') ?>
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
                <table class="table rr-lines" id="transferLines">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Item</th>
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
                up to <?= Transfers::MAX_LINES ?> lines. Serial numbers are chosen by the sending branch.</p>
        </section>
    </div>

    <aside class="form-side">
        <section class="card card--pad">
            <h2 class="card__title">Summary</h2>
            <dl class="detail-list">
                <div><dt>To</dt><dd><?= e(Branch::label()) ?></dd></div>
                <div><dt>Lines</dt><dd id="sumLines">0</dd></div>
                <div><dt>Total quantity</dt><dd id="sumQty">0</dd></div>
            </dl>
            <p class="form-hint doc-side-hint"><?= icon('info') ?> A request does not move stock. Stock leaves the other branch when they release it.</p>
        </section>
    </aside>

    <div class="form-actions">
        <a class="btn btn--light" href="<?= e(url('pages/' . $returnTo)) ?>">Cancel</a>
        <button type="submit" class="btn btn--primary" id="requestBtn"<?= $concrete && $sources ? '' : ' disabled' ?>><?= icon('check') ?> Send Request</button>
    </div>
</form>

<template id="lineTpl"><?php $renderLine('__i__', [], 0); ?></template>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
