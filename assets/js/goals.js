/**
 * Convermetry — Goals screen.
 *
 * Two small jobs, both purely presentational:
 *
 *  - Show only the matching rules that belong to the selected goal type, and
 *    hide the value field for rules that do not take one (phone, email, and
 *    external-link goals). Without this the form offers a marketer a CSS
 *    selector box next to "on a phone number link", which reads as though it
 *    were required.
 *  - Load an existing goal into the same form when Edit is clicked, rather than
 *    maintaining a second editing screen.
 *
 * The form posts normally. Every value is re-validated server-side by
 * GoalSettings, so nothing here is load-bearing — with JavaScript unavailable
 * the form still works, it just shows every operator at once.
 */
(function () {
    'use strict';

    const form = document.querySelector('.cvm-goal-form');
    if (!form) {
        return;
    }

    const typeSelect = form.querySelector('.cvm-goal-type');
    const operator = form.querySelector('.cvm-goal-operator');
    const valueField = form.querySelector('.cvm-goal-value');
    const valueHelp = form.querySelector('.cvm-goal-value-help');
    const dynamicRow = form.querySelector('.cvm-goal-dynamic-row');
    const idField = form.querySelector('.cvm-goal-id');
    const nameField = form.querySelector('#cvm-goal-name');
    const amountField = form.querySelector('#cvm-goal-value-amount');
    const onceField = form.querySelector('input[name="goal[once_per_session]"]');
    const enabledField = form.querySelector('input[name="goal[enabled]"]');
    const dynamicField = form.querySelector('input[name="goal[dynamic_value]"]');
    const cancelBtn = form.querySelector('.cvm-goal-cancel');
    const title = document.getElementById('cvm-goal-editor-title');

    /** Operators that describe themselves fully and take no value. */
    const VALUELESS = { tel: true, mailto: true, external: true };

    /** Placeholder guidance per operator, so the example matches the rule. */
    const PLACEHOLDERS = {
        selector: '.book-now-button',
        contains: '/brochure.pdf',
        equals: 'https://example.com/pricing/',
        name: 'appointment_booked',
        starts_with: '/services/',
        ends_with: '/confirmation/'
    };

    /** Shows only the operators belonging to the selected type. */
    function syncOperators() {
        const type = typeSelect.value;
        let firstVisible = null;

        for (let i = 0; i < operator.options.length; i++) {
            const option = operator.options[i];
            const matches = option.getAttribute('data-type') === type;

            option.hidden = !matches;
            option.disabled = !matches;

            if (matches && firstVisible === null) {
                firstVisible = option;
            }
        }

        const current = operator.options[operator.selectedIndex];
        if (!current || current.getAttribute('data-type') !== type) {
            if (firstVisible) {
                firstVisible.selected = true;
            }
        }

        syncValueField();
    }

    /** Hides the value field for rules that do not take one. */
    function syncValueField() {
        const selected = operator.value;
        const needsValue = !VALUELESS[selected];

        valueField.hidden = !needsValue;
        valueField.required = needsValue;

        if (needsValue) {
            valueField.placeholder = PLACEHOLDERS[selected] || '/thank-you/';
        }

        if (valueHelp) {
            valueHelp.hidden = !needsValue;
        }

        if (dynamicRow) {
            dynamicRow.hidden = typeSelect.value !== 'custom_event';
        }
    }

    typeSelect.addEventListener('change', syncOperators);
    operator.addEventListener('change', syncValueField);

    /** Loads a stored goal into the form. */
    function loadGoal(goal) {
        idField.value = goal.goal_id || '';
        nameField.value = goal.name || '';
        typeSelect.value = goal.type || 'click';

        syncOperators();
        operator.value = goal.operator || operator.value;
        syncValueField();

        valueField.value = goal.value || '';
        amountField.value = goal.goal_value === null || typeof goal.goal_value === 'undefined'
            ? ''
            : goal.goal_value;
        onceField.checked = !!goal.once_per_session;
        enabledField.checked = !!goal.enabled;
        if (dynamicField) {
            dynamicField.checked = !!goal.dynamic_value;
        }

        if (title) {
            title.textContent = 'Edit goal';
        }
        if (cancelBtn) {
            cancelBtn.hidden = false;
        }

        form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        nameField.focus();
    }

    /** Returns the form to "add a new goal" state. */
    function resetForm() {
        form.reset();
        idField.value = '';
        if (title) {
            title.textContent = 'Add a goal';
        }
        if (cancelBtn) {
            cancelBtn.hidden = true;
        }
        syncOperators();
    }

    const editButtons = document.querySelectorAll('.cvm-goal-edit');
    for (let i = 0; i < editButtons.length; i++) {
        editButtons[i].addEventListener('click', function () {
            let goal = null;
            try {
                goal = JSON.parse(this.getAttribute('data-goal'));
            } catch (e) {
                return;
            }
            if (goal) {
                loadGoal(goal);
            }
        });
    }

    if (cancelBtn) {
        cancelBtn.addEventListener('click', resetForm);
    }

    syncOperators();
})();
