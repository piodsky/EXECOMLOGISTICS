/**
 * Receiving pages: draft form lines (add/remove, code lookup, serial counts, live totals)
 * and the Print button on the report view. No innerHTML: lines are cloned from #rrLineTpl.
 */
(function () {
    'use strict';

    // ---- Print (receiving-view.php) -------------------------------------
    document.querySelectorAll('[data-print]').forEach((btn) => {
        btn.addEventListener('click', () => window.print());
    });

    const form = document.getElementById('rrForm');
    const table = document.getElementById('rrLines');
    const tpl = document.getElementById('rrLineTpl');
    if (!form || !table || !tpl) return;

    const maxLines = parseInt(form.dataset.maxLines, 10) || 100;
    const currency = form.dataset.currency || '';
    const lines = () => [...table.querySelectorAll('tbody[data-line]')];
    let nextIndex = lines().length;

    const fmt = (cents) => `${currency} ${(cents / 100).toLocaleString('en-US', {
        minimumFractionDigits: 2, maximumFractionDigits: 2,
    })}`;
    /**
     * "30,000.5" -> 300005000 (integer units of 1/10000); invalid -> NaN.
     * Same rules as Receiving::validate: commas stripped, then trimmed, up to 4 decimals, max 999,999.9999.
     */
    const costUnits = (text) => {
        const m = /^(\d{1,6})(?:\.(\d{1,4}))?$/.exec(String(text).replace(/,/g, '').trim());
        return m ? parseInt(m[1], 10) * 10000 + parseInt((m[2] || '').padEnd(4, '0'), 10) : NaN;
    };
    /** qty x cost in cents, half-up (same as Costing::lineCents). Max 99,999 x 9,999,999,999 stays a safe integer. */
    const lineCents = (qty, units) => Math.floor((2 * qty * units + 100) / 200);
    const serialList = (text) => text.split(/\r?\n/).map((s) => s.trim()).filter((s) => s !== '');

    function selectedOption(line) {
        const select = line.querySelector('[data-product]');
        return select.options[select.selectedIndex] || null;
    }

    /** Show the serial box for serial-tracked products (or when it already has text) + its count. */
    function syncLine(line) {
        const opt = selectedOption(line);
        const track = !!opt && opt.dataset.serial === '1';
        const box = line.querySelector('[data-serials]');
        const row = line.querySelector('[data-serial-row]');
        const list = serialList(box.value);
        row.hidden = !track && list.length === 0;

        const qty = parseInt(line.querySelector('[data-qty]').value, 10);
        const count = line.querySelector('[data-serial-count]');
        if (!track && list.length) {
            count.textContent = 'This product does not use serial numbers: remove them.';
            count.classList.add('is-warn');
        } else if (Number.isInteger(qty) && qty > 0) {
            count.textContent = `${list.length} of ${qty} serial numbers`;
            count.classList.toggle('is-warn', list.length !== qty);
        } else {
            count.textContent = `${list.length} serial ${list.length === 1 ? 'number' : 'numbers'}`;
            count.classList.remove('is-warn');
        }
    }

    function updateTotals() {
        let qtySum = 0;
        let costSum = 0;
        let costOk = true;
        let used = 0;
        lines().forEach((line, i) => {
            line.querySelector('.rr-line__n').textContent = String(i + 1);
            const qty = parseInt(line.querySelector('[data-qty]').value, 10);
            const hasProduct = line.querySelector('[data-product]').value !== '';
            if (hasProduct || Number.isInteger(qty)) used += 1;
            if (Number.isInteger(qty) && qty > 0) qtySum += qty;

            const costInput = line.querySelector('[data-cost]');
            const totalCell = line.querySelector('[data-line-total]');
            if (!costInput || !totalCell) return;
            const units = costUnits(costInput.value);
            if (Number.isFinite(units) && Number.isInteger(qty) && qty > 0) {
                const cents = lineCents(qty, units);
                totalCell.textContent = fmt(cents);
                costSum += cents;
            } else {
                totalCell.textContent = '—';
                if (hasProduct) costOk = false;
            }
        });
        document.getElementById('sumLines').textContent = String(used);
        document.getElementById('sumQty').textContent = qtySum.toLocaleString('en-US');
        const sumCost = document.getElementById('sumCost');
        if (sumCost) sumCost.textContent = costOk ? fmt(costSum) : 'Incomplete';
        document.getElementById('addLine').disabled = lines().length >= maxLines;
    }

    function addLine() {
        if (lines().length >= maxLines) {
            BB.toast(`A receiving report can have at most ${maxLines} lines.`, 'warning');
            return null;
        }
        const line = tpl.content.firstElementChild.cloneNode(true);
        const index = String(nextIndex++);
        line.querySelectorAll('[name]').forEach((el) => {
            el.setAttribute('name', el.getAttribute('name').replace('__i__', index));
        });
        table.appendChild(line);
        syncLine(line);
        updateTotals();
        return line;
    }

    /** Code / barcode box: pick the matching product (exact, case-insensitive). */
    function lookupCode(input) {
        const q = input.value.trim().toLowerCase();
        if (!q) return;
        const line = input.closest('tbody[data-line]');
        const select = line.querySelector('[data-product]');
        const opt = [...select.options].find((o) => o.value !== ''
            && ((o.dataset.code || '').toLowerCase() === q || (o.dataset.barcode || '').toLowerCase() === q));
        if (!opt) {
            BB.toast(`No active product with code or barcode “${input.value.trim()}”.`, 'error');
            input.select();
            return;
        }
        select.value = opt.value;
        input.value = '';
        syncLine(line);
        updateTotals();
        line.querySelector('[data-qty]').focus();
    }

    // ---- Events ----------------------------------------------------------
    document.getElementById('addLine').addEventListener('click', () => {
        const line = addLine();
        if (line) line.querySelector('[data-code-input]').focus();
    });

    table.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-remove-line]');
        if (!btn) return;
        const line = btn.closest('tbody[data-line]');
        line.remove();
        if (!lines().length) addLine();
        updateTotals();
    });

    table.addEventListener('change', (e) => {
        const line = e.target.closest('tbody[data-line]');
        if (!line) return;
        if (e.target.matches('[data-code-input]')) {
            lookupCode(e.target);
            return;
        }
        syncLine(line);
        updateTotals();
    });

    table.addEventListener('input', (e) => {
        const line = e.target.closest('tbody[data-line]');
        if (!line || e.target.matches('[data-code-input]')) return;
        if (e.target.matches('[data-serials], [data-qty]')) syncLine(line);
        updateTotals();
    });

    // Enter in the code box (or a scanner) looks the code up instead of submitting the form.
    table.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && e.target.matches('[data-code-input]')) {
            e.preventDefault();
            lookupCode(e.target);
        }
    });

    // Buttons with data-confirm-submit ask first (Save & Post).
    form.addEventListener('submit', (e) => {
        const msg = e.submitter && e.submitter.dataset.confirmSubmit;
        if (msg && !window.confirm(msg)) e.preventDefault();
    });

    lines().forEach(syncLine);
    updateTotals();
})();
