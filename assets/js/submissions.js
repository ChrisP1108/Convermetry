/**
 * Convermetry — Submissions page.
 *
 * A paginated, filterable list of server-confirmed form submissions. Each row
 * is its own accordion: the collapsed header carries the at-a-glance columns,
 * and the detail panel (form identity, attribution, visitor journey, the
 * visitor's field values, and per-endpoint delivery results) is fetched the
 * first time the row is expanded and cached on the element after that.
 *
 * All data comes from the cvm_get_submissions / cvm_get_submission_detail
 * AJAX actions; configuration and nonces arrive via the CVM_SUB object
 * localized by SubmissionsPage.
 */
(function () {
    'use strict';

    const MONTH_NAMES = ['January', 'February', 'March', 'April', 'May', 'June',
                       'July', 'August', 'September', 'October', 'November', 'December'];

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
        return (typeof CVM_SUB !== 'undefined' && CVM_SUB[key]) ? CVM_SUB[key] : '';
    }

    // ── Boot ─────────────────────────────────────────────────────────────────

    function initSubmissions() {
        const root = document.getElementById('cvm-submissions');
        if (!root) return;

        const state = {
            page: 1, perPage: 10, search: '', year: '', month: '',
            provider: '', formName: '', channel: '', campaign: '', status: ''
        };

        let initialized = false;

        const controls = document.createElement('div');
        controls.className = 'cvm-acc-controls';
        controls.innerHTML = buildControlsHtml();
        root.appendChild(controls);

        // Column headings for sighted users. Hidden from assistive tech: this
        // is a list, not a table, so a floating header row would be read as
        // stray text — each row's button carries its own full aria-label.
        const heading = document.createElement('div');
        heading.className = 'cvm-submission-heading';
        heading.setAttribute('aria-hidden', 'true');
        heading.innerHTML =
            '<span>Date</span><span>Visitor / Lead</span><span>Form</span>' +
            '<span>Page</span><span>Source</span><span>Campaign</span>' +
            '<span>Delivery</span><span></span>';
        root.appendChild(heading);

        const list = document.createElement('ul');
        list.className = 'cvm-submission-list';
        root.appendChild(list);

        const paginationEl = document.createElement('div');
        paginationEl.className = 'cvm-pagination';
        root.appendChild(paginationEl);

        // ── Controls wiring ──────────────────────────────────────────────────
        const simpleFilters = [
            ['.cvm-filter-year', 'year'],
            ['.cvm-filter-month', 'month'],
            ['.cvm-filter-provider', 'provider'],
            ['.cvm-filter-form', 'formName'],
            ['.cvm-filter-channel', 'channel'],
            ['.cvm-filter-campaign', 'campaign'],
            ['.cvm-filter-status', 'status']
        ];

        simpleFilters.forEach(function (pair) {
            const el = controls.querySelector(pair[0]);
            if (!el) return;
            el.addEventListener('change', function () {
                state[pair[1]] = this.value;
                state.page = 1;
                fetchSubmissions();
            });
        });

        controls.querySelector('.cvm-per-page').addEventListener('change', function () {
            state.perPage = parseInt(this.value, 10);
            state.page = 1;
            fetchSubmissions();
        });

        // Debounced: every keystroke would otherwise fire a LIKE query over the
        // LONGTEXT submission_data column.
        let searchTimer = null;
        controls.querySelector('.cvm-search-input').addEventListener('input', function () {
            const value = this.value;
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function () {
                state.search = value;
                state.page = 1;
                fetchSubmissions();
            }, 300);
        });
        controls.querySelector('.cvm-search-clear').addEventListener('click', function () {
            clearTimeout(searchTimer);
            controls.querySelector('.cvm-search-input').value = '';
            state.search = '';
            state.page = 1;
            fetchSubmissions();
        });

        // ── Accordion expand (lazy detail load) ──────────────────────────────
        list.addEventListener('click', function (e) {
            const header = e.target.closest('.cvm-submission-summary');
            if (!header || !list.contains(header)) return;

            const bodyId = header.getAttribute('aria-controls');
            const body   = bodyId ? document.getElementById(bodyId) : null;
            if (!body) return;

            const isExpanded = header.getAttribute('aria-expanded') === 'true';
            header.setAttribute('aria-expanded', isExpanded ? 'false' : 'true');
            body.hidden = isExpanded;

            if (!isExpanded && body.dataset.loaded !== '1') {
                loadDetail(header.closest('.cvm-submission-item'), body);
            }
        });

        // ── Delete (inside an expanded detail panel) ─────────────────────────
        list.addEventListener('click', function (e) {
            const btn = e.target.closest('.cvm-submission-delete-btn');
            if (!btn) return;

            const item  = btn.closest('.cvm-submission-item');
            const rowId = item ? item.dataset.rowId : null;
            if (!rowId) return;

            if (!confirm('Delete this submission? The lead data it holds is removed permanently and cannot be recovered.')) return;

            btn.disabled    = true;
            btn.textContent = 'Deleting…';

            const fd = new FormData();
            fd.append('action', 'cvm_delete_submission');
            fd.append('nonce', cfg('deleteNonce'));
            fd.append('submission_row', rowId);

            fetch(cfg('ajaxUrl'), { method: 'POST', body: fd })
                .then(function (res) { return res.json(); })
                .then(function (resp) {
                    if (resp.success) {
                        fetchSubmissions();
                    } else {
                        failDelete(btn, (resp.data && resp.data.message) || 'The submission could not be deleted.');
                    }
                })
                .catch(function () {
                    failDelete(btn, 'The submission could not be deleted.');
                });
        });

        /**
         * Restores a delete button and says why it failed. Silently re-enabling
         * it looked identical to "nothing happened", which is the worst thing a
         * destructive action can do.
         *
         * @param {HTMLElement} btn
         * @param {string}      message
         */
        function failDelete(btn, message) {
            btn.disabled    = false;
            btn.textContent = 'Delete Submission';

            const actions = btn.parentElement;
            if (!actions) return;

            let note = actions.querySelector('.cvm-delete-error');
            if (!note) {
                note = document.createElement('span');
                note.className = 'cvm-delete-error';
                note.setAttribute('role', 'alert');
                actions.insertBefore(note, btn);
            }
            note.textContent = message;
        }

        /**
         * Fetches one submission's detail panel, once per row.
         *
         * @param {HTMLElement} item
         * @param {HTMLElement} body
         */
        function loadDetail(item, body) {
            body.innerHTML = '<p class="cvm-empty-msg">Loading…</p>';

            const fd = new FormData();
            fd.append('action', 'cvm_get_submission_detail');
            fd.append('nonce', cfg('detailNonce'));
            fd.append('submission_row', item.dataset.rowId);

            fetch(cfg('ajaxUrl'), { method: 'POST', body: fd })
                .then(function (res) { return res.json(); })
                .then(function (resp) {
                    if (resp.success) {
                        body.innerHTML = resp.data.html;
                        body.dataset.loaded = '1';
                    } else {
                        body.innerHTML = '<p class="cvm-empty-msg">' +
                            escapeHtml((resp.data && resp.data.message) || 'This submission could not be loaded.') +
                            '</p>';
                    }
                })
                .catch(function () {
                    body.innerHTML = '<p class="cvm-empty-msg">This submission could not be loaded.</p>';
                });
        }

        /**
         * Keeps the "Export Current Filters" link pointing at exactly what is
         * on screen.
         */
        function syncExportLink() {
            const link = document.querySelector('.cvm-export-filtered');
            const base = cfg('exportBase');
            if (!link || !base) return;

            const params = new URLSearchParams({
                filter_year: state.year,
                filter_month: state.month,
                provider: state.provider,
                form_name: state.formName,
                channel: state.channel,
                campaign: state.campaign,
                search: state.search,
                delivery_status: state.status
            });

            // Drop empty values so the link stays readable.
            Array.from(params.keys()).forEach(function (key) {
                if (params.get(key) === '') params.delete(key);
            });

            const query = params.toString();
            link.href = query === '' ? base : base + '&' + query;
        }

        // ── Core fetch ───────────────────────────────────────────────────────
        // Monotonic sequence: concurrent requests (rapid filter changes, slow
        // searches) can resolve out of order, and a stale response must never
        // overwrite a newer one.
        let fetchSeq = 0;

        function fetchSubmissions() {
            const seq = ++fetchSeq;

            list.innerHTML         = '<li class="cvm-empty-msg">Loading…</li>';
            paginationEl.innerHTML = '';
            syncExportLink();

            const fd = new FormData();
            fd.append('action', 'cvm_get_submissions');
            fd.append('nonce', cfg('listNonce'));
            fd.append('page', state.page);
            fd.append('per_page', state.perPage);
            fd.append('search', state.search);
            fd.append('filter_year', state.year);
            fd.append('filter_month', state.month);
            fd.append('provider', state.provider);
            fd.append('form_name', state.formName);
            fd.append('channel', state.channel);
            fd.append('campaign', state.campaign);
            fd.append('delivery_status', state.status);

            fetch(cfg('ajaxUrl'), { method: 'POST', body: fd })
                .then(function (res) { return res.json(); })
                .then(function (resp) {
                    if (seq !== fetchSeq) {
                        return; // A newer request superseded this one.
                    }
                    if (!resp.success) {
                        list.innerHTML = '<li class="cvm-empty-msg">Failed to load submissions.</li>';
                        return;
                    }
                    const data = resp.data;

                    // The server clamps the requested page into range, so
                    // adopt what it actually answered with — otherwise a page
                    // that fell off the end (last row deleted, filter
                    // narrowed) would stay in state and every later request
                    // would re-ask for it.
                    if (typeof data.currentPage === 'number') {
                        state.page = data.currentPage;
                    }

                    if (!initialized) {
                        updateDateOptions(controls, data.years || [], data.months || []);
                        initialized = true;
                    }
                    updateListOptions(controls, '.cvm-filter-provider', data.providers || [], 'All Providers');
                    updateListOptions(controls, '.cvm-filter-form', data.formNames || [], 'All Forms');
                    updateListOptions(controls, '.cvm-filter-channel', data.channels || [], 'All Channels');
                    updateListOptions(controls, '.cvm-filter-campaign', data.campaigns || [], 'All Campaigns');

                    list.innerHTML = data.html !== ''
                        ? data.html
                        : '<li class="cvm-empty-msg">' + emptyMessage(state) + '</li>';

                    renderPagination(paginationEl, data.currentPage, data.totalPages, data.total, state.perPage, function (p) {
                        state.page = p;
                        fetchSubmissions();
                    });
                })
                .catch(function () {
                    if (seq === fetchSeq) {
                        list.innerHTML = '<li class="cvm-empty-msg">Failed to load submissions.</li>';
                    }
                });
        }

        fetchSubmissions();
    }

    /**
     * The empty-list message, distinguishing "nothing recorded yet" from
     * "nothing matches what you filtered for".
     *
     * @param {object} state
     * @returns {string}
     */
    function emptyMessage(state) {
        const filtered = state.search !== '' || state.year !== '' || state.month !== '' ||
                         state.provider !== '' || state.formName !== '' || state.channel !== '' ||
                         state.campaign !== '' || state.status !== '';

        return filtered
            ? 'No submissions match the current filters.'
            : 'No form submissions have been recorded yet. Submit one of your forms to see it here.';
    }

    /**
     * Populates the year and month filter dropdowns.
     *
     * @param {HTMLElement} controls
     * @param {string[]}    years
     * @param {string[]}    months
     */
    function updateDateOptions(controls, years, months) {
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
    }

    /**
     * Refreshes a simple value-list select, preserving the selection. The
     * filter stays hidden until it can actually narrow anything down.
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

        // A selected value can disappear from the list — the last row carrying
        // it was just deleted, say. Dropping it would silently reset the
        // control to "All" while the filter was still being applied, so the
        // list looked unfiltered but wasn't. Keep it selectable so the control
        // tells the truth and the user can clear it.
        if (currentVal !== '' && values.indexOf(currentVal) === -1) {
            const orphan = document.createElement('option');
            orphan.value       = currentVal;
            orphan.textContent = currentVal;
            orphan.selected    = true;
            select.appendChild(orphan);
        }

        // Shown as soon as there is anything to filter by. Blank values are
        // already excluded server-side, so a single campaign among a hundred
        // uncampaigned leads is a genuinely useful filter.
        const usable = values.length > 0 || currentVal !== '';
        select.parentElement.style.display = usable ? '' : 'none';
    }

    /**
     * Returns the HTML string for the controls bar. Year/month and the
     * value-list options start empty; the first response fills them.
     *
     * @returns {string}
     */
    function buildControlsHtml() {
        return '<div class="cvm-acc-filters">' +
                   '<select class="cvm-filter-year"><option value="">All Years</option></select>' +
                   '<select class="cvm-filter-month"><option value="">All Months</option></select>' +
                   '<span style="display:none"><select class="cvm-filter-provider"><option value="">All Providers</option></select></span>' +
                   '<span style="display:none"><select class="cvm-filter-form"><option value="">All Forms</option></select></span>' +
                   '<span style="display:none"><select class="cvm-filter-channel"><option value="">All Channels</option></select></span>' +
                   '<span style="display:none"><select class="cvm-filter-campaign"><option value="">All Campaigns</option></select></span>' +
                   '<select class="cvm-filter-status">' +
                       '<option value="">All Delivery States</option>' +
                       '<option value="delivered">Delivered</option>' +
                       '<option value="partial">Partially delivered</option>' +
                       '<option value="failed">Failed</option>' +
                       '<option value="pending">Queued</option>' +
                       '<option value="not_sent">Not sent</option>' +
                   '</select>' +
                   '<div class="cvm-acc-search">' +
                       '<input type="text" class="cvm-search-input" placeholder="Search name, email, field values, IDs…" />' +
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
            container.innerHTML = '';
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

    document.addEventListener('DOMContentLoaded', initSubmissions);

})();
