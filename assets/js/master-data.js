/* Master Data lists (pages/master-data.php): one add/edit dialog filled from the row's data-* attributes.
   The form posts normally (PRG); after a validation error the server re-renders it with data-reopen. */
(() => {
    'use strict';

    const dialog = document.getElementById('mdDialog');
    const form = document.getElementById('mdForm');
    if (!(dialog instanceof HTMLDialogElement) || !form) return;

    const title = document.getElementById('mdTitle');
    const field = (id) => document.getElementById(id);

    const clearErrors = () => {
        form.querySelectorAll('[aria-invalid="true"]').forEach((el) => el.removeAttribute('aria-invalid'));
        form.querySelectorAll('.form-error').forEach((el) => { el.hidden = true; });
    };

    const fill = (data) => {
        clearErrors();
        field('mdId').value = data.id || '';
        field('mdName').value = data.name || '';
        if (field('mdCode')) field('mdCode').value = data.code || '';
        if (field('mdBrand')) field('mdBrand').value = data.brand || '';
        if (field('mdSort')) field('mdSort').value = data.sort || '0';
        field('mdActive').checked = data.active !== '0';
        const icon = form.querySelector(`input[name="icon"][value="${CSS.escape(data.icon || 'grid')}"]`);
        if (icon) icon.checked = true;
        title.textContent = data.id ? dialog.dataset.titleEdit : dialog.dataset.titleAdd;
        dialog.showModal();
        (field('mdCode') || field('mdBrand') || field('mdName')).focus();
    };

    document.addEventListener('click', (e) => {
        const edit = e.target.closest('[data-md-edit]');
        if (edit) {
            fill({ ...edit.dataset });
            return;
        }
        if (e.target.closest('[data-md-new]')) fill({});
    });

    if (dialog.hasAttribute('data-reopen')) {
        dialog.showModal();
        (form.querySelector('[aria-invalid="true"]') || field('mdName')).focus();
    }
})();
