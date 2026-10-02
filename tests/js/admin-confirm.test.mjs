/**
 * The confirmation prompt on destructive admin actions, EXECUTED against the
 * real assets/js/admin-confirm.js.
 *
 * It replaced inline onclick/onsubmit handlers, whose failure mode was silent:
 * a handler that threw let the form submit, so Remove deleted without asking.
 * These pin that the prompt appears, shows the attribute's text, and that only
 * an explicit "Cancel" stops the action.
 *
 *     composer test:js       (or: node tests/js/admin-confirm.test.mjs)
 */

import { strict as assert } from 'node:assert';
import { readFileSync } from 'node:fs';
import { createContext, runInContext } from 'node:vm';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const SOURCE = readFileSync(
    join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'assets', 'js', 'admin-confirm.js'),
    'utf8'
);

let passed = 0;
const failures = [];

function test(name, fn) {
    try {
        fn();
        passed++;
        console.log(`  ✓ ${name}`);
    } catch (error) {
        failures.push({ name, error });
        console.log(`  ✗ ${name}`);
        console.log(`      ${error.message.split('\n')[0]}`);
    }
}

class FakeElement {
    constructor(tagName, attributes = {}, parent = null) {
        this.tagName = tagName.toUpperCase();
        this.attributes = { ...attributes };
        this.parent = parent;
        this.disabled = 'disabled' in attributes;
    }

    getAttribute(name) {
        return Object.prototype.hasOwnProperty.call(this.attributes, name) ? this.attributes[name] : null;
    }

    hasAttribute(name) {
        return Object.prototype.hasOwnProperty.call(this.attributes, name);
    }

    /** Supports the comma-separated `tag[attr]` selectors the script uses. */
    closest(selector) {
        const parts = selector.split(',').map((part) => /^\s*(\w+)\[([\w-]+)\]\s*$/.exec(part));
        for (let node = this; node; node = node.parent) {
            for (const part of parts) {
                if (part && node.tagName === part[1].toUpperCase() && node.hasAttribute(part[2])) {
                    return node;
                }
            }
        }
        return null;
    }
}

class FakeForm extends FakeElement {
    constructor(attributes = {}) {
        super('form', attributes);
    }
}

/** Boots the real script; `answer` is what window.confirm() returns. */
function boot(answer) {
    const listeners = {};
    const asked = [];
    const document = {
        addEventListener(type, handler) {
            (listeners[type] = listeners[type] || []).push(handler);
        },
    };
    const window = { confirm: (message) => { asked.push(message); return answer; } };
    const context = createContext({ window, document, HTMLFormElement: FakeForm });
    runInContext(SOURCE, context);

    return {
        asked,
        fire(type, target) {
            const event = { type, target, prevented: false, preventDefault() { this.prevented = true; } };
            for (const handler of listeners[type] || []) {
                handler(event);
            }
            return event;
        },
    };
}

console.log('\nAdmin confirmation prompts — executed against the real admin-confirm.js\n');

test('submitting a guarded form asks with its own text, and Cancel stops it', () => {
    const page = boot(false);
    const form = new FakeForm({ 'data-cvmtry-confirm': 'Remove this goal?' });

    const event = page.fire('submit', form);

    assert.deepEqual(page.asked, ['Remove this goal?']);
    assert.equal(event.prevented, true);
});

test('OK lets a guarded form submit', () => {
    const page = boot(true);

    const event = page.fire('submit', new FakeForm({ 'data-cvmtry-confirm': 'Remove this funnel?' }));

    assert.equal(page.asked.length, 1);
    assert.equal(event.prevented, false);
});

test('a form without the attribute submits without asking', () => {
    const page = boot(false);

    const event = page.fire('submit', new FakeForm({ method: 'post' }));

    assert.deepEqual(page.asked, []);
    assert.equal(event.prevented, false);
});

test('a click inside a guarded button asks, and Cancel stops the action', () => {
    const page = boot(false);
    const button = new FakeElement('button', { type: 'submit', 'data-cvmtry-confirm': 'Clear all logs?' });
    const label = new FakeElement('span', {}, button);

    const event = page.fire('click', label);

    assert.deepEqual(page.asked, ['Clear all logs?']);
    assert.equal(event.prevented, true);
});

test('a disabled guarded button does not prompt', () => {
    const page = boot(false);

    page.fire('click', new FakeElement('button', { disabled: '', 'data-cvmtry-confirm': 'Delete everything?' }));

    assert.deepEqual(page.asked, []);
});

test('clicks on unguarded elements are left alone', () => {
    const page = boot(false);

    const event = page.fire('click', new FakeElement('a', { href: '#' }));

    assert.deepEqual(page.asked, []);
    assert.equal(event.prevented, false);
});

console.log(`\n${passed} passed, ${failures.length} failed\n`);

if (failures.length > 0) {
    for (const { name, error } of failures) {
        console.error(`FAIL: ${name}\n${error.stack}\n`);
    }
    process.exit(1);
}
