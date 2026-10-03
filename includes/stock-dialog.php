<?php
/**
 * "Adjust stock" dialog. Opened by any [data-adjust] button (inventory.js fills it in).
 * Posts to inventory.php (action=adjust), which redirects back to $stockReturn.
 * The change applies to the current branch; only include it when Branch::isConcrete()
 * and the user has inventory.adjust.
 *
 * @var string $stockReturn  page to come back to, e.g. 'inventory.php?page=2'
 * @var ?array $stockLocation the branch's POS location (Warehouses::pickerLocations row, is_default):
 *                            adjustments go there (Products::adjustStock), so the dialog names it.
 *                            [data-adjust] buttons pass its quantity as data-stock.
 */
?>
<dialog class="modal" id="adjustDialog" aria-labelledby="adjustTitle">
    <form class="modal__body" method="post" action="<?= e(url('pages/inventory.php')) ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="adjust">
        <input type="hidden" name="id" id="adjustId">
        <input type="hidden" name="return" value="<?= e($stockReturn) ?>">

        <header class="modal__head">
            <h2 id="adjustTitle">Adjust Stock</h2>
            <button type="button" class="modal__close" data-close aria-label="Close"><?= icon('x') ?></button>
        </header>

        <p class="adjust-product">
            <strong id="adjustName"></strong>
            <?php
            $adjustAt = Branch::label() . (isset($stockLocation)
                ? ' · ' . $stockLocation['warehouse_code'] . ' / ' . $stockLocation['code'] . ' - ' . $stockLocation['name'] : '');
            ?>
            <span class="muted" id="adjustAt">Current stock at <?= e($adjustAt) ?>: <strong id="adjustStock"></strong></span>
            <small class="muted block">Adjustments change the POS location only. Use Stock Operations for other locations.</small>
        </p>

        <fieldset class="segmented">
            <legend class="form-label">Change</legend>
            <label><input type="radio" name="direction" value="add" checked> <span><?= icon('plus') ?> Add stock</span></label>
            <label><input type="radio" name="direction" value="remove"> <span><?= icon('minus') ?> Remove stock</span></label>
        </fieldset>

        <div class="form-grid">
            <label class="form-field">
                <span class="form-label">Quantity</span>
                <input class="form-input" type="number" name="quantity" id="adjustQty" min="1" max="<?= Products::MAX_STOCK ?>" step="1" required>
            </label>
            <label class="form-field">
                <span class="form-label">Reason</span>
                <select class="form-input" name="reason" id="adjustReason" required>
                    <?php foreach (Products::REASONS as $key => [$label, $dir]): ?>
                        <option value="<?= e($key) ?>" data-direction="<?= e($dir) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="form-field form-field--full">
                <span class="form-label">Note <span class="muted">(optional)</span></span>
                <input class="form-input" type="text" name="note" maxlength="200" placeholder="e.g. Delivery from supplier, DR #1234">
            </label>
        </div>

        <p class="adjust-preview muted" id="adjustPreview"></p>

        <footer class="modal__foot">
            <button type="button" class="btn btn--light" data-close>Cancel</button>
            <button type="submit" class="btn btn--primary">Save Adjustment</button>
        </footer>
    </form>
</dialog>
