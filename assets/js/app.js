/**
 * EXECOM Logistics POS — global scripts (every page).
 * Exposes window.BB for page scripts: BB.api(), BB.baseUrl, BB.csrf.
 */
(function () {
    'use strict';

    const body = document.body;
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');

    const BB = {
        baseUrl: body.dataset.baseUrl || '',
        csrf: csrfMeta ? csrfMeta.content : '',

        /**
         * JSON call to /api/<path>. Sends the CSRF header on non-GET requests.
         *   const data = await BB.api('cart/add.php', { method: 'POST', body: { id: 1 } });
         * Throws an Error with .status and .data when the server returns ok:false.
         */
        async api(path, { method = 'GET', body: payload } = {}) {
            const headers = { Accept: 'application/json' };
            if (method !== 'GET') {
                headers['Content-Type'] = 'application/json';
                headers['X-CSRF-Token'] = BB.csrf;
            }
            const res = await fetch(`${BB.baseUrl}/api/${path}`, {
                method,
                headers,
                credentials: 'same-origin',
                body: payload !== undefined ? JSON.stringify(payload) : undefined,
            });
            const data = await res.json().catch(() => ({ ok: false, message: 'Unexpected server response.' }));

            if (res.status === 401) {
                window.location.href = `${BB.baseUrl}/login.php`;
            }
            if (!res.ok || data.ok === false) {
                const err = new Error(data.message || 'Request failed.');
                err.status = res.status;
                err.data = data;
                throw err;
            }
            return data;
        },
    };

    /** Small notification in the bottom-right corner. type: info|success|warning|error */
    BB.toast = function (message, type = 'info', timeout = 3500) {
        let box = document.querySelector('.toasts');
        if (!box) {
            box = document.createElement('div');
            box.className = 'toasts';
            box.setAttribute('role', 'status');
            box.setAttribute('aria-live', 'polite');
            document.body.appendChild(box);
        }
        const toast = document.createElement('div');
        toast.className = `toast toast--${type}`;
        toast.textContent = message;
        box.appendChild(toast);
        setTimeout(() => {
            toast.classList.add('is-leaving');
            setTimeout(() => toast.remove(), 250);
        }, timeout);
    };

    window.BB = BB;

    // ---- Clear a field's error as soon as the user edits it ------------
    document.addEventListener('input', (e) => {
        const field = e.target;
        if (field.getAttribute && field.getAttribute('aria-invalid') === 'true') {
            field.removeAttribute('aria-invalid');
            const msg = document.getElementById(field.getAttribute('aria-describedby') || '');
            if (msg) msg.hidden = true;
        }
    });

    // ---- Forms with data-confirm="…" ask before submitting (e.g. Delete) ----
    document.addEventListener('submit', (e) => {
        const msg = e.target.dataset ? e.target.dataset.confirm : undefined;
        if (msg && !window.confirm(msg)) e.preventDefault();
    });

    // ---- Dialogs: [data-close] closes its <dialog>; [data-open="id"] opens one ----
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-close]');
        if (btn) btn.closest('dialog')?.close();

        const opener = e.target.closest('[data-open]');
        const dialog = opener && document.getElementById(opener.dataset.open);
        if (dialog instanceof HTMLDialogElement && !dialog.open) {
            dialog.showModal();
            dialog.querySelector('textarea, input:not([type=hidden]), select')?.focus();
        }
    });

    // ---- Dependent selects: <select data-filter-by="parentId"> shows only the options whose
    //      data-parent matches the parent's value (e.g. product Model filtered by Brand) ----
    document.querySelectorAll('select[data-filter-by]').forEach((child) => {
        const parent = document.getElementById(child.dataset.filterBy);
        if (!(parent instanceof HTMLSelectElement)) return;
        const sync = () => {
            const value = parent.value;
            child.querySelectorAll('option[data-parent]').forEach((opt) => {
                const show = value !== '' && opt.dataset.parent === value;
                opt.hidden = !show;
                opt.disabled = !show;
            });
            if (child.selectedOptions[0]?.disabled) child.value = '';
            if (!child.hasAttribute('data-locked')) child.disabled = value === '';
        };
        parent.addEventListener('change', sync);
        sync();
    });

    // ---- Live clock in the header --------------------------------------
    const dateEl = document.querySelector('[data-clock-date]');
    const timeEl = document.querySelector('[data-clock-time]');
    if (dateEl && timeEl) {
        const tick = () => {
            const now = new Date();
            dateEl.textContent = now.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            timeEl.textContent = now.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', second: '2-digit' });
        };
        tick();
        setInterval(tick, 1000);
    }

    // ---- Sidebar toggle (tablet / mobile) -------------------------------
    const toggles = document.querySelectorAll('[data-sidebar-toggle]');
    const backdrop = document.querySelector('.sidebar-backdrop');
    const menuBtn = document.querySelector('.topbar__toggle');
    const setSidebar = (open) => {
        body.classList.toggle('sidebar-open', open);
        if (backdrop) backdrop.hidden = !open;
        if (menuBtn) menuBtn.setAttribute('aria-expanded', String(open));
    };
    toggles.forEach((el) => el.addEventListener('click', () => setSidebar(!body.classList.contains('sidebar-open'))));

    // ---- User dropdown: close on outside click / Escape -----------------
    const userMenu = document.querySelector('.user-menu');
    if (userMenu) {
        document.addEventListener('click', (e) => {
            if (userMenu.open && !userMenu.contains(e.target)) userMenu.open = false;
        });
    }

    // ---- Branch switcher (topbar): submit as soon as a branch is picked --
    document.querySelectorAll('[data-branch-switch] select').forEach((select) => {
        select.addEventListener('change', () => select.form.requestSubmit());
    });
    // "Choose branch" buttons (e.g. the POS notice) open the topbar switcher
    document.querySelectorAll('[data-focus-branch]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const select = document.getElementById('branchSelect');
            if (!select) return;
            select.focus();
            try { select.showPicker(); } catch (e) { /* not supported or not allowed: focus is enough */ }
        });
    });

    // ---- Dismissible alerts ---------------------------------------------
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-dismiss="alert"]');
        if (btn) btn.closest('.alert')?.remove();
    });

    // ---- Show / hide password -------------------------------------------
    document.querySelectorAll('[data-toggle-password]').forEach((btn) => {
        const input = document.getElementById(btn.dataset.togglePassword);
        const use = btn.querySelector('use');
        btn.addEventListener('click', () => {
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            if (use) use.setAttribute('href', use.getAttribute('href').replace(/#i-eye(-off)?$/, show ? '#i-eye-off' : '#i-eye'));
        });
    });

    // ---- Keyboard shortcuts ---------------------------------------------
    // F2 = scan barcode, F3 = search, F4 = add item.
    // Page scripts (e.g. pos.js) listen for 'bb:shortcut' and call
    // e.preventDefault() to take over; otherwise the defaults below run.
    const search = document.getElementById('globalSearch');
    const focusSearch = () => { if (search) { search.focus(); search.select(); } };

    document.addEventListener('keydown', (e) => {
        if (!['F2', 'F3', 'F4'].includes(e.key)) return;
        e.preventDefault(); // stop the browser's own F3 (find) etc.

        const evt = new CustomEvent('bb:shortcut', { detail: { key: e.key }, cancelable: true });
        if (!document.dispatchEvent(evt)) return; // handled by page script

        if (e.key === 'F2' || e.key === 'F3') {
            if (search && search.offsetParent !== null) {
                focusSearch();
            } else if (body.dataset.pos === '1' && body.dataset.page !== 'pos') {
                window.location.href = `${BB.baseUrl}/pages/pos.php`;
            }
        }
    });

    // ---- List rows open their record ------------------------------------
    // A click anywhere on a list row (not on its own links, buttons or fields) follows the row's main link.
    const ROW_LINK = 'a.item-cell__name';
    document.querySelectorAll('.table tbody tr').forEach((tr) => {
        if (tr.querySelectorAll(ROW_LINK).length === 1) tr.classList.add('row-link');
    });
    document.addEventListener('click', (e) => {
        const tr = e.target.closest('tr.row-link');
        if (!tr || e.button !== 0 || e.target.closest('a, button, input, select, textarea, label, form, summary, dialog')) return;
        if (String(window.getSelection ? window.getSelection() : '').length) return; // selecting text
        const href = tr.querySelector(ROW_LINK).href;
        if (e.ctrlKey || e.metaKey) window.open(href, '_blank', 'noopener');
        else window.location.href = href;
    });

    // Escape closes the dropdown and the mobile sidebar.
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        if (userMenu) userMenu.open = false;
        setSidebar(false);
    });
})();
