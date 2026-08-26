/**
 * Convermetry — Funnels screen.
 *
 * Builds the step editor. A funnel's steps are an ordered list of varying
 * length, which is the one thing a plain server-rendered form cannot express
 * well, so the rows are managed here.
 *
 * Each row shows only the controls its step type actually uses: a page step
 * needs an operator and a path, a goal step needs a goal, and a form step needs
 * an optional form key. Showing all three at once — which is what the form
 * degrades to without JavaScript — is usable but noisy.
 *
 * Nothing here is authoritative. FunnelSettings re-validates every step
 * server-side and drops the ones that are incomplete, so a hand-crafted POST
 * cannot produce a funnel this editor would not allow.
 */
(function () {
    'use strict';

    const form = document.querySelector('.cvm-funnel-form');
    if (!form) {
        return;
    }

    const cfg = (typeof CVM_FUNNEL !== 'undefined') ? CVM_FUNNEL : {};
    const stepTypes = cfg.stepTypes || {};
    const goals = cfg.goals || {};
    const operators = cfg.operators || ['equals', 'contains', 'starts_with', 'ends_with'];
    const maxSteps = cfg.maxSteps || 8;

    const rows = form.querySelector('.cvm-funnel-step-rows');
    const addBtn = form.querySelector('.cvm-funnel-add-step');
    const idField = form.querySelector('.cvm-funnel-id');
    const nameField = form.querySelector('#cvm-funnel-name');
    const enabledField = form.querySelector('input[name="funnel[enabled]"]');
    const cancelBtn = form.querySelector('.cvm-funnel-cancel');
    const title = document.getElementById('cvm-funnel-editor-title');

    /** Human labels for the page-step operators. */
    const OPERATOR_LABELS = {
        equals: 'is exactly',
        contains: 'contains',
        starts_with: 'starts with',
        ends_with: 'ends with'
    };

    function escapeHtml(text) {
        const node = document.createElement('span');
        node.appendChild(document.createTextNode(String(text)));
        return node.innerHTML;
    }

    function optionsHtml(map, selected) {
        let html = '';
        for (const key in map) {
            if (Object.prototype.hasOwnProperty.call(map, key)) {
                html += '<option value="' + escapeHtml(key) + '"' +
                        (key === selected ? ' selected' : '') + '>' +
                        escapeHtml(map[key]) + '</option>';
            }
        }
        return html;
    }

    /** Builds one step row. */
    function buildRow(step, index) {
        step = step || {};

        const operatorMap = {};
        operators.forEach(function (op) {
            operatorMap[op] = OPERATOR_LABELS[op] || op;
        });

        const goalMap = {};
        for (const id in goals) {
            if (Object.prototype.hasOwnProperty.call(goals, id)) {
                goalMap[id] = goals[id];
            }
        }

        const row = document.createElement('div');
        row.className = 'cvm-funnel-step-row';
        row.innerHTML =
            '<span class="cvm-funnel-step-num">' + (index + 1) + '</span>' +
            '<select class="cvm-step-type" name="funnel[steps][' + index + '][type]">' +
                optionsHtml(stepTypes, step.type || 'page') +
            '</select>' +
            '<select class="cvm-step-operator" name="funnel[steps][' + index + '][operator]">' +
                optionsHtml(operatorMap, step.operator || 'equals') +
            '</select>' +
            '<select class="cvm-step-goal">' +
                (Object.keys(goalMap).length
                    ? optionsHtml(goalMap, step.type === 'goal' ? step.value : '')
                    : '<option value="">No goals configured yet</option>') +
            '</select>' +
            '<input type="text" class="cvm-step-value" name="funnel[steps][' + index + '][value]" ' +
                'value="' + escapeHtml(step.value || '') + '" placeholder="/services/">' +
            '<input type="text" class="cvm-step-label" name="funnel[steps][' + index + '][label]" ' +
                'value="' + escapeHtml(step.label || '') + '" placeholder="Label (optional)">' +
            '<button type="button" class="button-link cvm-btn-danger-link cvm-step-remove" ' +
                'aria-label="Remove step ' + (index + 1) + '">Remove</button>';

        return row;
    }

    /**
     * Shows only the controls the selected step type uses, and keeps the
     * submitted value field in sync with whichever control is visible.
     *
     * A goal step's value comes from a <select> that is NOT itself submitted —
     * its choice is copied into the hidden value input. That keeps the posted
     * shape identical for every step type, so the server has one thing to
     * validate rather than three.
     */
    function syncRow(row) {
        const type = row.querySelector('.cvm-step-type').value;
        const operator = row.querySelector('.cvm-step-operator');
        const goalSelect = row.querySelector('.cvm-step-goal');
        const value = row.querySelector('.cvm-step-value');

        const isPage = type === 'page';
        const isGoal = type === 'goal';

        operator.hidden = !isPage;
        goalSelect.hidden = !isGoal;
        value.hidden = isGoal;

        if (isGoal) {
            value.value = goalSelect.value;
        } else if (isPage) {
            value.placeholder = '/services/';
        } else {
            value.placeholder = 'Any form — or a form key such as gravityforms:7';
        }
    }

    /** Renumbers rows after an add or remove, so field names stay sequential. */
    function renumber() {
        const all = rows.querySelectorAll('.cvm-funnel-step-row');
        for (let i = 0; i < all.length; i++) {
            all[i].querySelector('.cvm-funnel-step-num').textContent = String(i + 1);
            all[i].querySelector('.cvm-step-type').name = 'funnel[steps][' + i + '][type]';
            all[i].querySelector('.cvm-step-operator').name = 'funnel[steps][' + i + '][operator]';
            all[i].querySelector('.cvm-step-value').name = 'funnel[steps][' + i + '][value]';
            all[i].querySelector('.cvm-step-label').name = 'funnel[steps][' + i + '][label]';
        }
        addBtn.disabled = all.length >= maxSteps;
    }

    function addRow(step) {
        const count = rows.querySelectorAll('.cvm-funnel-step-row').length;
        if (count >= maxSteps) {
            return;
        }
        const row = buildRow(step, count);
        rows.appendChild(row);
        syncRow(row);
        renumber();
    }

    rows.addEventListener('change', function (e) {
        const row = e.target.closest('.cvm-funnel-step-row');
        if (row) {
            syncRow(row);
        }
    });

    rows.addEventListener('click', function (e) {
        if (!e.target.closest('.cvm-step-remove')) {
            return;
        }
        const row = e.target.closest('.cvm-funnel-step-row');
        if (row) {
            row.remove();
            renumber();
        }
    });

    addBtn.addEventListener('click', function () {
        addRow(null);
    });

    /** Loads a stored funnel into the form. */
    function loadFunnel(funnel) {
        idField.value = funnel.funnel_id || '';
        nameField.value = funnel.name || '';
        enabledField.checked = !!funnel.enabled;

        rows.innerHTML = '';
        (funnel.steps || []).forEach(function (step) {
            addRow(step);
        });

        if (title) {
            title.textContent = 'Edit funnel';
        }
        if (cancelBtn) {
            cancelBtn.hidden = false;
        }

        form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        nameField.focus();
    }

    function resetForm() {
        form.reset();
        idField.value = '';
        rows.innerHTML = '';
        addRow(null);
        addRow(null);
        if (title) {
            title.textContent = 'Add a funnel';
        }
        if (cancelBtn) {
            cancelBtn.hidden = true;
        }
    }

    const editButtons = document.querySelectorAll('.cvm-funnel-edit');
    for (let i = 0; i < editButtons.length; i++) {
        editButtons[i].addEventListener('click', function () {
            let funnel = null;
            try {
                funnel = JSON.parse(this.getAttribute('data-funnel'));
            } catch (e) {
                return;
            }
            if (funnel) {
                loadFunnel(funnel);
            }
        });
    }

    if (cancelBtn) {
        cancelBtn.addEventListener('click', resetForm);
    }

    // Two empty rows to start: a funnel needs at least two steps, and an editor
    // that opens with one implies otherwise.
    addRow(null);
    addRow(null);
})();
