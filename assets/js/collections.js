/**
 * Collection form (collection-form.php):
 *  - choosing the customer reloads the page with their open bills ([data-autosubmit]);
 *  - method -> shows the reference / bank / check date fields that apply ([data-method="check bank ..."]);
 *  - "Full" per bill and "Pay All in Full": EWT and VAT withheld at the chosen rates on the amount before VAT
 *    (data-base, cents), cash applied = balance - withheld (data-balance, cents);
 *  - live "Left" per bill and the summary totals. The server re-checks everything (Collections::post).
 */
(function () {
    'use strict';

    const auto = document.querySelector('select[data-autosubmit]');
    if (auto && auto.form) auto.addEventListener('change', () => { if (auto.value) auto.form.submit(); });

    const form = document.getElementById('crForm');
    if (!form) return;
    const currency = form.dataset.currency || '';
    const fmt = (cents) => `${currency} ${(cents / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    const plain = (cents) => (cents / 100).toFixed(2);
    /** "1,234.5" -> cents; blank -> 0; invalid -> NaN. */
    const cents = (text) => {
        const t = String(text).replace(/,/g, '').trim();
        if (t === '') return 0;
        const m = /^(\d{1,8})(?:\.(\d{1,2}))?$/.exec(t);
        return m ? parseInt(m[1], 10) * 100 + parseInt((m[2] || '').padEnd(2, '0'), 10) : NaN;
    };

    // ---- Method-dependent fields --------------------------------------------
    const method = document.getElementById('crMethod');
    const refLabel = document.getElementById('crRefLabel');
    const syncMethod = () => {
        form.querySelectorAll('[data-method]').forEach((el) => {
            el.hidden = !el.dataset.method.split(' ').includes(method.value);
        });
        if (refLabel) refLabel.textContent = method.value === 'check' ? 'Check no. *' : 'Deposit / transaction reference *';
    };
    if (method) {
        method.addEventListener('change', syncMethod);
        syncMethod();
    }

    // ---- Lines ----------------------------------------------------------------
    const rows = Array.from(form.querySelectorAll('tr[data-cr-line]'));
    const ewtRate = document.getElementById('crEwtRate');
    const vatRate = document.getElementById('crVatRate');
    const field = (row, k) => row.querySelector(`[data-cr="${k}"]`);

    const summary = () => {
        const sum = { amount: 0, ewt: 0, vat: 0 };
        let ok = true;
        rows.forEach((row) => {
            let credit = 0;
            ['amount', 'ewt', 'vat'].forEach((k) => {
                const c = cents(field(row, k).value);
                if (Number.isNaN(c)) { ok = false; return; }
                sum[k] += c;
                credit += c;
            });
            const left = Number(row.dataset.balance) - credit;
            const cell = row.querySelector('[data-cr-left]');
            cell.textContent = fmt(left);
            cell.classList.toggle('text-danger', left < 0);
        });
        const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = ok ? fmt(v) : 'Check the amounts'; };
        set('crSumCash', sum.amount);
        set('crSumEwt', sum.ewt);
        set('crSumVat', sum.vat);
        set('crSumTotal', sum.amount + sum.ewt + sum.vat);
    };

    const fillFull = (row) => {
        const balance = Number(row.dataset.balance);
        const base = Number(row.dataset.base);
        const ewt = Math.round(base * Number(ewtRate.value) / 100);
        const vat = Math.round(base * Number(vatRate.value) / 100);
        field(row, 'ewt').value = ewt ? plain(ewt) : '';
        field(row, 'vat').value = vat ? plain(vat) : '';
        field(row, 'amount').value = plain(Math.max(0, balance - ewt - vat));
    };

    rows.forEach((row) => {
        row.querySelectorAll('input').forEach((el) => el.addEventListener('input', summary));
        const btn = row.querySelector('[data-cr-full]');
        if (btn) btn.addEventListener('click', () => { fillFull(row); summary(); });
    });
    const all = document.getElementById('crFillAll');
    if (all) all.addEventListener('click', () => { rows.forEach(fillFull); summary(); });
    summary();
})();
