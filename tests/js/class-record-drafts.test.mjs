import { test } from 'node:test';
import assert from 'node:assert/strict';
import { purgeForeign, purgeAll, readDraft, writeDraft, removeDraft, draftKeys, DRAFT_MAX_AGE_MS } from '../../resources/js/class-record-drafts.js';

function fakeStorage(initial = {}) {
    const data = new Map(Object.entries(initial));
    return {
        get length() { return data.size; },
        key: (i) => [...data.keys()][i] ?? null,
        getItem: (k) => (data.has(k) ? data.get(k) : null),
        setItem: (k, v) => data.set(k, String(v)),
        removeItem: (k) => data.delete(k),
        keys: () => [...data.keys()],
    };
}

const fresh = (cells = { '1:2': { value: '9' } }) => JSON.stringify({ at: new Date().toISOString(), cells });

test('another account\'s drafts are removed when a Class Record opens', () => {
    const store = fakeStorage({
        'cr-draft:7:2026-2027:3:11:1': fresh(),
        'cr-draft:13:2026-2027:3:11:1': fresh(),
        'unrelated': 'keep',
    });
    purgeForeign(13, Date.now(), store);
    assert.deepEqual(store.keys().sort(), ['cr-draft:13:2026-2027:3:11:1', 'unrelated']);
});

test('expired and unreadable drafts are removed, current ones kept', () => {
    const old = JSON.stringify({ at: new Date(Date.now() - DRAFT_MAX_AGE_MS - 1000).toISOString(), cells: {} });
    const store = fakeStorage({
        'cr-draft:13:2026-2027:3:11:1': old,
        'cr-draft:13:2026-2027:3:11:2': 'not json',
        'cr-draft:13:2026-2027:3:12:1': fresh(),
    });
    purgeForeign('13', Date.now(), store);
    assert.deepEqual(store.keys(), ['cr-draft:13:2026-2027:3:12:1']);
});

test('logout and the sign-in page remove every draft', () => {
    const store = fakeStorage({ 'cr-draft:13:a': fresh(), 'cr-draft:7:b': fresh(), other: 'x' });
    purgeAll(store);
    assert.deepEqual(draftKeys(store), []);
    assert.equal(store.getItem('other'), 'x');
});

test('a draft is written per class key, and an empty draft removes the key', () => {
    const store = fakeStorage();
    writeDraft('cr-draft:13:y:3:11:1', { '5:6': { value: 'M', base_score: '', base_missing: '0' } }, store);
    assert.equal(readDraft('cr-draft:13:y:3:11:1', store).cells['5:6'].value, 'M');
    assert.equal(readDraft('cr-draft:13:y:3:11:2', store), null, 'another class sees nothing');
    writeDraft('cr-draft:13:y:3:11:1', {}, store);
    assert.equal(readDraft('cr-draft:13:y:3:11:1', store), null);
    writeDraft('cr-draft:13:y:3:11:1', { '5:6': { value: '1' } }, store);
    removeDraft('cr-draft:13:y:3:11:1', store);
    assert.equal(draftKeys(store).length, 0);
});

test('storage that throws never breaks the page', () => {
    const broken = { get length() { throw new Error('blocked'); }, setItem() { throw new Error('full'); }, removeItem() { throw new Error('blocked'); }, getItem() { throw new Error('blocked'); } };
    assert.doesNotThrow(() => writeDraft('k', { a: 1 }, broken));
    assert.doesNotThrow(() => removeDraft('k', broken));
    assert.equal(readDraft('k', broken), null);
});

test('an assessment chosen to save its blanks as Missing is kept in the draft on its own, per class, and cleared like cells', () => {
    const store = fakeStorage();
    writeDraft('cr-draft:13:y:3:11:1', {}, store, new Set([42, '42']));
    assert.deepEqual(readDraft('cr-draft:13:y:3:11:1', store).scope, ['42'], 'a scope alone keeps the draft; ids de-duplicated as strings');
    assert.deepEqual(readDraft('cr-draft:13:y:3:11:1', store).cells, {});
    assert.equal(readDraft('cr-draft:14:y:3:11:1', store), null, 'another account never sees it');
    writeDraft('cr-draft:13:y:3:11:1', {}, store, []);
    assert.equal(readDraft('cr-draft:13:y:3:11:1', store), null, 'no cells and no scope: the draft is removed');
    writeDraft('cr-draft:13:y:3:11:1', { '5:6': { value: '1' } }, store);
    assert.deepEqual(readDraft('cr-draft:13:y:3:11:1', store).scope, [], 'callers that pass no scope keep the old shape');
});
