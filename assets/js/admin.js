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
        const row = document.createElement('div');
        row.className = 'cvm-kv-row';

        const keyInput = document.createElement('input');
        keyInput.type = 'text';
        keyInput.className = 'regular-text code cvm-kv-key';
        keyInput.name = name + '[' + index + '][key]';
        keyInput.placeholder = 'Key';
        keyInput.value = key || '';

        const valueInput = document.createElement('input');
        valueInput.type = 'text';
        valueInput.className = 'regular-text code cvm-kv-value';
        valueInput.name = name + '[' + index + '][value]';
        valueInput.placeholder = 'Value';
        valueInput.value = value || '';

        const removeBtn = document.createElement('button');
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

            const name = builder.dataset.kvName;
            const rows = builder.querySelector('.cvm-kv-rows');
            const addBtn = builder.querySelector('.cvm-kv-add');
            if (!name || !rows || !addBtn) {
                return;
            }

            rows.querySelectorAll('.cvm-kv-remove').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const row = btn.closest('.cvm-kv-row');
                    if (row) row.remove();
                });
            });

            addBtn.addEventListener('click', function () {
                const index = parseInt(builder.dataset.kvNext || String(rows.children.length), 10);
                builder.dataset.kvNext = String(index + 1);
                const row = buildKvRow(name, index, '', '');
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
        const toggleCard = document.getElementById('cvm-webhook-toggle-card');
        if (!toggleCard || !container) {
            return;
        }

        let hasAny = false;
        container.querySelectorAll('.cvm-webhook-url-input').forEach(function (inp) {
            if (inp.value.trim() !== '') {
                hasAny = true;
            }
        });

        toggleCard.style.display = hasAny ? '' : 'none';
        const checkbox = toggleCard.querySelector('input[type="checkbox"]');
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
        const block = document.createElement('div');
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
            const container = document.getElementById('cvm-webhooks-container');
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

            const title = block.querySelector('.cvm-webhook-block-title');
            if (title) {
                title.textContent = 'Endpoint ' + (idx + 1);
            }

            [['url', '.cvm-webhook-url-input'], ['label', '.cvm-webhook-label-input'], ['secret', '.cvm-webhook-secret-input']]
                .forEach(function (pair) {
                    const input = block.querySelector(pair[1]);
                    if (input) {
                        input.name = 'cvm_webhooks[' + idx + '][' + pair[0] + ']';
                    }
                });

            block.querySelectorAll('.cvm-webhook-types input[type="checkbox"]').forEach(function (checkbox) {
                const type = checkbox.name.indexOf('[analytics]') !== -1 ? 'analytics' : 'forms';
                checkbox.name = 'cvm_webhooks[' + idx + '][' + type + ']';
            });

            const removeBtn = block.querySelector('.cvm-remove-webhook-btn');
            if (removeBtn) {
                removeBtn.style.display = idx === 0 ? 'none' : '';
            }
        });
    }

    /** Wires an endpoint block's test buttons. */
    function wireTestButtons(block) {
        const result = block.querySelector('.cvm-test-result');

        block.querySelectorAll('.cvm-test-endpoint').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const urlInput = block.querySelector('.cvm-webhook-url-input');
                const url = urlInput ? urlInput.value.trim() : '';

                if (!url) {
                    if (result) result.textContent = 'Enter an endpoint URL first.';
                    return;
                }

                btn.disabled = true;
                if (result) result.textContent = 'Sending…';

                const fd = new FormData();
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
                            const d = resp.data || {};
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
        const container = document.getElementById('cvm-webhooks-container');
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
                const block = btn.closest('.cvm-webhook-block');
                if (block) {
                    block.remove();
                }
                reindexEndpointBlocks(container);
                updateToggleCard(container);
            });
        });

        container.querySelectorAll('.cvm-webhook-block').forEach(wireTestButtons);

        const addButton = document.getElementById('cvm-add-webhook');
        if (addButton) {
            addButton.addEventListener('click', function () {
                const block = buildEndpointBlock(endpointCount(container));
                container.appendChild(block);
                block.querySelector('.cvm-webhook-url-input').focus();
                updateToggleCard(container);
            });
        }

        const toggle = document.getElementById('cvm_webhook_active');
        const label  = document.getElementById('cvm-webhook-toggle-label');
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
        const list = document.getElementById('cvm-forms-list');
        if (!list) {
            return;
        }

        const search   = document.getElementById('cvm-form-search');
        const provider = document.getElementById('cvm-form-provider-filter');
        const state    = document.getElementById('cvm-form-state-filter');
        const countEl  = document.getElementById('cvm-form-filter-count');

        function applyFilters() {
            const term = search ? search.value.trim().toLowerCase() : '';
            const prov = provider ? provider.value : '';
            const st   = state ? state.value : '';
            let visible = 0;

            list.querySelectorAll('.cvm-form-block').forEach(function (block) {
                let matches = true;

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
                    const haystack = (block.dataset.name + ' ' + block.dataset.formId + ' ' + block.dataset.nativeId).toLowerCase();
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
                const block = checkbox.closest('.cvm-form-block');
                if (!block) return;
                block.dataset.excluded = checkbox.checked ? '1' : '0';
                const badge = block.querySelector('.cvm-form-state-badge');
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
                const block = input.closest('.cvm-form-block');
                if (block) {
                    block.dataset.formId = input.value;
                }
            });
        });

        applyFilters();
    }

    /* ------------------------------------------------------------------ *
     *  Notifications page
     * ------------------------------------------------------------------ */

    /**
     * Wires the "Send test email" button.
     *
     * The message is built server-side entirely from synthetic data, so this
     * never causes a real lead to be emailed. Note the wording on success:
     * wp_mail() accepting a message is not proof it reached an inbox.
     */
    function initNotificationsPage() {
        const btn = document.querySelector('.cvm-test-notification');
        if (!btn || typeof CVM_NOTIFY === 'undefined') return;

        const input = document.getElementById('cvm-notify-test-address');
        const result = btn.parentElement
            ? btn.parentElement.querySelector('.cvm-test-result')
            : null;

        btn.addEventListener('click', function () {
            const recipient = input ? input.value.trim() : '';

            if (!recipient) {
                if (result) {
                    result.textContent = 'Enter a recipient address first.';
                    result.className = 'cvm-test-result cvm-test-fail';
                }
                return;
            }

            btn.disabled = true;
            if (result) {
                result.textContent = 'Sending…';
                result.className = 'cvm-test-result';
            }

            const fd = new FormData();
            fd.append('action', 'cvm_test_notification');
            fd.append('nonce', CVM_NOTIFY.testNonce || '');
            fd.append('recipient', recipient);

            fetch(CVM_NOTIFY.ajaxUrl, { method: 'POST', body: fd })
                .then(function (res) { return res.json(); })
                .then(function (resp) {
                    btn.disabled = false;
                    if (!result) return;
                    const d = (resp && resp.data) || {};
                    const ok = resp && resp.success && d.ok;
                    result.textContent = (ok ? '✓ ' : '✗ ') + (d.message || 'Test failed.');
                    result.className = 'cvm-test-result ' + (ok ? 'cvm-test-ok' : 'cvm-test-fail');
                })
                .catch(function () {
                    btn.disabled = false;
                    if (result) {
                        result.textContent = '✗ The test request could not be sent.';
                        result.className = 'cvm-test-result cvm-test-fail';
                    }
                });
        });
    }

    /* ------------------------------------------------------------------ *
     *  Boot
     * ------------------------------------------------------------------ */

    function init() {
        initKvBuilders(document);
        initWebhooksPage();
        initFormsPage();
        initNotificationsPage();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
