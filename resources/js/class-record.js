/**
 * Class Record grid (resources/views/teacher/class-record.blade.php).
 *
 * Edits stay in the page (and in a per-account local draft) until
 * "Save Scores"; a save is one all-or-nothing request carrying, for every
 * changed cell, the value it had when the page loaded, so the server can
 * refuse a stale edit instead of silently overwriting someone else's. The
 * browser never calculates a grade: after a save the server returns the
 * text of each touched learner's read-only computed cells.
 *
 * UI refinement pass (2026-09-28): Save lives in a sticky save bar;
 * Mark Missing / Clear Entry / Undo act on ONE selected cell and only change
 * its pending value (nothing is saved until Save Scores).
 */
import { parseCellInput, displayFor, isChanged, parseClipboard, isBlockPaste, planPaste, planSave, formatScore } from './class-record-core.js';
import { purgeForeign, readDraft, writeDraft, removeDraft, installDraftHygiene } from './class-record-drafts.js';

installDraftHygiene();

function init() {
    const root = document.getElementById('classRecord');
    const selectors = document.getElementById('classRecordSelectors');

    // Dismissible notices (never move the grid: they only collapse themselves).
    document.querySelectorAll('[data-dismiss]').forEach((btn) => btn.addEventListener('click', () => btn.closest('.alert')?.remove()));

    // Help panel: a real button toggling a region (keyboard and touch).
    const helpToggle = document.getElementById('crHelpToggle');
    const help = document.getElementById('crHelp');
    helpToggle?.addEventListener('click', () => {
        const open = helpToggle.getAttribute('aria-expanded') === 'true';
        helpToggle.setAttribute('aria-expanded', open ? 'false' : 'true');
        help.hidden = open;
    });

    if (!root) {
        selectors?.querySelectorAll('select').forEach((select) => select.addEventListener('change', () => submitSelectors(select)));
        return;
    }

    const canEdit = root.dataset.canEdit === '1';
    const rows = [...root.querySelectorAll('tbody tr[data-student-row]')];
    const grid = rows.map((tr) => [...tr.querySelectorAll('input.cr-cell')]);
    const cols = grid[0]?.length ?? 0;
    const status = document.getElementById('crStatus');
    const unsavedLabel = document.getElementById('crUnsaved');
    const saveBar = document.getElementById('crSaveBar');
    const saveBtn = document.getElementById('crSave');
    const discardBtn = document.getElementById('crDiscard');
    const clearBtn = document.getElementById('crClearEntry');
    const undoBtn = document.getElementById('crUndoCell');
    const scopeBtn = document.getElementById('crScopeAssessment');
    const selTarget = document.getElementById('crSelTarget');
    const selNote = document.getElementById('crSelNote');
    const errorsBox = document.getElementById('crErrors');
    const conflictsBox = document.getElementById('crConflicts');
    const draftNotice = document.getElementById('crDraftNotice');
    const scopeLabel = document.getElementById('crScope');
    const previewStatus = document.getElementById('crPreviewStatus');
    const scroller = root.querySelector('.cr-scroll');
    const main = document.getElementById('main');
    const draftKey = root.dataset.draftKey;
    let saving = false;
    let selected = null;
    let lastState = '';
    // Assessments the teacher put in the save explicitly ("Save this
    // assessment's blanks as Missing") — no cell of theirs has to change.
    const extraScope = new Set();

    purgeForeign(root.dataset.userId);
    // A read-only page (no assignment, closed term) never offers or keeps a draft.
    if (!canEdit) removeDraft(draftKey);

    const position = new Map();
    grid.forEach((cells, r) => cells.forEach((cell, c) => position.set(cell, [r, c])));
    const allCells = grid.flat();
    const keyOf = (cell) => `${cell.dataset.a}:${cell.dataset.s}`;
    const byKey = new Map(allCells.map((cell) => [keyOf(cell), cell]));
    const labelOf = (cell) => `${cell.dataset.learner} · ${cell.dataset.item}`;
    const savedDisplay = (cell) => (cell.dataset.baseMissing === '1' ? 'M' : (cell.dataset.baseScore || ''));
    const describe = (value) => (value === 'M' ? 'Missing' : (value === '' ? 'blank (becomes Missing when its assessment is saved)' : value));

    /**
     * Status in the save bar. `state` is one of: idle, unsaved, saving,
     * saved, invalid, offline, forbidden, conflict. The live region is only
     * rewritten when the state (or an outcome message) changes, so typing
     * does not trigger an announcement on every keystroke.
     */
    function setStatus(state, text) {
        if (!status) return;
        if (state === lastState && status.textContent === text) return;
        lastState = state;
        status.dataset.state = state;
        status.textContent = text;
    }

    function evaluate(cell) {
        const parsed = parseCellInput(cell.value, parseFloat(cell.dataset.max));
        const dirty = isChanged(parsed, cell.dataset.baseScore, cell.dataset.baseMissing === '1');
        const td = cell.parentElement;
        cell.classList.toggle('cr-dirty', dirty && parsed.kind !== 'invalid');
        cell.classList.toggle('cr-invalid', parsed.kind === 'invalid');
        cell.classList.toggle('cr-missing', parsed.kind === 'missing');
        td.classList.toggle('cr-td-dirty', dirty && parsed.kind !== 'invalid');
        td.classList.toggle('cr-td-invalid', parsed.kind === 'invalid');
        if (parsed.kind === 'invalid') {
            cell.title = parsed.error;
            cell.setAttribute('aria-invalid', 'true');
        } else {
            cell.title = dirty ? `Unsaved change (saved value: ${describe(savedDisplay(cell))})` : '';
            cell.removeAttribute('aria-invalid');
        }
        return { parsed, dirty };
    }

    const dirtyCells = () => allCells.filter((cell) => evaluate(cell).dirty);
    const hasUnsaved = () => canEdit && (dirtyCells().length > 0 || extraScope.size > 0);
    /** Blank, never-saved cells of one assessment: what its explicit save would turn into Missing. */
    const openBlanks = (a) => allCells.filter((cell) => cell.dataset.a === a && cell.dataset.support !== '1' && cell.dataset.baseMissing !== '1' && cell.dataset.baseScore === ''
        && parseCellInput(cell.value, parseFloat(cell.dataset.max)).kind === 'blank');

    /** The save scope (planSave): which assessments are being saved and which cells go with them. */
    function savePlan() {
        const states = allCells.map((cell) => {
            const { parsed, dirty } = evaluate(cell);
            return { a: cell.dataset.a, kind: parsed.kind, dirty, baseScore: cell.dataset.baseScore, baseMissing: cell.dataset.baseMissing === '1', support: cell.dataset.support === '1' };
        });
        const plan = planSave(states, extraScope);
        return { scope: plan.scope, send: plan.send.map((i) => allCells[i]), pending: plan.pendingMissing.map((i) => allCells[i]) };
    }

    function refresh(persist = true) {
        const dirty = dirtyCells();
        const invalid = dirty.filter((cell) => cell.classList.contains('cr-invalid'));
        const plan = canEdit ? savePlan() : { scope: new Set(), pending: [] };
        const pending = new Set(plan.pending);
        allCells.forEach((cell) => {
            const on = pending.has(cell);
            cell.classList.toggle('cr-pending-missing', on);
            if (on) { cell.placeholder = 'M?'; cell.title = 'Blank — will be saved as Missing with this assessment.'; } else { cell.placeholder = ''; }
        });
        if (scopeLabel) {
            const names = [...plan.scope].map((a) => root.querySelector(`input.cr-cell[data-a="${a}"]`)?.dataset.item ?? a);
            scopeLabel.textContent = names.length === 0 ? ''
                : `Being saved: ${names.join(', ')}` + (plan.pending.length ? ` — ${plan.pending.length} blank entr${plan.pending.length === 1 ? 'y' : 'ies'} will become Missing.` : '.');
        }
        const pendingWork = dirty.length > 0 || plan.pending.length > 0;
        if (saveBtn) saveBtn.disabled = saving || !pendingWork;
        if (discardBtn) discardBtn.disabled = saving || (dirty.length === 0 && extraScope.size === 0);
        if (unsavedLabel) {
            unsavedLabel.textContent = dirty.length > 0
                ? `${dirty.length} unsaved change${dirty.length === 1 ? '' : 's'}` + (invalid.length ? ` — ${invalid.length} to correct` : '')
                : (plan.pending.length ? `${plan.pending.length} blank entr${plan.pending.length === 1 ? 'y' : 'ies'} to save as Missing` : 'No unsaved changes');
        }
        if (!saving && pendingWork && !['invalid', 'offline', 'forbidden', 'conflict'].includes(lastState)) {
            setStatus('unsaved', 'Not saved yet — press Save Scores.');
        }
        if (!saving && !pendingWork && lastState === 'unsaved') setStatus('idle', '');
        if (persist && canEdit) {
            const cells = {};
            dirty.forEach((cell) => { cells[keyOf(cell)] = { value: cell.value, base_score: cell.dataset.baseScore, base_missing: cell.dataset.baseMissing }; });
            writeDraft(draftKey, cells, undefined, [...extraScope]);
        }
        renderSelection();
        schedulePreview();
        return dirty;
    }

    // ---------------- live preview (read-only, server-computed) -----------
    // The browser never calculates a grade: a debounced request sends the
    // valid unsaved cells, and the server answers with the computed cells
    // from the same grading engine as a save — nothing is written. A newer
    // request cancels an older one, and a late answer is ignored.
    let previewSeq = 0;
    let previewTimer = null;
    let previewCtrl = null;
    let lastPreviewSig = '';
    let previewed = new Set();

    function setPreview(state, text) {
        if (!previewStatus) return;
        previewStatus.dataset.state = state;
        previewStatus.textContent = text;
    }

    function previewPayload() {
        const cells = allCells.filter((cell) => cell.classList.contains('cr-dirty')).map((cell) => {
            const parsed = parseCellInput(cell.value, parseFloat(cell.dataset.max));
            return { assessment_id: Number(cell.dataset.a), student_id: Number(cell.dataset.s), score: parsed.kind === 'score' ? formatScore(parsed.value) : null };
        });
        const students = new Set([...cells.map((c) => c.student_id), ...previewed]);
        return { cells, student_ids: [...students].sort((x, y) => x - y) };
    }

    function cancelPreview() {
        previewSeq += 1;
        clearTimeout(previewTimer);
        previewCtrl?.abort();
    }

    function schedulePreview() {
        if (!canEdit || saving || !root.dataset.previewUrl) return;
        const payload = previewPayload();
        const sig = JSON.stringify(payload);
        if (sig === lastPreviewSig) return;
        lastPreviewSig = sig;
        cancelPreview();
        if (payload.student_ids.length === 0) { setPreview('idle', ''); return; }
        const seq = previewSeq;
        previewTimer = setTimeout(() => runPreview(payload, seq), 350);
    }

    async function runPreview(payload, seq) {
        const controller = new AbortController();
        previewCtrl = controller;
        const draftStudents = new Set(payload.cells.map((c) => String(c.student_id)));
        let response = null;
        let data = {};
        try {
            response = await fetch(root.dataset.previewUrl, {
                method: 'POST',
                credentials: 'same-origin',
                signal: controller.signal,
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                body: JSON.stringify({ section_id: Number(root.dataset.sectionId), subject_id: Number(root.dataset.subjectId), period: Number(root.dataset.period), ...payload }),
            });
            data = await response.json().catch(() => ({}));
        } catch (e) {
            if (e.name === 'AbortError') return;
            response = null;
        }
        if (seq !== previewSeq) return; // a newer edit (or a save) superseded this answer
        if (!response || !response.ok || response.redirected || !data.preview || !data.rows) {
            lastPreviewSig = ''; // let the next edit try again
            draftStudents.forEach((sid) => root.querySelector(`tr[data-student-row="${sid}"]`)?.classList.add('cr-preview-stale'));
            setPreview('stale', `Preview unavailable${data.message ? ` (${data.message})` : ''} — the computed columns are not up to date. Your entries are kept; Save Scores still checks everything.`);
            return;
        }
        Object.entries(data.rows).forEach(([sid, values]) => {
            updateRow(sid, values);
            const tr = root.querySelector(`tr[data-student-row="${sid}"]`);
            if (!tr) return;
            const isDraft = draftStudents.has(sid);
            tr.classList.toggle('cr-row-preview', isDraft);
            tr.classList.remove('cr-preview-stale');
            const note = tr.querySelector('[data-cr-out="grade:initial:note"]');
            if (isDraft && note) note.textContent = `${note.textContent} · unsaved preview`;
        });
        previewed = new Set([...draftStudents].map(Number));
        const invalidCount = allCells.filter((cell) => cell.classList.contains('cr-invalid')).length;
        setPreview(draftStudents.size ? 'live' : 'idle', draftStudents.size
            ? 'Running Initial Grade · Unsaved preview' + (invalidCount ? ` (${invalidCount} cell(s) to correct are left out)` : '')
            : '');
    }

    // ---------------- selected-cell actions --------------------------------
    function select(cell) {
        rows.forEach((tr) => tr.classList.remove('cr-row-active'));
        root.querySelectorAll('.cr-col-active').forEach((th) => th.classList.remove('cr-col-active'));
        selected = cell && !cell.readOnly ? cell : null;
        if (cell) {
            cell.closest('tr').classList.add('cr-row-active');
            root.querySelector(`th[data-item-head="${cell.dataset.a}"]`)?.classList.add('cr-col-active');
        }
        renderSelection();
    }

    function renderSelection() {
        if (!selTarget) return;
        if (!selected) {
            selTarget.textContent = 'No cell selected — click or tab into a score cell to clear it or undo it.';
            selNote.textContent = '';
            [clearBtn, undoBtn].forEach((b) => { if (b) b.disabled = true; });
            return;
        }
        const now = parseCellInput(selected.value, parseFloat(selected.dataset.max));
        const current = now.kind === 'invalid' ? `“${selected.value}” (not a valid score)` : describe(displayFor(now));
        const saved = savedDisplay(selected);
        selTarget.textContent = `Selected: ${selected.dataset.learner} · ${selected.dataset.item} · HPS ${formatScore(selected.dataset.max)} · Now: ${current}`;
        const dirty = isChanged(now, selected.dataset.baseScore, selected.dataset.baseMissing === '1');
        if (dirty) {
            const replaces = selected.dataset.baseScore !== '' ? ` It replaces the saved score ${saved}; Undo keeps the saved score.` : '';
            selNote.textContent = `Pending change — click Save Scores.${replaces}`;
        } else {
            selNote.textContent = `Saved value: ${describe(saved)}.`;
        }
        clearBtn.disabled = selected.value.trim() === '';
        undoBtn.disabled = !dirty;
        if (scopeBtn) {
            const on = extraScope.has(selected.dataset.a);
            scopeBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
            scopeBtn.querySelector('span').textContent = on ? `Keep ${selected.dataset.item}'s blanks blank` : "Save this assessment's blanks as Missing";
            scopeBtn.disabled = !on && openBlanks(selected.dataset.a).length === 0;
        }
    }

    function actOnSelected(value) {
        if (!selected || selected.readOnly) return;
        selected.value = value;
        refresh();
        selected.focus();
        selected.select();
    }
    // mousedown is prevented so the selected cell keeps focus and the target
    // cannot change between pressing and releasing the button.
    [clearBtn, undoBtn, scopeBtn].forEach((b) => b?.addEventListener('mousedown', (e) => e.preventDefault()));
    clearBtn?.addEventListener('click', () => actOnSelected(''));
    undoBtn?.addEventListener('click', () => { if (selected) actOnSelected(savedDisplay(selected)); });
    scopeBtn?.addEventListener('click', () => {
        if (!selected) return;
        const a = selected.dataset.a;
        if (extraScope.has(a)) extraScope.delete(a); else extraScope.add(a);
        refresh();
        selected.focus();
    });

    // ---------------- drafts ------------------------------------------------
    function offerDraft() {
        const draft = readDraft(draftKey);
        if (!canEdit || !draft || !draftNotice) return;
        const entries = Object.entries(draft.cells || {}).filter(([key]) => byKey.has(key));
        // A chosen assessment is re-checked against what the server just
        // rendered: it must still be on this (editable) page and still have
        // blank, never-saved cells. Cells scored or marked since then are not
        // blank any more, so they are never sent — nothing newer is overwritten.
        const nameOf = (a) => root.querySelector(`input.cr-cell[data-a="${a}"]`)?.dataset.item;
        const scope = (draft.scope || []).map(String).filter((a) => nameOf(a) !== undefined)
            .map((a) => ({ a, name: nameOf(a), blanks: openBlanks(a).length }));
        const usable = scope.filter((s) => s.blanks > 0);
        const dropped = scope.filter((s) => s.blanks === 0);
        if (entries.length === 0 && usable.length === 0) {
            if ((draft.scope || []).length) removeDraft(draftKey);
            return;
        }
        draftNotice.innerHTML = '';
        const parts = [];
        if (entries.length) parts.push(`${entries.length} unsaved entr${entries.length === 1 ? 'y' : 'ies'}`);
        usable.forEach((s) => parts.push(`${s.name} chosen to save its blanks as Missing (${s.blanks} blank entr${s.blanks === 1 ? 'y' : 'ies'} now)`));
        draftNotice.append(`You have ${parts.join(' and ')} for this class from ${new Date(draft.at).toLocaleString()}. Nothing of this is saved. `
            + (dropped.length ? `(${dropped.map((s) => s.name).join(', ')}: no blank entries are left, so that choice is not restored.) ` : ''));
        draftNotice.dataset.restoreScope = usable.map((s) => s.a).join(',');
        const restore = button('Restore them', 'btn btn-primary btn-xs ml-2', () => {
            entries.forEach(([key, d]) => {
                const cell = byKey.get(key);
                // Keep the value each entry was based on: if the record
                // changed since, the save is refused as a conflict rather
                // than overwriting the newer value.
                cell.dataset.baseScore = d.base_score ?? '';
                cell.dataset.baseMissing = d.base_missing ?? '0';
                cell.value = d.value;
            });
            usable.forEach((s) => extraScope.add(s.a));
            draftNotice.classList.add('hidden');
            refresh();
        });
        const discard = button('Discard', 'btn btn-outline btn-xs ml-2', () => {
            removeDraft(draftKey);
            draftNotice.classList.add('hidden');
        });
        draftNotice.append(restore, discard);
        draftNotice.classList.remove('hidden');
    }

    function button(text, className, onClick) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = className;
        b.textContent = text;
        b.addEventListener('click', onClick);
        return b;
    }

    // ---------------- keeping the focused cell visible ---------------------
    // The grid scrolls itself (scroll-margin keeps the cell clear of the
    // sticky header and learner column); then <main> is nudged so the cell
    // is not hidden behind the sticky save bar (or an on-screen keyboard).
    function ensureVisible(cell) {
        cell.scrollIntoView({ block: 'nearest', inline: 'nearest' });
        if (!saveBar || !main) return;
        const cellRect = cell.getBoundingClientRect();
        const barTop = saveBar.getBoundingClientRect().top;
        const viewBottom = window.visualViewport ? window.visualViewport.offsetTop + window.visualViewport.height : window.innerHeight;
        const limit = Math.min(barTop, viewBottom) - 8;
        if (cellRect.bottom > limit) main.scrollBy({ top: cellRect.bottom - limit });
    }
    window.visualViewport?.addEventListener('resize', () => {
        if (document.activeElement?.classList?.contains('cr-cell')) ensureVisible(document.activeElement);
    });

    // ---------------- keyboard ------------------------------------------
    function focusCell(r, c) {
        const cell = grid[r]?.[c];
        if (cell) { cell.focus(); cell.select(); }
    }

    allCells.forEach((cell) => {
        cell.addEventListener('focus', () => {
            cell.select();
            select(cell);
            ensureVisible(cell);
        });
        cell.addEventListener('input', () => refresh());
        cell.addEventListener('blur', () => {
            const parsed = parseCellInput(cell.value, parseFloat(cell.dataset.max));
            // A blank on a Missing cell stays Missing (blank = Missing on save).
            if (parsed.kind !== 'invalid') cell.value = parsed.kind === 'blank' && cell.dataset.baseMissing === '1' ? 'M' : displayFor(parsed);
            refresh();
        });
        cell.addEventListener('keydown', (e) => {
            const [r, c] = position.get(cell);
            const atStart = cell.selectionStart === 0 && cell.selectionEnd === 0;
            const atEnd = cell.selectionStart === cell.value.length;
            const allSelected = cell.selectionStart === 0 && cell.selectionEnd === cell.value.length;
            switch (e.key) {
                case 'ArrowUp': e.preventDefault(); focusCell(r - 1, c); break;
                case 'ArrowDown': e.preventDefault(); focusCell(r + 1, c); break;
                case 'Enter': e.preventDefault(); focusCell(e.shiftKey ? r - 1 : r + 1, c); break;
                case 'ArrowLeft': if (atStart || allSelected) { e.preventDefault(); focusCell(r, c - 1); } break;
                case 'ArrowRight': if (atEnd || allSelected) { e.preventDefault(); focusCell(r, c + 1); } break;
                case 'Escape':
                    e.preventDefault();
                    cell.value = savedDisplay(cell);
                    refresh();
                    cell.select();
                    break;
                default: break;
            }
        });
        cell.addEventListener('paste', (e) => {
            if (!canEdit || cell.readOnly) return;
            const text = e.clipboardData?.getData('text/plain') ?? '';
            if (!isBlockPaste(text)) return; // a single value pastes normally
            e.preventDefault();
            previewPaste(position.get(cell), parseClipboard(text));
        });
    });

    // Ctrl/Cmd+S saves only while working in the grid or save bar — never
    // hijacked while typing in the filters or anywhere else.
    document.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's' && canEdit
            && (root.contains(document.activeElement) || saveBar?.contains(document.activeElement) || document.activeElement === document.body)) {
            e.preventDefault();
            save();
        }
    });

    window.addEventListener('beforeunload', (e) => {
        if (hasUnsaved() && !navigatingAfterChoice) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    // ---------------- discard (save bar) ------------------------------------
    function revertAll() {
        allCells.forEach((cell) => { cell.value = savedDisplay(cell); });
        extraScope.clear();
        removeDraft(draftKey);
        errorsBox?.classList.add('hidden');
        conflictsBox?.classList.add('hidden');
        allCells.forEach((cell) => cell.classList.remove('cr-conflict'));
        lastState = '';
        refresh(false);
    }
    discardBtn?.addEventListener('click', () => {
        document.getElementById('crDiscardMessage').textContent =
            `${dirtyCells().length} unsaved change(s)${extraScope.size ? ' and the blanks chosen to be saved as Missing' : ''} will be discarded. Every cell returns to its last saved value; nothing already saved is changed.`;
        window.showModal('crDiscardModal');
    });
    document.getElementById('crDiscardCancel')?.addEventListener('click', () => window.hideModal('crDiscardModal'));
    document.getElementById('crDiscardConfirm')?.addEventListener('click', () => {
        window.hideModal('crDiscardModal');
        revertAll();
        setStatus('idle', 'Changes discarded.');
    });

    // ---------------- leave guard (save / discard / cancel) --------------
    let navigatingAfterChoice = false;
    let pendingAction = null;
    const leaveMessage = document.getElementById('crLeaveMessage');

    function guard(action, message) {
        if (!hasUnsaved()) { action(); return; }
        pendingAction = action;
        leaveMessage.textContent = message;
        window.showModal('crLeaveModal');
    }
    document.getElementById('crLeaveCancel')?.addEventListener('click', () => { pendingAction = null; window.hideModal('crLeaveModal'); });
    document.getElementById('crLeaveDiscard')?.addEventListener('click', () => {
        revertAll();
        window.hideModal('crLeaveModal');
        const action = pendingAction; pendingAction = null;
        navigatingAfterChoice = true;
        action?.();
    });
    document.getElementById('crLeaveSave')?.addEventListener('click', async () => {
        window.hideModal('crLeaveModal');
        const action = pendingAction; pendingAction = null;
        if (await save()) {
            navigatingAfterChoice = true;
            action?.();
        }
    });

    document.querySelectorAll('[data-cr-leave]').forEach((link) => {
        link.addEventListener('click', (e) => {
            if (!hasUnsaved()) return;
            e.preventDefault();
            guard(() => { window.location.href = link.href; },
                'Save your scores before leaving this Class Record, or discard them. Unsaved scores are not kept by the system.');
        });
    });
    document.querySelector('[data-cr-export]')?.addEventListener('click', (e) => {
        if (!hasUnsaved()) return;
        e.preventDefault();
        const href = e.currentTarget.href;
        guard(() => { window.location.href = href; },
            'The download contains only SAVED scores. Save first to include your changes, or discard them to download the last saved version.');
    });
    selectors?.querySelectorAll('select').forEach((select) => {
        const original = select.value;
        select.addEventListener('change', () => {
            if (!hasUnsaved()) { selected = null; submitSelectors(select); return; }
            const chosen = select.value;
            select.value = original; // nothing changes until the choice is made
            guard(() => { select.value = chosen; selected = null; submitSelectors(select); },
                'Save your scores before opening another class, term or subject, or discard them.');
        });
    });

    // ---------------- paste preview -------------------------------------
    let pendingPaste = null;
    function previewPaste([anchorRow, anchorCol], matrix) {
        const plan = planPaste({
            rows: grid.length, cols, anchorRow, anchorCol, matrix,
            cellAt: (r, c) => {
                const cell = grid[r]?.[c];
                return cell ? { editable: !cell.readOnly, max: parseFloat(cell.dataset.max), label: labelOf(cell), current: cell.value } : null;
            },
        });
        pendingPaste = plan.ok ? plan : null;
        const summary = document.getElementById('crPasteSummary');
        const body = document.getElementById('crPasteBody');
        const apply = document.getElementById('crPasteApply');
        body.innerHTML = '';
        const table = document.createElement('table');
        table.className = 'tbl text-xs';
        const tbody = document.createElement('tbody');
        if (plan.ok) {
            summary.textContent = `${plan.placements.length} cell(s) in a ${plan.height} × ${plan.width} block will be filled. They are not saved until you press Save Scores.`;
            table.innerHTML = '<thead><tr><th>Cell</th><th>Now</th><th>After paste</th></tr></thead>';
            plan.placements.forEach((p) => {
                const tr = document.createElement('tr');
                [p.label, p.current || '(empty)', p.display === 'M' ? 'M (Missing)' : (p.display || '(empty)')].forEach((t) => {
                    const td = document.createElement('td');
                    td.textContent = t;
                    tr.append(td);
                });
                tbody.append(tr);
            });
        } else {
            summary.textContent = 'Nothing was placed. Correct the copied values (or pick another starting cell) and paste again:';
            plan.errors.forEach((err) => {
                const tr = document.createElement('tr');
                const td = document.createElement('td');
                td.textContent = err;
                tr.append(td);
                tbody.append(tr);
            });
        }
        table.append(tbody);
        body.append(table);
        apply.disabled = !plan.ok;
        apply.classList.toggle('hidden', !plan.ok);
        window.showModal('crPasteModal');
    }

    document.getElementById('crPasteApply')?.addEventListener('click', () => {
        if (!pendingPaste) return;
        pendingPaste.placements.forEach((p) => { grid[p.row][p.col].value = p.display; });
        pendingPaste = null;
        window.hideModal('crPasteModal');
        refresh();
    });
    document.getElementById('crPasteCancel')?.addEventListener('click', () => {
        pendingPaste = null;
        window.hideModal('crPasteModal');
    });

    // ---------------- save ----------------------------------------------
    function showList(box, heading, items, extra = []) {
        box.innerHTML = '';
        const head = document.createElement('div');
        head.className = 'flex items-start gap-2';
        const p = document.createElement('p');
        p.className = 'font-medium flex-1';
        p.textContent = heading;
        const close = button('', 'cr-dismiss', () => box.classList.add('hidden'));
        close.setAttribute('aria-label', 'Dismiss this message');
        close.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';
        head.append(p, close);
        const ul = document.createElement('ul');
        ul.className = 'list-disc list-inside mt-1';
        items.forEach((t) => { const li = document.createElement('li'); li.textContent = t; ul.append(li); });
        box.append(head, ul, ...extra);
        box.classList.remove('hidden');
    }

    function focusProblem(cell) {
        if (!cell) return;
        cell.focus();
        ensureVisible(cell);
    }

    /** @returns {Promise<boolean>} true when everything was saved */
    async function save() {
        if (saving || !canEdit) return false;
        const dirty = refresh();
        const sendCells = savePlan().send;
        if (sendCells.length === 0) return true;
        errorsBox.classList.add('hidden');
        conflictsBox.classList.add('hidden');
        allCells.forEach((cell) => cell.classList.remove('cr-conflict'));

        const invalid = dirty.filter((cell) => cell.classList.contains('cr-invalid'));
        if (invalid.length) {
            showList(errorsBox, 'Nothing was saved. Correct these cells first:', invalid.map((cell) => `${labelOf(cell)}: ${cell.title}`));
            setStatus('invalid', `Not saved — ${invalid.length} cell(s) need correcting (marked with !).`);
            focusProblem(invalid[0]);
            return false;
        }

        // Every changed cell, plus the still-blank cells of the assessments
        // being saved (they become Missing). Nothing else is sent.
        const payload = {
            section_id: Number(root.dataset.sectionId),
            subject_id: Number(root.dataset.subjectId),
            period: Number(root.dataset.period),
            cells: sendCells.map((cell) => {
                const parsed = parseCellInput(cell.value, parseFloat(cell.dataset.max));
                return {
                    assessment_id: Number(cell.dataset.a),
                    student_id: Number(cell.dataset.s),
                    score: parsed.kind === 'score' ? formatScore(parsed.value) : null,
                    missing: parsed.kind !== 'score',
                    base_score: cell.dataset.baseScore === '' ? null : cell.dataset.baseScore,
                    base_missing: cell.dataset.baseMissing === '1',
                };
            }),
        };

        saving = true;
        cancelPreview(); // a preview answer must never land on top of the saved figures
        if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = 'Saving…'; }
        if (discardBtn) discardBtn.disabled = true;
        setStatus('saving', 'Saving…');
        let response;
        let data = {};
        try {
            response = await fetch(root.dataset.saveUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                body: JSON.stringify(payload),
            });
            data = await response.json().catch(() => ({}));
        } catch (e) {
            response = null;
        } finally {
            saving = false;
            if (saveBtn) saveBtn.innerHTML = '<i class="bi bi-save" aria-hidden="true"></i> Save Scores';
        }

        if (!response) {
            lastState = '';
            setStatus('offline', 'Save failed — no connection. Your entries are still here; press Save Scores to retry.');
            refresh();
            return false;
        }
        // Success only when the save endpoint itself confirms it: a redirect
        // followed to another page (e.g. the sign-in page) or a body that is
        // not the save response is a failure, never "Saved" (final audit).
        const confirmed = response.ok && !response.redirected && typeof data.changed === 'number';
        if (response.ok && !confirmed) {
            showList(errorsBox, 'The server did not confirm this save. Your entries are still here — reload the page to check your session, then press Save Scores again.', []);
            setStatus('offline', 'Not saved — the server did not confirm the save. Your entries are still here.');
            refresh();
            return false;
        }
        if (response.status === 401 || response.status === 419) {
            // 401 carries the app's own account message; a 419's is the framework's "CSRF token mismatch."
            showList(errorsBox, (response.status === 401 && data.message ? data.message + ' ' : '') + 'Your session has ended. Sign in again (in a new tab if you like), then press Save Scores — your entries are kept on this page.', []);
            setStatus('forbidden', 'Not saved — your session has ended.');
            refresh();
            return false;
        }
        if (confirmed) {
            payload.cells.forEach((sent) => {
                const cell = byKey.get(`${sent.assessment_id}:${sent.student_id}`);
                cell.dataset.baseScore = sent.score ?? '';
                cell.dataset.baseMissing = sent.score === null ? '1' : '0';
                if (sent.score === null) cell.value = 'M';
            });
            extraScope.clear();
            // The saved (authoritative) figures replace any preview.
            Object.entries(data.rows || {}).forEach(([studentId, values]) => updateRow(studentId, values));
            root.querySelectorAll('tr.cr-row-preview, tr.cr-preview-stale').forEach((tr) => tr.classList.remove('cr-row-preview', 'cr-preview-stale'));
            previewed = new Set();
            lastPreviewSig = '';
            setPreview('idle', '');
            lastState = '';
            refresh();
            setStatus('saved', `${data.message || 'Saved.'} ${new Date().toLocaleTimeString()}`);
            if (data.monitoring_warning) showList(conflictsBox, data.monitoring_warning, []);
            return true;
        }
        if (response.status === 422 && data.errors) {
            let first = null;
            const lines = Object.entries(data.errors).map(([key, message]) => {
                const cell = byKey.get(key);
                if (cell) {
                    cell.classList.add('cr-invalid');
                    cell.parentElement.classList.add('cr-td-invalid');
                    cell.title = message;
                    first ??= cell;
                }
                return `${cell ? labelOf(cell) : key}: ${message}`;
            });
            showList(errorsBox, data.message || 'Nothing was saved.', lines);
            setStatus('invalid', 'Not saved — see the cells marked with !.');
            focusProblem(first);
        } else if (response.status === 409 && data.conflicts) {
            const conflicted = Object.entries(data.conflicts);
            const lines = conflicted.map(([key, c]) => {
                const cell = byKey.get(key);
                if (cell) cell.classList.add('cr-conflict');
                const now = c.current_missing ? 'Missing' : (c.current_score === '' ? 'empty' : c.current_score);
                return `${cell ? labelOf(cell) : key}: now ${now} — your entry ${cell ? (cell.value || 'empty') : ''}`;
            });
            const keepMine = button('Keep my entries (replace the newer values)', 'btn btn-warning btn-xs mt-2 mr-2', () => {
                conflicted.forEach(([key, c]) => {
                    const cell = byKey.get(key);
                    if (!cell) return;
                    cell.dataset.baseScore = c.current_score ?? '';
                    cell.dataset.baseMissing = c.current_missing ? '1' : '0';
                    cell.classList.remove('cr-conflict');
                });
                conflictsBox.classList.add('hidden');
                lastState = '';
                refresh();
                setStatus('unsaved', 'Press Save Scores to save your entries over the newer values.');
            });
            const useTheirs = button('Use the saved values', 'btn btn-outline btn-xs mt-2', () => {
                conflicted.forEach(([key, c]) => {
                    const cell = byKey.get(key);
                    if (!cell) return;
                    cell.dataset.baseScore = c.current_score ?? '';
                    cell.dataset.baseMissing = c.current_missing ? '1' : '0';
                    cell.value = c.current_missing ? 'M' : (c.current_score ?? '');
                    cell.classList.remove('cr-conflict');
                });
                conflictsBox.classList.add('hidden');
                lastState = '';
                refresh();
            });
            showList(conflictsBox, data.message || 'Some cells changed since you opened the record.', lines, [keepMine, useTheirs]);
            setStatus('conflict', 'Not saved — someone else changed some of these cells. Choose which values to keep.');
        } else if (response.status === 403 || response.status === 423) {
            // Assignment removed/replaced, or the term closed: nothing can be
            // saved here any more, so the local draft is dropped too.
            removeDraft(draftKey);
            showList(errorsBox, data.message || 'You can no longer save scores for this class.', []);
            setStatus('forbidden', 'Not saved — this class is no longer open to you for encoding.');
        } else {
            showList(errorsBox, data.message || `Save failed (HTTP ${response.status}). Your entries are still here; press Save Scores to retry.`, []);
            setStatus('offline', 'Save failed — your entries are still here. Press Save Scores to retry.');
        }
        refresh(response.status !== 403 && response.status !== 423);
        return false;
    }

    function updateRow(studentId, values) {
        const tr = root.querySelector(`tr[data-student-row="${studentId}"]`);
        if (!tr) return;
        tr.querySelectorAll('[data-cr-out]').forEach((el) => {
            const key = el.dataset.crOut;
            if (Object.prototype.hasOwnProperty.call(values, key)) el.textContent = values[key];
        });
        tr.querySelectorAll('[data-cr-why]').forEach((el) => {
            const key = el.dataset.crWhy;
            if (Object.prototype.hasOwnProperty.call(values, key)) el.title = values[key];
        });
    }

    saveBtn?.addEventListener('click', () => { save(); });

    // ---------------- copy ----------------------------------------------
    document.getElementById('crCopy')?.addEventListener('click', (e) => {
        const btn = e.currentTarget;
        const mode = document.getElementById('crCopyMode')?.value === 'scores' ? 'scores' : 'full';
        const report = (state, text) => (status ? setStatus(state, text) : (btn.title = text));
        const run = async () => {
            try {
                const url = new URL(btn.dataset.copyUrl, window.location.href);
                url.searchParams.set('mode', mode);
                const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'text/plain' } });
                if (!response.ok) throw new Error(String(response.status));
                const text = await response.text();
                if (navigator.clipboard?.writeText) {
                    await navigator.clipboard.writeText(text);
                } else {
                    const area = document.createElement('textarea');
                    area.value = text;
                    document.body.append(area);
                    area.select();
                    document.execCommand('copy');
                    area.remove();
                }
                btn.dataset.copied = mode;
                report('idle', mode === 'scores'
                    ? 'Saved scores copied (scores only) — paste into the first cell of a matching score range.'
                    : 'Saved scores copied with LRN, names and headers — paste into cell A1 of a blank sheet.');
            } catch (err) {
                report('offline', 'Could not copy the scores. Use Download Excel Class Record instead.');
            }
        };
        if (hasUnsaved()) {
            guard(run, 'Copy Scores copies only SAVED scores. Save first to include your changes, or discard them to copy the last saved version.');
            return;
        }
        run();
    });

    // ---------------- a newly added assessment column ---------------------
    const newId = root.dataset.newAssessment;
    if (newId) {
        const head = root.querySelector(`th[data-item-head="${newId}"]`);
        if (head && scroller) {
            const left = head.offsetLeft - (scroller.clientWidth / 2) + (head.offsetWidth / 2);
            scroller.scrollLeft = Math.max(0, left);
            const first = canEdit ? root.querySelector(`input.cr-cell[data-a="${newId}"]`) : null;
            first?.focus({ preventScroll: true });
            setTimeout(() => head.classList.remove('cr-new'), 6000);
        }
    }

    if (canEdit) {
        offerDraft();
        refresh(false); // never overwrite a stored draft before the user acts
    }
}

function submitSelectors(select) {
    (select.dataset.crReset || '').split(',').filter(Boolean).forEach((name) => {
        const dependant = select.form.querySelector(`[name="${name}"]`);
        if (dependant) dependant.disabled = true;
    });
    select.form.submit();
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
else init();
