/* =====================================================================
   Evallish BPO — App shell interactions
   Sidebar collapse / mobile drawer / accordion / menu search
   + legacy nav + responsive tables (kept for backward compatibility)
   ===================================================================== */
(function () {
    function onReady(cb) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', cb);
        } else {
            cb();
        }
    }

    function setCookie(name, value, days) {
        var d = new Date();
        d.setTime(d.getTime() + (days * 24 * 60 * 60 * 1000));
        document.cookie = name + '=' + value + ';expires=' + d.toUTCString() + ';path=/;SameSite=Lax';
    }

    onReady(function () {
        var body = document.body;
        var DESKTOP = '(min-width: 1025px)';

        /* ---------- Sidebar: collapse (desktop) ---------- */
        document.querySelectorAll('[data-sidebar-collapse]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var collapsed = body.classList.toggle('sidebar-collapsed');
                setCookie('ev_sidebar', collapsed ? 'collapsed' : 'expanded', 180);
            });
        });

        /* ---------- Sidebar: mobile drawer ---------- */
        function openDrawer() { body.classList.add('sidebar-open'); }
        function closeDrawer() { body.classList.remove('sidebar-open'); }

        document.querySelectorAll('[data-sidebar-mobile]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (body.classList.contains('sidebar-open')) { closeDrawer(); } else { openDrawer(); }
            });
        });
        document.querySelectorAll('[data-sidebar-overlay]').forEach(function (ov) {
            ov.addEventListener('click', closeDrawer);
        });
        // Close drawer when navigating on mobile
        document.querySelectorAll('.app-sidebar a.sidebar-link').forEach(function (link) {
            link.addEventListener('click', function () {
                if (!window.matchMedia(DESKTOP).matches) { closeDrawer(); }
            });
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { closeDrawer(); }
        });

        /* ---------- Sidebar: accordion groups ---------- */
        document.querySelectorAll('[data-sidebar-group-toggle]').forEach(function (toggle) {
            toggle.addEventListener('click', function (e) {
                e.preventDefault();
                var group = toggle.closest('[data-sidebar-group]');
                if (group) { group.classList.toggle('is-open'); }
            });
        });

        /* ---------- Sidebar: live menu search ---------- */
        var nav = document.querySelector('[data-sidebar-nav]');
        document.querySelectorAll('[data-sidebar-search]').forEach(function (input) {
            input.addEventListener('input', function () {
                filterSidebar(nav, input.value.trim().toLowerCase());
            });
        });

        // Ctrl/Cmd+K focuses the menu search
        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                var s = document.querySelector('[data-sidebar-search]');
                if (s) { e.preventDefault(); s.focus(); s.select(); }
            }
        });

        function filterSidebar(navEl, q) {
            if (!navEl) { return; }
            var children = Array.prototype.slice.call(navEl.children);
            var currentLabel = null;
            var labelHasVisible = false;

            function commitLabel() {
                if (currentLabel) { currentLabel.style.display = labelHasVisible ? '' : 'none'; }
            }

            children.forEach(function (el) {
                if (el.classList.contains('sidebar-section-label')) {
                    commitLabel();
                    currentLabel = el;
                    labelHasVisible = false;
                    return;
                }
                var match = true;
                if (q) {
                    var texts = [];
                    el.querySelectorAll('.sidebar-link__text').forEach(function (t) { texts.push(t.textContent.toLowerCase()); });
                    if (el.classList.contains('sidebar-link')) {
                        var own = el.querySelector('.sidebar-link__text');
                        match = own ? own.textContent.toLowerCase().indexOf(q) !== -1 : false;
                    } else {
                        match = texts.some(function (t) { return t.indexOf(q) !== -1; });
                        if (match && el.classList.contains('sidebar-group')) { el.classList.add('is-open'); }
                    }
                }
                el.style.display = match ? '' : 'none';
                if (match) { labelHasVisible = true; }
            });
            commitLabel();
        }

        /* ---------- LEGACY: data-nav-toggle (older inline menus) ---------- */
        document.querySelectorAll('[data-nav-toggle]').forEach(function (toggle) {
            var targetId = toggle.getAttribute('data-nav-target');
            if (!targetId) { return; }
            var menu = document.getElementById(targetId);
            if (!menu) { return; }
            function setState(open) {
                menu.setAttribute('data-open', open ? 'true' : 'false');
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            }
            var dm = window.matchMedia('(min-width: 769px)');
            setState(dm.matches);
            if (typeof dm.addEventListener === 'function') { dm.addEventListener('change', function (ev) { setState(ev.matches); }); }
            toggle.addEventListener('click', function () { setState(menu.getAttribute('data-open') !== 'true'); });
        });

        /* ---------- LEGACY: generic dropdowns ---------- */
        (function () {
            var dropdowns = [];
            document.querySelectorAll('[data-nav-dropdown]').forEach(function (dropdown) {
                var trigger = dropdown.querySelector('[data-nav-dropdown-trigger]');
                var menu = dropdown.querySelector('[data-nav-dropdown-menu]');
                if (!trigger || !menu) { return; }
                function ensureClosed() { dropdown.classList.remove('is-open'); trigger.setAttribute('aria-expanded', 'false'); menu.setAttribute('hidden', ''); }
                if (trigger.getAttribute('aria-expanded') !== 'true') { ensureClosed(); } else { dropdown.classList.add('is-open'); menu.removeAttribute('hidden'); }
                trigger.addEventListener('click', function (event) {
                    event.preventDefault();
                    var isOpen = dropdown.classList.contains('is-open');
                    dropdowns.forEach(function (entry) { if (entry.dropdown !== dropdown) { entry.close(true); } });
                    if (!isOpen) { dropdown.classList.add('is-open'); trigger.setAttribute('aria-expanded', 'true'); menu.removeAttribute('hidden'); } else { ensureClosed(); }
                });
                dropdowns.push({ dropdown: dropdown, close: function (force) { if (force || dropdown.classList.contains('is-open')) { ensureClosed(); } } });
            });
            document.addEventListener('click', function (event) {
                dropdowns.forEach(function (entry) { if (!entry.dropdown.contains(event.target)) { entry.close(true); } });
            });
        })();

        /* ---------- Responsive tables (wrap in horizontal scroller) ---------- */
        document.querySelectorAll('table').forEach(function (table) {
            if (table.dataset.skipResponsive === 'true') { return; }
            if (table.closest('[data-responsive-parent]')) { return; }
            var parent = table.parentElement;
            if (parent && parent.classList.contains('responsive-scroll')) { parent.setAttribute('data-responsive-parent', 'true'); return; }
            if (parent && parent.classList.contains('overflow-x-auto')) { parent.classList.add('responsive-scroll'); parent.setAttribute('data-responsive-parent', 'true'); return; }
            var wrapper = document.createElement('div');
            wrapper.className = 'responsive-scroll';
            wrapper.setAttribute('data-responsive-parent', 'true');
            if (parent) { parent.insertBefore(wrapper, table); wrapper.appendChild(table); }
        });

        /* ---------- Client-side pagination for long CRUD tables (render fluidity) ---------- */
        document.querySelectorAll('table').forEach(function (table) {
            if (table.classList.contains('dataTable')) { return; }
            if (table.closest('.dataTables_wrapper')) { return; }
            if (table.hasAttribute('data-skip-paginate')) { return; }
            var tbody = table.tBodies && table.tBodies[0];
            if (!tbody) { return; }
            var dataRows = Array.prototype.filter.call(tbody.rows, function (r) { return !r.querySelector('td[colspan]'); });
            var optIn = parseInt(table.getAttribute('data-ev-paginate'), 10);
            var pageSize = optIn > 0 ? optIn : 25;
            if (dataRows.length <= pageSize) { return; }

            var page = 1;
            var pageCount = Math.ceil(dataRows.length / pageSize);
            var anchor = table.closest('.responsive-scroll') || table;
            var bar = document.createElement('div');
            bar.className = 'ev-pagination';
            anchor.parentNode.insertBefore(bar, anchor.nextSibling);

            function mkBtn(html, target, disabled, active) {
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'ev-pg-btn' + (active ? ' is-active' : '');
                b.innerHTML = html;
                if (disabled) { b.disabled = true; }
                else { b.addEventListener('click', function () { page = target; render(); }); }
                return b;
            }
            function render() {
                var start = (page - 1) * pageSize, end = start + pageSize;
                dataRows.forEach(function (r, i) { r.style.display = (i >= start && i < end) ? '' : 'none'; });
                bar.innerHTML = '';
                var info = document.createElement('span');
                info.className = 'ev-pg-info';
                info.textContent = (start + 1) + '–' + Math.min(end, dataRows.length) + ' de ' + dataRows.length;
                bar.appendChild(info);
                var nav = document.createElement('div');
                nav.className = 'ev-pg-nav';
                nav.appendChild(mkBtn('<i class="fas fa-chevron-left"></i>', page - 1, page === 1));
                var from = Math.max(1, page - 2), to = Math.min(pageCount, page + 2);
                if (from > 1) { nav.appendChild(mkBtn('1', 1, false, false)); if (from > 2) { var el = document.createElement('span'); el.className = 'ev-pg-ellipsis'; el.textContent = '…'; nav.appendChild(el); } }
                for (var p = from; p <= to; p++) { nav.appendChild(mkBtn(String(p), p, false, p === page)); }
                if (to < pageCount) { if (to < pageCount - 1) { var el2 = document.createElement('span'); el2.className = 'ev-pg-ellipsis'; el2.textContent = '…'; nav.appendChild(el2); } nav.appendChild(mkBtn(String(pageCount), pageCount, false, false)); }
                nav.appendChild(mkBtn('<i class="fas fa-chevron-right"></i>', page + 1, page === pageCount));
                bar.appendChild(nav);
            }
            render();
        });
    });
})();
