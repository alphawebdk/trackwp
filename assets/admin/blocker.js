/**
 * TrackWP admin: tab "Blokering" (1.11.0).
 *
 * Config: window.trackwpBlockerAdmin = {scanUrl, nonce} (printed by TrackWP).
 * Scan data is never inserted as HTML: all dynamic text goes through
 * textContent, and the only markup this file creates is a clone of an
 * existing server-rendered row.
 */
(function () {
    'use strict';

    function byId(id) {
        return document.getElementById(id);
    }

    function each(list, fn) {
        for (var i = 0; i < list.length; i++) {
            fn(list[i], i);
        }
    }

    // ------------------------------------------------------------------
    // Scan button: POST scanUrl with X-WP-Nonce, then reload the tab.
    // ------------------------------------------------------------------
    function initScan() {
        var button = byId('trackwp-blocker-scan-button');
        var status = byId('trackwp-blocker-scan-status');
        if (!button || !status) {
            return;
        }
        var config = window.trackwpBlockerAdmin || {};

        button.addEventListener('click', function () {
            if (!config.scanUrl || !config.nonce || !window.XMLHttpRequest) {
                status.textContent = status.getAttribute('data-missing') || '';
                return;
            }
            button.disabled = true;
            status.textContent = status.getAttribute('data-running') || '';

            var xhr = new XMLHttpRequest();
            xhr.open('POST', config.scanUrl, true);
            xhr.setRequestHeader('X-WP-Nonce', config.nonce);
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.onreadystatechange = function () {
                if (xhr.readyState !== 4) {
                    return;
                }
                if (xhr.status >= 200 && xhr.status < 300) {
                    status.textContent = status.getAttribute('data-done') || '';
                    window.location.hash = 'blocker';
                    window.location.reload();
                    return;
                }
                var message = '';
                try {
                    var body = JSON.parse(xhr.responseText);
                    if (body && typeof body.message === 'string') {
                        message = body.message;
                    }
                } catch (e) {
                    message = '';
                }
                if (!message) {
                    message = 'HTTP ' + xhr.status;
                }
                status.textContent = (status.getAttribute('data-failed') || '') + ' ' + message;
                button.disabled = false;
            };
            xhr.send('{}');
        });
    }

    // ------------------------------------------------------------------
    // Table: vendor -> category, "Bloker alle kendte", protected warning.
    // ------------------------------------------------------------------
    function initTable() {
        var table = document.querySelector('.trackwp-blocker-table');
        if (!table) {
            return;
        }

        table.addEventListener('change', function (e) {
            var target = e.target;
            if (!target || !target.classList) {
                return;
            }
            if (target.classList.contains('trackwp-blocker-vendor')) {
                var option = target.options[target.selectedIndex];
                var category = option ? option.getAttribute('data-category') : '';
                var row = target.closest('tr');
                var select = row ? row.querySelector('.trackwp-blocker-category') : null;
                if (select) {
                    // KC13/F2: "Uafklaret" vendor (value "") resets the category
                    // to "Uafklaret" too, instead of leaving a stale guess.
                    if (option && option.value === '') {
                        select.value = '';
                    } else if (category) {
                        select.value = category;
                    }
                }
            }
            if (target.classList.contains('trackwp-blocker-toggle') && target.checked &&
                target.getAttribute('data-protected') === '1') {
                var warning = table.getAttribute('data-protected-warning') || '';
                if (warning && !window.confirm(warning)) {
                    target.checked = false;
                }
            }
        });

        var blockKnown = byId('trackwp-blocker-block-known');
        if (blockKnown) {
            blockKnown.addEventListener('click', function () {
                each(table.querySelectorAll('tbody tr'), function (row) {
                    var vendor = row.querySelector('.trackwp-blocker-vendor');
                    var category = row.querySelector('.trackwp-blocker-category');
                    var toggle = row.querySelector('.trackwp-blocker-toggle');
                    if (!vendor || !category || !toggle || toggle.disabled) {
                        return;
                    }
                    // Protected (payment) scripts always need an explicit choice.
                    if (toggle.getAttribute('data-protected') === '1') {
                        return;
                    }
                    if (vendor.value !== '' && category.value !== 'necessary') {
                        toggle.checked = true;
                    }
                });
            });
        }
    }

    // ------------------------------------------------------------------
    // "Tillad altid": add a row by cloning the last server-rendered one.
    // ------------------------------------------------------------------
    function initAllowRows() {
        var container = byId('trackwp-blocker-allow-rows');
        var add = byId('trackwp-blocker-allow-add');
        if (!container || !add) {
            return;
        }
        add.addEventListener('click', function () {
            var rows = container.querySelectorAll('.trackwp-blocker-allow-row');
            if (!rows.length) {
                return;
            }
            var index = rows.length;
            var clone = rows[rows.length - 1].cloneNode(true);
            each(clone.querySelectorAll('select, input'), function (field) {
                var name = field.getAttribute('name');
                if (name) {
                    field.setAttribute('name', name.replace(/\[allow\]\[\d+\]/, '[allow][' + index + ']'));
                }
                if (field.tagName === 'INPUT') {
                    field.value = '';
                }
            });
            container.appendChild(clone);
            var input = clone.querySelector('input');
            if (input) {
                input.focus();
            }
        });
    }

    function init() {
        initScan();
        initTable();
        initAllowRows();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
