/**
 * Convermetry — Activity Log page.
 *
 * Detailed webhook delivery visibility:
 * two lazy-loading accordions (successful / failed deliveries) with
 * year/month/endpoint filters, payload search, per-page selection, windowed
 * pagination, per-entry delete, and the Deliveries API toggle/key card.
 *
 * All data comes from the cvm_get_activity_logs (and sibling) AJAX actions;
 * configuration and nonces arrive via the CVM_LOG object localized by
 * ActivityLogPage.
 */
(function () {
    'use strict';

    /**
     * Safely escapes a string for insertion into HTML.
     *
     * @param {string} text
     * @returns {string}
     */
    function escapeHtml(text) {
        const node = document.createElement('span');
        node.appendChild(document.createTextNode(String(text)));
        return node.innerHTML;
    }

    function cfg(key) {
        return (typeof CVM_LOG !== 'undefined' && CVM_LOG[key]) ? CVM_LOG[key] : '';
    }

    // ── Accordions ────────────────────────────────────────────────────────────

    function initAccordions() {
        document.querySelectorAll('.cvm-accordion-header').forEach(function (header) {
            header.addEventListener('click', function () {
                const isExpanded = header.getAttribute('aria-expanded') === 'true';
                const bodyId     = header.getAttribute('aria-controls');
                const body       = bodyId ? document.getElementById(bodyId) : header.nextElementSibling;

                if (!body) return;

                header.setAttribute('aria-expanded', isExpanded ? 'false' : 'true');
                body.hidden = !body.hidden;
            });
        });
    }

    // ── Log lists (AJAX pagination) ───────────────────────────────────────────

    /**
     * Wires each accordion to load its log list on demand via the
     * cvm_get_activity_logs AJAX action. On first open, the controls bar and
     * list are injected and the first page is fetched; filter and page changes
     * trigger subsequent fetches. Delete buttons are handled via event
     * delegation and trigger a re-fetch after a successful delete.
     */
    function initLogLists() {
        document.querySelectorAll('.cvm-accordion').forEach(function (accordion) {
            const header = accordion.querySelector('.cvm-accordion-header');
            const body   = accordion.querySelector('.cvm-accordion-body');
            if (!header || !body) return;

            const status      = body.dataset.status || '';
            let initialized = false;
            let dirty       = false;
            const state       = {
                page: 1, perPage: 10, search: '', year: '', month: '',
                endpoint: '', messageType: '', provider: '', formName: ''
            };

            const controls = document.createElement('div');
            controls.className = 'cvm-acc-controls';
            controls.innerHTML = buildControlsHtml();
            body.appendChild(controls);

            const list = document.createElement('ul');
            list.className = 'cvm-log-list';
            body.appendChild(list);

            const paginationEl = document.createElement('div');
            paginationEl.className = 'cvm-pagination';
            body.appendChild(paginationEl);

            // ── Controls wiring ───────────────────────────────────────────────
            controls.querySelector('.cvm-filter-year').addEventListener('change', function () {
                state.year = this.value; state.page = 1; fetchLogs();
            });
            controls.querySelector('.cvm-filter-month').addEventListener('change', function () {
                state.month = this.value; state.page = 1; fetchLogs();
            });
            controls.querySelector('.cvm-filter-endpoint').addEventListener('change', function () {
                state.endpoint = this.value; state.page = 1; fetchLogs();
            });
            controls.querySelector('.cvm-filter-type').addEventListener('change', function () {
                state.messageType = this.value; state.page = 1; fetchLogs();
            });
            controls.querySelector('.cvm-filter-provider').addEventListener('change', function () {
                state.provider = this.value; state.page = 1; fetchLogs();
            });
            controls.querySelector('.cvm-filter-form').addEventListener('change', function () {
                state.formName = this.value; state.page = 1; fetchLogs();
            });
            controls.querySelector('.cvm-per-page').addEventListener('change', function () {
                state.perPage = parseInt(this.value, 10); state.page = 1; fetchLogs();
            });
            // Debounced: every keystroke would otherwise fire a LIKE query
            // over LONGTEXT payloads.
            let searchTimer = null;
            controls.querySelector('.cvm-search-input').addEventListener('input', function () {
                const value = this.value;
                clearTimeout(searchTimer);
                searchTimer = setTimeout(function () {
                    state.search = value; state.page = 1; fetchLogs();
                }, 300);
            });
            controls.querySelector('.cvm-search-clear').addEventListener('click', function () {
                clearTimeout(searchTimer);
                controls.querySelector('.cvm-search-input').value = '';
                state.search = ''; state.page = 1; fetchLogs();
            });

            // ── Fetch on accordion open ───────────────────────────────────────
            header.addEventListener('click', function () {
                const isOpen = header.getAttribute('aria-expanded') === 'true';
                if (isOpen && (!initialized || dirty)) {
                    fetchLogs();
                }
            });

            // ── Delete via event delegation ───────────────────────────────────
            list.addEventListener('click', function (e) {
                const btn = e.target.closest('.cvm-log-delete-btn');
                if (!btn) return;
                const li    = btn.closest('.cvm-log-item');
                const logId = li ? li.dataset.logId : null;
                if (!logId) return;

                if (!confirm('Delete this log entry? This cannot be undone.')) return;

                btn.disabled    = true;
                btn.textContent = '…';

                const fd = new FormData();
                fd.append('action', 'cvm_delete_activity_log');
                fd.append('nonce', cfg('deleteNonce'));
                fd.append('log_id', logId);

                fetch(cfg('ajaxUrl'), { method: 'POST', body: fd })
                    .then(function (res) { return res.json(); })
                    .then(function (response) {
                        if (response.success) {
                            const badge = accordion.querySelector('.cvm-accordion-header .cvm-badge');
                            if (badge) {
                                const count = parseInt(badge.textContent, 10);
                                if (!isNaN(count) && count > 0) badge.textContent = String(count - 1);
                            }
                            fetchLogs();
                            document.dispatchEvent(new CustomEvent('cvm:log-deleted'));
                        } else {
                            btn.disabled    = false;
                            btn.textContent = 'Delete';
                        }
                    })
                    .catch(function () {
                        btn.disabled    = false;
                        btn.textContent = 'Delete';
                    });
            });

            // Mark stale if the other accordion deletes an entry while closed.
            document.addEventListener('cvm:log-deleted', function () {
                dirty = true;
            });

            // ── Core fetch ────────────────────────────────────────────────────
            // Monotonic sequence: concurrent requests (rapid filter changes,
            // slow searches) can resolve out of order, and a stale response
            // must never overwrite a newer one.
            let fetchSeq = 0;

            function fetchLogs() {
                const seq = ++fetchSeq;

                list.innerHTML         = '<li class="cvm-empty-msg">Loading…</li>';
                paginationEl.innerHTML = '';

                const fd = new FormData();
                fd.append('action', 'cvm_get_activity_logs');
                fd.append('nonce', cfg('logsNonce'));
                fd.append('page', state.page);
                fd.append('per_page', state.perPage);
                fd.append('status', status);
                fd.append('search', state.search);
                fd.append('filter_year', state.year);
                fd.append('filter_month', state.month);
                fd.append('endpoint', state.endpoint);
                fd.append('message_type', state.messageType);
                fd.append('provider', state.provider);
                fd.append('form_name', state.formName);

                fetch(cfg('ajaxUrl'), { method: 'POST', body: fd })
                    .then(function (res) { return res.json(); })
                    .then(function (resp) {
                        if (seq !== fetchSeq) {
                            return; // A newer request superseded this one.
                        }
                        if (!resp.success) {
                            list.innerHTML = '<li class="cvm-empty-msg">Failed to load the delivery log.</li>';
                            return;
                        }
                        const data = resp.data;

                        if (!initialized) {
                            updateFilterOptions(controls, data.years || [], data.months || [], data.endpoints || []);
                            updateListOptions(controls, '.cvm-filter-provider', data.providers || [], 'All Providers');
                            updateListOptions(controls, '.cvm-filter-form', data.formNames || [], 'All Forms');
                            initialized = true;
                        } else {
                            updateEndpointOptions(controls, data.endpoints || []);
                            updateListOptions(controls, '.cvm-filter-provider', data.providers || [], 'All Providers');
                            updateListOptions(controls, '.cvm-filter-form', data.formNames || [], 'All Forms');
                        }
                        dirty = false;

                        list.innerHTML = data.html !== ''
                            ? data.html
                            : '<li class="cvm-empty-msg">' +
                              (status === 'error' ? 'No failed deliveries recorded.' : 'No successful deliveries recorded yet.') +
                              '</li>';

                        renderPagination(paginationEl, data.currentPage, data.totalPages, data.total, state.perPage, function (p) {
                            state.page = p;
                            fetchLogs();
                        });
                    })
                    .catch(function () {
                        if (seq === fetchSeq) {
                            list.innerHTML = '<li class="cvm-empty-msg">Failed to load the delivery log.</li>';
                        }
                    });
            }
        });
    }

    /**
     * Populates the year, month, and endpoint filter dropdowns.
     *
     * @param {HTMLElement} controls
     * @param {string[]}    years
     * @param {string[]}    months
     * @param {string[]}    endpoints
     */
    function updateFilterOptions(controls, years, months, endpoints) {
        const MONTH_NAMES = ['January', 'February', 'March', 'April', 'May', 'June',
                           'July', 'August', 'September', 'October', 'November', 'December'];

        const yearSelect  = controls.querySelector('.cvm-filter-year');
        const monthSelect = controls.querySelector('.cvm-filter-month');

        yearSelect.innerHTML = '<option value="">All Years</option>';
        years.forEach(function (y) {
            yearSelect.innerHTML += '<option value="' + escapeHtml(y) + '">' + escapeHtml(y) + '</option>';
        });

        monthSelect.innerHTML = '<option value="">All Months</option>';
        months.forEach(function (m) {
            const name = MONTH_NAMES[parseInt(m, 10) - 1] || m;
            monthSelect.innerHTML += '<option value="' + escapeHtml(m) + '">' + escapeHtml(name) + '</option>';
        });

        updateEndpointOptions(controls, endpoints);
    }

    /**
     * Refreshes just the endpoint select options, preserving the selection.
     * The filter stays hidden until more than one endpoint has log entries.
     *
     * @param {HTMLElement} controls
     * @param {string[]}    endpoints
     */
    function updateEndpointOptions(controls, endpoints) {
        const endpointSelect = controls.querySelector('.cvm-filter-endpoint');
        if (!endpointSelect) return;

        const currentVal = endpointSelect.value;

        endpointSelect.innerHTML = '<option value="">All Endpoints</option>';
        endpoints.forEach(function (url) {
            const opt = document.createElement('option');
            opt.value       = url;
            opt.textContent = url;
            if (url === currentVal) opt.selected = true;
            endpointSelect.appendChild(opt);
        });

        endpointSelect.parentElement.style.display = endpoints.length > 1 ? '' : 'none';
    }

    /**
     * Refreshes a simple value-list select (provider / form filters),
     * preserving the selection. Hidden until at least one value exists.
     *
     * @param {HTMLElement} controls
     * @param {string}      selector
     * @param {string[]}    values
     * @param {string}      allLabel
     */
    function updateListOptions(controls, selector, values, allLabel) {
        const select = controls.querySelector(selector);
        if (!select) return;

        const currentVal = select.value;

        select.innerHTML = '<option value="">' + escapeHtml(allLabel) + '</option>';
        values.forEach(function (value) {
            const opt = document.createElement('option');
            opt.value       = value;
            opt.textContent = value;
            if (value === currentVal) opt.selected = true;
            select.appendChild(opt);
        });

        select.parentElement.style.display = values.length > 0 ? '' : 'none';
    }

    /**
     * Returns the HTML string for the controls bar. Year/month/endpoint
     * options start empty; updateFilterOptions() fills them.
     *
     * @returns {string}
     */
    function buildControlsHtml() {
        return '<div class="cvm-acc-filters">' +
                   '<select class="cvm-filter-year"><option value="">All Years</option></select>' +
                   '<select class="cvm-filter-month"><option value="">All Months</option></select>' +
                   '<select class="cvm-filter-type">' +
                       '<option value="">All Types</option>' +
                       '<option value="analytics_report">Analytics Reports</option>' +
                       '<option value="form_submission">Form Submissions</option>' +
                   '</select>' +
                   '<span style="display:none"><select class="cvm-filter-endpoint"><option value="">All Endpoints</option></select></span>' +
                   '<span style="display:none"><select class="cvm-filter-provider"><option value="">All Providers</option></select></span>' +
                   '<span style="display:none"><select class="cvm-filter-form"><option value="">All Forms</option></select></span>' +
                   '<div class="cvm-acc-search">' +
                       '<input type="text" class="cvm-search-input" placeholder="Search payload…" />' +
                       '<button type="button" class="cvm-search-clear" aria-label="Clear search">✕</button>' +
                   '</div>' +
               '</div>' +
               '<div class="cvm-acc-perpage">' +
                   '<label>Per page: <select class="cvm-per-page">' +
                       '<option value="5">5</option>' +
                       '<option value="10" selected>10</option>' +
                       '<option value="25">25</option>' +
                       '<option value="50">50</option>' +
                       '<option value="100">100</option>' +
                   '</select></label>' +
               '</div>';
    }

    /**
     * Renders the pagination bar into the given container element.
     *
     * @param {HTMLElement} container
     * @param {number}      currentPage
     * @param {number}      totalPages
     * @param {number}      totalItems
     * @param {number}      perPage
     * @param {function}    onPageChange Called with the new page number.
     */
    function renderPagination(container, currentPage, totalPages, totalItems, perPage, onPageChange) {
        if (totalItems === 0) {
            container.innerHTML = '<span class="cvm-page-info">No results match the selected filter.</span>';
            return;
        }

        const start = (currentPage - 1) * perPage + 1;
        const end   = Math.min(currentPage * perPage, totalItems);

        let html = '<span class="cvm-page-info">Showing ' + start + '–' + end + ' of ' + totalItems + '</span>';

        if (totalPages > 1) {
            html += '<div class="cvm-page-buttons">';

            if (currentPage > 1) {
                html += '<button class="cvm-page-btn" data-page="' + (currentPage - 1) + '" aria-label="Previous page">&#8249;</button>';
            }

            getPageNumbers(currentPage, totalPages).forEach(function (p) {
                if (p === '...') {
                    html += '<span class="cvm-page-ellipsis">&#8230;</span>';
                } else {
                    const activeClass = p === currentPage ? ' cvm-page-btn-active' : '';
                    html += '<button class="cvm-page-btn' + activeClass + '" data-page="' + p + '" aria-label="Page ' + p + '">' + p + '</button>';
                }
            });

            if (currentPage < totalPages) {
                html += '<button class="cvm-page-btn" data-page="' + (currentPage + 1) + '" aria-label="Next page">&#8250;</button>';
            }

            html += '</div>';
        }

        container.innerHTML = html;

        container.querySelectorAll('.cvm-page-btn[data-page]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                onPageChange(parseInt(this.dataset.page, 10));
            });
        });
    }

    /**
     * Returns an array of page numbers (and '...' sentinels) for a windowed
     * page selector. Always shows first, last, and up to two neighbours of
     * the current page; inserts '...' for gaps larger than one.
     *
     * @param {number} currentPage
     * @param {number} totalPages
     * @returns {Array<number|string>}
     */
    function getPageNumbers(currentPage, totalPages) {
        if (totalPages <= 7) {
            return Array.from({ length: totalPages }, function (_, i) { return i + 1; });
        }

        const pages = [1];

        if (currentPage > 3) pages.push('...');

        const rangeStart = Math.max(2, currentPage - 1);
        const rangeEnd   = Math.min(totalPages - 1, currentPage + 1);

        for (let i = rangeStart; i <= rangeEnd; i++) {
            pages.push(i);
        }

        if (currentPage < totalPages - 2) pages.push('...');

        pages.push(totalPages);

        return pages;
    }

    // ── Deliveries API card ───────────────────────────────────────────────────

    /**
     * Wires up the Deliveries API toggle switch, copy-key button, and
     * regenerate-key button.
     *
     * The server stores only a hash of the key, so a raw key arrives at most
     * once — from the generate/toggle-on response. revealKey() swaps the
     * masked placeholder for that value and unhides Copy; on the next page
     * load the mask is back and the key is gone for good.
     */
    function initApiCard() {
        const toggle   = document.getElementById('cvm-delivery-api-toggle');
        const label    = document.getElementById('cvm-api-toggle-label');
        const section  = document.getElementById('cvm-api-key-section');
        const keyValue = document.getElementById('cvm-api-key-value');
        const copyBtn  = document.querySelector('.cvm-copy-key-btn');

        if (!toggle) return;

        /** Displays a freshly generated raw key (the one time it exists). */
        function revealKey(key) {
            if (!keyValue || !key) return;
            keyValue.textContent = key;
            keyValue.removeAttribute('data-masked');
            if (copyBtn) copyBtn.hidden = false;
        }

        toggle.addEventListener('change', function () {
            const active = toggle.checked;
            toggle.disabled = true;

            const fd = new FormData();
            fd.append('action', 'cvm_toggle_delivery_api');
            fd.append('nonce', cfg('apiToggleNonce'));
            fd.append('active', active ? '1' : '0');

            fetch(cfg('ajaxUrl'), { method: 'POST', body: fd })
                .then(function (res) { return res.json(); })
                .then(function (resp) {
                    toggle.disabled = false;
                    if (resp.success) {
                        if (label)   label.textContent = resp.data.active ? 'Active' : 'Inactive';
                        if (section) section.hidden    = !resp.data.active;
                        revealKey(resp.data.key);
                    } else {
                        toggle.checked = !active; // revert on failure
                    }
                })
                .catch(function () {
                    toggle.disabled = false;
                    toggle.checked  = !active;
                });
        });

        if (copyBtn && keyValue) {
            copyBtn.addEventListener('click', function () {
                if (keyValue.hasAttribute('data-masked')) return;
                const key = keyValue.textContent.trim();
                if (!key) return;

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(key).then(function () {
                        const orig = copyBtn.textContent;
                        copyBtn.textContent = 'Copied!';
                        setTimeout(function () { copyBtn.textContent = orig; }, 2000);
                    }).catch(function () { fallbackCopy(key, copyBtn); });
                } else {
                    fallbackCopy(key, copyBtn);
                }
            });
        }

        const regenBtn = document.querySelector('.cvm-regen-key-btn');
        if (regenBtn) {
            regenBtn.addEventListener('click', function () {
                if (!confirm('Regenerate the API key? Any existing integrations using the current key will stop working until updated. The new key is shown only once — copy it right away.')) return;

                regenBtn.disabled = true;

                const fd = new FormData();
                fd.append('action', 'cvm_regen_delivery_api_key');
                fd.append('nonce', cfg('apiRegenNonce'));

                fetch(cfg('ajaxUrl'), { method: 'POST', body: fd })
                    .then(function (res) { return res.json(); })
                    .then(function (resp) {
                        regenBtn.disabled = false;
                        if (resp.success) {
                            revealKey(resp.data.key);
                        }
                    })
                    .catch(function () {
                        regenBtn.disabled = false;
                    });
            });
        }
    }

    /**
     * Fallback clipboard copy using a temporary textarea.
     *
     * @param {string}      text
     * @param {HTMLElement} btn Button whose label is temporarily replaced.
     */
    function fallbackCopy(text, btn) {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.cssText = 'position:fixed;opacity:0;top:0;left:0;';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        try {
            document.execCommand('copy');
            const orig = btn.textContent;
            btn.textContent = 'Copied!';
            setTimeout(function () { btn.textContent = orig; }, 2000);
        } catch (_) {}
        document.body.removeChild(ta);
    }

    // ── Boot ─────────────────────────────────────────────────────────────────

    document.addEventListener('DOMContentLoaded', function () {
        initAccordions();
        initLogLists();
        initApiCard();
    });

})();
