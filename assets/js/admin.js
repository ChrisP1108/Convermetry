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

    const { __, sprintf } = wp.i18n;

    /** Escapes text for insertion into HTML markup or an attribute value. */
    function esc(text) {
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function cfg(key) {
        return (typeof CVMTRY_ADMIN !== 'undefined' && CVMTRY_ADMIN[key]) ? CVMTRY_ADMIN[key] : '';
    }

    /* ------------------------------------------------------------------ *
     *  Generic key/value pair builders
     * ------------------------------------------------------------------ */

    /**
     * Builds one key/value row for a builder container.
     *
     * @param {string} name  The field name prefix, e.g. "cvmtry_global_headers".
     * @param {number} index Row index.
     * @param {string} key   Existing key.
     * @param {string} value Existing value.
     * @returns {HTMLElement}
     */
    function buildKvRow(name, index, key, value) {
        const row = document.createElement('div');
        row.className = 'cvmtry-kv-row';

        const keyInput = document.createElement('input');
        keyInput.type = 'text';
        keyInput.className = 'regular-text code cvmtry-kv-key';
        keyInput.name = name + '[' + index + '][key]';
        keyInput.placeholder = __('Key', 'convermetry');
        keyInput.value = key || '';

        const valueInput = document.createElement('input');
        valueInput.type = 'text';
        valueInput.className = 'regular-text code cvmtry-kv-value';
        valueInput.name = name + '[' + index + '][value]';
        valueInput.placeholder = __('Value', 'convermetry');
        valueInput.value = value || '';

        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'button cvmtry-kv-remove';
        removeBtn.textContent = __('Remove', 'convermetry');
        removeBtn.setAttribute('aria-label', __('Remove this row', 'convermetry'));
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
        (root || document).querySelectorAll('.cvmtry-kv-builder').forEach(function (builder) {
            if (builder.dataset.kvWired === '1') {
                return;
            }
            builder.dataset.kvWired = '1';

            const name = builder.dataset.kvName;
            const rows = builder.querySelector('.cvmtry-kv-rows');
            const addBtn = builder.querySelector('.cvmtry-kv-add');
            if (!name || !rows || !addBtn) {
                return;
            }

            rows.querySelectorAll('.cvmtry-kv-remove').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const row = btn.closest('.cvmtry-kv-row');
                    if (row) row.remove();
                });
            });

            addBtn.addEventListener('click', function () {
                const index = parseInt(builder.dataset.kvNext || String(rows.children.length), 10);
                builder.dataset.kvNext = String(index + 1);
                const row = buildKvRow(name, index, '', '');
                rows.appendChild(row);
                row.querySelector('.cvmtry-kv-key').focus();
            });
        });
    }

    /* ------------------------------------------------------------------ *
     *  Webhooks page — endpoint repeater + status toggle + tests
     * ------------------------------------------------------------------ */

    function endpointCount(container) {
        return container.querySelectorAll('.cvmtry-webhook-block').length;
    }

    function updateToggleCard(container) {
        const toggleCard = document.getElementById('cvmtry-webhook-toggle-card');
        if (!toggleCard || !container) {
            return;
        }

        let hasAny = false;
        container.querySelectorAll('.cvmtry-webhook-url-input').forEach(function (inp) {
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
        block.className = 'cvmtry-webhook-block';
        block.dataset.webhookIndex = index;

        const n = index + 1;

        // Every translated string is escaped on the way into innerHTML: a
        // translation is text, and may contain a quote or an angle bracket.
        block.innerHTML =
            '<div class="cvmtry-webhook-block-header">' +
                /* translators: %d: the endpoint's position in the list. */
                '<strong class="cvmtry-webhook-block-title">' + esc(sprintf(__('Endpoint %d', 'convermetry'), n)) + '</strong>' +
                /* translators: %d: the endpoint's position in the list. */
                '<button type="button" class="button cvmtry-remove-webhook-btn" aria-label="' + esc(sprintf(__('Remove endpoint %d', 'convermetry'), n)) + '">' +
                    esc(__('Remove', 'convermetry')) + '</button>' +
            '</div>' +
            '<div class="cvmtry-webhook-url-row">' +
                '<input type="url" class="cvmtry-webhook-url-input regular-text code"' +
                    ' name="cvmtry_webhooks[' + index + '][url]"' +
                    ' placeholder="https://example.com/convermetry-hook"' +
                    /* translators: %d: the endpoint's position in the list. */
                    ' aria-label="' + esc(sprintf(__('Endpoint %d URL', 'convermetry'), n)) + '">' +
            '</div>' +
            '<div class="cvmtry-webhook-field">' +
                '<input type="text" class="regular-text cvmtry-webhook-label-input"' +
                    ' name="cvmtry_webhooks[' + index + '][label]"' +
                    ' placeholder="' + esc(__('Label (optional — shown in the Activity Log)', 'convermetry')) + '"' +
                    /* translators: %d: the endpoint's position in the list. */
                    ' aria-label="' + esc(sprintf(__('Endpoint %d label', 'convermetry'), n)) + '">' +
            '</div>' +
            '<div class="cvmtry-webhook-field">' +
                '<input type="text" class="regular-text code cvmtry-webhook-secret-input" autocomplete="off"' +
                    ' name="cvmtry_webhooks[' + index + '][secret]"' +
                    ' placeholder="' + esc(__('Signing secret (optional — overrides the shared secret)', 'convermetry')) + '"' +
                    /* translators: %d: the endpoint's position in the list. */
                    ' aria-label="' + esc(sprintf(__('Endpoint %d signing secret', 'convermetry'), n)) + '">' +
            '</div>' +
            '<fieldset class="cvmtry-webhook-types">' +
                /* translators: %d: the endpoint's position in the list. */
                '<legend class="screen-reader-text">' + esc(sprintf(__('Delivery types for endpoint %d', 'convermetry'), n)) + '</legend>' +
                '<label><input type="checkbox" name="cvmtry_webhooks[' + index + '][analytics]" value="1" checked> ' +
                    esc(__('Analytics Reports', 'convermetry')) + '</label> ' +
                '<label><input type="checkbox" name="cvmtry_webhooks[' + index + '][forms]" value="1" checked> ' +
                    esc(__('Form Submissions', 'convermetry')) + '</label>' +
            '</fieldset>' +
            '<div class="cvmtry-endpoint-tests">' +
                '<button type="button" class="button cvmtry-test-endpoint" data-type="analytics">' + esc(__('Send analytics test', 'convermetry')) + '</button> ' +
                '<button type="button" class="button cvmtry-test-endpoint" data-type="form">' + esc(__('Send form test', 'convermetry')) + '</button>' +
                '<span class="cvmtry-test-result" role="status" aria-live="polite"></span>' +
            '</div>';

        block.querySelector('.cvmtry-remove-webhook-btn').addEventListener('click', function () {
            const container = document.getElementById('cvmtry-webhooks-container');
            block.remove();
            reindexEndpointBlocks(container);
            updateToggleCard(container);
        });

        block.querySelector('.cvmtry-webhook-url-input').addEventListener('input', function () {
            updateToggleCard(document.getElementById('cvmtry-webhooks-container'));
        });

        wireTestButtons(block);

        return block;
    }

    /** Re-indexes name attributes and titles after a block is added or removed. */
    function reindexEndpointBlocks(container) {
        container.querySelectorAll('.cvmtry-webhook-block').forEach(function (block, idx) {
            block.dataset.webhookIndex = idx;

            const title = block.querySelector('.cvmtry-webhook-block-title');
            if (title) {
                /* translators: %d: the endpoint's position in the list. */
                title.textContent = sprintf(__('Endpoint %d', 'convermetry'), idx + 1);
            }

            // The hidden id input is renamed with the rest of its block. Left
            // behind, removing a block above it paired this endpoint's URL with
            // a neighbour's index and posted it without its id, so the save
            // minted a new one and stranded the retry state keyed by the old.
            [['id', '.cvmtry-webhook-id-input'], ['url', '.cvmtry-webhook-url-input'], ['label', '.cvmtry-webhook-label-input'], ['secret', '.cvmtry-webhook-secret-input']]
                .forEach(function (pair) {
                    const input = block.querySelector(pair[1]);
                    if (input) {
                        input.name = 'cvmtry_webhooks[' + idx + '][' + pair[0] + ']';
                    }
                });

            block.querySelectorAll('.cvmtry-webhook-types input[type="checkbox"]').forEach(function (checkbox) {
                const type = checkbox.name.indexOf('[analytics]') !== -1 ? 'analytics' : 'forms';
                checkbox.name = 'cvmtry_webhooks[' + idx + '][' + type + ']';
            });

            const removeBtn = block.querySelector('.cvmtry-remove-webhook-btn');
            if (removeBtn) {
                removeBtn.style.display = idx === 0 ? 'none' : '';
            }
        });
    }

    /** Wires an endpoint block's test buttons. */
    function wireTestButtons(block) {
        const result = block.querySelector('.cvmtry-test-result');

        block.querySelectorAll('.cvmtry-test-endpoint').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const urlInput = block.querySelector('.cvmtry-webhook-url-input');
                const url = urlInput ? urlInput.value.trim() : '';

                if (!url) {
                    if (result) result.textContent = __('Enter an endpoint URL first.', 'convermetry');
                    return;
                }

                btn.disabled = true;
                if (result) result.textContent = __('Sending…', 'convermetry');

                const fd = new FormData();
                fd.append('action', 'cvmtry_test_webhook');
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
                            result.textContent = (d.ok ? '✓ ' : '✗ ') + (d.message || '') +
                                /* translators: %d: HTTP response status code. */
                                (d.code ? ' ' + sprintf(__('(HTTP %d)', 'convermetry'), d.code) : '');
                            result.className = 'cvmtry-test-result ' + (d.ok ? 'cvmtry-test-ok' : 'cvmtry-test-fail');
                        } else {
                            result.textContent = '✗ ' + ((resp.data && resp.data.message) || __('Test failed.', 'convermetry'));
                            result.className = 'cvmtry-test-result cvmtry-test-fail';
                        }
                    })
                    .catch(function () {
                        btn.disabled = false;
                        if (result) {
                            result.textContent = '✗ ' + __('The test request could not be sent.', 'convermetry');
                            result.className = 'cvmtry-test-result cvmtry-test-fail';
                        }
                    });
            });
        });
    }

    function initWebhooksPage() {
        const container = document.getElementById('cvmtry-webhooks-container');
        if (!container) {
            return;
        }

        container.querySelectorAll('.cvmtry-webhook-url-input').forEach(function (inp) {
            inp.addEventListener('input', function () {
                updateToggleCard(container);
            });
        });

        container.querySelectorAll('.cvmtry-remove-webhook-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const block = btn.closest('.cvmtry-webhook-block');
                if (block) {
                    block.remove();
                }
                reindexEndpointBlocks(container);
                updateToggleCard(container);
            });
        });

        container.querySelectorAll('.cvmtry-webhook-block').forEach(wireTestButtons);

        const addButton = document.getElementById('cvmtry-add-webhook');
        if (addButton) {
            addButton.addEventListener('click', function () {
                const block = buildEndpointBlock(endpointCount(container));
                container.appendChild(block);
                block.querySelector('.cvmtry-webhook-url-input').focus();
                updateToggleCard(container);
            });
        }

        const toggle = document.getElementById('cvmtry_webhook_active');
        const label  = document.getElementById('cvmtry-webhook-toggle-label');
        if (toggle && label) {
            toggle.addEventListener('change', function () {
                label.textContent = this.checked ? __('Active', 'convermetry') : __('Inactive', 'convermetry');
            });
        }

        updateToggleCard(container);
    }

    /* ------------------------------------------------------------------ *
     *  Forms page — provider/name/state filtering
     * ------------------------------------------------------------------ */

    function initFormsPage() {
        const list = document.getElementById('cvmtry-forms-list');
        if (!list) {
            return;
        }

        const search   = document.getElementById('cvmtry-form-search');
        const provider = document.getElementById('cvmtry-form-provider-filter');
        const state    = document.getElementById('cvmtry-form-state-filter');
        const countEl  = document.getElementById('cvmtry-form-filter-count');

        function applyFilters() {
            const term = search ? search.value.trim().toLowerCase() : '';
            const prov = provider ? provider.value : '';
            const st   = state ? state.value : '';
            let visible = 0;

            list.querySelectorAll('.cvmtry-form-block').forEach(function (block) {
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
        list.querySelectorAll('.cvmtry-form-excluded-toggle').forEach(function (checkbox) {
            checkbox.addEventListener('change', function () {
                const block = checkbox.closest('.cvmtry-form-block');
                if (!block) return;
                block.dataset.excluded = checkbox.checked ? '1' : '0';
                const badge = block.querySelector('.cvmtry-form-state-badge');
                if (badge) {
                    badge.textContent = checkbox.checked ? __('Excluded', 'convermetry') : __('Included', 'convermetry');
                    badge.className = 'cvmtry-form-state-badge ' + (checkbox.checked ? 'is-excluded' : 'is-included');
                }
                applyFilters();
            });
        });

        // Custom form id edits update the filter haystack.
        list.querySelectorAll('.cvmtry-form-id-input').forEach(function (input) {
            input.addEventListener('input', function () {
                const block = input.closest('.cvmtry-form-block');
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
        const btn = document.querySelector('.cvmtry-test-notification');
        if (!btn || typeof CVMTRY_NOTIFY === 'undefined') return;

        const input = document.getElementById('cvmtry-notify-test-address');
        const result = btn.parentElement
            ? btn.parentElement.querySelector('.cvmtry-test-result')
            : null;

        btn.addEventListener('click', function () {
            const recipient = input ? input.value.trim() : '';

            if (!recipient) {
                if (result) {
                    result.textContent = __('Enter a recipient address first.', 'convermetry');
                    result.className = 'cvmtry-test-result cvmtry-test-fail';
                }
                return;
            }

            btn.disabled = true;
            if (result) {
                result.textContent = __('Sending…', 'convermetry');
                result.className = 'cvmtry-test-result';
            }

            const fd = new FormData();
            fd.append('action', 'cvmtry_test_notification');
            fd.append('nonce', CVMTRY_NOTIFY.testNonce || '');
            fd.append('recipient', recipient);

            fetch(CVMTRY_NOTIFY.ajaxUrl, { method: 'POST', body: fd })
                .then(function (res) { return res.json(); })
                .then(function (resp) {
                    btn.disabled = false;
                    if (!result) return;
                    const d = (resp && resp.data) || {};
                    const ok = resp && resp.success && d.ok;
                    result.textContent = (ok ? '✓ ' : '✗ ') + (d.message || __('Test failed.', 'convermetry'));
                    result.className = 'cvmtry-test-result ' + (ok ? 'cvmtry-test-ok' : 'cvmtry-test-fail');
                })
                .catch(function () {
                    btn.disabled = false;
                    if (result) {
                        result.textContent = '✗ ' + __('The test request could not be sent.', 'convermetry');
                        result.className = 'cvmtry-test-result cvmtry-test-fail';
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
