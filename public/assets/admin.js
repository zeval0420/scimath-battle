(function () {
    'use strict';

    // ---- Tabs -------------------------------------------------------
    function initTabs() {
        var tabLinks = document.querySelectorAll('[data-tab-link]');
        var tabPanels = document.querySelectorAll('[data-tab-panel]');
        if (tabLinks.length === 0) return;

        function activate(tabId) {
            tabLinks.forEach(function (link) {
                link.classList.toggle('active', link.getAttribute('data-tab-link') === tabId);
            });
            tabPanels.forEach(function (panel) {
                panel.classList.toggle('active', panel.getAttribute('data-tab-panel') === tabId);
            });
        }

        tabLinks.forEach(function (link) {
            link.addEventListener('click', function (e) {
                e.preventDefault();
                var tabId = link.getAttribute('data-tab-link');
                activate(tabId);
                history.replaceState(null, '', '#' + tabId);
            });
        });

        var initial = (window.location.hash || '').replace('#', '');
        var validIds = Array.prototype.map.call(tabLinks, function (l) { return l.getAttribute('data-tab-link'); });
        activate(validIds.indexOf(initial) !== -1 ? initial : validIds[0]);
    }

    // ---- Confirm destructive actions ---------------------------------
    function initConfirm() {
        document.querySelectorAll('[data-confirm]').forEach(function (el) {
            el.addEventListener('click', function (e) {
                var message = el.getAttribute('data-confirm') || 'Are you sure?';
                if (!window.confirm(message)) {
                    e.preventDefault();
                }
            });
        });
    }

    // ---- Image upload preview -----------------------------------------
    function initImagePreviews() {
        document.querySelectorAll('input[type=file][data-preview-target]').forEach(function (input) {
            input.addEventListener('change', function () {
                var targetId = input.getAttribute('data-preview-target');
                var target = document.getElementById(targetId);
                if (!target || !input.files || !input.files[0]) return;

                var file = input.files[0];
                var validTypes = ['image/png', 'image/jpeg', 'image/webp'];
                if (validTypes.indexOf(file.type) === -1) {
                    return; // server will reject; don't bother previewing
                }

                var reader = new FileReader();
                reader.onload = function (e) {
                    target.src = e.target.result;
                    target.style.display = 'block';
                };
                reader.readAsDataURL(file);
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initTabs();
        initConfirm();
        initImagePreviews();
    });
})();
