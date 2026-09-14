import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

function setup() {
    const events = {};
    const elements = new Map();
    const overlays = {};
    const shown = [];
    const hidden = [];
    const document = {
        addEventListener(name, handler) { events[name] = handler; },
        getElementById(id) {
            if (id === 'flashToast') return null;
            if (!elements.has(id)) elements.set(id, { dataset: {}, disabled: false, textContent: '', innerHTML: '' });
            return elements.get(id);
        },
    };
    const window = {
        showModal(id) { shown.push(id); },
        hideModal(id) { hidden.push(id); },
        bindModalOverlayClose(id, handler) { overlays[id] = handler; },
    };
    vm.runInNewContext(readFileSync(new URL('../../resources/js/confirm.js', import.meta.url), 'utf8'), { document, window });
    events.DOMContentLoaded();
    const form = dataset => ({ dataset, submitted: 0, submit() { this.submitted++; } });
    const submit = target => {
        let prevented = false;
        events.submit({ target, preventDefault() { prevented = true; } });
        assert.equal(prevented, true);
    };
    return { events, document, window, overlays, shown, hidden, form, submit };
}

test('import uses its own modal and submits once only after confirmation', () => {
    const ui = setup();
    const message = 'Insert 12 new learner(s) into Narra? Existing, Conflict, and Rejected rows will be skipped and will not be modified.';
    const form = ui.form({ importConfirm: message });
    ui.submit(form);
    assert.deepEqual(ui.shown, ['confirmImportModal']);
    assert.equal(ui.document.getElementById('confirmImportMessage').textContent, message);
    assert.equal(ui.document.getElementById('confirmImportBtn').textContent, 'Yes, Import');
    assert.equal(form.submitted, 0);
    ui.window.proceedImport();
    ui.window.proceedImport();
    assert.equal(form.submitted, 1);
    assert.equal(ui.document.getElementById('confirmImportBtn').disabled, true);
    assert.equal(ui.document.getElementById('confirmImportBtn').textContent, 'Importing...');
});

test('cancel, escape, and overlay dismissal never submit an import', () => {
    for (const close of [ui => ui.window.closeConfirmImport(), ui => ui.events.keydown({ key: 'Escape' }), ui => ui.overlays.confirmImportModal()]) {
        const ui = setup();
        const form = ui.form({ importConfirm: 'Insert learners?' });
        ui.submit(form);
        close(ui);
        ui.window.proceedImport();
        assert.equal(form.submitted, 0);
    }
});

test('delete wording and handler remain independent after an import', () => {
    const ui = setup();
    const importing = ui.form({ importConfirm: 'Insert learners?' });
    ui.submit(importing);
    ui.window.closeConfirmImport();
    const deleting = ui.form({ confirm: 'Delete unused record?' });
    ui.submit(deleting);
    assert.equal(ui.shown.at(-1), 'confirmDeleteModal');
    assert.match(ui.document.getElementById('confirmDeleteBtn').innerHTML, /Yes, Delete/);
    assert.match(ui.document.getElementById('confirmDeleteBtn').innerHTML, /bi-trash/);
    ui.window.proceedDelete();
    assert.equal(deleting.submitted, 1);
    assert.equal(importing.submitted, 0);
});

test('non-destructive actions use their own neutral modal with action-specific wording', () => {
    const ui = setup();
    const form = ui.form({
        actionConfirm: 'Activate School Year 2027-2028?',
        actionTitle: 'Confirm Activation',
        actionLabel: 'Yes, Activate',
        actionIcon: 'bi-check-circle',
        actionLoading: 'Activating...',
    });
    ui.submit(form);
    assert.deepEqual(ui.shown, ['confirmActionModal']);
    assert.equal(ui.document.getElementById('confirmActionTitle').textContent, 'Confirm Activation');
    assert.equal(ui.document.getElementById('confirmActionMessage').textContent, 'Activate School Year 2027-2028?');
    assert.match(ui.document.getElementById('confirmActionBtn').innerHTML, /Yes, Activate/);
    assert.doesNotMatch(ui.document.getElementById('confirmActionBtn').innerHTML, /Delete/);
    assert.equal(form.submitted, 0);
    ui.window.proceedAction();
    ui.window.proceedAction();
    assert.equal(form.submitted, 1);
    assert.match(ui.document.getElementById('confirmActionBtn').innerHTML, /Activating\.\.\./);
});

test('cancelling the action modal never submits, and the delete modal is untouched by it', () => {
    for (const close of [ui => ui.window.closeConfirmAction(), ui => ui.events.keydown({ key: 'Escape' }), ui => ui.overlays.confirmActionModal()]) {
        const ui = setup();
        const form = ui.form({ actionConfirm: 'Close Term 1?', actionTitle: 'Confirm Close', actionLabel: 'Yes, Close' });
        ui.submit(form);
        close(ui);
        ui.window.proceedAction();
        assert.equal(form.submitted, 0);
        assert.ok(!ui.shown.includes('confirmDeleteModal'));
    }
});
