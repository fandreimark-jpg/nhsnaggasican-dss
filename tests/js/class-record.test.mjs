import { test } from 'node:test';
import assert from 'node:assert/strict';
import { parseCellInput, displayFor, isChanged, parseClipboard, isBlockPaste, planPaste, planSave } from '../../resources/js/class-record-core.js';

test('blank, zero, Missing and invalid are distinct', () => {
    assert.deepEqual(parseCellInput('', 20), { kind: 'blank', value: null, error: null });
    assert.deepEqual(parseCellInput('  ', 20).kind, 'blank');
    assert.deepEqual(parseCellInput('0', 20), { kind: 'score', value: 0, error: null });
    assert.equal(parseCellInput('m', 20).kind, 'missing');
    assert.equal(parseCellInput('MISSING', 20).kind, 'missing');
    assert.equal(parseCellInput('-1', 20).kind, 'invalid');
    assert.equal(parseCellInput('21', 20).kind, 'invalid');
    assert.equal(parseCellInput('ten', 20).kind, 'invalid');
    assert.equal(parseCellInput('=1+1', 20).kind, 'invalid');
    assert.equal(parseCellInput('19.5', 20).value, 19.5);
    assert.equal(displayFor(parseCellInput('m', 20)), 'M');
    assert.equal(displayFor(parseCellInput('', 20)), '');
});

test('a change is detected against the loaded value only', () => {
    assert.equal(isChanged(parseCellInput('18', 20), '18', false), false);
    assert.equal(isChanged(parseCellInput('18.00', 20), '18', false), false);
    assert.equal(isChanged(parseCellInput('0', 20), '', false), true, '0 is not blank');
    assert.equal(isChanged(parseCellInput('', 20), '0', false), true, 'clearing a 0 is a change');
    assert.equal(isChanged(parseCellInput('M', 20), '', true), false);
    // Automatic Missing: a blank is saved as Missing, so emptying a Missing
    // cell is not a change, and blank on blank is not either.
    assert.equal(isChanged(parseCellInput('', 20), '', true), false, 'blank on a Missing cell stays Missing');
    assert.equal(isChanged(parseCellInput('', 20), '', false), false);
    assert.equal(isChanged(parseCellInput('M', 20), '', false), true, 'M on a blank cell puts its assessment in the save');
    assert.equal(isChanged(parseCellInput('', 20), '18', false), true, 'Clear Entry on a score is a change');
});

test('Excel clipboard text is parsed without inventing a trailing row', () => {
    assert.deepEqual(parseClipboard('1\t2\r\n3\t4\r\n'), [['1', '2'], ['3', '4']]);
    assert.deepEqual(parseClipboard('"a""b"\t5'), [['a"b', '5']]);
    assert.equal(isBlockPaste('17'), false);
    assert.equal(isBlockPaste('17\n18'), true);
    assert.equal(isBlockPaste('17\t18'), true);
});

const grid = (rows, cols, readonly = new Set()) => ({
    rows, cols,
    cellAt: (r, c) => (r < rows && c < cols ? { editable: !readonly.has(`${r}:${c}`), max: 20, label: `R${r}C${c}`, current: '' } : null),
});

test('a paste is placed exactly from the anchor, never shifted', () => {
    const plan = planPaste({ ...grid(3, 3), anchorRow: 1, anchorCol: 1, matrix: [['5', ''], ['M', '0']] });
    assert.equal(plan.ok, true);
    assert.deepEqual(plan.placements.map((p) => [p.row, p.col, p.display]), [[1, 1, '5'], [1, 2, ''], [2, 1, 'M'], [2, 2, '0']]);
});

test('a block that does not fit, touches a read-only cell, or holds an invalid value places nothing', () => {
    const tooBig = planPaste({ ...grid(2, 2), anchorRow: 1, anchorCol: 0, matrix: [['1'], ['2']] });
    assert.equal(tooBig.ok, false);
    assert.equal(tooBig.placements.length, 0);
    assert.match(tooBig.errors[0], /Nothing was placed/);

    const readonly = planPaste({ ...grid(2, 2, new Set(['0:1'])), anchorRow: 0, anchorCol: 0, matrix: [['1', '2']] });
    assert.equal(readonly.ok, false);
    assert.match(readonly.errors[0], /read-only/);

    const invalid = planPaste({ ...grid(2, 2), anchorRow: 0, anchorCol: 0, matrix: [['1', '25']] });
    assert.equal(invalid.ok, false);
    assert.match(invalid.errors[0], /above the highest possible score/);
});

test('the save scope is the assessments with a change; their blanks go along as Missing, nothing else does', () => {
    const cell = (a, kind, dirty, baseScore = '', baseMissing = false) => ({ a, kind, dirty, baseScore, baseMissing });
    const cells = [
        cell('1', 'score', true),            // 0: changed -> scope {1}
        cell('1', 'blank', false),           // 1: blank in scope -> Missing
        cell('1', 'missing', false, '', true), // 2: already Missing -> not sent
        cell('1', 'score', false, '14'),     // 3: unchanged score -> not sent
        cell('2', 'blank', false),           // 4: other assessment, untouched -> not sent
        cell('3', 'blank', true, '9'),       // 5: cleared score -> scope {3}, sent
        cell('3', 'blank', false),           // 6: blank in scope -> Missing
    ];
    const plan = planSave(cells);
    assert.deepEqual([...plan.scope].sort(), ['1', '3']);
    assert.deepEqual(plan.send, [0, 1, 5, 6]);
    assert.deepEqual(plan.pendingMissing, [1, 6]);
    assert.deepEqual(planSave([cell('1', 'blank', false), cell('2', 'blank', false)]).send, [], 'no change, nothing sent');
});

test('an all-blank assessment can be put in the save explicitly; only its blanks are sent, nothing else', () => {
    const cell = (a, kind, dirty, baseScore = '', baseMissing = false) => ({ a, kind, dirty, baseScore, baseMissing });
    const cells = [
        cell('7', 'blank', false),             // 0: all-blank assessment 7
        cell('7', 'blank', false),             // 1
        cell('7', 'missing', false, '', true), // 2: already Missing -> not sent
        cell('8', 'blank', false),             // 3: another assessment -> never sent
        cell('8', 'score', false, '5'),        // 4
    ];
    const plan = planSave(cells, new Set([7]));
    assert.deepEqual([...plan.scope], ['7'], 'numeric ids are matched as strings');
    assert.deepEqual(plan.send, [0, 1]);
    assert.deepEqual(plan.pendingMissing, [0, 1]);
    assert.deepEqual(planSave(cells, []).send, [], 'without the explicit choice nothing is sent');
});

test('an additional-support item\'s blanks are "not given": never sent, never pending Missing', () => {
    const cells = [
        { a: '9', kind: 'score', dirty: true, baseScore: '', baseMissing: false, support: true }, // 0: the learner given it
        { a: '9', kind: 'blank', dirty: false, baseScore: '', baseMissing: false, support: true }, // 1: not given
    ];
    const plan = planSave(cells, new Set(['9']));
    assert.deepEqual(plan.send, [0]);
    assert.deepEqual(plan.pendingMissing, []);
});
