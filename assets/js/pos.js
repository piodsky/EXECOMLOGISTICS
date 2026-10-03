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
    };

    const state = {
        products: [],
        byId: new Map(),
        category: 'all',
        query: els.search ? els.search.value.trim() : '',
        visible: [],
        highlight: 0,
        cart: [],            // [{ id, qty }]
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
                cart: state.cart,
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
                .filter((l) => l && Number.isInteger(l.id) && Number.isInteger(l.qty) && l.qty > 0)
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
            row.querySelector('[data-act="dec"]').disabled = line.qty <= 1;
            row.querySelector('[data-act="inc"]').disabled = line.qty >= p.stock;
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

    /** Enter in the search box: exact barcode/code match first, else the highlighted card. */
    function handleSearchEnter() {
        const q = els.search.value.trim();
        if (!q) return;

        const lower = q.toLowerCase();
        const exact = state.products.find((p) => p.barcode === q || p.code.toLowerCase() === lower);
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
                    items: state.cart.map((l) => ({ product_id: l.id, qty: l.qty })),
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
            if (err.status === 409) loadProducts(); // stock changed — refresh and clamp the cart
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
