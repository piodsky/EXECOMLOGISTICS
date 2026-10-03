/* Settings → Warehouses (pages/warehouses.php): add / rename dialogs for warehouses and locations,
   filled from the button's data-* attributes. The forms post normally (PRG); after a validation
   error the server re-renders the dialog with data-reopen. */
(() => {
    'use strict';

    const clearErrors = (form) => {
        form.querySelectorAll('[aria-invalid="true"]').forEach((el) => el.removeAttribute('aria-invalid'));
        form.querySelectorAll('.form-error').forEach((el) => { el.hidden = true; });
    };

    /** Shared open logic: code is editable only when adding (codes are immutable). */
    const setup = (dialogId, prefix, onFill) => {
        const dialog = document.getElementById(dialogId);
        if (!(dialog instanceof HTMLDialogElement)) return null;
        const form = dialog.querySelector('form');
        const field = (name) => document.getElementById(prefix + name);
        const open = (data) => {
            clearErrors(form);
            const editing = Boolean(data.id);
            field('Id').value = data.id || '';
            field('Code').value = data.code || '';
            field('Code').readOnly = editing;
            field('Name').value = data.name || '';
            document.getElementById(prefix + 'Title').textContent = editing ? dialog.dataset.titleEdit : dialog.dataset.titleAdd;
            if (onFill) onFill(data, editing);
            dialog.showModal();
            (editing ? field('Name') : field('Code')).focus();
        };
        if (dialog.hasAttribute('data-reopen')) {
            dialog.showModal();
            (form.querySelector('[aria-invalid="true"]') || field('Name')).focus();
        }
        return open;
    };

    const openWarehouse = setup('whDialog', 'wh', (data, editing) => {
        const hint = document.getElementById('whNewHint');
        if (hint) hint.hidden = editing;
    });
    const openLocation = setup('locDialog', 'loc', (data) => {
        document.getElementById('locWarehouse').value = data.warehouseId || '';
        document.getElementById('locWarehouseLabel').textContent = data.warehouseLabel || '';
        document.getElementById('locWarehouseLabelInput').value = data.warehouseLabel || '';
    });

    document.addEventListener('click', (e) => {
        const t = e.target;
        if (!(t instanceof Element)) return;
        const whEdit = t.closest('[data-wh-edit]');
        if (whEdit && openWarehouse) { openWarehouse({ ...whEdit.dataset }); return; }
        if (t.closest('[data-wh-new]') && openWarehouse) { openWarehouse({}); return; }
        const locEdit = t.closest('[data-loc-edit]');
        if (locEdit && openLocation) { openLocation({ ...locEdit.dataset }); return; }
        const locNew = t.closest('[data-loc-new]');
        if (locNew && openLocation) {
            openLocation({ warehouseId: locNew.dataset.warehouseId, warehouseLabel: locNew.dataset.warehouseLabel });
        }
    });
})();
