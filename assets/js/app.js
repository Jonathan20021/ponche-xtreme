(function () {
    function onReady(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback);
        } else {
            callback();
        }
    }

    onReady(function () {
        var DESKTOP_QUERY = '(min-width: 769px)';

        document.querySelectorAll('[data-nav-toggle]').forEach(function (toggle) {
            var targetId = toggle.getAttribute('data-nav-target');
            if (!targetId) {
                return;
            }

            var nav = document.getElementById(targetId);
            if (!nav) {
                return;
            }

            function setState(open) {
                nav.setAttribute('data-open', open ? 'true' : 'false');
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            }

            var desktopMatch = window.matchMedia(DESKTOP_QUERY);
            setState(desktopMatch.matches);

            var handleChange = function (event) {
                setState(event.matches);
            };

            if (typeof desktopMatch.addEventListener === 'function') {
                desktopMatch.addEventListener('change', handleChange);
            } else if (typeof desktopMatch.addListener === 'function') {
                desktopMatch.addListener(handleChange);
            }

            toggle.addEventListener('click', function () {
                var isOpen = nav.getAttribute('data-open') === 'true';
                setState(!isOpen);
            });
        });

        (function () {
            var dropdowns = [];

            document.querySelectorAll('[data-nav-dropdown]').forEach(function (dropdown) {
                var trigger = dropdown.querySelector('[data-nav-dropdown-trigger]');
                var menu = dropdown.querySelector('[data-nav-dropdown-menu]');
                if (!trigger || !menu) {
                    return;
                }

                var close = function () {
                    dropdown.classList.remove('is-open');
                    trigger.setAttribute('aria-expanded', 'false');
                };

                var ensureClosed = function () {
                    dropdown.classList.remove('is-open');
                    trigger.setAttribute('aria-expanded', 'false');
                    menu.setAttribute('hidden', '');
                };

                // Solo cerrar si no está marcado como activo inicialmente
                var isInitiallyExpanded = trigger.getAttribute('aria-expanded') === 'true';
                if (!isInitiallyExpanded) {
                    ensureClosed();
                } else {
                    dropdown.classList.add('is-open');
                    menu.removeAttribute('hidden');
                }

                trigger.addEventListener('click', function (event) {
                    event.preventDefault();
                    var isOpen = dropdown.classList.contains('is-open');

                    dropdowns.forEach(function (entry) {
                        if (entry.dropdown !== dropdown) {
                            entry.close(true);
                        }
                    });

                    if (!isOpen) {
                        dropdown.classList.add('is-open');
                        trigger.setAttribute('aria-expanded', 'true');
                        menu.removeAttribute('hidden');
                    } else {
                        ensureClosed();
                    }
                });

                dropdowns.push({
                    dropdown: dropdown,
                    close: function (force) {
                        if (force || dropdown.classList.contains('is-open')) {
                            ensureClosed();
                        }
                    }
                });
            });

            document.addEventListener('click', function (event) {
                dropdowns.forEach(function (entry) {
                    if (!entry.dropdown.contains(event.target)) {
                        entry.close(true);
                    }
                });
            });
        })();

        document.querySelectorAll('table').forEach(function (table) {
            if (table.dataset.skipResponsive === 'true') {
                return;
            }

            if (table.closest('[data-responsive-parent]')) {
                return;
            }

            var parent = table.parentElement;

            if (parent && parent.classList.contains('responsive-scroll')) {
                parent.setAttribute('data-responsive-parent', 'true');
                return;
            }

            if (parent && parent.classList.contains('overflow-x-auto')) {
                parent.classList.add('responsive-scroll');
                parent.setAttribute('data-responsive-parent', 'true');
                return;
            }

            var wrapper = document.createElement('div');
            wrapper.className = 'responsive-scroll';
            wrapper.setAttribute('data-responsive-parent', 'true');

            if (parent) {
                parent.insertBefore(wrapper, table);
                wrapper.appendChild(table);
            }
        });
    });
})();
