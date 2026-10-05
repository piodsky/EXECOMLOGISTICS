/**
 * Bell + notifications panel in the top bar (includes/header.php, every page).
 *  - The badge is rendered by the server; checked again every 60 s while the tab is visible (count only).
 *  - Opening the panel loads the newest notifications (All / Unread), grouped Today / Earlier.
 *  - A row links to notification-open.php (marks it read on the server and opens the document).
 *  - "Mark all read" -> api/notifications/read-all.php.
 * DOM is built from <template>s with textContent only (CSP: no innerHTML with data).
 */
(function () {
    'use strict';

    const root = document.getElementById('notif');
    if (!root || !window.BB) return;
    const SVG_NS = 'http://www.w3.org/2000/svg';
    const bell = document.getElementById('notifBell');
    const panel = document.getElementById('notifPanel');
    const badge = document.getElementById('notifBadge');
    const list = document.getElementById('notifList');
    const state = document.getElementById('notifState');
    const itemTpl = document.getElementById('notifItemTpl');
    const groupTpl = document.getElementById('notifGroupTpl');
    let filter = '';
    let unread = Number(badge.hidden ? 0 : (badge.textContent === '99+' ? 100 : badge.textContent)) || 0;

    const svgIcon = (name) => {
        const svg = document.createElementNS(SVG_NS, 'svg');
        svg.setAttribute('class', 'icon');
        svg.setAttribute('aria-hidden', 'true');
        const use = document.createElementNS(SVG_NS, 'use');
        use.setAttribute('href', `${root.dataset.icons}#i-${name}`);
        svg.appendChild(use);
        return svg;
    };

    const setBadge = (n) => {
        unread = Math.max(0, n);
        badge.hidden = unread === 0;
        badge.textContent = unread > 99 ? '99+' : String(unread);
        bell.setAttribute('aria-label', unread > 0 ? `Notifications, ${unread} unread` : 'Notifications');
    };

    // "PO-MAR-2026-000041 needs …" with the document number in bold.
    const fillText = (el, message, ref) => {
        el.replaceChildren();
        const at = ref ? message.indexOf(ref) : -1;
        if (at < 0) { el.textContent = message; return; }
        const strong = document.createElement('strong');
        strong.textContent = ref;
        el.append(message.slice(0, at), strong, message.slice(at + ref.length));
    };

    const render = (items) => {
        list.replaceChildren();
        state.hidden = items.length > 0;
        state.textContent = filter === 'unread' ? 'No unread notifications. You are all caught up.' : 'No notifications yet.';
        let group = null;
        items.forEach((n) => {
            const g = n.today ? 'Today' : 'Earlier';
            if (g !== group) {
                group = g;
                const li = groupTpl.content.firstElementChild.cloneNode(true);
                li.textContent = g;
                list.appendChild(li);
            }
            const li = itemTpl.content.firstElementChild.cloneNode(true);
            const a = li.querySelector('a');
            a.href = n.url;
            a.dataset.notification = String(n.id);
            a.classList.toggle('is-unread', n.unread);
            const icon = li.querySelector('.notif-icon');
            icon.classList.add(`notif-icon--${n.tone}`);
            icon.appendChild(svgIcon(n.icon));
            fillText(li.querySelector('.notif-text'), n.message, n.ref);
            li.querySelector('.notif-meta').textContent = [n.ago, n.branch, n.actor].filter(Boolean).join(' · ');
            if (!n.unread) li.querySelector('.notif-dot').remove();
            if (n.unread) a.addEventListener('click', () => setBadge(unread - 1)); // the server marks it read on open
            list.appendChild(li);
        });
    };

    const load = async () => {
        state.hidden = false;
        state.textContent = 'Loading…';
        try {
            const data = await BB.api('notifications/list.php' + (filter ? '?filter=unread' : ''));
            setBadge(data.unread);
            render(data.items);
        } catch (err) {
            state.hidden = false;
            state.textContent = 'Could not load notifications. Try again.';
        }
    };

    const open = (show) => {
        panel.hidden = !show;
        bell.setAttribute('aria-expanded', String(show));
        root.classList.toggle('is-open', show);
        if (show) load();
    };

    bell.addEventListener('click', (e) => {
        e.stopPropagation();
        open(panel.hidden);
    });
    document.addEventListener('click', (e) => {
        if (!panel.hidden && !root.contains(e.target)) open(false);
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !panel.hidden) {
            open(false);
            bell.focus();
        }
    });

    panel.querySelectorAll('[data-notif-filter]').forEach((tab) => {
        tab.addEventListener('click', () => {
            filter = tab.dataset.notifFilter;
            panel.querySelectorAll('[data-notif-filter]').forEach((t) => {
                const on = t === tab;
                t.classList.toggle('is-active', on);
                t.setAttribute('aria-selected', String(on));
            });
            load();
        });
    });

    document.getElementById('notifMarkAll').addEventListener('click', async () => {
        try {
            await BB.api('notifications/read-all.php', { method: 'POST', body: {} });
            setBadge(0);
            load();
        } catch (err) {
            if (window.BB.toast) BB.toast('Could not mark the notifications as read.', 'error');
        }
    });

    // Badge refresh every minute while the page is visible (and when the tab becomes visible again).
    const poll = async () => {
        if (document.visibilityState !== 'visible') return;
        try {
            const data = await BB.api('notifications/list.php?count=1');
            if (data.unread !== unread) {
                setBadge(data.unread);
                if (!panel.hidden) load();
            }
        } catch (err) { /* offline / signed out: try again next minute */ }
    };
    setInterval(poll, 60000);
    document.addEventListener('visibilitychange', poll);
})();
