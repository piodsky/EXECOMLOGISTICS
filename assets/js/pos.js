/**
 * EXECOM Logistics POS — product grid, cart, checkout, receipt printing, shortcuts.
 *
 * Money is handled in integer centavos. The server recomputes every total on
 * checkout; the numbers here are only for display.
 * DOM is built from <template>s with textContent (never innerHTML with data).
 */
(function () {
    'use strict';

    const root = document.getElementById('pos');
    if (!root || !window.BB) return;

    const $ = (id) => document.getElementById(id);
    const SVG_NS = 'http://www.w3.org/2000/svg';
    const MAX_LINES = 100;
    const MAX_QTY = 999;

    const cfg = {
        vatRate: parseFloat(root.dataset.vatRate) || 0,
        currency: root.dataset.currency || '₱',
        icons: root.dataset.icons,
        receiptUrl: root.dataset.receiptUrl,
        storageKey: `bb.pos.${root.dataset.userId}.${root.dataset.branchId}`,
    };

    const els = {
        grid: $('productGrid'),
        tabs: root.querySelectorAll('[data-category]'),
        search: $('globalSearch'),
        cardTpl: $('productCardTpl'),
        rowTpl: $('cartRowTpl'),
        cartBody: $('cartBody'),
        cartEmpty: $('cartEmpty'),
        customer: $('customerSelect'),
        payment: $('paymentSelect'),
        discount: $('discountInput'),
        tSubtotal: $('tSubtotal'),
        tDiscount: $('tDiscount'),
        tVat: $('tVat'),
        tTotal: $('tTotal'),
        saleNo: $('saleNo'),
        sumItems: $('sumItems'),
        sumValue: $('sumValue'),
        sumUpdated: $('sumUpdated'),
        payDialog: $('payDialog'),
        payForm: $('payForm'),
        payTotal: $('payTotal'),
        payMethod: $('payMethod'),
        cashFields: $('cashFields'),
        payAmount: $('payAmount'),
        quickCash: $('quickCash'),
        payChangeBox: $('payChangeBox'),
        payChangeLabel: $('payChangeLabel'),
        payChange: $('payChange'),
        payError: $('payError'),
        payConfirm: $('payConfirm'),
        doneDialog: $('doneDialog'),
        customerDialog: $('customerDialog'),
        customerForm: $('customerForm'),
        customerError: $('customerError'),
        receiptFrame: $('receiptFrame'),
        snChipTpl: $('cartSerialTpl'),
        snOptionTpl: $('serialOptionTpl'),
        serialDialog: $('serialDialog'),
        serialForm: $('serialForm'),
        serialProduct: $('serialProduct'),
        serialCode: $('serialCode'),
        serialFilter: $('serialFilter'),
        serialList: $('serialList'),
        serialState: $('serialState'),
        serialError: $('serialError'),
        serialCount: $('serialCount'),
        serialConfirm: $('serialConfirm'),
    };

    const state = {
        products: [],
        byId: new Map(),
        category: 'all',
        query: els.search ? els.search.value.trim() : '',
        visible: [],
        highlight: 0,
        cart: [],            // [{ id, qty, serials? }] serials = [{ id, serial_no }] (serial-tracked: qty = serials.length)
        picker: null,        // { product } while the serial picker is open
        lastSale: null,      // { id, sale_no } — for Print after completing
        printAfter: false,
        busy: false,
        loaded: false,
    };

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------
    const fmt = (cents) => `${cfg.currency} ${(cents / 100).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;

    /** "352.8" -> 35280; invalid -> NaN */
    const parseMoney = (text) => {
        const clean = String(text).replace(/[,\s]/g, '');
        return /^\d{1,9}(\.\d{0,2})?$/.test(clean) ? Math.round(parseFloat(clean) * 100) : NaN;
    };

    const svgIcon = (name) => {
        const svg = document.createElementNS(SVG_NS, 'svg');
        svg.setAttribute('class', 'icon');
        svg.setAttribute('aria-hidden', 'true');
        const use = document.createElementNS(SVG_NS, 'use');
        use.setAttribute('href', `${cfg.icons}#i-${name}`);
        svg.appendChild(use);
        return svg;
    };

    const isDialogOpen = () => document.querySelector('dialog[open]') !== null;
    const SERIAL_RE = /^[A-Za-z0-9][A-Za-z0-9._\/-]{0,59}$/;
    const isTrack = (p) => !!p && p.track_serial === true;
    /** Only { id, serial_no } per serial (nothing else is ever stored), unique ids. */
    const cleanSerials = (list) => {
        const seen = new Set();
        return (Array.isArray(list) ? list : [])
            .filter((s) => s && Number.isInteger(s.id) && s.id > 0
                && typeof s.serial_no === 'string' && s.serial_no !== '' && s.serial_no.length <= 60
                && !seen.has(s.id) && seen.add(s.id))
            .map((s) => ({ id: s.id, serial_no: s.serial_no }));
    };
    const qtyInCart = (id) => (state.cart.find((l) => l.id === id) || { qty: 0 }).qty;

    const discountPercent = () => {
        const v = parseFloat(els.discount.value);
        if (!Number.isFinite(v) || v < 0) return 0;
        return Math.min(100, Math.round(v * 100) / 100);
    };

    /** Mirrors Sales::complete() on the server. */
    function totals() {
        let subtotal = 0;
        let count = 0;
        for (const line of state.cart) {
            const p = state.byId.get(line.id);
            if (!p) continue;
            subtotal += p.price_cents * line.qty;
            count += line.qty;
        }
        const discount = Math.round(subtotal * discountPercent() / 100);
        const vat = Math.round((subtotal - discount) * cfg.vatRate / 100);
        return { subtotal, discount, vat, total: subtotal - discount + vat, count };
    }

    // ------------------------------------------------------------------
    // Persistence (survives an accidental refresh)
    // ------------------------------------------------------------------
    function persist() {
        try {
            localStorage.setItem(cfg.storageKey, JSON.stringify({
                cart: state.cart.map((l) => (Array.isArray(l.serials)
                    ? { id: l.id, qty: l.serials.length, serials: cleanSerials(l.serials) }
                    : { id: l.id, qty: l.qty })),
                customer: els.customer.value,
                payment: els.payment.value,
                discount: els.discount.value,
                lastSale: state.lastSale,
            }));
        } catch (e) { /* storage unavailable — ignore */ }
    }

    function restore() {
        let saved = null;
        try { saved = JSON.parse(localStorage.getItem(cfg.storageKey) || 'null'); } catch (e) { saved = null; }
        if (!saved || typeof saved !== 'object') return;

        if (Array.isArray(saved.cart)) {
            state.cart = saved.cart
                .filter((l) => l && Number.isInteger(l.id) && l.id > 0)
                .map((l) => {
                    if (Array.isArray(l.serials)) {
                        const serials = cleanSerials(l.serials).slice(0, MAX_QTY);
                        return { id: l.id, qty: serials.length, serials };
                    }
                    return { id: l.id, qty: l.qty };
                })
                .filter((l) => Number.isInteger(l.qty) && l.qty > 0 && l.qty <= MAX_QTY)
                .slice(0, MAX_LINES);
        }
        if (typeof saved.customer === 'string' && [...els.customer.options].some((o) => o.value === saved.customer)) {
            els.customer.value = saved.customer;
        }
        if (typeof saved.payment === 'string' && [...els.payment.options].some((o) => o.value === saved.payment)) {
            els.payment.value = saved.payment;
        }
        if (typeof saved.discount === 'string' && saved.discount !== '') {
            els.discount.value = saved.discount;
        }
        if (saved.lastSale && Number.isInteger(saved.lastSale.id)) {
            state.lastSale = { id: saved.lastSale.id, sale_no: String(saved.lastSale.sale_no || '') };
        }
        persist(); // rewrite the cleaned cart: only {id, qty} or {id, qty, serials: [{id, serial_no}]} is kept
    }

    // ------------------------------------------------------------------
    // Products
    // ------------------------------------------------------------------
    async function loadProducts() {
        if (root.dataset.branchId === '0') {
            // "All branches": stock is per branch, so there is nothing to sell until one is chosen
            const msg = document.createElement('p');
            msg.className = 'product-grid__state';
            msg.textContent = 'Choose a branch in the top bar to see its products and stock.';
            els.grid.replaceChildren(msg);
            return;
        }
        try {
            const data = await BB.api('pos/products.php');
            state.products = data.products;
            state.byId = new Map(data.products.map((p) => [p.id, p]));
            state.loaded = true;
            if (data.next_sale_no) els.saleNo.textContent = data.next_sale_no;
            if (typeof data.vat_rate === 'number') cfg.vatRate = data.vat_rate;

            els.sumItems.textContent = String(state.products.length);
            els.sumValue.textContent = fmt(state.products.reduce((sum, p) => sum + p.price_cents * p.stock, 0));
            els.sumUpdated.textContent = new Date().toLocaleString('en-US', {
                month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit',
            });

            clampCartToStock();
            renderGrid();
            renderCart();
        } catch (err) {
            const msg = document.createElement('p');
            msg.className = 'product-grid__state';
            msg.textContent = `Could not load products: ${err.message}`;
            els.grid.replaceChildren(msg);
        }
    }

    /** After a stock refresh, make sure the cart doesn't exceed what's available. */
    function clampCartToStock() {
        const notes = [];
        state.cart = state.cart.filter((line) => {
            const p = state.byId.get(line.id);
            if (!p) { notes.push('An item is no longer sold and was removed.'); return false; }
            if (p.stock <= 0) { notes.push(`${p.name} is out of stock and was removed.`); return false; }
            if (isTrack(p)) {
                // Serial-tracked: qty = picked serials; checkout (409) names the ones that are gone.
                line.serials = cleanSerials(line.serials);
                line.qty = line.serials.length;
                if (!line.qty) { notes.push(`${p.name}: choose its serial numbers again.`); return false; }
                return true;
            }
            if (line.serials) delete line.serials;
            if (line.qty > p.stock) { notes.push(`${p.name}: only ${p.stock} left.`); line.qty = p.stock; }
            return true;
        });
        if (notes.length) {
            BB.toast(notes.join(' '), 'warning', 6000);
            persist();
        }
    }

    function filteredProducts() {
        const q = state.query.toLowerCase();
        return state.products.filter((p) =>
            (state.category === 'all' || p.category_id === Number(state.category))
            && (!q
                || p.name.toLowerCase().includes(q)
                || p.code.toLowerCase().includes(q)
                || (p.barcode && p.barcode.includes(q))));
    }

    function renderGrid() {
        if (!state.loaded) return;
        state.visible = filteredProducts();
        if (state.highlight >= state.visible.length) state.highlight = 0;

        const frag = document.createDocumentFragment();
        state.visible.forEach((p, i) => frag.appendChild(buildCard(p, i)));
        if (!state.visible.length) {
            const msg = document.createElement('p');
            msg.className = 'product-grid__state';
            msg.textContent = state.products.length
                ? (state.query ? `No products match “${state.query}”.` : 'No products in this category.')
                : 'No products yet. Add some in Inventory.';
            frag.appendChild(msg);
        }
        els.grid.replaceChildren(frag);
        syncHighlightVisibility();
    }

    function buildCard(p, index) {
        const card = els.cardTpl.content.firstElementChild.cloneNode(true);
        card.dataset.id = String(p.id);

        const media = card.querySelector('.product-card__media');
        if (p.image_url) {
            const img = document.createElement('img');
            img.src = p.image_url;
            img.alt = '';
            img.loading = 'lazy';
            media.appendChild(img);
        } else {
            media.classList.add('is-placeholder');
            media.appendChild(svgIcon(p.category_icon || 'box'));
        }

        card.querySelector('.product-card__name').textContent = p.name;
        card.querySelector('.product-card__code').textContent = p.code;
        card.querySelector('.product-card__price').textContent = fmt(p.price_cents);

        const pill = card.querySelector('.stock-pill');
        if (p.stock <= 0) {
            pill.textContent = 'Out of stock';
            pill.classList.add('is-out');
        } else {
            pill.textContent = `In Stock: ${p.stock}`;
            if (p.stock <= p.reorder_level) pill.classList.add('is-low');
        }

        const inCart = qtyInCart(p.id);
        if (inCart > 0) {
            const badge = card.querySelector('.product-card__incart');
            badge.textContent = `${inCart} in cart`;
            badge.hidden = false;
        }
        if (p.stock - inCart <= 0) {
            card.classList.add('is-disabled');
            card.setAttribute('aria-disabled', 'true');
        }
        if (index === state.highlight) card.classList.add('is-highlight');

        card.setAttribute('aria-label', `${p.name}, ${fmt(p.price_cents)}, ${p.stock} in stock`);
        return card;
    }

    /** Show the F4 highlight only while searching. */
    function syncHighlightVisibility() {
        const active = state.query !== '' || document.activeElement === els.search;
        els.grid.classList.toggle('show-highlight', active);
    }

    function moveHighlight(step) {
        if (!state.visible.length) return;
        state.highlight = (state.highlight + step + state.visible.length) % state.visible.length;
        els.grid.querySelectorAll('.product-card').forEach((card, i) => {
            card.classList.toggle('is-highlight', i === state.highlight);
            if (i === state.highlight) card.scrollIntoView({ block: 'nearest' });
        });
    }

    function setCategory(category) {
        state.category = category;
        state.highlight = 0;
        els.tabs.forEach((tab) => {
            const active = tab.dataset.category === category;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', String(active));
        });
        renderGrid();
    }

    // ------------------------------------------------------------------
    // Cart
    // ------------------------------------------------------------------
    function addToCart(id, qty = 1) {
        const p = state.byId.get(id);
        if (!p) return false;
        if (isTrack(p)) return openSerialPicker(p);

        const line = state.cart.find((l) => l.id === id);
        const current = line ? line.qty : 0;

        if (p.stock <= 0) {
            BB.toast(`${p.name} is out of stock.`, 'error');
            return false;
        }
        if (current + qty > p.stock) {
            BB.toast(`Only ${p.stock} ${p.name} in stock.`, 'warning');
            return false;
        }
        if (!line && state.cart.length >= MAX_LINES) {
            BB.toast(`A sale can have at most ${MAX_LINES} different items.`, 'warning');
            return false;
        }

        if (line) {
            line.qty = Math.min(MAX_QTY, current + qty);
        } else {
            state.cart.push({ id, qty });
        }
        cartChanged(id);
        return true;
    }

    function setQty(id, qty) {
        const p = state.byId.get(id);
        const line = state.cart.find((l) => l.id === id);
        if (!p || !line) return;

        if (isTrack(p)) { cartChanged(id); return; } // qty = serials picked
        let next = Number.isInteger(qty) ? qty : line.qty;
        if (next < 1) next = 1;
        if (next > p.stock) {
            BB.toast(`Only ${p.stock} ${p.name} in stock.`, 'warning');
            next = p.stock;
        }
        line.qty = Math.min(MAX_QTY, next);
        cartChanged(id);
    }

    function removeFromCart(id) {
        state.cart = state.cart.filter((l) => l.id !== id);
        cartChanged();
    }

    function cartChanged(flashId) {
        renderCart(flashId);
        renderGrid();
        persist();
    }

    function renderCart(flashId) {
        const frag = document.createDocumentFragment();
        state.cart.forEach((line, i) => {
            const p = state.byId.get(line.id);
            if (!p) return;
            const row = els.rowTpl.content.firstElementChild.cloneNode(true);
            row.dataset.id = String(p.id);
            row.querySelector('.c-n').textContent = String(i + 1);
            row.querySelector('.cart-row__name strong').textContent = p.name;
            row.querySelector('.cart-row__name small').textContent = p.code;
            const input = row.querySelector('input');
            input.value = String(line.qty);
            input.max = String(p.stock);
            const track = isTrack(p);
            row.querySelector('[data-act="dec"]').disabled = track || line.qty <= 1;
            row.querySelector('[data-act="inc"]').disabled = track || line.qty >= p.stock;
            if (track) {
                input.disabled = true;
                input.title = 'Quantity = serial numbers picked';
                row.classList.add('is-serial');
                renderSerialChips(row.querySelector('.cart-sn'), line);
            }
            row.querySelector('.cart-row__price').textContent = fmt(p.price_cents);
            row.querySelector('.cart-row__total').textContent = fmt(p.price_cents * line.qty);
            if (line.id === flashId) row.classList.add('is-flash');
            frag.appendChild(row);
        });
        els.cartBody.replaceChildren(frag);
        els.cartEmpty.hidden = state.cart.length > 0;

        if (flashId) {
            const row = els.cartBody.querySelector('.is-flash');
            if (row) row.scrollIntoView({ block: 'nearest' });
        }
        renderTotals();
    }

    function renderTotals() {
        const t = totals();
        els.tSubtotal.textContent = fmt(t.subtotal);
        els.tDiscount.textContent = t.discount > 0 ? `(− ${fmt(t.discount)})` : '';
        els.tVat.textContent = fmt(t.vat);
        els.tTotal.textContent = fmt(t.total);
    }

    function resetSale() {
        state.cart = [];
        els.discount.value = '0';
        els.customer.value = '';
        els.payment.value = 'cash';
        cartChanged();
    }

    // ------------------------------------------------------------------
    // Serial-tracked items: picker dialog, scanned serials, chips in the cart
    // ------------------------------------------------------------------
    /** Replace the serials of a line (qty follows); none = remove the line. */
    function setLineSerials(id, serials) {
        const line = state.cart.find((l) => l.id === id);
        const list = cleanSerials(serials).slice(0, MAX_QTY);
        if (!list.length) {
            if (line) removeFromCart(id);
            return;
        }
        if (line) {
            line.serials = list;
            line.qty = list.length;
        } else {
            state.cart.push({ id, qty: list.length, serials: list });
        }
        cartChanged(id);
    }

    function removeSerial(id, serialId) {
        const line = state.cart.find((l) => l.id === id);
        if (!line || !Array.isArray(line.serials)) return;
        setLineSerials(id, line.serials.filter((s) => s.id !== serialId));
    }

    function renderSerialChips(list, line) {
        const frag = document.createDocumentFragment();
        cleanSerials(line.serials).forEach((s) => {
            const chip = els.snChipTpl.content.firstElementChild.cloneNode(true);
            chip.dataset.serialId = String(s.id);
            chip.querySelector('.cart-sn__no').textContent = s.serial_no;
            chip.querySelector('button').setAttribute('aria-label', `Remove serial ${s.serial_no}`);
            frag.appendChild(chip);
        });
        list.replaceChildren(frag);
        list.hidden = false;
    }

    /** Exact serial scan at this branch: { product_id, serial_id, serial_no } or null. */
    async function lookupSerial(q) {
        try {
            const data = await BB.api(`pos/serials.php?serial=${encodeURIComponent(q)}`);
            return data.match || null;
        } catch (err) {
            return null; // not a serial here: fall back to the highlighted card
        }
    }

    function addSerialToCart(match) {
        const p = state.byId.get(match.product_id);
        if (!p) {
            BB.toast(`Serial ${match.serial_no} belongs to an item that is not on sale.`, 'error');
            return false;
        }
        const line = state.cart.find((l) => l.id === p.id);
        const current = line && Array.isArray(line.serials) ? line.serials : [];
        if (current.some((s) => s.id === match.serial_id)) {
            BB.toast(`Serial ${match.serial_no} is already in the cart.`, 'warning');
            return true;
        }
        if (!line && state.cart.length >= MAX_LINES) {
            BB.toast(`A sale can have at most ${MAX_LINES} different items.`, 'warning');
            return false;
        }
        setLineSerials(p.id, [...current, { id: match.serial_id, serial_no: match.serial_no }]);
        return true;
    }

    /** After a 409 at checkout: drop every serial the server says is gone. */
    function dropSoldSerials(problems) {
        if (!Array.isArray(problems)) return;
        const gone = new Set(problems.map((pr) => pr && pr.serial_id).filter(Number.isInteger));
        if (!gone.size) return;
        let dropped = 0;
        state.cart = state.cart.filter((line) => {
            if (!Array.isArray(line.serials)) return true;
            const keep = line.serials.filter((s) => !gone.has(s.id));
            dropped += line.serials.length - keep.length;
            line.serials = keep;
            line.qty = keep.length;
            return keep.length > 0;
        });
        if (dropped) {
            cartChanged();
            BB.toast(`${dropped} serial ${dropped === 1 ? 'number is' : 'numbers are'} no longer available and ${dropped === 1 ? 'was' : 'were'} removed from the sale.`, 'warning', 6000);
        }
    }

    /** Open the picker for a serial-tracked product (sync result: was it opened?). */
    function openSerialPicker(p) {
        if (p.stock <= 0) {
            BB.toast(`${p.name} is out of stock.`, 'error');
            return false;
        }
        const line = state.cart.find((l) => l.id === p.id);
        if (!line && state.cart.length >= MAX_LINES) {
            BB.toast(`A sale can have at most ${MAX_LINES} different items.`, 'warning');
            return false;
        }
        const picker = {
            product: p,
            serials: [],
            chosen: new Set(line && Array.isArray(line.serials) ? line.serials.map((s) => s.id) : []),
        };
        state.picker = picker;
        els.serialProduct.textContent = p.name;
        els.serialCode.textContent = p.code;
        els.serialFilter.value = '';
        els.serialList.replaceChildren();
        els.serialError.hidden = true;
        els.serialState.textContent = 'Loading serial numbers…';
        els.serialConfirm.disabled = true;
        updatePickerCount();
        if (!els.serialDialog.open) els.serialDialog.showModal();
        els.serialFilter.focus();
        loadPickerSerials(picker);
        return true;
    }

    async function loadPickerSerials(picker) {
        try {
            const data = await BB.api(`pos/serials.php?product_id=${encodeURIComponent(picker.product.id)}`);
            if (state.picker !== picker) return;
            picker.serials = cleanSerials(data.serials);
            renderPickerList();
        } catch (err) {
            if (state.picker !== picker) return;
            els.serialState.textContent = '';
            els.serialError.textContent = err.message;
            els.serialError.hidden = false;
        }
    }

    function renderPickerList() {
        const picker = state.picker;
        const available = new Set(picker.serials.map((s) => s.id));
        const missing = [...picker.chosen].filter((id) => !available.has(id));
        missing.forEach((id) => picker.chosen.delete(id));

        const frag = document.createDocumentFragment();
        picker.serials.forEach((s) => {
            const item = els.snOptionTpl.content.firstElementChild.cloneNode(true);
            item.dataset.serialNo = s.serial_no.toLowerCase();
            const box = item.querySelector('input');
            box.value = String(s.id);
            box.checked = picker.chosen.has(s.id);
            item.querySelector('.sn-option__no').textContent = s.serial_no;
            frag.appendChild(item);
        });
        els.serialList.replaceChildren(frag);
        els.serialState.textContent = picker.serials.length
            ? (missing.length ? `${missing.length} serial ${missing.length === 1 ? 'number' : 'numbers'} in the cart ${missing.length === 1 ? 'is' : 'are'} no longer in stock and will be removed.` : '')
            : 'No serial numbers of this item are in stock at this branch.';
        els.serialConfirm.disabled = false;
        updatePickerCount();
    }

    function updatePickerCount() {
        const n = state.picker ? state.picker.chosen.size : 0;
        els.serialCount.textContent = `${n} selected`;
    }

    function filterPicker() {
        const f = els.serialFilter.value.trim().toLowerCase();
        els.serialList.querySelectorAll('li').forEach((item) => {
            item.hidden = f !== '' && !item.dataset.serialNo.includes(f);
        });
    }

    /** Enter in the filter box (scanner): tick the exact serial. */
    function pickFilteredSerial() {
        const picker = state.picker;
        const f = els.serialFilter.value.trim();
        if (!picker || !f) return;
        const s = picker.serials.find((x) => x.serial_no.toLowerCase() === f.toLowerCase());
        if (!s) {
            els.serialError.textContent = `Serial ${f} is not in stock for this item.`;
            els.serialError.hidden = false;
            els.serialFilter.select();
            return;
        }
        if (!picker.chosen.has(s.id) && picker.chosen.size >= MAX_QTY) return;
        picker.chosen.add(s.id);
        els.serialError.hidden = true;
        els.serialFilter.value = '';
        const box = els.serialList.querySelector(`input[value="${s.id}"]`);
        if (box) box.checked = true;
        filterPicker();
        updatePickerCount();
    }

    function submitSerials(e) {
        e.preventDefault();
        const picker = state.picker;
        if (!picker || els.serialConfirm.disabled) return;
        const line = state.cart.find((l) => l.id === picker.product.id);
        const before = line && Array.isArray(line.serials) ? line.serials.map((s) => s.id) : [];
        // Keep the cart order: serials already in the cart first, then the new ones in list order.
        const byId = new Map(picker.serials.map((s) => [s.id, s]));
        const ids = [...before.filter((id) => picker.chosen.has(id)),
            ...picker.serials.map((s) => s.id).filter((id) => picker.chosen.has(id) && !before.includes(id))];
        setLineSerials(picker.product.id, ids.map((id) => byId.get(id)).filter(Boolean));
        els.serialDialog.close();
    }

    // ------------------------------------------------------------------
    // Search / barcode
    // ------------------------------------------------------------------
    function focusSearch(mode) {
        if (!els.search) return;
        els.search.placeholder = mode === 'scan'
            ? 'Scan barcode now…'
            : 'Search for product, barcode or item name...';
        els.search.focus();
        els.search.select();
    }

    function clearSearch() {
        els.search.value = '';
        state.query = '';
        state.highlight = 0;
        renderGrid();
    }

    /**
     * Enter in the search box: exact barcode/code match first, then (only when serial-tracked items exist)
     * an exact serial scan at this branch, else the highlighted card.
     */
    async function handleSearchEnter() {
        const q = els.search.value.trim();
        if (!q) return;

        const lower = q.toLowerCase();
        const exact = state.products.find((p) => p.barcode === q || p.code.toLowerCase() === lower);
        if (!exact && SERIAL_RE.test(q) && state.products.some(isTrack)) {
            const match = await lookupSerial(q);
            if (els.search.value.trim() !== q) return; // the cashier typed on while we waited
            if (match) {
                if (addSerialToCart(match)) clearSearch(); else els.search.select();
                return;
            }
        }
        const target = exact || state.visible[state.highlight];

        if (!target) {
            BB.toast(`No product found for “${q}”.`, 'error');
            els.search.select();
            return;
        }
        if (addToCart(target.id)) {
            clearSearch();
        } else {
            els.search.select();
        }
    }

    function addHighlighted() {
        const p = state.visible[state.highlight];
        if (!p) {
            BB.toast('No product selected. Press F3 to search first.', 'info');
            return;
        }
        addToCart(p.id);
    }

    // ------------------------------------------------------------------
    // Payment
    // ------------------------------------------------------------------
    function openPayment(printAfter) {
        if (state.busy) return;
        if (!state.cart.length) {
            BB.toast('Add at least one item to the sale first.', 'warning');
            focusSearch();
            return;
        }
        state.printAfter = printAfter;

        const t = totals();
        const isCash = els.payment.value === 'cash';
        els.payTotal.textContent = fmt(t.total);
        els.payMethod.textContent = els.payment.options[els.payment.selectedIndex].text;
        els.cashFields.hidden = !isCash;
        els.payAmount.value = '';
        els.payError.hidden = true;
        els.payConfirm.textContent = printAfter ? 'Confirm & Print' : 'Confirm Payment';
        buildQuickCash(t.total);
        updateChange();

        els.payDialog.showModal();
        (isCash ? els.payAmount : els.payConfirm).focus();
    }

    /** Exact amount + the total rounded up to the next ₱100 / ₱500 / ₱1,000 / ₱5,000. */
    function buildQuickCash(totalCents) {
        const pesos = totalCents / 100;
        const options = new Set([totalCents]);
        [100, 500, 1000, 5000]
            .map((step) => Math.ceil(pesos / step) * step * 100)
            .filter((v) => v > totalCents)
            .forEach((v) => options.add(v));

        const frag = document.createDocumentFragment();
        [...options].sort((a, b) => a - b).slice(0, 5).forEach((cents, i) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn--light';
            btn.dataset.amount = String(cents);
            btn.textContent = i === 0 ? 'Exact' : fmt(cents);
            frag.appendChild(btn);
        });
        els.quickCash.replaceChildren(frag);
    }

    function updateChange() {
        const total = totals().total;
        const paid = parseMoney(els.payAmount.value);
        const short = Number.isFinite(paid) && paid < total;
        els.payChangeBox.classList.toggle('is-short', short);
        els.payChangeLabel.textContent = short ? 'Short by' : 'Change';
        els.payChange.textContent = !Number.isFinite(paid) ? fmt(0) : fmt(Math.abs(paid - total));
    }

    async function submitPayment(e) {
        e.preventDefault();
        if (state.busy) return;

        const t = totals();
        const isCash = els.payment.value === 'cash';
        const paid = isCash ? parseMoney(els.payAmount.value) : t.total;

        if (isCash && (!Number.isFinite(paid) || paid < t.total)) {
            showPayError(!Number.isFinite(paid)
                ? 'Enter the amount received from the customer.'
                : `Amount received is less than the total (${fmt(t.total)}).`);
            els.payAmount.focus();
            return;
        }

        setBusy(true);
        try {
            const data = await BB.api('pos/checkout.php', {
                method: 'POST',
                body: {
                    items: state.cart.map((l) => (Array.isArray(l.serials)
                        ? { product_id: l.id, qty: l.serials.length, serial_ids: l.serials.map((s) => s.id) }
                        : { product_id: l.id, qty: l.qty })),
                    customer_id: els.customer.value ? Number(els.customer.value) : null,
                    payment_type: els.payment.value,
                    discount_percent: String(discountPercent()),
                    amount_paid: isCash ? (paid / 100).toFixed(2) : null,
                },
            });
            els.payDialog.close();
            saleCompleted(data);
        } catch (err) {
            showPayError(err.message);
            if (err.status === 409) {
                dropSoldSerials(err.data && err.data.problems);
                loadProducts(); // stock changed — refresh and clamp the cart
            }
        } finally {
            setBusy(false);
        }
    }

    function showPayError(message) {
        els.payError.textContent = message;
        els.payError.hidden = false;
    }

    function setBusy(busy) {
        state.busy = busy;
        els.payConfirm.disabled = busy;
        els.payConfirm.textContent = busy
            ? 'Processing…'
            : (state.printAfter ? 'Confirm & Print' : 'Confirm Payment');
    }

    function saleCompleted(data) {
        const sale = data.sale;
        state.lastSale = { id: sale.id, sale_no: sale.sale_no };
        resetSale();
        if (data.next_sale_no) els.saleNo.textContent = data.next_sale_no;
        loadProducts();

        $('doneNo').textContent = sale.sale_no;
        $('doneTotal').textContent = fmt(sale.total_cents);
        $('donePaid').textContent = fmt(sale.paid_cents);
        $('doneChange').textContent = fmt(sale.change_cents);
        els.doneDialog.showModal();

        if (state.printAfter) printReceipt(sale.id);
    }

    // ------------------------------------------------------------------
    // Receipt printing (hidden iframe; the receipt page calls print())
    // ------------------------------------------------------------------
    function printReceipt(saleId) {
        els.receiptFrame.src = `${cfg.receiptUrl}?id=${encodeURIComponent(saleId)}&autoprint=1&t=${Date.now()}`;
    }

    // ------------------------------------------------------------------
    // Quick add customer
    // ------------------------------------------------------------------
    async function submitCustomer(e) {
        e.preventDefault();
        const form = els.customerForm;
        const submit = form.querySelector('[type="submit"]');
        els.customerError.hidden = true;
        submit.disabled = true;
        try {
            const data = await BB.api('customers/create.php', {
                method: 'POST',
                body: {
                    name: form.elements.name.value,
                    phone: form.elements.phone.value,
                    email: form.elements.email.value,
                },
            });
            const option = new Option(data.customer.name, String(data.customer.id));
            els.customer.add(option);
            els.customer.value = option.value;
            persist();
            els.customerDialog.close();
            BB.toast(data.message, 'success');
        } catch (err) {
            els.customerError.textContent = err.message;
            els.customerError.hidden = false;
        } finally {
            submit.disabled = false;
        }
    }

    // ------------------------------------------------------------------
    // Events
    // ------------------------------------------------------------------
    els.tabs.forEach((tab) => tab.addEventListener('click', () => setCategory(tab.dataset.category)));

    els.grid.addEventListener('click', (e) => {
        const card = e.target.closest('.product-card');
        if (!card) return;
        const id = Number(card.dataset.id);
        if (card.classList.contains('is-disabled')) {
            const p = state.byId.get(id);
            if (p) BB.toast(p.stock <= 0 ? `${p.name} is out of stock.` : `All ${p.stock} ${p.name} are already in the cart.`, 'warning');
            return;
        }
        addToCart(id);
    });

    els.cartBody.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-act]');
        if (!btn) return;
        const id = Number(btn.closest('tr').dataset.id);
        const line = state.cart.find((l) => l.id === id);
        if (!line) return;
        if (btn.dataset.act === 'inc') setQty(id, line.qty + 1);
        if (btn.dataset.act === 'dec') setQty(id, line.qty - 1);
        if (btn.dataset.act === 'remove') removeFromCart(id);
        if (btn.dataset.act === 'sn-remove') removeSerial(id, Number(btn.closest('li').dataset.serialId));
    });

    els.cartBody.addEventListener('change', (e) => {
        if (e.target.tagName !== 'INPUT') return;
        setQty(Number(e.target.closest('tr').dataset.id), parseInt(e.target.value, 10));
    });

    els.discount.addEventListener('input', () => { renderTotals(); persist(); });
    els.discount.addEventListener('change', () => {
        els.discount.value = String(discountPercent());
        renderTotals();
        persist();
    });
    els.customer.addEventListener('change', persist);
    els.payment.addEventListener('change', persist);

    if (els.search) {
        els.search.form.addEventListener('submit', (e) => e.preventDefault());
        els.search.addEventListener('input', () => {
            state.query = els.search.value.trim();
            state.highlight = 0;
            renderGrid();
        });
        els.search.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); handleSearchEnter(); }
            if (e.key === 'ArrowDown') { e.preventDefault(); moveHighlight(1); }
            if (e.key === 'ArrowUp') { e.preventDefault(); moveHighlight(-1); }
            if (e.key === 'Escape' && els.search.value) { e.stopPropagation(); clearSearch(); }
        });
        els.search.addEventListener('focus', syncHighlightVisibility);
        els.search.addEventListener('blur', () => {
            els.search.placeholder = 'Search for product, barcode or item name...';
            syncHighlightVisibility();
        });
    }

    // Buttons
    $('btnNew').addEventListener('click', () => {
        if (state.cart.length && !window.confirm('Start a new sale? Items in the current sale will be cleared.')) return;
        resetSale();
        focusSearch();
    });
    $('btnSave').addEventListener('click', () => openPayment(false));
    $('btnPrint').addEventListener('click', () => {
        if (state.cart.length) {
            openPayment(true);
        } else if (state.lastSale) {
            printReceipt(state.lastSale.id);
        } else {
            BB.toast('Nothing to print yet. Complete a sale first.', 'info');
        }
    });
    $('btnCancel').addEventListener('click', () => {
        if (!state.cart.length) {
            BB.toast('There is no sale in progress.', 'info');
            return;
        }
        if (!window.confirm('Cancel this sale? All items will be removed.')) return;
        resetSale();
        BB.toast('Sale cancelled.', 'info');
        focusSearch();
    });

    // Payment dialog
    els.payForm.addEventListener('submit', submitPayment);
    els.payAmount.addEventListener('input', () => { els.payError.hidden = true; updateChange(); });
    els.quickCash.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-amount]');
        if (!btn) return;
        els.payAmount.value = (Number(btn.dataset.amount) / 100).toFixed(2);
        els.payError.hidden = true;
        updateChange();
        els.payConfirm.focus();
    });

    // Done dialog
    $('donePrint').addEventListener('click', () => { if (state.lastSale) printReceipt(state.lastSale.id); });
    $('doneNew').addEventListener('click', () => els.doneDialog.close());
    els.doneDialog.addEventListener('close', () => focusSearch());

    // Serial picker dialog
    els.serialForm.addEventListener('submit', submitSerials);
    els.serialFilter.addEventListener('input', () => { els.serialError.hidden = true; filterPicker(); });
    els.serialFilter.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); pickFilteredSerial(); }
    });
    els.serialList.addEventListener('change', (e) => {
        const picker = state.picker;
        if (!picker || e.target.type !== 'checkbox') return;
        const id = Number(e.target.value);
        if (e.target.checked) {
            if (picker.chosen.size >= MAX_QTY) { e.target.checked = false; return; }
            picker.chosen.add(id);
        } else {
            picker.chosen.delete(id);
        }
        updatePickerCount();
    });
    els.serialDialog.addEventListener('close', () => {
        state.picker = null;
        focusSearch();
    });

    // Customer dialog
    $('addCustomerBtn')?.addEventListener('click', () => {
        els.customerForm.reset();
        els.customerError.hidden = true;
        els.customerDialog.showModal();
        els.customerForm.elements.name.focus();
    });
    els.customerForm.addEventListener('submit', submitCustomer);

    // Shortcuts: F2 scan, F3 search, F4 add highlighted item
    document.addEventListener('bb:shortcut', (e) => {
        e.preventDefault(); // POS handles these itself
        if (isDialogOpen()) return;
        if (e.detail.key === 'F2') focusSearch('scan');
        if (e.detail.key === 'F3') focusSearch('search');
        if (e.detail.key === 'F4') addHighlighted();
    });

    // A barcode scanner "types" into whatever has focus: send stray typing to the search box.
    document.addEventListener('keydown', (e) => {
        if (!els.search || e.defaultPrevented || e.ctrlKey || e.metaKey || e.altKey || e.key.length !== 1) return;
        if (isDialogOpen() || e.target.closest('input, select, textarea, [contenteditable="true"]')) return;
        els.search.focus();
    });

    // Sidebar "Scan Barcode" card
    document.querySelectorAll('[data-scan-trigger]').forEach((el) => {
        el.addEventListener('click', (e) => { e.preventDefault(); focusSearch('scan'); });
    });

    // ------------------------------------------------------------------
    // Start
    // ------------------------------------------------------------------
    restore();
    renderCart();
    loadProducts();
})();
