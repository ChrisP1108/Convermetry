/**
 * Convermetry — confirmation prompts for destructive admin actions.
 *
 * Any form, button or link carrying data-cvmtry-confirm asks before its default
 * action runs: a form before it submits, a button or link before it is
 * activated. The attribute holds the already-translated question, so this file
 * needs no strings of its own.
 *
 * It replaces inline onclick/onsubmit handlers. Those had to JSON-encode the
 * message into a JavaScript literal, and a mistake there was silent in the
 * worst way: a handler that throws lets the form submit, so Remove deleted
 * without asking. An attribute read with getAttribute() cannot be malformed.
 *
 * Every action guarded here is a nonce-protected POST. With scripting off it
 * runs without the prompt, exactly as it did with the inline handlers.
 */
(function () {
    'use strict';

    var ATTR = 'data-cvmtry-confirm';

    function confirmed(el) {
        var message = el.getAttribute(ATTR);

        return !message || window.confirm(message);
    }

    document.addEventListener('submit', function (e) {
        var form = e.target;

        if (form instanceof HTMLFormElement && form.hasAttribute(ATTR) && !confirmed(form)) {
            e.preventDefault();
        }
    });

    document.addEventListener('click', function (e) {
        var el = e.target.closest && e.target.closest('a[' + ATTR + '], button[' + ATTR + '], input[' + ATTR + ']');

        if (el && !el.disabled && !confirmed(el)) {
            e.preventDefault();
        }
    });
})();
