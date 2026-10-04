/**
 * Pay supplier form (dv-form.php):
 *  - choosing the supplier reloads the page with their unpaid invoices ([data-autosubmit]);
 *  - method -> shows the reference / bank / check date fields that apply ([data-method="check bank ..."]);
 *  - "Full" per invoice and "Pay All in Full": EWT at the chosen rate on the amount before VAT (data-vat on the form),
 *    cash paid = balance - EWT (data-balance, cents);
 *  - live "Left" per invoice and the summary totals. The server re-checks everything (Payables::postDv).
 */
(function () {
    'use strict';

    const auto = document.querySelector('select[data-autosubmit]');
    if (auto && auto.form) auto.addEventListener('change', () => { if (auto.value) auto.form.submit(); });

    const form = document.getElementById('dvForm');
    if (!form) return;
    const currency = form.dataset.currency || '';
    const vatRate = Number(form.dataset.vat) || 0;
    const fmt = (cents) => `${currency} ${(cents / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    const plain = (cents) => (cents / 100).toFixed(2);
    const cents = (text) => {
        const t = String(text).replace(/,/g, '').trim();
        if (t === '') return 0;
        const m = /^(\d{1,8})(?:\.(\d{1,2}))?$/.exec(t);
        return m ? parseInt(m[1], 10) * 100 + parseInt((m[2] || '').padEnd(2, '0'), 10) : NaN;
    };

    const method = document.getElementById('crMethod');
    const refLabel = document.getElementById('crRefLabel');
    const syncMethod = () => {
        form.querySelectorAll('[data-method]').forEach((el) => { el.hidden = !el.dataset.method.split(' ').includes(method.value); });
        if (refLabel) refLabel.textContent = method.value === 'check' ? 'Check no. *' : 'Transfer / transaction reference *';
    };
    if (method) {
        method.addEventListener('change', syncMethod);
        syncMethod();
    }

    const rows = Array.from(form.querySelectorAll('tr[data-dv-line]'));
    const rate = document.getElementById('dvEwtRate');
    const field = (row, k) => row.querySelector(`[data-dv="${k}"]`);

    const summary = () => {
        const sum = { amount: 0, ewt: 0 };
        let ok = true;
        rows.forEach((row) => {
            let credit = 0;
            ['amount', 'ewt'].forEach((k) => {
                const c = cents(field(row, k).value);
                if (Number.isNaN(c)) { ok = false; return; }
                sum[k] += c;
                credit += c;
            });
            const left = Number(row.dataset.balance) - credit;
            const cell = row.querySelector('[data-dv-left]');
            cell.textContent = fmt(left);
            cell.classList.toggle('text-danger', left < 0);
        });
        const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = ok ? fmt(v) : 'Check the amounts'; };
        set('dvSumPaid', sum.amount);
        set('dvSumEwt', sum.ewt);
        set('dvSumTotal', sum.amount + sum.ewt);
    };

    const fillFull = (row) => {
        const balance = Number(row.dataset.balance);
        const base = Math.round(balance * 100 / (100 + vatRate));
        const ewt = Math.round(base * Number(rate.value) / 100);
        field(row, 'ewt').value = ewt ? plain(ewt) : '';
        field(row, 'amount').value = plain(Math.max(0, balance - ewt));
    };

    rows.forEach((row) => {
        row.querySelectorAll('input').forEach((el) => el.addEventListener('input', summary));
        row.querySelector('[data-dv-full]').addEventListener('click', () => { fillFull(row); summary(); });
    });
    const all = document.getElementById('dvFillAll');
    if (all) all.addEventListener('click', () => { rows.forEach(fillFull); summary(); });
    summary();
})();
