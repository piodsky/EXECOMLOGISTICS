<?php
/**
 * Quick "Add customer" dialog for forms with a customer select. Put a button next to the select:
 *   <button type="button" class="icon-btn" data-add-customer="selectId">…</button>
 * assets/js/customer-add.js opens this dialog, posts to api/customers/create.php (customers.edit, working branch),
 * then adds the new customer to that select, selects it and fires "change".
 * Include only when customer_quick_add_allowed() is true; add 'js/customer-add.js' to $pageScripts.
 */
$cqaTypes = MasterData::options('customer-types', null);
?>
<dialog class="modal" id="customerAddDialog" aria-labelledby="customerAddTitle">
    <form class="modal__body" id="customerAddForm" novalidate>
        <header class="modal__head">
            <h2 id="customerAddTitle">Add Customer</h2>
            <button type="button" class="modal__close" data-close aria-label="Close"><?= icon('x') ?></button>
        </header>
        <div class="form-grid cqa-grid">
            <label class="form-field form-field--full">
                <span class="form-label">Name *</span>
                <input class="form-input" name="name" required maxlength="100" autocomplete="off" placeholder="Person, company or office">
            </label>
            <label class="form-field">
                <span class="form-label">Phone</span>
                <input class="form-input" name="phone" maxlength="30" inputmode="tel" autocomplete="off" placeholder="0917 123 4567">
            </label>
            <label class="form-field">
                <span class="form-label">Email</span>
                <input class="form-input" name="email" type="email" maxlength="120" autocomplete="off">
            </label>
            <label class="form-field">
                <span class="form-label">Customer type</span>
                <select class="form-input" name="customer_type_id">
                    <option value="">Not set</option>
                    <?php foreach ($cqaTypes as $t): ?>
                        <?php if ((int) $t['is_active'] === 1): ?><option value="<?= (int) $t['id'] ?>"><?= e($t['name']) ?></option><?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="form-field">
                <span class="form-label">TIN</span>
                <input class="form-input form-input--mono" name="tin" maxlength="20" autocomplete="off" placeholder="123-456-789-000">
            </label>
            <label class="form-field form-field--full">
                <span class="form-label">Address</span>
                <input class="form-input" name="address" maxlength="255" autocomplete="off">
            </label>
        </div>
        <p class="form-hint">More details (contacts, credit terms) can be added later in Customers.</p>
        <p class="alert alert--error cqa-error" id="customerAddError" role="alert" hidden></p>
        <footer class="modal__foot">
            <button type="button" class="btn btn--light" data-close>Cancel</button>
            <button type="submit" class="btn btn--primary" id="customerAddSave"><?= icon('save') ?> Save Customer</button>
        </footer>
    </form>
</dialog>
