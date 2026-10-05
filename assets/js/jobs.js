/**
 * Job Orders pages:
 *  - job-form.php: choosing a customer record fills the name / contact number when they are empty
 *    or still hold the previous customer's values.
 *  - job-form.php: problem quick picks fill the problem text; "None" accessory excludes the others; the lead
 *    technician is not offered as a helper (also on the job-view Assign form).
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

    // ---- Intake: problem quick picks -> lines in the "Problem reported" text ----
    // Ticking adds the problem as its own line (once); unticking removes that line. The text stays editable.
    const problem = document.getElementById('jobProblem');
    const picks = document.querySelectorAll('[data-problem-pick]');
    if (problem && picks.length) {
        const lines = () => problem.value.split('\n');
        picks.forEach((box) => {
            const text = box.dataset.problemPick;
            box.checked = lines().some((l) => l.trim() === text);
            box.addEventListener('change', () => {
                const current = lines().filter((l) => l.trim() !== text);
                if (box.checked) {
                    while (current.length && current[current.length - 1].trim() === '') current.pop();
                    current.push(text);
                }
                problem.value = current.join('\n').replace(/^\n+/, '');
                problem.dispatchEvent(new Event('input', { bubbles: true }));
            });
        });
        problem.addEventListener('input', (e) => {
            if (e.isTrusted) picks.forEach((box) => { box.checked = lines().some((l) => l.trim() === box.dataset.problemPick); });
        });
    }

    // ---- Intake: "None (unit only)" accessory excludes the others --------
    const accessories = document.getElementById('accessoryChecks');
    if (accessories) {
        accessories.addEventListener('change', (e) => {
            const box = e.target;
            if (!(box instanceof HTMLInputElement) || !box.checked) return;
            const isNone = /^none\b/i.test(box.value);
            accessories.querySelectorAll('input[type=checkbox]').forEach((other) => {
                if (other !== box && /^none\b/i.test(other.value) !== isNone) other.checked = false;
            });
        });
    }

    // ---- Intake / assign: the lead technician is not also a helper -------
    document.querySelectorAll('[data-lead-select]').forEach((lead) => {
        const group = document.getElementById(lead.dataset.leadSelect);
        if (!group) return;
        const sync = () => group.querySelectorAll('input[type=checkbox]').forEach((box) => {
            const isLead = box.value === lead.value;
            box.disabled = isLead;
            if (isLead) box.checked = false;
            box.closest('label')?.classList.toggle('is-disabled', isLead);
        });
        lead.addEventListener('change', sync);
        sync();
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
