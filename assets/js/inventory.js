/**
 * Inventory pages: "Adjust stock" dialog and product image preview.
 */
(function () {
    'use strict';

    // ---- Adjust stock dialog ------------------------------------------
    const dialog = document.getElementById('adjustDialog');
    if (dialog) {
        const form = dialog.querySelector('form');
        const qty = document.getElementById('adjustQty');
        const reason = document.getElementById('adjustReason');
        const preview = document.getElementById('adjustPreview');
        let current = 0;

        const direction = () => form.elements.direction.value;

        /** Only offer reasons that make sense for adding / removing. */
        const syncReasons = () => {
            const dir = direction();
            let firstAllowed = null;
            [...reason.options].forEach((opt) => {
                const ok = opt.dataset.direction === 'both' || opt.dataset.direction === dir;
                opt.hidden = !ok;
                opt.disabled = !ok;
                if (ok && !firstAllowed) firstAllowed = opt;
            });
            if (reason.selectedOptions[0]?.disabled) reason.value = firstAllowed.value;
        };

        const syncPreview = () => {
            const n = parseInt(qty.value, 10);
            if (!Number.isInteger(n) || n < 1) {
                preview.textContent = '';
                return;
            }
            const next = direction() === 'add' ? current + n : current - n;
            preview.textContent = next < 0
                ? `Can't remove ${n} — only ${current} in stock.`
                : `New stock will be ${next}.`;
            preview.classList.toggle('text-danger', next < 0);
        };

        document.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-adjust]');
            if (!btn) return;
            current = parseInt(btn.dataset.stock, 10) || 0;
            form.reset();
            document.getElementById('adjustId').value = btn.dataset.id;
            document.getElementById('adjustName').textContent = `${btn.dataset.name} (${btn.dataset.code})`;
            document.getElementById('adjustStock').textContent = String(current);
            syncReasons();
            syncPreview();
            dialog.showModal();
            qty.focus();
        });

        form.addEventListener('change', (e) => {
            if (e.target.name === 'direction') syncReasons();
            syncPreview();
        });
        qty.addEventListener('input', syncPreview);
    }

    // ---- Product image preview ----------------------------------------
    const input = document.getElementById('imageInput');
    if (input) {
        const img = document.getElementById('imagePreviewImg');
        const empty = document.querySelector('.image-preview__empty');
        const label = document.getElementById('imageLabel');
        const remove = document.getElementById('removeImage');
        const original = img.getAttribute('src');
        const MAX = 2 * 1024 * 1024;
        const TYPES = ['image/jpeg', 'image/png', 'image/webp'];
        let objectUrl = null;

        const show = (src) => {
            img.hidden = !src;
            empty.hidden = Boolean(src);
            if (src) img.src = src; else img.removeAttribute('src');
        };

        input.addEventListener('change', () => {
            if (objectUrl) URL.revokeObjectURL(objectUrl);
            objectUrl = null;
            const file = input.files[0];
            if (!file) {
                show(original);
                return;
            }
            // Quick checks for the user; the server checks everything again.
            if (!TYPES.includes(file.type)) {
                BB.toast('Only JPG, PNG or WebP images are allowed.', 'error');
                input.value = '';
                show(original);
                return;
            }
            if (file.size > MAX) {
                BB.toast('The image is too large. Maximum size is 2 MB.', 'error');
                input.value = '';
                show(original);
                return;
            }
            objectUrl = URL.createObjectURL(file);
            show(objectUrl);
            label.textContent = file.name;
            if (remove) remove.checked = false;
        });

        if (remove) {
            remove.addEventListener('change', () => {
                if (remove.checked) {
                    input.value = '';
                    show(null);
                } else {
                    show(original);
                }
            });
        }
    }

    // ---- Register serials (serial-register.php): live count per location ----
    document.querySelectorAll('[data-reg-input]').forEach((box) => {
        const out = box.parentElement.querySelector('[data-reg-count]');
        const qty = parseInt(box.dataset.qty, 10) || 0;
        const sync = () => {
            const list = box.value.split(/\r?\n/).map((s) => s.trim()).filter((s) => s !== '');
            const dupes = list.length - new Set(list.map((s) => s.toUpperCase())).size;
            out.textContent = `${list.length} of ${qty} serial numbers` + (dupes > 0 ? ` (${dupes} repeated)` : '');
            out.classList.toggle('is-ok', list.length === qty && dupes === 0);
            out.classList.toggle('is-warn', list.length !== qty || dupes > 0);
        };
        box.addEventListener('input', sync);
        sync();
    });

    // ---- Product form: serial-tracked items start at 0 (stock comes through Receiving) ----
    const track = document.getElementById('trackSerial');
    const opening = document.getElementById('openingStock');
    if (track && opening) {
        const hint = document.getElementById('openingStockHint');
        const sync = () => {
            opening.readOnly = track.checked;
            if (track.checked) opening.value = '0';
            if (hint) hint.hidden = !track.checked;
        };
        track.addEventListener('change', sync);
    }
})();
