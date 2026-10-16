/**
 * Class Record — the pure rules the grid follows (no DOM), so they can be
 * tested with `node --test` and shared by typing and pasting.
 *
 *   ''              -> blank    (No Evidence Yet — never 0)
 *   'M' / 'MISSING' -> missing  (an explicit Missing marker)
 *   a number        -> score    (0 is a real, Completed score)
 *   anything else   -> invalid  (never saved; the server re-validates too)
 */

export const MISSING_TOKENS = ['m', 'missing'];

export function parseCellInput(raw, max) {
    const text = String(raw ?? '').trim();
    if (text === '') {
        return { kind: 'blank', value: null, error: null };
    }
    if (MISSING_TOKENS.includes(text.toLowerCase())) {
        return { kind: 'missing', value: null, error: null };
    }
    if (!/^[+-]?(\d+(\.\d*)?|\.\d+)$/.test(text)) {
        return { kind: 'invalid', value: null, error: `"${text.slice(0, 20)}" is not a number. Type a score, leave it empty, or type M for Missing.` };
    }
    const value = Math.round(parseFloat(text) * 100) / 100;
    if (value < 0) {
        return { kind: 'invalid', value: null, error: 'A score cannot be negative.' };
    }
    if (max !== undefined && max !== null && value > Number(max) + 1e-6) {
        return { kind: 'invalid', value: null, error: `A score cannot be above the highest possible score (${formatScore(Number(max))}).` };
    }
    return { kind: 'score', value, error: null };
}

export function formatScore(value) {
    if (value === null || value === undefined) return '';
    return String(Math.round(Number(value) * 100) / 100);
}

/** What the cell should display for a parsed value. */
export function displayFor(parsed) {
    if (parsed.kind === 'missing') return 'M';
    if (parsed.kind === 'score') return formatScore(parsed.value);
    return '';
}

/**
 * Has a (valid) parsed value moved away from what was loaded from the server?
 * Automatic Missing (2026-10-04): a blank in an assessment being saved is
 * saved as Missing, so blank and M mean the same on save — emptying a
 * Missing cell is not a change, typing M on a blank one is (it puts that
 * assessment in the save), and clearing a score is.
 */
export function isChanged(parsed, baseScore, baseMissing) {
    if (parsed.kind === 'invalid') return true;
    const base = String(baseScore ?? '').trim();
    if (parsed.kind === 'missing') return !baseMissing;
    if (parsed.kind === 'blank') return base !== '';
    return !!baseMissing || base === '' || Math.abs(parseFloat(base) - parsed.value) >= 0.005;
}

/**
 * The SAVE SCOPE: the assessments with at least one changed cell. Returns
 * the cells to send — every changed cell, plus each still-blank cell of a
 * scoped assessment that is not yet Missing (it becomes Missing). A cell of
 * any other assessment is never sent, so it can never be read as blank.
 * `extraScope` adds assessments the teacher chose explicitly ("Save blanks
 * as Missing" for one assessment) even though no cell of theirs changed.
 *
 * @param {{a:string, kind:string, dirty:boolean, baseScore:string, baseMissing:boolean, support?:boolean}[]} cells
 * @param {Iterable<string>} [extraScope]
 * @returns {{scope:Set<string>, send:number[], pendingMissing:number[]}} indexes into `cells`
 */
export function planSave(cells, extraScope = []) {
    const scope = new Set([...cells.filter((c) => c.dirty).map((c) => c.a), ...[...extraScope].map(String)]);
    const send = [];
    const pendingMissing = [];
    cells.forEach((c, i) => {
        if (c.dirty) { send.push(i); return; }
        // An additional-support blank is "not given", never Missing: not sent.
        if (scope.has(c.a) && !c.support && c.kind === 'blank' && !c.baseMissing && String(c.baseScore ?? '').trim() === '') {
            send.push(i);
            pendingMissing.push(i);
        }
    });
    return { scope, send, pendingMissing };
}

/**
 * Clipboard text from Excel -> rows of cells. A trailing line break (Excel
 * always adds one) is not an extra row; a quoted cell ("a""b") is unquoted.
 */
export function parseClipboard(text) {
    const normalised = String(text ?? '').replace(/\r\n?/g, '\n');
    const lines = normalised.split('\n');
    if (lines.length > 1 && lines[lines.length - 1] === '') lines.pop();
    return lines.map((line) => line.split('\t').map((cell) => {
        const m = /^"([\s\S]*)"$/.exec(cell);
        return m ? m[1].replace(/""/g, '"') : cell;
    }));
}

export function isBlockPaste(text) {
    const matrix = parseClipboard(text);
    return matrix.length > 1 || (matrix[0] && matrix[0].length > 1);
}

/**
 * Where a pasted block would land, starting at the focused cell. It is
 * placed ONLY if every value fits inside the record, every target cell is
 * editable, and every value is valid — otherwise nothing is placed and
 * every problem is listed. Rows are never shifted and no cell outside the
 * block is touched.
 *
 * @param {{rows:number, cols:number, anchorRow:number, anchorCol:number, matrix:string[][], cellAt:(r:number,c:number)=>({editable:boolean,max:number,label:string,current:string}|null)}} args
 */
export function planPaste({ rows, cols, anchorRow, anchorCol, matrix, cellAt }) {
    const height = matrix.length;
    const width = Math.max(0, ...matrix.map((r) => r.length));
    const errors = [];
    const placements = [];

    if (anchorRow + height > rows || anchorCol + width > cols) {
        errors.push(`The copied block is ${height} row(s) × ${width} column(s), but only ${rows - anchorRow} row(s) × ${cols - anchorCol} column(s) fit from the selected cell. Nothing was placed — select a cell with room, or copy a smaller block.`);
        return { ok: false, placements, errors, height, width };
    }

    matrix.forEach((cells, i) => {
        cells.forEach((raw, j) => {
            const target = cellAt(anchorRow + i, anchorCol + j);
            if (!target) {
                errors.push(`Row ${i + 1}, column ${j + 1} has no assessment cell to receive it.`);
                return;
            }
            if (!target.editable) {
                errors.push(`${target.label} is read-only.`);
                return;
            }
            const parsed = parseCellInput(raw, target.max);
            if (parsed.kind === 'invalid') {
                errors.push(`${target.label}: ${parsed.error}`);
                return;
            }
            placements.push({ row: anchorRow + i, col: anchorCol + j, raw, parsed, label: target.label, current: target.current, display: displayFor(parsed) });
        });
    });

    return { ok: errors.length === 0, placements, errors, height, width };
}
