/* Quick "Add customer" from a form's customer select (includes/customer-quick-add.php).
   A button [data-add-customer="<select id>"] opens the dialog; on save the new customer is added to that select
   (with data-name / data-phone / data-address for forms that copy them), selected, and "change" is fired. */
(() => {
    'use strict';
    const dialog = document.getElementById('customerAddDialog');
    const form = document.getElementById('customerAddForm');
    if (!dialog || !form) return;
    const error = document.getElementById('customerAddError');
    const save = document.getElementById('customerAddSave');
    let target = null;

    document.querySelectorAll('[data-add-customer]').forEach((btn) => btn.addEventListener('click', () => {
        target = document.getElementById(btn.dataset.addCustomer);
        form.reset();
        error.hidden = true;
        dialog.showModal();
        form.elements.name.focus();
    }));

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        error.hidden = true;
        save.disabled = true;
        const f = form.elements;
        try {
            const data = await BB.api('customers/create.php', {
                method: 'POST',
                body: {
                    name: f.name.value, phone: f.phone.value, email: f.email.value,
                    customer_type_id: f.customer_type_id.value, tin: f.tin.value, address: f.address.value,
                },
            });
            const c = data.customer;
            if (target) {
                const label = target.dataset.labelPhone !== undefined
                    ? c.name + (c.phone ? ' · ' + c.phone : '')
                    : c.name + (c.type_name ? ' · ' + c.type_name : '');
                const option = new Option(label, String(c.id), true, true);
                option.dataset.name = c.name;
                option.dataset.phone = c.phone || '';
                option.dataset.address = c.address || '';
                target.add(option);
                target.value = option.value;
                target.dispatchEvent(new Event('change', { bubbles: true }));
            }
            dialog.close();
            BB.toast(data.message, 'success');
        } catch (err) {
            error.textContent = err.message;
            error.hidden = false;
        } finally {
            save.disabled = false;
        }
    });
})();
