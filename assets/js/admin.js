/**
 * Convermetry — admin script for the Webhooks and Forms pages.
 *
 * Webhooks page: the endpoint repeater ("+ Add Endpoint" appends a
 * URL/label/secret block with per-endpoint delivery-type checkboxes and a
 * Remove button), the "Webhook Status" toggle card (shown only while at
 * least one URL has a value), generic key/value builders for global
 * headers and query parameters, and per-endpoint test buttons that send an
 * analytics-report or form-submission test payload via AJAX.
 *
 * Forms page: live filtering of discovered forms by provider, name/id
 * text, and included/excluded state, plus the same key/value builders for
 * per-form query parameters and headers.
 */
(function () {
    'use strict';

    function cfg(key) {
        return (typeof CVM_ADMIN !== 'undefined' && CVM_ADMIN[key]) ? CVM_ADMIN[key] : '';
    }

    /* ------------------------------------------------------------------ *
     *  Generic key/value pair builders
     * ------------------------------------------------------------------ */

    /**
     * Builds one key/value row for a builder container.
     *
     * @param {string} name  The field name prefix, e.g. "cvm_global_headers".
     * @param {number} index Row index.
     * @param {string} key   Existing key.
     * @param {string} value Existing value.
     * @returns {HTMLElement}
     */
    function buildKvRow(name, index, key, value) {
        var row = document.createElement('div');
        row.className = 'cvm-kv-row';

        var keyInput = document.createElement('input');
        keyInput.type = 'text';
        keyInput.className = 'regular-text code cvm-kv-key';
        keyInput.name = name + '[' + index + '][key]';
        keyInput.placeholder = 'Key';
        keyInput.value = key || '';

        var valueInput = document.createElement('input');
        valueInput.type = 'text';
        valueInput.className = 'regular-text code cvm-kv-value';
        valueInput.name = name + '[' + index + '][value]';
        valueInput.placeholder = 'Value';
        valueInput.value = value || '';

        var removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'button cvm-kv-remove';
        removeBtn.textContent = 'Remove';
        removeBtn.setAttribute('aria-label', 'Remove this row');
        removeBtn.addEventListener('click', function () {
            row.remove();
        });

        row.appendChild(keyInput);
        row.appendChild(valueInput);
        row.appendChild(removeBtn);

        return row;
    }

    /** Wires every key/value builder container on the page. */
    function initKvBuilders(root) {
        (root || document).querySelectorAll('.cvm-kv-builder').forEach(function (builder) {
            if (builder.dataset.kvWired === '1') {
                return;
            }
            builder.dataset.kvWired = '1';

            var name = builder.dataset.kvName;
            var rows = builder.querySelector('.cvm-kv-rows');
            var addBtn = builder.querySelector('.cvm-kv-add');
            if (!name || !rows || !addBtn) {
                return;
            }

            rows.querySelectorAll('.cvm-kv-remove').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var row = btn.closest('.cvm-kv-row');
                    if (row) row.remove();
                });
            });

            addBtn.addEventListener('click', function () {
                var index = parseInt(builder.dataset.kvNext || String(rows.children.length), 10);
                builder.dataset.kvNext = String(index + 1);
                var row = buildKvRow(name, index, '', '');
                rows.appendChild(row);
                row.querySelector('.cvm-kv-key').focus();
            });
        });
    }

    /* ------------------------------------------------------------------ *
     *  Webhooks page — endpoint repeater + status toggle + tests
     * ------------------------------------------------------------------ */

    function endpointCount(container) {
        return container.querySelectorAll('.cvm-webhook-block').length;
    }

    function updateToggleCard(container) {
        var toggleCard = document.getElementById('cvm-webhook-toggle-card');
        if (!toggleCard || !container) {
            return;
        }

        var hasAny = false;
        container.querySelectorAll('.cvm-webhook-url-input').forEach(function (inp) {
            if (inp.value.trim() !== '') {
                hasAny = true;
            }
        });

        toggleCard.style.display = hasAny ? '' : 'none';
        var checkbox = toggleCard.querySelector('input[type="checkbox"]');
        if (checkbox) {
            checkbox.disabled = !hasAny;
        }
    }

    /**
     * Builds a new endpoint block for the given index.
     *
     * @param {number} index
     * @returns {HTMLElement}
     */
    function buildEndpointBlock(index) {
        var block = document.createElement('div');
        block.className = 'cvm-webhook-block';
        block.dataset.webhookIndex = index;

        block.innerHTML =
            '<div class="cvm-webhook-block-header">' +
                '<strong class="cvm-webhook-block-title">Endpoint ' + (index + 1) + '</strong>' +
                '<button type="button" class="button cvm-remove-webhook-btn" aria-label="Remove endpoint ' + (index + 1) + '">Remove</button>' +
            '</div>' +
            '<div class="cvm-webhook-url-row">' +
                '<input type="url" class="cvm-webhook-url-input regular-text code"' +
                    ' name="cvm_webhooks[' + index + '][url]"' +
                    ' placeholder="https://example.com/convermetry-hook"' +
                    ' aria-label="Endpoint ' + (index + 1) + ' URL">' +
            '</div>' +
            '<div class="cvm-webhook-field">' +
                '<input type="text" class="regular-text cvm-webhook-label-input"' +
                    ' name="cvm_webhooks[' + index + '][label]"' +
                    ' placeholder="Label (optional — shown in the Activity Log)"' +
                    ' aria-label="Endpoint ' + (index + 1) + ' label">' +
            '</div>' +
            '<div class="cvm-webhook-field">' +
                '<input type="text" class="regular-text code cvm-webhook-secret-input" autocomplete="off"' +
                    ' name="cvm_webhooks[' + index + '][secret]"' +
                    ' placeholder="Signing secret (optional — overrides the shared secret)"' +
                    ' aria-label="Endpoint ' + (index + 1) + ' signing secret">' +
            '</div>' +
            '<fieldset class="cvm-webhook-types">' +
                '<legend class="screen-reader-text">Delivery types for endpoint ' + (index + 1) + '</legend>' +
                '<label><input type="checkbox" name="cvm_webhooks[' + index + '][analytics]" value="1" checked> Analytics Reports</label> ' +
                '<label><input type="checkbox" name="cvm_webhooks[' + index + '][forms]" value="1" checked> Form Submissions</label>' +
            '</fieldset>' +
            '<div class="cvm-endpoint-tests">' +
                '<button type="button" class="button cvm-test-endpoint" data-type="analytics">Send analytics test</button> ' +
                '<button type="button" class="button cvm-test-endpoint" data-type="form">Send form test</button>' +
                '<span class="cvm-test-result" role="status" aria-live="polite"></span>' +
            '</div>';

        block.querySelector('.cvm-remove-webhook-btn').addEventListener('click', function () {
            var container = document.getElementById('cvm-webhooks-container');
            block.remove();
            reindexEndpointBlocks(container);
            updateToggleCard(container);
        });

        block.querySelector('.cvm-webhook-url-input').addEventListener('input', function () {
            updateToggleCard(document.getElementById('cvm-webhooks-container'));
        });

        wireTestButtons(block);

        return block;
    }

    /** Re-indexes name attributes and titles after a block is added or removed. */
    function reindexEndpointBlocks(container) {
        container.querySelectorAll('.cvm-webhook-block').forEach(function (block, idx) {
            block.dataset.webhookIndex = idx;

            var title = block.querySelector('.cvm-webhook-block-title');
            if (title) {
                title.textContent = 'Endpoint ' + (idx + 1);
            }

            // The hidden id must be reindexed alongside the visible fields.
            // Miss it and removing a block leaves each surviving endpoint's id
            // under its OLD index: the save then sees a URL with no id (and
            // mints a fresh one, orphaning that endpoint's delivery window and
            // retry chain) plus an id with no URL, which is dropped.
            [['id', '.cvm-webhook-id-input'], ['url', '.cvm-webhook-url-input'], ['label', '.cvm-webhook-label-input'], ['secret', '.cvm-webhook-secret-input']]
                .forEach(function (pair) {
                    var input = block.querySelector(pair[1]);
                    if (input) {
                        input.name = 'cvm_webhooks[' + idx + '][' + pair[0] + ']';
                    }
                });

            block.querySelectorAll('.cvm-webhook-types input[type="checkbox"]').forEach(function (checkbox) {
                var type = checkbox.name.indexOf('[analytics]') !== -1 ? 'analytics' : 'forms';
                checkbox.name = 'cvm_webhooks[' + idx + '][' + type + ']';
            });

            var removeBtn = block.querySelector('.cvm-remove-webhook-btn');
            if (removeBtn) {
                removeBtn.style.display = idx === 0 ? 'none' : '';
            }
        });
    }

    /** Wires an endpoint block's test buttons. */
    function wireTestButtons(block) {
        var result = block.querySelector('.cvm-test-result');

        block.querySelectorAll('.cvm-test-endpoint').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var urlInput = block.querySelector('.cvm-webhook-url-input');
                var url = urlInput ? urlInput.value.trim() : '';

                if (!url) {
                    if (result) result.textContent = 'Enter an endpoint URL first.';
                    return;
                }

                btn.disabled = true;
                if (result) result.textContent = 'Sending…';

                var fd = new FormData();
                fd.append('action', 'cvm_test_webhook');
                fd.append('nonce', cfg('testNonce'));
                fd.append('url', url);
                fd.append('type', btn.dataset.type || 'analytics');

                fetch(cfg('ajaxUrl'), { method: 'POST', body: fd })
                    .then(function (res) { return res.json(); })
                    .then(function (resp) {
                        btn.disabled = false;
                        if (!result) return;
                        if (resp.success) {
                            var d = resp.data || {};
                            result.textContent = (d.ok ? '✓ ' : '✗ ') + (d.message || '') + (d.code ? ' (HTTP ' + d.code + ')' : '');
                            result.className = 'cvm-test-result ' + (d.ok ? 'cvm-test-ok' : 'cvm-test-fail');
                        } else {
                            result.textContent = '✗ ' + ((resp.data && resp.data.message) || 'Test failed.');
                            result.className = 'cvm-test-result cvm-test-fail';
                        }
                    })
                    .catch(function () {
                        btn.disabled = false;
                        if (result) {
                            result.textContent = '✗ The test request could not be sent.';
                            result.className = 'cvm-test-result cvm-test-fail';
                        }
                    });
            });
        });
    }

    function initWebhooksPage() {
        var container = document.getElementById('cvm-webhooks-container');
        if (!container) {
            return;
        }

        container.querySelectorAll('.cvm-webhook-url-input').forEach(function (inp) {
            inp.addEventListener('input', function () {
                updateToggleCard(container);
            });
        });

        container.querySelectorAll('.cvm-remove-webhook-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                // Saving after this removal permanently discards the
                // endpoint's queued leads, so confirm when there are any.
                var pending = parseInt(btn.dataset.pending || '0', 10);
                if (pending > 0) {
                    var message = 'This endpoint has ' + pending + ' queued delivery' +
                        (pending === 1 ? '' : 'ies') +
                        ' still waiting to be sent.\n\nRemoving it and saving will discard ' +
                        (pending === 1 ? 'it' : 'them') + ' permanently. Continue?';
                    if (!window.confirm(message)) {
                        return;
                    }
                }

                var block = btn.closest('.cvm-webhook-block');
                if (block) {
                    block.remove();
                }
                reindexEndpointBlocks(container);
                updateToggleCard(container);
            });
        });

        container.querySelectorAll('.cvm-webhook-block').forEach(wireTestButtons);

        var addButton = document.getElementById('cvm-add-webhook');
        if (addButton) {
            addButton.addEventListener('click', function () {
                var block = buildEndpointBlock(endpointCount(container));
                container.appendChild(block);
                block.querySelector('.cvm-webhook-url-input').focus();
                updateToggleCard(container);
            });
        }

        var toggle = document.getElementById('cvm_webhook_active');
        var label  = document.getElementById('cvm-webhook-toggle-label');
        if (toggle && label) {
            toggle.addEventListener('change', function () {
                label.textContent = this.checked ? 'Active' : 'Inactive';
            });
        }

        updateToggleCard(container);
    }

    /* ------------------------------------------------------------------ *
     *  Forms page — provider/name/state filtering
     * ------------------------------------------------------------------ */

    function initFormsPage() {
        var list = document.getElementById('cvm-forms-list');
        if (!list) {
            return;
        }

        var search   = document.getElementById('cvm-form-search');
        var provider = document.getElementById('cvm-form-provider-filter');
        var state    = document.getElementById('cvm-form-state-filter');
        var countEl  = document.getElementById('cvm-form-filter-count');

        function applyFilters() {
            var term = search ? search.value.trim().toLowerCase() : '';
            var prov = provider ? provider.value : '';
            var st   = state ? state.value : '';
            var visible = 0;

            list.querySelectorAll('.cvm-form-block').forEach(function (block) {
                var matches = true;

                if (prov && block.dataset.provider !== prov) {
                    matches = false;
                }
                if (matches && st === 'included' && block.dataset.excluded === '1') {
                    matches = false;
                }
                if (matches && st === 'excluded' && block.dataset.excluded !== '1') {
                    matches = false;
                }
                if (matches && term !== '') {
                    var haystack = (block.dataset.name + ' ' + block.dataset.formId + ' ' + block.dataset.nativeId).toLowerCase();
                    if (haystack.indexOf(term) === -1) {
                        matches = false;
                    }
                }

                block.style.display = matches ? '' : 'none';
                if (matches) visible++;
            });

            if (countEl) {
                countEl.textContent = String(visible);
            }
        }

        [search, provider, state].forEach(function (control) {
            if (!control) return;
            control.addEventListener('input', applyFilters);
            control.addEventListener('change', applyFilters);
        });

        // Live state: flipping the Excluded checkbox updates the block's
        // badge and its filterable state immediately.
        list.querySelectorAll('.cvm-form-excluded-toggle').forEach(function (checkbox) {
            checkbox.addEventListener('change', function () {
                var block = checkbox.closest('.cvm-form-block');
                if (!block) return;
                block.dataset.excluded = checkbox.checked ? '1' : '0';
                var badge = block.querySelector('.cvm-form-state-badge');
                if (badge) {
                    badge.textContent = checkbox.checked ? 'Excluded' : 'Included';
                    badge.className = 'cvm-form-state-badge ' + (checkbox.checked ? 'is-excluded' : 'is-included');
                }
                applyFilters();
            });
        });

        // Custom form id edits update the filter haystack.
        list.querySelectorAll('.cvm-form-id-input').forEach(function (input) {
            input.addEventListener('input', function () {
                var block = input.closest('.cvm-form-block');
                if (block) {
                    block.dataset.formId = input.value;
                }
            });
        });

        applyFilters();
    }

    /* ------------------------------------------------------------------ *
     *  Boot
     * ------------------------------------------------------------------ */

    function init() {
        initKvBuilders(document);
        initWebhooksPage();
        initFormsPage();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
