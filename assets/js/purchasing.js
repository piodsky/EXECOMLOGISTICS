/**
 * Purchasing pages (purchase requests, PO Internal) and Customer Orders (orders, delivery receipts, billing):
 *  - pr-form.php / po-form.php ([data-lines-form]): item lines (add / remove, code or barcode lookup), summary;
 *    with [data-cost-lines] also line totals and the total amount (same rounding as Costing::lineCents).
 *    Changing the product of a line loaded from a purchase request drops its request link.
 *  - po-form.php: choosing a supplier fills empty payment terms.
 *  - views: dialogs re-opened after a failed submit, data-confirm-submit buttons, one post per submit.
 * No innerHTML: new lines are cloned from <template id="lineTpl"> and filled with values / textContent.
 */
(function () {
    'use strict';

    // ---- Dialogs re-rendered after a failed submit -----------------------
    document.querySelectorAll('dialog[data-reopen]').forEach((dialog) => {
        if (!(dialog instanceof HTMLDialogElement) || dialog.open) return;
        dialog.showModal();
        const bad = dialog.querySelector('[aria-invalid="true"]') || dialog.querySelector('textarea, input:not([type=hidden])');
        if (bad) bad.focus();
    });

    // ---- Confirm buttons; one post per submit ----------------------------
    // Document level, registered after app.js: runs after its data-confirm question.
    document.addEventListener('submit', (e) => {
        const form = e.target;
        if (e.defaultPrevented || !(form instanceof HTMLFormElement)) return;
        const msg = e.submitter && e.submitter.dataset.confirmSubmit;
        if (msg && !window.confirm(msg)) {
            e.preventDefault();
            return;
        }
        if ((form.getAttribute('method') || '').toLowerCase() !== 'post') return;
        if (form.dataset.sent) {
            e.preventDefault();
            return;
        }
        form.dataset.sent = '1';
    });

    // ---- Supplier -> payment terms (PO form) -----------------------------
    const supplier = document.getElementById('poSupplier');
    const terms = document.getElementById('poTerms');
    if (supplier && terms) {
        let last = '';
        const current = () => (supplier.selectedOptions[0] && supplier.selectedOptions[0].dataset.terms) || '';
        last = current();
        supplier.addEventListener('change', () => {
            const next = current();
            if (terms.value.trim() === '' || terms.value === last) terms.value = next;
            last = next;
        });
    }

    // ---- Customer -> place of delivery (customer order form) --------------
    const customer = document.getElementById('coCustomer');
    const place = document.getElementById('coPlace');
    if (customer && place) {
        const addr = () => (customer.selectedOptions[0] && customer.selectedOptions[0].dataset.address) || '';
        let lastAddr = addr();
        customer.addEventListener('change', () => {
            const nextAddr = addr();
            if (place.value.trim() === '' || place.value === lastAddr) place.value = nextAddr;
            lastAddr = nextAddr;
        });
    }

    // ---- Serial picks (delivery receipt) -----------------------------------
    document.querySelectorAll('fieldset[data-pick]').forEach((box) => {
        const row = box.closest('tr');
        const qtyInput = row && row.querySelector('[data-pick-qty]');
        const out = box.querySelector('[data-pick-count]');
        const update = () => {
            const n = box.querySelectorAll('input[type=checkbox]:checked').length;
            const need = qtyInput ? (parseInt(qtyInput.value, 10) || 0) : (Number(box.dataset.pick) || 0);
            if (!out) return;
            out.textContent = `${n} chosen of ${need}.`;
            out.classList.toggle('is-warn', n !== need);
        };
        box.addEventListener('change', update);
        if (qtyInput) qtyInput.addEventListener('input', update);
        update();
    });

    // ---- Bill dialog: amount received only for cash ---------------------------
    const pay = document.getElementById('billPayment');
    if (pay) {
        const sync = () => document.querySelectorAll('[data-cash-only]').forEach((el) => { el.hidden = pay.value !== 'cash'; });
        pay.addEventListener('change', sync);
        sync();
    }

    // =====================================================================
    // Line forms
    // =====================================================================
    const form = document.querySelector('form[data-lines-form]');
    const table = document.getElementById('docLines');
    const tpl = document.getElementById('lineTpl');
    if (!form || !table || !tpl) return;

    const max = Number(form.dataset.maxLines) || 100;
    const withCost = form.hasAttribute('data-cost-lines');
    const currency = form.dataset.currency || '';
    const lines = () => Array.from(table.querySelectorAll('tbody[data-line]'));
    let next = lines().length;

    const fmt = (cents) => `${currency} ${(cents / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    /** "30,000.5" -> integer units of 1/10000; invalid -> NaN (same rules as PurchaseOrders::validate). */
    const costUnits = (text) => {
        const m = /^(\d{1,7})(?:\.(\d{1,4}))?$/.exec(String(text).replace(/,/g, '').trim());
        return m ? parseInt(m[1], 10) * 10000 + parseInt((m[2] || '').padEnd(4, '0'), 10) : NaN;
    };
    const lineCents = (qty, units) => Math.floor((2 * qty * units + 100) / 200);

    const summary = () => {
        let count = 0;
        let qty = 0;
        let cents = 0;
        let costOk = true;
        lines().forEach((line, i) => {
            const no = line.querySelector('[data-line-no]');
            if (no) no.textContent = String(i + 1);
            const pid = line.querySelector('[data-product]').value;
            const q = parseInt(line.querySelector('[data-qty]').value, 10);
            if (pid) count++;
            if (pid && q > 0) qty += q;
            if (!withCost) return;
            const cell = line.querySelector('[data-line-total]');
            const units = costUnits(line.querySelector('[data-cost]').value);
            if (Number.isFinite(units) && Number.isInteger(q) && q > 0) {
                const c = lineCents(q, units);
                cell.textContent = fmt(c);
                cents += c;
            } else {
                cell.textContent = '—';
                if (pid) costOk = false;
            }
        });
        const set = (id, text) => { const el = document.getElementById(id); if (el) el.textContent = text; };
        set('sumLines', String(count));
        set('sumQty', qty.toLocaleString('en-US'));
        if (withCost) set('sumCost', costOk ? fmt(cents) : 'Incomplete');
        const addBtn = document.getElementById('addLine');
        if (addBtn) addBtn.disabled = lines().length >= max;
    };

    const wire = (line) => {
        const code = line.querySelector('[data-code-input]');
        const select = line.querySelector('[data-product]');
        const qty = line.querySelector('[data-qty]');
        const links = line.querySelector('[data-links]');
        const priceInput = line.querySelector('[data-price-input]');
        const hint = line.querySelector('[data-price-hint]');
        const showHint = () => {
            if (!hint) return;
            const o = select.selectedOptions[0];
            hint.textContent = o && o.value && o.dataset.price !== undefined
                ? `Suggested ${fmt(Math.round(parseFloat(o.dataset.price) * 100))} · ${Number(o.dataset.free || 0).toLocaleString('en-US')} free at the branch`
                : '';
        };
        const pick = () => {
            const v = code.value.trim().toUpperCase();
            if (!v) return;
            const opt = Array.from(select.options).find((o) =>
                o.value && ((o.dataset.code || '').toUpperCase() === v || (o.dataset.barcode || '').toUpperCase() === v));
            if (opt) {
                select.value = opt.value;
                select.dispatchEvent(new Event('change', { bubbles: true }));
                code.value = '';
                if (!qty.value) qty.value = '1';
                qty.focus();
                qty.select();
            } else {
                code.setCustomValidity('No active product with this code or barcode.');
                code.reportValidity();
            }
        };
        code.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            pick();
        });
        code.addEventListener('input', () => code.setCustomValidity(''));
        select.addEventListener('change', () => {
            if (select.value && !qty.value) qty.value = '1';
            const o = select.selectedOptions[0];
            if (priceInput && o && o.dataset.price !== undefined && priceInput.value.trim() === '') priceInput.value = o.dataset.price;
            showHint();
            if (links && links.value) { // a different product no longer belongs to the purchase request
                links.value = '';
                const note = line.querySelector('[data-links-note]');
                if (note) note.remove();
            }
            summary();
        });
        line.querySelectorAll('input').forEach((el) => el.addEventListener('input', summary));
        showHint();
        line.querySelector('[data-remove-line]').addEventListener('click', () => {
            if (lines().length > 1) {
                line.remove();
            } else {
                line.querySelectorAll('input:not([type=hidden]), select').forEach((el) => { el.value = ''; });
                if (links) links.value = '';
                const note = line.querySelector('[data-links-note]');
                if (note) note.remove();
            }
            summary();
        });
    };

    const addLine = () => {
        if (lines().length >= max) {
            if (window.BB && BB.toast) BB.toast(`At most ${max} items.`, 'error');
            return;
        }
        const frag = tpl.content.cloneNode(true);
        const line = frag.querySelector('tbody[data-line]');
        const i = String(next++);
        line.querySelectorAll('[name]').forEach((el) => { el.setAttribute('name', el.getAttribute('name').replace('__i__', i)); });
        table.appendChild(frag);
        wire(line);
        summary();
        line.querySelector('[data-code-input]').focus();
    };

    lines().forEach(wire);
    const addBtn = document.getElementById('addLine');
    if (addBtn) addBtn.addEventListener('click', addLine);
    summary();
})();
