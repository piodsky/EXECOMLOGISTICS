<?php
/**
 * Contact person rows (supplier and customer forms). Rows: Contacts::formRows($saved).
 * Inputs post as contacts[i][name|position|phone|email]; blank rows are ignored on save.
 *
 * @var list<array{name:string, position:string, phone:string, email:string}> $contactRows
 * @var string $ro ' disabled' for a read-only view, else ''
 */
?>
<div class="contact-rows" id="contactRows">
    <?= field_error('contacts') ?>
    <?php foreach ($contactRows as $i => $c): ?>
        <fieldset class="contact-row" data-contact-row="<?= (int) $i ?>">
            <legend class="form-label">Contact <?= (int) $i + 1 ?></legend>
            <div class="form-grid contact-grid">
                <?php foreach (['name' => ['Name', 100, 'text', ''], 'position' => ['Position', 60, 'text', ''],
                                'phone' => ['Phone', 30, 'text', 'tel'], 'email' => ['Email', 120, 'email', 'email']] as $field => [$label, $max, $type, $mode]): ?>
                    <?php $key = "contact_{$i}_{$field}"; ?>
                    <label class="form-field">
                        <span class="form-label form-label--sm"><?= e($label) ?></span>
                        <input class="form-input" type="<?= $type ?>" name="contacts[<?= (int) $i ?>][<?= $field ?>]" maxlength="<?= $max ?>"
                               <?= $mode !== '' ? 'inputmode="' . $mode . '" ' : '' ?>value="<?= e($c[$field] ?? '') ?>"<?= invalid($key) ?><?= $ro ?>>
                        <?= field_error($key) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>
    <?php endforeach; ?>
    <?php if (!$contactRows): ?>
        <p class="muted">No contact persons.</p>
    <?php endif; ?>
</div>
