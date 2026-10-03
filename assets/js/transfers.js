/**
 * Branch Transfers pages:
 *  - transfer-form.php: item lines (add / remove, code or barcode lookup), summary.
 *  - transfer-view.php: Print slip, confirm + one post per page load, serial pick counts (release)
 *    and received counts (receive).
 * No innerHTML: new lines are cloned from <template> and filled with values / textContent.
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

    // ---- Confirm buttons; one post per page load -------------------------
    document.querySelectorAll('form').forEach((form) => {
        form.addEventListener('submit', (e) => {
            if (e.defaultPrevented) return; // app.js data-confirm said no
            const msg = e.submitter && e.submitter.dataset.confirmSubmit;
            if (msg && !window.confirm(msg)) { e.preventDefault(); return; }
            if (form.method.toLowerCase() === 'post') {
                if (form.dataset.sent) { e.preventDefault(); return; }
                form.dataset.sent = '1';
            }
        });
    });

    const plural = (n, one, many) => `${n.toLocaleString('en-US')} ${n === 1 ? one : many}`;

    // =====================================================================
    // Request form
    // =====================================================================
    const form = document.getElementById('transferForm');
    const table = document.getElementById('transferLines');
    const tpl = document.getElementById('lineTpl');
    if (form && table && tpl) {
        const max = Number(form.dataset.maxLines) || 100;
        const lines = () => Array.from(table.querySelectorAll('tbody[data-line]'));
        let next = lines().length;

        const summary = () => {
            let count = 0;
            let qty = 0;
            lines().forEach((line, i) => {
                const no = line.querySelector('[data-line-no]');
                if (no) no.textContent = String(i + 1);
                const pid = line.querySelector('[data-product]').value;
                const q = parseInt(line.querySelector('[data-qty]').value, 10);
                if (pid) count++;
                if (pid && q > 0) qty += q;
            });
            const sumLines = document.getElementById('sumLines');
            const sumQty = document.getElementById('sumQty');
            if (sumLines) sumLines.textContent = String(count);
            if (sumQty) sumQty.textContent = qty.toLocaleString('en-US');
        };

        const wire = (line) => {
            const code = line.querySelector('[data-code-input]');
            const select = line.querySelector('[data-product]');
            const qty = line.querySelector('[data-qty]');
            code.addEventListener('keydown', (e) => {
                if (e.key !== 'Enter') return;
                e.preventDefault();
                const v = code.value.trim().toUpperCase();
                if (!v) return;
                const opt = Array.from(select.options).find((o) =>
                    o.value && ((o.dataset.code || '').toUpperCase() === v || (o.dataset.barcode || '').toUpperCase() === v));
                if (opt) {
                    select.value = opt.value;
                    code.value = '';
                    if (!qty.value) qty.value = '1';
                    qty.focus();
                    qty.select();
                    summary();
                } else {
                    code.setCustomValidity('No active product with this code or barcode.');
                    code.reportValidity();
                }
            });
            code.addEventListener('input', () => code.setCustomValidity(''));
            select.addEventListener('change', () => { if (select.value && !qty.value) qty.value = '1'; summary(); });
            qty.addEventListener('input', summary);
            line.querySelector('[data-remove-line]').addEventListener('click', () => {
                if (lines().length > 1) {
                    line.remove();
                } else {
                    select.value = '';
                    qty.value = '';
                }
                summary();
            });
        };

        const addLine = () => {
            if (lines().length >= max) {
                if (window.BB && BB.toast) BB.toast(`A transfer can have at most ${max} items.`, 'error');
                return;
            }
            const frag = tpl.content.cloneNode(true);
            const line = frag.querySelector('tbody[data-line]');
            const i = String(next++);
            line.querySelectorAll('[name]').forEach((el) => { el.name = el.name.replace('__i__', i); });
            table.appendChild(frag);
            wire(line);
            summary();
            line.querySelector('[data-code-input]').focus();
        };

        lines().forEach(wire);
        const addBtn = document.getElementById('addLine');
        if (addBtn) addBtn.addEventListener('click', addLine);
        summary();
    }

    // =====================================================================
    // View: release serial picks, receive counts
    // =====================================================================
    document.querySelectorAll('fieldset[data-pick]').forEach((box) => {
        const need = Number(box.dataset.pick) || 0;
        const out = box.querySelector('[data-pick-count]');
        const update = () => {
            const n = box.querySelectorAll('input[type=checkbox]:checked').length;
            if (!out) return;
            out.textContent = `${plural(n, 'serial', 'serials')} chosen of ${need}.`;
            out.classList.toggle('is-warn', n !== need);
        };
        box.addEventListener('change', update);
        update();
    });

    document.querySelectorAll('fieldset[data-arrived]').forEach((box) => {
        const row = box.closest('tr');
        const out = row && row.querySelector('[data-arrived-count]');
        const update = () => {
            if (out) out.textContent = String(box.querySelectorAll('input[type=checkbox]:checked').length);
        };
        box.addEventListener('change', update);
        update();
    });
})();
