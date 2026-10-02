/**
 * Convermetry — Submissions page.
 *
 * A paginated, filterable list of server-confirmed form submissions. Each row
 * is its own accordion: the collapsed header carries the at-a-glance columns,
 * and the detail panel (form identity, attribution, visitor journey, the
 * visitor's field values, and per-endpoint delivery results) is fetched the
 * first time the row is expanded and cached on the element after that.
 *
 * All data comes from the cvmtry_get_submissions / cvmtry_get_submission_detail
 * AJAX actions; configuration and nonces arrive via the CVMTRY_SUB object
 * localized by SubmissionsPage.
 */
(function () {
    'use strict';

    const { __, sprintf } = wp.i18n;

    // Localized by WordPress (AdminAssets::monthNames()).
    const MONTH_NAMES = (typeof CVMTRY_SUB !== 'undefined' && Array.isArray(CVMTRY_SUB.monthNames)) ? CVMTRY_SUB.monthNames : [];

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

    /**
     * For interpolation into a QUOTED ATTRIBUTE value. escapeHtml() serializes
     * a text node, and the HTML serializer escapes quotes only in attribute
     * context — so its output is safe as element content but lets a quote in a
     * form name, provider or endpoint label break out of value="…".
     *
     * @param {*} text
     * @returns {string}
     */
    function escapeAttr(text) {
        // escapeHtml handles & first, so these replacements cannot double-encode.
        return escapeHtml(text).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function cfg(key) {
        return (typeof CVMTRY_SUB !== 'undefined' && CVMTRY_SUB[key]) ? CVMTRY_SUB[key] : '';
    }

    // ── Boot ─────────────────────────────────────────────────────────────────

    function initSubmissions() {
        const root = document.getElementById('cvmtry-submissions');
        if (!root) return;

        // Seeded from ?cvmtry_search= so a deep link (a notification email links
        // here with the submission id) opens that one submission rather than
        // the full list. Server-sanitized before localization.
        const state = {
            page: 1, perPage: 10, search: cfg('initialSearch') || '', year: '', month: '',
            provider: '', formName: '', channel: '', campaign: '', status: '',
            leadStatus: '', hasValue: ''
        };

        let initialized = false;

        const controls = document.createElement('div');
        controls.className = 'cvmtry-acc-controls';
        controls.innerHTML = buildControlsHtml();
        root.appendChild(controls);

        // Column headings for sighted users. Hidden from assistive tech: this
        // is a list, not a table, so a floating header row would be read as
        // stray text — each row's button carries its own full aria-label.
        const heading = document.createElement('div');
        heading.className = 'cvmtry-submission-heading';
        heading.setAttribute('aria-hidden', 'true');
        heading.innerHTML = [
            __('Date', 'convermetry'),
            __('Visitor / Lead', 'convermetry'),
            __('Form', 'convermetry'),
            __('Page', 'convermetry'),
            __('Source', 'convermetry'),
            __('Campaign', 'convermetry'),
            __('Lead', 'convermetry'),
            __('Delivery', 'convermetry'),
            ''
        ].map(function (label) { return '<span>' + escapeHtml(label) + '</span>'; }).join('');
        root.appendChild(heading);

        const list = document.createElement('ul');
        list.className = 'cvmtry-submission-list';
        root.appendChild(list);

        const paginationEl = document.createElement('div');
        paginationEl.className = 'cvmtry-pagination';
        root.appendChild(paginationEl);

        // ── Controls wiring ──────────────────────────────────────────────────
        const simpleFilters = [
            ['.cvmtry-filter-lead-status', 'leadStatus'],
            ['.cvmtry-filter-has-value', 'hasValue'],
            ['.cvmtry-filter-year', 'year'],
            ['.cvmtry-filter-month', 'month'],
            ['.cvmtry-filter-provider', 'provider'],
            ['.cvmtry-filter-form', 'formName'],
            ['.cvmtry-filter-channel', 'channel'],
            ['.cvmtry-filter-campaign', 'campaign'],
            ['.cvmtry-filter-status', 'status']
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

        controls.querySelector('.cvmtry-per-page').addEventListener('change', function () {
            state.perPage = parseInt(this.value, 10);
            state.page = 1;
            fetchSubmissions();
        });

        // Reflect a seeded search in the box, so the filtered view is visibly
        // filtered and the clear button is discoverable.
        if (state.search !== '') {
            controls.querySelector('.cvmtry-search-input').value = state.search;
        }

        // Debounced: every keystroke would otherwise fire a LIKE query over the
        // LONGTEXT submission_data column.
        let searchTimer = null;
        controls.querySelector('.cvmtry-search-input').addEventListener('input', function () {
            const value = this.value;
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function () {
                state.search = value;
                state.page = 1;
                fetchSubmissions();
            }, 300);
        });
        controls.querySelector('.cvmtry-search-clear').addEventListener('click', function () {
            clearTimeout(searchTimer);
            controls.querySelector('.cvmtry-search-input').value = '';
            state.search = '';
            state.page = 1;
            fetchSubmissions();
        });

        // ── Accordion expand (lazy detail load) ──────────────────────────────
        list.addEventListener('click', function (e) {
            const header = e.target.closest('.cvmtry-submission-summary');
            if (!header || !list.contains(header)) return;

            const bodyId = header.getAttribute('aria-controls');
            const body   = bodyId ? document.getElementById(bodyId) : null;
            if (!body) return;

            const isExpanded = header.getAttribute('aria-expanded') === 'true';
            header.setAttribute('aria-expanded', isExpanded ? 'false' : 'true');
            body.hidden = isExpanded;

            if (!isExpanded && body.dataset.loaded !== '1') {
                loadDetail(header.closest('.cvmtry-submission-item'), body);
            }
        });

        // ── Delete (inside an expanded detail panel) ─────────────────────────
        list.addEventListener('click', function (e) {
            const btn = e.target.closest('.cvmtry-submission-delete-btn');
            if (!btn) return;

            const item  = btn.closest('.cvmtry-submission-item');
            const rowId = item ? item.dataset.rowId : null;
            if (!rowId) return;

            if (!confirm(__('Delete this submission? The lead data it holds is removed permanently and cannot be recovered.', 'convermetry'))) return;

            btn.disabled    = true;
            btn.textContent = __('Deleting…', 'convermetry');

            const fd = new FormData();
            fd.append('action', 'cvmtry_delete_submission');
            fd.append('nonce', cfg('deleteNonce'));
            fd.append('submission_row', rowId);

            fetch(cfg('ajaxUrl'), { method: 'POST', body: fd })
                .then(function (res) { return res.json(); })
                .then(function (resp) {
                    if (resp.success) {
                        fetchSubmissions();
                    } else {
                        failDelete(btn, (resp.data && resp.data.message) || __('The submission could not be deleted.', 'convermetry'));
                    }
                })
                .catch(function () {
                    failDelete(btn, __('The submission could not be deleted.', 'convermetry'));
                });
        });

        // ── Lead outcome (inside an expanded detail panel) ───────────────────
        // Saved in place rather than reloading the list. Qualifying leads is
        // done in runs — open a row, judge it, move on — and re-rendering the
        // whole list after each save would collapse the row you were working in
        // and lose your place.
        list.addEventListener('click', function (e) {
            const btn = e.target.closest('.cvmtry-lead-save');
            if (!btn) return;

            const block = btn.closest('.cvmtry-lead-block');
            const item  = btn.closest('.cvmtry-submission-item');
            if (!block || !item) return;

            const submissionId = block.dataset.submissionId;
            const statusSelect = block.querySelector('.cvmtry-lead-status');
            const valueInput   = block.querySelector('.cvmtry-lead-value');
            const feedback     = block.querySelector('.cvmtry-lead-feedback');
            if (!submissionId || !statusSelect || !valueInput) return;

            btn.disabled    = true;
            btn.textContent = __('Saving…', 'convermetry');
            if (feedback) feedback.textContent = '';

            const fd = new FormData();
            fd.append('action', 'cvmtry_update_lead');
            fd.append('nonce', cfg('leadNonce'));
            fd.append('submission_id', submissionId);
            fd.append('lead_status', statusSelect.value);
            // Always sent, including empty — an empty string is what CLEARS a
            // recorded value, and omitting the key would mean "leave unchanged".
            fd.append('lead_value', valueInput.value);

            fetch(cfg('ajaxUrl'), { method: 'POST', body: fd })
                .then(function (res) { return res.json(); })
                .then(function (resp) {
                    btn.disabled    = false;
                    btn.textContent = __('Save', 'convermetry');

                    if (!resp.success) {
                        if (feedback) {
                            feedback.className = 'cvmtry-lead-feedback cvmtry-lead-error';
                            feedback.textContent = (resp.data && resp.data.message) ||
                                __('The lead could not be updated.', 'convermetry');
                        }
                        return;
                    }

                    // Reflect the stored values, not what was typed: the server
                    // normalizes "$12,500.5" to "12500.50", and showing the raw
                    // input back would suggest it was stored verbatim.
                    valueInput.value = resp.data.value === null ? '' : resp.data.value;
                    updateRowChip(item, resp.data);

                    if (feedback) {
                        feedback.className = 'cvmtry-lead-feedback cvmtry-lead-saved';
                        feedback.textContent = __('Saved', 'convermetry');
                    }
                })
                .catch(function () {
                    btn.disabled    = false;
                    btn.textContent = __('Save', 'convermetry');
                    if (feedback) {
                        feedback.className = 'cvmtry-lead-feedback cvmtry-lead-error';
                        feedback.textContent = __('The lead could not be updated.', 'convermetry');
                    }
                });
        });

        /**
         * Updates the collapsed row's chip so the list agrees with the panel
         * without a refetch.
         *
         * @param {HTMLElement} item
         * @param {object}      data
         */
        function updateRowChip(item, data) {
            const cell = item.querySelector('.cvmtry-sub-lead-status');
            if (!cell) return;

            let html = '<span class="cvmtry-status-chip ' + escapeAttr(data.chipClass) + '">' +
                       escapeHtml(data.statusLabel) + '</span>';

            if (data.valueLabel) {
                html += '<span class="cvmtry-sub-lead-value">' + escapeHtml(data.valueLabel) + '</span>';
            }

            cell.innerHTML = html;
        }

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
            btn.textContent = __('Delete Submission', 'convermetry');

            const actions = btn.parentElement;
            if (!actions) return;

            let note = actions.querySelector('.cvmtry-delete-error');
            if (!note) {
                note = document.createElement('span');
                note.className = 'cvmtry-delete-error';
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
            body.innerHTML = '<p class="cvmtry-empty-msg">' + escapeHtml(__('Loading…', 'convermetry')) + '</p>';

            const fd = new FormData();
            fd.append('action', 'cvmtry_get_submission_detail');
            fd.append('nonce', cfg('detailNonce'));
            fd.append('submission_row', item.dataset.rowId);

            fetch(cfg('ajaxUrl'), { method: 'POST', body: fd })
                .then(function (res) { return res.json(); })
                .then(function (resp) {
                    if (resp.success) {
                        body.innerHTML = resp.data.html;
                        body.dataset.loaded = '1';
                    } else {
                        body.innerHTML = '<p class="cvmtry-empty-msg">' +
                            escapeHtml((resp.data && resp.data.message) || __('This submission could not be loaded.', 'convermetry')) +
                            '</p>';
                    }
                })
                .catch(function () {
                    body.innerHTML = '<p class="cvmtry-empty-msg">' + escapeHtml(__('This submission could not be loaded.', 'convermetry')) + '</p>';
                });
        }

        /**
         * Keeps the "Export Current Filters" link pointing at exactly what is
         * on screen.
         */
        function syncExportLink() {
            const link = document.querySelector('.cvmtry-export-filtered');
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
                delivery_status: state.status,
                lead_status: state.leadStatus,
                has_value: state.hasValue
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

            list.innerHTML         = '<li class="cvmtry-empty-msg">' + escapeHtml(__('Loading…', 'convermetry')) + '</li>';
            paginationEl.innerHTML = '';
            syncExportLink();

            const fd = new FormData();
            fd.append('action', 'cvmtry_get_submissions');
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
            fd.append('lead_status', state.leadStatus);
            fd.append('has_value', state.hasValue);

            fetch(cfg('ajaxUrl'), { method: 'POST', body: fd })
                .then(function (res) { return res.json(); })
                .then(function (resp) {
                    if (seq !== fetchSeq) {
                        return; // A newer request superseded this one.
                    }
                    if (!resp.success) {
                        list.innerHTML = '<li class="cvmtry-empty-msg">' + escapeHtml(__('Failed to load submissions.', 'convermetry')) + '</li>';
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
                    updateListOptions(controls, '.cvmtry-filter-provider', data.providers || [], __('All Providers', 'convermetry'));
                    updateListOptions(controls, '.cvmtry-filter-form', data.formNames || [], __('All Forms', 'convermetry'));
                    updateListOptions(controls, '.cvmtry-filter-channel', data.channels || [], __('All Channels', 'convermetry'));
                    updateListOptions(controls, '.cvmtry-filter-campaign', data.campaigns || [], __('All Campaigns', 'convermetry'));

                    list.innerHTML = data.html !== ''
                        ? data.html
                        : '<li class="cvmtry-empty-msg">' + escapeHtml(emptyMessage(state)) + '</li>';

                    renderPagination(paginationEl, data.currentPage, data.totalPages, data.total, state.perPage, function (p) {
                        state.page = p;
                        fetchSubmissions();
                    });
                })
                .catch(function () {
                    if (seq === fetchSeq) {
                        list.innerHTML = '<li class="cvmtry-empty-msg">' + escapeHtml(__('Failed to load submissions.', 'convermetry')) + '</li>';
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
                         state.campaign !== '' || state.status !== '' ||
                         state.leadStatus !== '' || state.hasValue !== '';

        return filtered
            ? __('No submissions match the current filters.', 'convermetry')
            : __('No form submissions have been recorded yet. Submit one of your forms to see it here.', 'convermetry');
    }

    /**
     * Populates the year and month filter dropdowns.
     *
     * @param {HTMLElement} controls
     * @param {string[]}    years
     * @param {string[]}    months
     */
    function updateDateOptions(controls, years, months) {
        const yearSelect  = controls.querySelector('.cvmtry-filter-year');
        const monthSelect = controls.querySelector('.cvmtry-filter-month');

        yearSelect.innerHTML = '<option value="">' + escapeHtml(__('All Years', 'convermetry')) + '</option>';
        years.forEach(function (y) {
            yearSelect.innerHTML += '<option value="' + escapeAttr(y) + '">' + escapeHtml(y) + '</option>';
        });

        monthSelect.innerHTML = '<option value="">' + escapeHtml(__('All Months', 'convermetry')) + '</option>';
        months.forEach(function (m) {
            const name = MONTH_NAMES[parseInt(m, 10) - 1] || m;
            monthSelect.innerHTML += '<option value="' + escapeAttr(m) + '">' + escapeHtml(name) + '</option>';
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
     * Lead-status filter options, built from the labels the server localized.
     *
     * Read from CVMTRY_SUB rather than duplicated here so the vocabulary has one
     * owner — LeadStatus — and a status added there cannot go missing from the
     * filter.
     *
     * @returns {string}
     */
    function leadStatusOptions() {
        const statuses = (typeof CVMTRY_SUB !== 'undefined' && CVMTRY_SUB.leadStatuses) || {};
        let html = '';

        for (const machine in statuses) {
            if (Object.prototype.hasOwnProperty.call(statuses, machine)) {
                html += '<option value="' + escapeAttr(machine) + '">' +
                        escapeHtml(statuses[machine]) + '</option>';
            }
        }

        return html;
    }

    /**
     * Returns the HTML string for the controls bar. Year/month and the
     * value-list options start empty; the first response fills them.
     *
     * @returns {string}
     */
    function buildControlsHtml() {
        const option = function (value, label) {
            return '<option value="' + escapeAttr(value) + '">' + escapeHtml(label) + '</option>';
        };

        return '<div class="cvmtry-acc-filters">' +
                   '<select class="cvmtry-filter-year">' + option('', __('All Years', 'convermetry')) + '</select>' +
                   '<select class="cvmtry-filter-month">' + option('', __('All Months', 'convermetry')) + '</select>' +
                   '<span style="display:none"><select class="cvmtry-filter-provider">' + option('', __('All Providers', 'convermetry')) + '</select></span>' +
                   '<span style="display:none"><select class="cvmtry-filter-form">' + option('', __('All Forms', 'convermetry')) + '</select></span>' +
                   '<span style="display:none"><select class="cvmtry-filter-channel">' + option('', __('All Channels', 'convermetry')) + '</select></span>' +
                   '<span style="display:none"><select class="cvmtry-filter-campaign">' + option('', __('All Campaigns', 'convermetry')) + '</select></span>' +
                   '<select class="cvmtry-filter-status">' +
                       option('', __('All Delivery States', 'convermetry')) +
                       option('delivered', __('Delivered', 'convermetry')) +
                       option('partial', __('Partially delivered', 'convermetry')) +
                       option('failed', __('Failed', 'convermetry')) +
                       option('pending', __('Queued', 'convermetry')) +
                       option('not_sent', __('Not sent', 'convermetry')) +
                   '</select>' +
                   '<select class="cvmtry-filter-lead-status">' +
                       option('', __('All Lead Statuses', 'convermetry')) +
                       leadStatusOptions() +
                   '</select>' +
                   '<select class="cvmtry-filter-has-value">' +
                       option('', __('Any Value', 'convermetry')) +
                       option('yes', __('Has a value', 'convermetry')) +
                       option('no', __('No value recorded', 'convermetry')) +
                   '</select>' +
                   '<div class="cvmtry-acc-search">' +
                       '<input type="text" class="cvmtry-search-input" placeholder="' + escapeAttr(__('Search name, email, field values, IDs…', 'convermetry')) + '" />' +
                       '<button type="button" class="cvmtry-search-clear" aria-label="' + escapeAttr(__('Clear search', 'convermetry')) + '">✕</button>' +
                   '</div>' +
               '</div>' +
               '<div class="cvmtry-acc-perpage">' +
                   '<label>' + escapeHtml(__('Per page:', 'convermetry')) + ' <select class="cvmtry-per-page">' +
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

        /* translators: 1: first item shown, 2: last item shown, 3: total number of items. */
        let html = '<span class="cvmtry-page-info">' + escapeHtml(sprintf(__('Showing %1$d–%2$d of %3$d', 'convermetry'), start, end, totalItems)) + '</span>';

        if (totalPages > 1) {
            html += '<div class="cvmtry-page-buttons">';

            if (currentPage > 1) {
                html += '<button class="cvmtry-page-btn" data-page="' + (currentPage - 1) + '" aria-label="' + escapeAttr(__('Previous page', 'convermetry')) + '">&#8249;</button>';
            }

            getPageNumbers(currentPage, totalPages).forEach(function (p) {
                if (p === '...') {
                    html += '<span class="cvmtry-page-ellipsis">&#8230;</span>';
                } else {
                    const activeClass = p === currentPage ? ' cvmtry-page-btn-active' : '';
                    /* translators: %d: page number. */
                    html += '<button class="cvmtry-page-btn' + activeClass + '" data-page="' + p + '" aria-label="' + escapeAttr(sprintf(__('Page %d', 'convermetry'), p)) + '">' + p + '</button>';
                }
            });

            if (currentPage < totalPages) {
                html += '<button class="cvmtry-page-btn" data-page="' + (currentPage + 1) + '" aria-label="' + escapeAttr(__('Next page', 'convermetry')) + '">&#8250;</button>';
            }

            html += '</div>';
        }

        container.innerHTML = html;

        container.querySelectorAll('.cvmtry-page-btn[data-page]').forEach(function (btn) {
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
