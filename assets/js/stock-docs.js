/**
 * Stock Operations pages (Phase 7b):
 *  - stock-docs.php: reopen the New Count dialog after an error.
 *  - stock-doc-form.php: item lines (add / remove, code lookup), location pickers filtered by the
 *    source warehouse, stock available at the source and the serial checklist of serial-tracked
 *    items (api/inventory/serials.php). Serial items: quantity = number of ticked serials.
 *  - stock-doc-view.php: Print, live differences on the count sheet, confirm on Submit.
 * No innerHTML: rows are cloned from <template>s and filled with textContent.
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
        const bad = dialog.querySelector('[aria-invalid="true"]') || dialog.querySelector('select, input:not([type=hidden])');
        if (bad) bad.focus();
    });

    // ---- Buttons with data-confirm-submit ask first ----------------------
    document.querySelectorAll('form').forEach((form) => {
        form.addEventListener('submit', (e) => {
            const msg = e.submitter && e.submitter.dataset.confirmSubmit;
            if (msg && !window.confirm(msg)) { e.preventDefault(); return; }
            // One post per page load: a double click must not post a document twice.
            if (form.method.toLowerCase() === 'post') {
                if (form.dataset.sent) { e.preventDefault(); return; }
                form.dataset.sent = '1';
            }
        });
    });

    const plural = (n, one, many) => `${n.toLocaleString('en-US')} ${n === 1 ? one : many}`;

    // =====================================================================
    // New transfer / internal use / write-off
    // =====================================================================
    const form = document.getElementById('docForm');
    const table = document.getElementById('docLines');
    const lineTpl = document.getElementById('docLineTpl');
    const serialTpl = document.getElementById('serialTpl');
    if (form && table && lineTpl && serialTpl) {
        const maxLines = parseInt(form.dataset.maxLines, 10) || 100;
        const fromSel = document.getElementById('fromLocation');
        const toSel = document.getElementById('toLocation');
        const lines = () => [...table.querySelectorAll('tbody[data-line]')];
        let nextIndex = lines().length;
        const cache = new Map(); // "productId:locationId" -> Promise<{qty, track_serial, serials}>

        const fromLabel = () => {
            const opt = fromSel.options[fromSel.selectedIndex];
            return opt && opt.value ? (opt.dataset.label || opt.textContent.trim()) : '';
        };

        /** Destination options: never the source; damage / display only in the source's warehouse. */
        function syncTo() {
            if (!toSel) return;
            const fromOpt = fromSel.options[fromSel.selectedIndex];
            const sameWh = toSel.dataset.sameWarehouse === '1';
            const wh = fromOpt && fromOpt.value ? fromOpt.dataset.warehouse : null;
            let firstVisible = null;
            [...toSel.options].forEach((opt) => {
                if (!opt.value) return;
                const ok = opt.value !== fromSel.value && (!sameWh || wh === null || opt.dataset.warehouse === wh);
                opt.hidden = !ok;
                opt.disabled = !ok;
                if (ok && !firstVisible) firstVisible = opt;
            });
            const current = toSel.options[toSel.selectedIndex];
            if (current && current.disabled) toSel.value = '';
            if (sameWh && !toSel.value && firstVisible) toSel.value = firstVisible.value;
        }

        function stockAt(productId, locationId) {
            const key = `${productId}:${locationId}`;
            if (!cache.has(key)) {
                const q = new URLSearchParams({ product_id: productId, location_id: locationId });
                const p = BB.api(`inventory/serials.php?${q}`).catch((err) => {
                    cache.delete(key);
                    throw err;
                });
                cache.set(key, p);
            }
            return cache.get(key);
        }

        const selectedOption = (line) => {
            const select = line.querySelector('[data-product]');
            return select.options[select.selectedIndex] || null;
        };

        /** Ticked serial ids of a line (or the server's list on the first render after an error). */
        function chosenSerials(line) {
            const box = line.querySelector('[data-serial-box]');
            const inputs = box.querySelectorAll('input[type=checkbox]');
            if (inputs.length) return new Set([...inputs].filter((i) => i.checked).map((i) => i.value));
            return new Set((box.dataset.selected || '').split(',').filter((s) => s !== ''));
        }

        function syncSerialCount(line) {
            const opt = selectedOption(line);
            const track = !!opt && opt.dataset.serial === '1';
            if (!track) return;
            const boxes = [...line.querySelectorAll('[data-serial-list] input[type=checkbox]')];
            const n = boxes.filter((b) => b.checked).length;
            const qty = line.querySelector('[data-qty]');
            qty.value = n > 0 ? String(n) : '';
            const count = line.querySelector('[data-serial-count]');
            count.textContent = boxes.length ? `${n} of ${boxes.length} ticked` : '';
            count.classList.toggle('is-warn', n === 0 && boxes.length > 0);
        }

        /** Availability at the source + the serial checklist; stale answers are ignored. */
        async function loadLine(line) {
            const opt = selectedOption(line);
            const avail = line.querySelector('[data-avail]');
            const row = line.querySelector('[data-serial-row]');
            const list = line.querySelector('[data-serial-list]');
            const count = line.querySelector('[data-serial-count]');
            const qty = line.querySelector('[data-qty]');
            const track = !!opt && opt.value !== '' && opt.dataset.serial === '1';
            const keep = chosenSerials(line);
            const token = String(Date.now() + Math.random());
            line.dataset.token = token;

            qty.readOnly = track;
            row.hidden = !track && !row.querySelector('.form-error');
            avail.textContent = '';
            avail.classList.remove('is-warn');
            if (!opt || !opt.value || !fromSel.value) {
                list.replaceChildren();
                count.textContent = track ? 'Choose the source location to list its serial numbers.' : '';
                return;
            }
            avail.textContent = 'Checking stock…';
            let data;
            try {
                data = await stockAt(opt.value, fromSel.value);
            } catch (err) {
                if (line.dataset.token !== token) return;
                avail.textContent = err.message || 'Could not load the stock.';
                avail.classList.add('is-warn');
                return;
            }
            if (line.dataset.token !== token) return;
            avail.textContent = `${data.qty.toLocaleString('en-US')} ${opt.dataset.unit || ''} at ${fromLabel()}`.replace(/\s+/g, ' ');
            line.dataset.available = String(data.qty);
            checkQty(line);

            if (!track) return;
            const box = line.querySelector('[data-serial-box]');
            const nodes = data.serials.map((s) => {
                const label = serialTpl.content.firstElementChild.cloneNode(true);
                const input = label.querySelector('input');
                input.name = box.dataset.name;
                input.value = String(s.id);
                input.checked = keep.has(String(s.id));
                label.querySelector('span').textContent = s.serial_no;
                return label;
            });
            list.replaceChildren(...nodes);
            box.dataset.selected = '';
            if (!nodes.length) {
                count.textContent = `No serial numbers in stock at ${fromLabel()}.`;
                count.classList.add('is-warn');
                qty.value = '';
            } else {
                syncSerialCount(line);
            }
            updateTotals();
        }

        function checkQty(line) {
            const avail = line.querySelector('[data-avail]');
            const have = parseInt(line.dataset.available, 10);
            const qty = parseInt(line.querySelector('[data-qty]').value, 10);
            avail.classList.toggle('is-warn', Number.isInteger(have) && Number.isInteger(qty) && qty > have);
        }

        function updateTotals() {
            let qtySum = 0;
            let used = 0;
            lines().forEach((line, i) => {
                line.querySelector('.rr-line__n').textContent = String(i + 1);
                const qty = parseInt(line.querySelector('[data-qty]').value, 10);
                if (line.querySelector('[data-product]').value !== '' || Number.isInteger(qty)) used += 1;
                if (Number.isInteger(qty) && qty > 0) qtySum += qty;
            });
            document.getElementById('sumLines').textContent = String(used);
            document.getElementById('sumQty').textContent = qtySum.toLocaleString('en-US');
            document.getElementById('addLine').disabled = lines().length >= maxLines;
        }

        function addLine() {
            if (lines().length >= maxLines) {
                BB.toast(`A stock document can have at most ${maxLines} lines.`, 'warning');
                return null;
            }
            const line = lineTpl.content.firstElementChild.cloneNode(true);
            const index = String(nextIndex++);
            line.dataset.index = index;
            line.querySelectorAll('[name]').forEach((el) => {
                el.setAttribute('name', el.getAttribute('name').replace('__i__', index));
            });
            const box = line.querySelector('[data-serial-box]');
            box.dataset.name = box.dataset.name.replace('__i__', index);
            table.appendChild(line);
            loadLine(line);
            updateTotals();
            return line;
        }

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
            loadLine(line);
            updateTotals();
            const qty = line.querySelector('[data-qty]');
            if (!qty.readOnly) qty.focus();
        }

        document.getElementById('addLine').addEventListener('click', () => {
            const line = addLine();
            if (line) line.querySelector('[data-code-input]').focus();
        });

        table.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-remove-line]');
            if (!btn) return;
            btn.closest('tbody[data-line]').remove();
            if (!lines().length) addLine();
            updateTotals();
        });

        table.addEventListener('change', (e) => {
            const line = e.target.closest('tbody[data-line]');
            if (!line) return;
            if (e.target.matches('[data-code-input]')) {
                lookupCode(e.target);
            } else if (e.target.matches('[data-product]')) {
                line.querySelector('[data-serial-list]').replaceChildren();
                line.querySelector('[data-serial-box]').dataset.selected = '';
                line.querySelector('[data-qty]').value = '';
                loadLine(line);
            } else if (e.target.matches('[data-serial-list] input')) {
                syncSerialCount(line);
            }
            updateTotals();
        });

        table.addEventListener('input', (e) => {
            const line = e.target.closest('tbody[data-line]');
            if (!line || !e.target.matches('[data-qty]')) return;
            checkQty(line);
            updateTotals();
        });

        table.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && e.target.matches('[data-code-input]')) {
                e.preventDefault();
                lookupCode(e.target);
            }
        });

        fromSel.addEventListener('change', () => {
            syncTo();
            // Other serial numbers live at another location: start the checklists over.
            lines().forEach((line) => {
                line.querySelector('[data-serial-list]').replaceChildren();
                line.querySelector('[data-serial-box]').dataset.selected = '';
                if (selectedOption(line)?.dataset.serial === '1') line.querySelector('[data-qty]').value = '';
                loadLine(line);
            });
            updateTotals();
        });

        syncTo();
        lines().forEach(loadLine);
        updateTotals();
    }

    // =====================================================================
    // Count sheet: differences while typing
    // =====================================================================
    const countForm = document.getElementById('countForm');
    if (countForm) {
        const rows = [...countForm.querySelectorAll('tr[data-count-line]')];
        const progress = document.getElementById('countProgress');

        const setVariance = (row, counted) => {
            const cell = row.querySelector('[data-variance]');
            const system = parseInt(row.dataset.system, 10) || 0;
            cell.classList.remove('text-danger', 'text-success');
            if (counted === null) {
                cell.textContent = '—';
                return;
            }
            const v = counted - system;
            cell.textContent = v === 0 ? '0' : `${v > 0 ? '+' : '−'}${Math.abs(v).toLocaleString('en-US')}`;
            if (v !== 0) cell.classList.add(v < 0 ? 'text-danger' : 'text-success');
        };

        const syncRow = (row) => {
            const input = row.querySelector('[data-counted]');
            if (input) {
                const n = parseInt(input.value, 10);
                const ok = input.value.trim() !== '' && Number.isInteger(n) && n >= 0;
                setVariance(row, ok ? n : null);
                return ok;
            }
            const found = row.querySelectorAll('[data-found]');
            if (!found.length && !row.querySelector('[data-found-count]')) return true;
            const n = [...found].filter((b) => b.checked).length;
            const label = row.querySelector('[data-found-count]');
            if (label) label.textContent = String(n);
            setVariance(row, n);
            return true;
        };

        const syncAll = () => {
            const done = rows.filter(syncRow).length;
            if (progress) progress.textContent = `${done} of ${plural(rows.length, 'item', 'items')} counted`;
        };

        countForm.addEventListener('input', syncAll);
        countForm.addEventListener('change', syncAll);
        // Enter in a quantity box moves to the next one instead of submitting.
        countForm.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter' || !e.target.matches('[data-counted]')) return;
            e.preventDefault();
            const inputs = [...countForm.querySelectorAll('[data-counted]')];
            const next = inputs[inputs.indexOf(e.target) + 1];
            if (next) { next.focus(); next.select(); }
        });
        syncAll();
    }
})();
