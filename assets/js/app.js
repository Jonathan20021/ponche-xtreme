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
