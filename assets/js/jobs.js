/**
 * Job Orders pages:
 *  - job-form.php: choosing a customer record fills the name / contact number when they are empty
 *    or still hold the previous customer's values.
 *  - job-view.php: Print Ticket, dialogs re-opened after a failed submit, one post per submit.
 */
(function () {
    'use strict';

    // ---- Print -----------------------------------------------------------
    document.querySelectorAll('[data-print]').forEach((btn) => {
        btn.addEventListener('click', () => window.print());
    });

    // ---- Dialogs re-rendered after a failed submit -----------------------
    document.querySelectorAll('dialog[data-reopen]').forEach((dialog) => {
        if (!(dialog instanceof HTMLDialogElement) || dialog.open) return;
        dialog.showModal();
        const bad = dialog.querySelector('[aria-invalid="true"]') || dialog.querySelector('textarea, input:not([type=hidden])');
        if (bad) bad.focus();
    });

    // ---- One post per submit (double clicks) -----------------------------
    // Document level, registered after app.js: runs after its data-confirm question.
    document.addEventListener('submit', (e) => {
        const form = e.target;
        if (e.defaultPrevented || !(form instanceof HTMLFormElement) || (form.getAttribute('method') || '').toLowerCase() !== 'post') return;
        if (form.dataset.sent) {
            e.preventDefault();
            return;
        }
        form.dataset.sent = '1';
    });

    // ---- Intake: customer record -> name / phone -------------------------
    const customer = document.getElementById('customerId');
    const name = document.getElementById('customerName');
    const phone = document.getElementById('customerPhone');
    if (customer && name && phone) {
        let last = { name: '', phone: '' };
        const selected = () => customer.selectedOptions[0];
        const opt = selected();
        if (opt && opt.value) last = { name: opt.dataset.name || '', phone: opt.dataset.phone || '' };
        customer.addEventListener('change', () => {
            const o = selected();
            const next = o && o.value ? { name: o.dataset.name || '', phone: o.dataset.phone || '' } : { name: '', phone: '' };
            if (name.value.trim() === '' || name.value === last.name) name.value = next.name;
            if (phone.value.trim() === '' || phone.value === last.phone) phone.value = next.phone;
            last = next;
        });
    }
})();
