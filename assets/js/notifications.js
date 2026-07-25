/**
 * Centro de notificaciones (campana del header).
 *
 * Sondea el contador con el intervalo configurado en settings.php y respeta la
 * pausa por pestaña oculta (mismo criterio que el resto del sistema: la oficina
 * comparte una sola IP y LiteSpeed responde 429 si todos sondean a la vez).
 */
(function () {
    'use strict';

    const root = document.querySelector('[data-notif-center]');
    if (!root) {
        return;
    }

    const cfg = window.PoncheNotifications || {};
    const endpoint = cfg.endpoint || 'api/notifications.php';
    const baseHref = cfg.baseHref || '';
    const pollMs = Math.max(15, parseInt(cfg.pollSeconds, 10) || 90) * 1000;
    const pauseWhenHidden = (window.PonchePolling && window.PonchePolling.pauseWhenHidden) !== false;

    const toggle = root.querySelector('[data-notif-toggle]');
    const panel = root.querySelector('[data-notif-panel]');
    const list = root.querySelector('[data-notif-list]');
    const badge = root.querySelector('[data-notif-badge]');
    const markAll = root.querySelector('[data-notif-mark-all]');

    let timer = null;
    let loading = false;

    function setBadge(count) {
        const n = parseInt(count, 10) || 0;
        badge.textContent = n > 99 ? '99+' : String(n);
        badge.classList.toggle('hidden', n === 0);
    }

    function relativeTime(iso) {
        const then = new Date((iso || '').replace(' ', 'T'));
        if (isNaN(then.getTime())) {
            return '';
        }
        const secs = Math.floor((Date.now() - then.getTime()) / 1000);
        if (secs < 60) return 'hace un momento';
        if (secs < 3600) return 'hace ' + Math.floor(secs / 60) + ' min';
        if (secs < 86400) return 'hace ' + Math.floor(secs / 3600) + ' h';
        if (secs < 604800) return 'hace ' + Math.floor(secs / 86400) + ' d';
        return then.toLocaleDateString('es-DO');
    }

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }

    function resolveUrl(url) {
        if (!url) return '';
        if (/^(https?:)?\/\//.test(url) || url.charAt(0) === '/') {
            return url;
        }
        return baseHref + url;
    }

    function iconFor(type) {
        if (!type) return 'fa-bell';
        if (type.indexOf('INVENTORY') === 0) return 'fa-boxes-stacked';
        if (type.indexOf('RECRUITMENT') === 0) return 'fa-user-check';
        if (type.indexOf('PAYROLL') === 0) return 'fa-money-check-dollar';
        return 'fa-bell';
    }

    function render(notifications) {
        if (!notifications || notifications.length === 0) {
            list.innerHTML = '<div class="notif-empty"><i class="fas fa-check-circle mb-2 block text-lg"></i>Sin notificaciones</div>';
            return;
        }

        list.innerHTML = notifications.map(function (n) {
            const sev = 'sev-' + String(n.severity || 'normal').toLowerCase();
            const unread = n.is_read ? '' : ' is-unread';
            const tag = n.url ? 'a' : 'div';
            const href = n.url ? ' href="' + escapeHtml(resolveUrl(n.url)) + '"' : '';
            const action = n.url
                ? '<span class="notif-item-action">Revisar <i class="fas fa-arrow-right ml-1"></i></span>'
                : '';

            return '<' + tag + href + ' class="notif-item ' + sev + unread + '" data-notif-id="' + parseInt(n.id, 10) + '">'
                + '<div class="notif-item-top">'
                + '<span class="notif-item-title"><i class="fas ' + iconFor(n.notif_type) + ' mr-2"></i>'
                + escapeHtml(n.title) + '</span>'
                + '<span class="notif-item-time">' + escapeHtml(relativeTime(n.created_at)) + '</span>'
                + '</div>'
                + '<div class="notif-item-message">' + escapeHtml(n.message) + '</div>'
                + action
                + '</' + tag + '>';
        }).join('');
    }

    function refreshCount() {
        if (loading) return;
        if (pauseWhenHidden && document.hidden) return;

        fetch(endpoint + '?action=count', { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (data && data.success) {
                    setBadge(data.count);
                }
            })
            .catch(function () { /* sin ruido: la campana no debe romper la página */ });
    }

    function loadList() {
        loading = true;
        list.innerHTML = '<div class="notif-empty">Cargando…</div>';
        fetch(endpoint + '?action=list&limit=25', { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (data && data.success) {
                    render(data.notifications);
                    setBadge(data.count);
                } else {
                    list.innerHTML = '<div class="notif-empty">No se pudieron cargar las notificaciones</div>';
                }
            })
            .catch(function () {
                list.innerHTML = '<div class="notif-empty">No se pudieron cargar las notificaciones</div>';
            })
            .finally(function () { loading = false; });
    }

    function open() {
        panel.hidden = false;
        root.setAttribute('data-open', 'true');
        toggle.setAttribute('aria-expanded', 'true');
        loadList();
    }

    function close() {
        panel.hidden = true;
        root.setAttribute('data-open', 'false');
        toggle.setAttribute('aria-expanded', 'false');
    }

    toggle.addEventListener('click', function (e) {
        e.stopPropagation();
        if (panel.hidden) {
            open();
        } else {
            close();
        }
    });

    document.addEventListener('click', function (e) {
        if (!panel.hidden && !root.contains(e.target)) {
            close();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !panel.hidden) {
            close();
        }
    });

    // Marcar como leída al abrirla; el enlace sigue navegando igual.
    list.addEventListener('click', function (e) {
        const item = e.target.closest('[data-notif-id]');
        if (!item) return;

        const id = item.getAttribute('data-notif-id');
        const body = new FormData();
        body.append('action', 'read');
        body.append('id', id);
        fetch(endpoint, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (data && data.success) {
                    item.classList.remove('is-unread');
                    setBadge(data.count);
                }
            })
            .catch(function () { /* ignorar */ });
    });

    markAll.addEventListener('click', function () {
        const body = new FormData();
        body.append('action', 'read_all');
        fetch(endpoint, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (data && data.success) {
                    setBadge(data.count);
                    list.querySelectorAll('.notif-item').forEach(function (el) {
                        el.classList.remove('is-unread');
                    });
                }
            })
            .catch(function () { /* ignorar */ });
    });

    function startPolling() {
        if (timer) return;
        timer = setInterval(refreshCount, pollMs);
    }

    function stopPolling() {
        if (!timer) return;
        clearInterval(timer);
        timer = null;
    }

    if (pauseWhenHidden) {
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) {
                stopPolling();
            } else {
                refreshCount();
                startPolling();
            }
        });
    }

    startPolling();
})();
