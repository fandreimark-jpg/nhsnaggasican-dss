/**
 * Browser checks for the Class Record workflow, against the ISOLATED server
 * only (see tests/Browser/isolation.php):
 *
 *   php tests/Browser/prepare.php
 *   php -S 127.0.0.1:8765 -t public tests/Browser/router.php     (separate shell)
 *   node tests/Browser/run.mjs [outputDir]
 *
 * Refuses to run unless the server answers the isolated-only sign-in.
 */
import { launchChrome, sleep } from './cdp.mjs';
import { mkdirSync, readdirSync, existsSync, statSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = 'http://127.0.0.1:8765';
const PASSWORD = 'Browser-Test-Only-1';
const OUT = resolve(process.argv[2] ?? 'storage/framework/browser-testing/results');
const DOWNLOADS = join(OUT, 'downloads');
mkdirSync(DOWNLOADS, { recursive: true });

const results = [];
function check(name, ok, detail = '') {
    results.push({ name, ok: !!ok, detail });
    console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? ' — ' + detail : ''}`);
}

const chromePath = ['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'].find(existsSync);
const { cdp, proc } = await launchChrome({ chromePath });

async function login(page, email) {
    await page.goto(`${BASE}/login`);
    await page.navigating(() => page.eval(`(() => {
        const f = document.querySelector('form[action$="/login"]');
        f.querySelector('[name=email]').value = ${JSON.stringify(email)};
        f.querySelector('[name=password]').value = ${JSON.stringify(PASSWORD)};
        f.requestSubmit();
    })()`));
}
async function logout(page) {
    await page.navigating(() => page.eval(`document.querySelector('form[action$="/logout"]').requestSubmit()`));
}
const ids = async (page) => page.eval(`(() => { const r = document.getElementById('classRecord'); return r ? { section: r.dataset.sectionId, subject: r.dataset.subjectId, user: r.dataset.userId, key: r.dataset.draftKey } : null; })()`);
const cellValues = (page) => page.eval(`[...document.querySelectorAll('tbody tr[data-student-row]')].map(tr => tr.querySelector('input.cr-cell')?.value ?? null)`);
const statusText = (page) => page.eval(`document.getElementById('crStatus')?.textContent.trim()`);
async function focusCell(page, row, col = 0) {
    await page.eval(`(() => { const c = document.querySelectorAll('tbody tr[data-student-row]')[${row}].querySelectorAll('input.cr-cell')[${col}]; c.focus(); c.select(); })()`);
}
async function saveAndWait(page) {
    await page.eval(`document.getElementById('crSave').click()`);
    await page.waitUntil(`!/Saving/.test(document.getElementById('crStatus').textContent)`, 15000);
    return statusText(page);
}
/**
 * Shared-layout audit at the current viewport: at the top, middle and bottom
 * of <main>'s scroll, nothing position:fixed and visible may overlap <main>
 * (the old floating menu button did), the page must not scroll sideways,
 * and on phones the header sits above <main> in normal flow.
 */
async function layoutAudit(page, label) {
    const issues = [];
    for (const [pos, frac] of [['top', 0], ['mid', 0.5], ['end', 1]]) {
        await page.eval(`(() => { const m = document.getElementById('main'); if (m) m.scrollTop = (m.scrollHeight - m.clientHeight) * ${frac}; })()`);
        await sleep(120);
        const r = await page.eval(`(() => {
            const m = document.getElementById('main');
            if (!m) return { noMain: true };
            const mr = m.getBoundingClientRect();
            const overlapping = [...document.querySelectorAll('body *')].filter(el => {
                const cs = getComputedStyle(el);
                if (cs.position !== 'fixed' || cs.display === 'none' || cs.visibility === 'hidden' || +cs.opacity === 0) return false;
                const b = el.getBoundingClientRect();
                if (b.width === 0 || b.height === 0) return false;
                if (el.closest('#sidebar') && b.right <= 0) return false;               // closed drawer, off-screen
                return b.left < mr.right && b.right > mr.left && b.top < mr.bottom && b.bottom > mr.top;
            }).map(el => el.id || el.className.toString().slice(0, 40));
            const header = document.querySelector('[data-mobile-header]');
            const hr = header && getComputedStyle(header).display !== 'none' ? header.getBoundingClientRect() : null;
            return { overlapping, headerAbove: !hr || hr.bottom <= mr.top + 1, overflowX: document.documentElement.scrollWidth - innerWidth };
        })()`);
        if (r.noMain) { issues.push(`${pos}: no <main>`); break; }
        if (r.overlapping.length) issues.push(`${pos}: fixed over content ${JSON.stringify(r.overlapping)}`);
        if (!r.headerAbove) issues.push(`${pos}: header overlaps main`);
        if (r.overflowX > 1) issues.push(`${pos}: page scrolls sideways ${r.overflowX}px`);
        await page.screenshot(join(OUT, `layout-${label}-${pos}.png`));
    }
    return issues;
}

async function subjectRecordUrl(page, sectionName, subjectName) {
    return page.eval(`(async () => {
        const html = await (await fetch('${BASE}/teacher/class-record')).text();
        const d = new DOMParser().parseFromString(html, 'text/html');
        const sec = [...d.querySelectorAll('#crSection option')].find(o => o.textContent.includes(${JSON.stringify(sectionName)}));
        const html2 = await (await fetch('${BASE}/teacher/class-record?section_id=' + sec.value + '&period=1')).text();
        const d2 = new DOMParser().parseFromString(html2, 'text/html');
        const sub = [...d2.querySelectorAll('#crSubject option')].find(o => o.textContent.includes(${JSON.stringify(subjectName)}));
        return '${BASE}/teacher/class-record?section_id=' + sec.value + '&subject_id=' + sub.value + '&period=1';
    })()`);
}

try {
    // ---- Isolation guard: the isolated-only account must exist on this server.
    const main = await cdp.newPage();
    await main.viewport(1366, 900);
    await login(main, 'bong@browser.test');
    check('isolated server: isolated-only account signs in', (await main.eval('location.pathname')).includes('/adviser/dashboard'), await main.eval('location.pathname'));
    if (!(await main.eval('location.pathname')).includes('/adviser/dashboard')) throw new Error('not isolated — stopping');

    // ---- My Classes and advisory read-only.
    await main.goto(`${BASE}/teacher/classes`);
    check('Adviser My Classes lists the assigned CESC class', await main.eval(`document.body.innerText.includes('Community Engagement')`));
    const scienceUrl = await subjectRecordUrl(main, 'Agila', 'General Science');
    const cescUrl = await subjectRecordUrl(main, 'Agila', 'Community Engagement');
    await main.goto(scienceUrl);
    check('Adviser: General Science (taught by Bosco) is view-only', await main.eval(`document.getElementById('classRecord').dataset.canEdit === '0' && /View only — you are not assigned/.test(document.body.innerText)`));
    check('Adviser view shows the assigned teacher', await main.eval(`document.querySelector('[data-assigned-teacher]').textContent.includes('Bosco, Darien')`));
    check('read-only view: reason shown, no save bar, no cell actions, no Add Assessment', await main.eval(`!!document.querySelector('[data-cr-readonly]') && !document.getElementById('crSaveBar') && !document.getElementById('crSelection') && !document.querySelector('[data-cr-add]') && [...document.querySelectorAll('input.cr-cell')].every(c => c.readOnly)`));
    await main.screenshot(join(OUT, 'adviser-readonly-science-1366.png'));

    // ---- Page consolidation: no standalone Assessments page.
    const navLabels = await main.eval(`[...document.querySelectorAll('aside a')].map(a => a.textContent.trim())`);
    check('Adviser sidebar has no Assessments item', !navLabels.includes('Assessments') && navLabels.includes('Class Record') && navLabels.includes('My Classes') && navLabels.includes('Encode Grades') && navLabels.includes('Verify Grades') && !navLabels.includes('Encode & Verify Grades'), JSON.stringify(navLabels));
    const cescId = new URL(cescUrl).searchParams.get('subject_id');
    await main.goto(`${BASE}/adviser/assessments?period=1&subject_id=${cescId}`);
    check('an old Assessments bookmark lands on the matching Class Record', await main.eval(`location.pathname.endsWith('/teacher/class-record') && new URLSearchParams(location.search).get('subject_id') === '${cescId}'`), await main.eval('location.href'));
    await main.goto(`${BASE}/adviser/assessments?period=9&subject_id=999999`);
    check('a bogus bookmark falls back safely to the Class Record', await main.eval(`location.pathname.endsWith('/teacher/class-record') && !new URLSearchParams(location.search).has('subject_id') && !!document.getElementById('classRecord')`), await main.eval('location.href'));
    await main.goto(`${BASE}/adviser/advisory-overview?period=1`);
    const scienceLink = await main.eval(`[...document.querySelectorAll('[data-subject-record-links] a')].find(a => a.textContent.includes('General Science'))?.href`);
    await main.goto(scienceLink);
    check('Advisory Overview opens a subject\'s read-only evidence', await main.eval(`document.getElementById('classRecord').dataset.canEdit === '0'`));

    // ---- Upload removal (every teacher-facing page). The only file input
    // allowed is Encode Grades' separate FINAL-grade import.
    for (const path of ['/teacher/class-record', '/teacher/classes', '/adviser/advisory-overview', '/adviser/grades', '/adviser/verify-grades', '/adviser/dashboard', '/adviser/submit-report']) {
        await main.goto(BASE + path);
        const bad = await main.eval(`[...document.querySelectorAll('input[type=file]')].filter(i => !i.closest('form[action$="/adviser/grades/import"]')).length + (/(Upload Assessment|Download Assessment Record)/.test(document.body.innerText) ? 100 : 0)`);
        check(`no assessment upload control on ${path}`, bad === 0, `file inputs/labels: ${bad}`);
    }
    const retired = await main.eval(`fetch('${BASE}/adviser/assessments/detect', { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } }).then(r => r.status)`);
    check('retired upload endpoint does not answer', [404, 405].includes(retired), `HTTP ${retired}`);

    // ---- Details-only Add Assessment.
    await main.goto(cescUrl);
    await main.navigating(() => main.eval(`document.querySelector('[data-cr-add]').click()`));
    check('Add Assessment is a page with no score or Missing inputs', await main.eval(`location.pathname.endsWith('/teacher/assessments/create') && !document.querySelector('[name^="scores["],[name^="missing["]')`));
    await main.navigating(() => main.eval(`(() => {
        const f = document.getElementById('assessmentForm');
        f.querySelector('[name=item_name]').value = 'Quiz 1';
        f.querySelector('[name=component]').value = 'written_work';
        f.querySelector('[name=max_score]').value = '20';
        f.requestSubmit();
    })()`));
    check('saving returns to the Class Record with the message', await main.eval(`location.pathname.endsWith('/teacher/class-record') && document.body.innerText.includes('Assessment added. Enter scores in the Class Record.')`));
    check('the new column is highlighted and every cell is empty (no zero, no Missing)', await main.eval(`!!document.querySelector('th.cr-new') && [...document.querySelectorAll('input.cr-cell')].every(c => c.value === '')`));
    check("the new column's first cell has focus", await main.eval(`document.activeElement?.dataset.a === document.querySelector('th.cr-new').dataset.itemHead`));

    // ---- Typing, keyboard, invalid value, Escape, save.
    await focusCell(main, 0);
    await main.type('18');
    await main.key('ArrowDown', { keyCode: 40 });
    await main.type('0');
    await main.key('Enter', { keyCode: 13 });
    await main.type('m');
    await main.key('ArrowDown', { keyCode: 40 });
    await main.type('25');
    const cls = await main.eval(`[...document.querySelectorAll('tbody tr[data-student-row]')].slice(0, 4).map(tr => tr.querySelector('input.cr-cell').className)`);
    check('keyboard moved down the column; dirty/missing/invalid states shown', cls[0].includes('cr-dirty') && cls[1].includes('cr-dirty') && cls[2].includes('cr-missing') && cls[3].includes('cr-invalid'), JSON.stringify(cls));
    // Automatic Missing: the other blanks of Quiz 1 are named as becoming Missing.
    check('save scope and the blank-to-Missing notice are shown next to Save Scores', await main.eval(`(() => {
        const pending = [...document.querySelectorAll('tbody tr[data-student-row]')].slice(4).every(tr => tr.querySelector('input.cr-cell').classList.contains('cr-pending-missing'));
        return pending && /Blank entries in the assessments being saved will be marked Missing\./.test(document.getElementById('crMissingNotice').textContent)
            && /Being saved: Quiz 1 — 4 blank entries will become Missing\./.test(document.getElementById('crScope').textContent);
    })()`), await main.eval(`document.getElementById('crScope').textContent`));
    // Live preview: server-computed, labelled, before any save.
    await main.waitUntil(`/Running Initial Grade · Unsaved preview/.test(document.getElementById('crPreviewStatus').textContent)`, 8000);
    await main.screenshot(join(OUT, 'class-record-live-preview-1366.png'));
    check('typing updates TOTAL / PS / WS and the running Initial Grade before saving (unsaved preview)', await main.eval(`(() => {
        const tr = document.querySelector('tbody tr[data-student-row]');
        const out = (k) => tr.querySelector('[data-cr-out="' + k + '"]').textContent.trim();
        return tr.classList.contains('cr-row-preview') && out('written_work:total') === '18 / 20' && out('written_work:ps') === '90.00'
            && out('written_work:ws') !== '—' && out('grade:initial') === out('written_work:ws') && /unsaved preview/.test(out('grade:initial:note'));
    })()`), await main.eval(`document.querySelector('tbody tr[data-student-row]').innerText.replace(/\s+/g, ' ').slice(0, 200)`));
    check('unsaved-change indicator in the save bar', /unsaved change/.test(await main.eval(`document.getElementById('crUnsaved').textContent`)) && await main.eval(`document.getElementById('crStatus').dataset.state === 'unsaved'`), await main.eval(`document.getElementById('crUnsaved').textContent`));
    await main.key('Escape', { keyCode: 27 });
    check('Esc reverts the invalid cell', (await cellValues(main))[3] === '');
    const saved = await saveAndWait(main);
    check('Save Scores succeeds: 3 typed cells + 5 blanks saved as Missing', /Saved 8 change/.test(saved), saved);
    check('after the save: confirmed Missing shown, the saved figures replace the preview', await main.eval(`(() => {
        const rows = [...document.querySelectorAll('tbody tr[data-student-row]')];
        return rows.slice(3).every(tr => tr.querySelector('input.cr-cell').value === 'M') && !document.querySelector('tr.cr-row-preview')
            && rows[0].querySelector('[data-cr-out="written_work:total"]').textContent.trim() === '18 / 20' && document.getElementById('crPreviewStatus').textContent === '';
    })()`));
    await main.goto(cescUrl);
    const afterReload = await cellValues(main);
    check('saved values persist: 18, 0 (recorded zero), M, and the blank saved as M', JSON.stringify(afterReload.slice(0, 4)) === JSON.stringify(['18', '0', 'M', 'M']), JSON.stringify(afterReload.slice(0, 4)));
    check('no per-learner Mark Missing control exists', await main.eval(`!document.getElementById('crMarkMissing')`));
    await main.screenshot(join(OUT, 'class-record-template-1366.png'));

    // ---- An older preview answer never overwrites a newer one.
    await main.eval(`(() => {
        const real = window.fetch;
        window.__restoreFetch = () => { window.fetch = real; };
        window.fetch = (url, opts = {}) => (String(url).includes('/class-record/preview') && /"score":"1"/.test(opts.body || '')
            ? new Promise((done) => setTimeout(() => done(real(url, { ...opts, signal: undefined })), 1500))
            : real(url, opts));
    })()`);
    await focusCell(main, 7);
    await main.type('1');
    await sleep(600);
    await main.type('7');
    await sleep(2200);
    check('a late, older preview answer is ignored', await main.eval(`document.querySelectorAll('tbody tr[data-student-row]')[7].querySelector('[data-cr-out="written_work:total"]').textContent.trim() === '17 / 20'`),
        await main.eval(`document.querySelectorAll('tbody tr[data-student-row]')[7].querySelector('[data-cr-out="written_work:total"]').textContent.trim()`));
    await main.eval(`window.__restoreFetch()`);
    await main.key('Escape', { keyCode: 27 });
    await main.waitUntil(`!document.querySelector('tr.cr-row-preview')`, 8000);
    check('undoing the edit returns the row to its saved figures', await main.eval(`document.querySelectorAll('tbody tr[data-student-row]')[7].querySelector('[data-cr-out="written_work:total"]').textContent.trim() === '—' && document.getElementById('crPreviewStatus').textContent === ''`));

    // ---- UI refinement: selected-cell actions, save bar, discard (large class).
    const narraUrl = await subjectRecordUrl(main, 'Narra', 'General Mathematics');
    await main.viewport(1366, 900);
    await main.goto(narraUrl);
    const sel = () => main.eval(`({ target: document.getElementById('crSelTarget').textContent, note: document.getElementById('crSelNote').textContent,
        clr: document.getElementById('crClearEntry').disabled, undo: document.getElementById('crUndoCell').disabled,
        save: document.getElementById('crSave').disabled, discard: document.getElementById('crDiscard').disabled })`);
    const s0 = await sel();
    check('no selection: cell actions disabled with an explanation; Save and Discard disabled', s0.clr && s0.undo && s0.save && s0.discard && /No cell selected/.test(s0.target), JSON.stringify(s0));
    const t1 = await main.eval(`(() => {
        const c = [...document.querySelectorAll('tbody tr[data-student-row]')[3].querySelectorAll('input.cr-cell')].find(i => /^[0-9]/.test(i.value));
        c.id = 'crT1';
        return { learner: c.dataset.learner, item: c.dataset.item, max: c.dataset.max, value: c.value };
    })()`);
    await main.click('#crT1');
    const s1 = await sel();
    check('a clicked cell is named: learner, assessment, HPS, current value', s1.target.includes(t1.learner) && s1.target.includes(t1.item) && s1.target.includes('HPS ' + t1.max) && s1.target.includes('Now: ' + t1.value) && !s1.clr && s1.undo, JSON.stringify(s1));
    check('row and column cues on the selected cell', await main.eval(`document.getElementById('crT1').closest('tr').classList.contains('cr-row-active') && document.querySelector('th.cr-col-active')?.dataset.itemHead === document.getElementById('crT1').dataset.a`));
    await main.click('#crClearEntry');
    const s2 = await sel();
    check('Clear Entry (mouse) changes only the selected cell and keeps its focus', await main.eval(`document.getElementById('crT1').value === '' && document.activeElement.id === 'crT1' && document.querySelectorAll('input.cr-cell.cr-dirty').length === 1`));
    check('the action is described as pending, replacing the saved score', /Pending change — click Save Scores\./.test(s2.note) && /replaces the saved score/.test(s2.note), s2.note);
    check('unsaved cell has a non-colour marker; save bar counts it; Save and Discard enabled', await main.eval(`document.getElementById('crT1').parentElement.classList.contains('cr-td-dirty') && getComputedStyle(document.getElementById('crT1').parentElement, '::after').content !== 'none' && /^1 unsaved change/.test(document.getElementById('crUnsaved').textContent) && document.getElementById('crStatus').dataset.state === 'unsaved'`) && !s2.save && !s2.discard);
    await main.click('#crUndoCell');
    check('Undo returns the saved value', await main.eval(`document.getElementById('crT1').value === ${JSON.stringify(t1.value)} && document.querySelectorAll('input.cr-cell.cr-dirty').length === 0 && document.getElementById('crSave').disabled`));
    await main.click('#crClearEntry');
    check('Clear Entry blanks the cell (not 0); it is named as becoming Missing on save', await main.eval(`document.getElementById('crT1').value === ''`) && /Now: blank \(becomes Missing when its assessment is saved\)/.test((await sel()).target), (await sel()).target);
    await main.click('#crDiscard');
    await main.waitUntil(`!document.getElementById('crDiscardModal').classList.contains('hidden')`);
    check('Discard asks for confirmation before changing anything', await main.eval(`document.getElementById('crT1').value === ''`));
    await main.click('#crDiscardCancel');
    await main.waitUntil(`document.getElementById('crDiscardModal').classList.contains('hidden')`);
    check('"Keep editing" keeps the pending change', await main.eval(`document.getElementById('crT1').value === '' && /^1 unsaved/.test(document.getElementById('crUnsaved').textContent)`));
    await main.click('#crDiscard');
    await main.waitUntil(`!document.getElementById('crDiscardModal').classList.contains('hidden')`);
    await main.click('#crDiscardConfirm');
    await main.waitUntil(`document.getElementById('crDiscardModal').classList.contains('hidden')`);
    const narraIds = await ids(main);
    check('confirmed Discard restores saved values and drops the draft', await main.eval(`document.getElementById('crT1').value === ${JSON.stringify(t1.value)} && document.querySelectorAll('input.cr-cell.cr-dirty').length === 0 && localStorage.getItem(${JSON.stringify(narraIds.key)}) === null`));
    // Out-of-range entry: nothing is sent; the cell is brought into view and focused.
    await main.eval(`(() => { const rows = document.querySelectorAll('tbody tr[data-student-row]'); const c = rows[rows.length - 2].querySelectorAll('input.cr-cell')[0]; c.id = 'crT2'; c.focus(); })()`);
    await main.type('99');
    await main.eval(`document.getElementById('main').scrollTop = 0; document.querySelector('.cr-scroll').scrollTop = 0; document.getElementById('crSave').focus()`);
    const inv = await saveAndWait(main);
    await sleep(200);
    check('an invalid cell blocks the save and is focused and in view', /Not saved/.test(inv) && await main.eval(`(() => {
        const c = document.getElementById('crT2'), r = c.getBoundingClientRect(), bar = document.getElementById('crSaveBar').getBoundingClientRect(), sc = document.querySelector('.cr-scroll').getBoundingClientRect();
        return document.activeElement === c && r.top >= sc.top && r.bottom <= Math.min(bar.top, sc.bottom) + 1 && !document.getElementById('crErrors').classList.contains('hidden');
    })()`), inv);
    await main.key('Escape', { keyCode: 27 });
    await main.eval(`document.getElementById('crT2').blur()`);
    // Changing a filter never carries a stale selection into the next class.
    await main.navigating(() => main.eval(`(() => { const t = document.getElementById('crTerm'); t.value = '2'; t.dispatchEvent(new Event('change')); })()`));
    check('a filter change clears the selection', await main.eval(`!document.querySelector('.cr-row-active') && (!document.getElementById('crSelTarget') || /No cell selected/.test(document.getElementById('crSelTarget').textContent))`));

    // ---- The save bar never covers the focused cell, the rows or the scrollbar.
    for (const [width, height, dsf, label] of [[390, 800, 1, '390'], [768, 900, 1, '768'], [1366, 900, 1, '1366'], [1920, 1000, 1, '1920'], [683, 450, 2, '200pct-zoom']]) {
        await main.viewport(width, height, dsf);
        await main.goto(narraUrl);
        const top = await main.eval(`(() => { const b = document.getElementById('crSaveBar').getBoundingClientRect(), sc = document.querySelector('.cr-scroll').getBoundingClientRect();
            return sc.top >= innerHeight || (b.bottom <= innerHeight + 1 && b.top >= 0); })()`);
        await main.eval(`{ const m = document.getElementById('main'); m.scrollTop += document.querySelector('.cr-workspace').getBoundingClientRect().top - m.getBoundingClientRect().top + 200; }`);
        await sleep(100);
        const mid = await main.eval(`(() => { const b = document.getElementById('crSaveBar').getBoundingClientRect(); return b.bottom <= innerHeight + 1 && b.top >= 0; })()`);
        await main.eval(`(() => { const rows = document.querySelectorAll('tbody tr[data-student-row]'); rows[rows.length - 1].querySelectorAll('input.cr-cell')[4].focus(); })()`);
        await sleep(250);
        const g = await main.eval(`(() => {
            const c = document.activeElement.getBoundingClientRect(), bar = document.getElementById('crSaveBar').getBoundingClientRect();
            const aside = document.querySelector('aside').getBoundingClientRect();
            return { cellCovered: c.bottom > bar.top + 1, cellOffscreen: c.top < 0 || c.bottom > innerHeight, barInView: bar.top >= 0 && bar.bottom <= innerHeight + 1,
                     barLeftOfSidebar: innerWidth >= 768 && bar.left < aside.right - 1, pageOverflow: document.documentElement.scrollWidth - innerWidth };
        })()`);
        await main.screenshot(join(OUT, `class-record-large-focus-${label}.png`));
        await main.eval(`document.getElementById('main').scrollTop = 1e6`);
        await sleep(150);
        const end = await main.eval(`(() => { const bar = document.getElementById('crSaveBar').getBoundingClientRect(), sc = document.querySelector('.cr-scroll').getBoundingClientRect(); return sc.bottom <= bar.top + 1; })()`);
        await main.screenshot(join(OUT, `class-record-large-end-${label}.png`));
        check(`${label}: save bar in view, focused last-row cell not covered, grid scrollbar clear at the end, no sideways page scroll`,
            top && mid && !g.cellCovered && !g.cellOffscreen && g.barInView && !g.barLeftOfSidebar && g.pageOverflow <= 1 && end, JSON.stringify({ top, mid, ...g, end }));
    }
    await main.viewport(1366, 900);
    await main.goto(cescUrl);

    // ---- Clipboard paste with preview.
    const paste = (row, text) => main.eval(`(() => {
        const cell = document.querySelectorAll('tbody tr[data-student-row]')[${row}].querySelector('input.cr-cell');
        cell.focus();
        const dt = new DataTransfer(); dt.setData('text/plain', ${JSON.stringify(text)});
        cell.dispatchEvent(new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true }));
    })()`);
    await paste(3, '15\r\n16\r\n17\r\n');
    await main.waitUntil(`!document.getElementById('crPasteModal').classList.contains('hidden')`);
    check('paste preview lists 3 cells before placing anything', await main.eval(`document.getElementById('crPasteBody').querySelectorAll('tbody tr').length === 3 && document.querySelectorAll('tbody tr[data-student-row]')[3].querySelector('input.cr-cell').value === 'M'`));
    await main.eval(`document.getElementById('crPasteApply').click()`);
    check('paste placed exactly from the anchor', JSON.stringify((await cellValues(main)).slice(3, 6)) === JSON.stringify(['15', '16', '17']));
    await paste(6, Array(9).fill('10').join('\n'));
    await main.waitUntil(`!document.getElementById('crPasteModal').classList.contains('hidden')`);
    check('an oversized paste is refused with a reason and places nothing', await main.eval(`/Nothing was placed/.test(document.getElementById('crPasteSummary').textContent) && document.getElementById('crPasteApply').classList.contains('hidden')`));
    await main.eval(`document.getElementById('crPasteCancel').click()`);
    check('saving the pasted block', /Saved 3 change/.test(await saveAndWait(main)));

    // ---- Unsaved guard: save / discard / cancel.
    await focusCell(main, 6);
    await main.type('11');
    await main.eval(`document.querySelector('[data-cr-add]').click()`);
    await main.waitUntil(`!document.getElementById('crLeaveModal').classList.contains('hidden')`);
    check('Add Assessment with unsaved edits asks save/discard/cancel', await main.eval(`location.pathname.endsWith('/teacher/class-record')`));
    await main.eval(`document.getElementById('crLeaveCancel').click()`);
    check('Cancel keeps the page and the typed value', (await cellValues(main))[6] === '11');
    await main.eval(`document.querySelector('[data-cr-export]').click()`);
    await main.waitUntil(`!document.getElementById('crLeaveModal').classList.contains('hidden')`);
    check('Download with unsaved edits warns that only saved scores are included', await main.eval(`/only SAVED scores/.test(document.getElementById('crLeaveMessage').textContent)`));
    await main.eval(`document.getElementById('crLeaveCancel').click()`);
    await main.navigating(() => main.eval(`(() => { document.querySelector('[data-cr-add]').click(); setTimeout(() => document.getElementById('crLeaveDiscard').click(), 200); })()`));
    check('Discard continues to the Add page', await main.eval(`location.pathname.endsWith('/teacher/assessments/create')`));
    await main.goto(cescUrl);
    check('discarded value was not saved', (await cellValues(main))[6] === 'M');

    // ---- Two-tab conflict.
    const tab2 = await cdp.newPage();
    await tab2.viewport(1366, 900);
    await tab2.goto(cescUrl);
    await focusCell(tab2, 0); await tab2.type('19');
    check('tab 2 saves first', /Saved 1 change/.test(await saveAndWait(tab2)));
    await focusCell(main, 0); await main.type('17');
    await saveAndWait(main);
    check('tab 1 is refused as a conflict, nothing overwritten', await main.eval(`!document.getElementById('crConflicts').classList.contains('hidden') && /now 19/.test(document.getElementById('crConflicts').textContent)`));
    await main.eval(`[...document.querySelectorAll('#crConflicts button')].find(b => /Use the saved/.test(b.textContent)).click()`);
    check('"Use the saved values" shows 19', (await cellValues(main))[0] === '19');
    await tab2.close();

    // ---- Failed save and retry.
    await main.offline(true);
    await focusCell(main, 6); await main.type('12');
    await main.waitUntil(`document.getElementById('crPreviewStatus').dataset.state === 'stale'`, 8000);
    check('offline: the preview says it is unavailable and keeps the entry', /Preview unavailable/.test(await main.eval(`document.getElementById('crPreviewStatus').textContent`)) && (await cellValues(main))[6] === '12'
        && await main.eval(`document.querySelectorAll('tbody tr[data-student-row]')[6].classList.contains('cr-preview-stale')`));
    const failed = await saveAndWait(main);
    check('offline save fails without losing the entry', /Save failed/.test(failed) && (await cellValues(main))[6] === '12', failed);
    const { key } = await ids(main);
    check('the unsaved entry is kept in this account\'s draft', await main.eval(`!!localStorage.getItem(${JSON.stringify(key)})`));
    await main.offline(false);
    check('retry succeeds after reconnecting', /Saved 1 change/.test(await saveAndWait(main)));
    check('a successful save clears the draft', await main.eval(`localStorage.getItem(${JSON.stringify(key)}) === null`));

    // ---- Copy Scores.
    await cdp.send('Browser.grantPermissions', { origin: BASE, permissions: ['clipboardReadWrite', 'clipboardSanitizedWrite'] });
    await main.eval(`document.getElementById('crCopy').click()`);
    await main.waitUntil(`/copied/.test(document.getElementById('crStatus').textContent)`);
    const full = await main.eval(`navigator.clipboard.readText()`);
    check('Copy (full) has headers, LRN as text and the saved values', full.startsWith('LRN\tLearner\tQuiz 1') && /\n'0\d{11}\tABAD, ANA\t19/.test(full.replace(/\r/g, '')) && full.includes('MISSING'), JSON.stringify(full.slice(0, 120)));
    // Wait for THIS copy to finish (a fixed sleep raced the request: the
    // clipboard still held the full copy — the intermittent 164/165).
    await main.eval(`document.getElementById('crStatus').textContent = ''; document.getElementById('crCopyMode').value = 'scores'; document.getElementById('crCopy').click()`);
    await main.waitUntil(`document.getElementById('crStatus').textContent.includes('copied (scores only)')`);
    // One line per learner (8), the last learner has no evidence yet so its line is empty.
    const scoresOnly = (await main.eval(`navigator.clipboard.readText()`)).replace(/\r/g, '').replace(/\n$/, '').split('\n');
    check('Copy (scores only) is the score block in roster order', scoresOnly.length === 8 && scoresOnly[0] === '19' && scoresOnly[1] === '0' && scoresOnly[2] === 'MISSING' && scoresOnly[7] === 'MISSING', JSON.stringify(scoresOnly));

    // ---- Download.
    await cdp.send('Browser.setDownloadBehavior', { behavior: 'allow', downloadPath: DOWNLOADS });
    await main.eval(`document.querySelector('[data-cr-export]').click()`);
    let file = null;
    for (let i = 0; i < 50 && !file; i += 1) { await sleep(200); file = readdirSync(DOWNLOADS).find((f) => f.endsWith('.xlsx')); }
    check('Download Excel Class Record produces an .xlsx', !!file && statSync(join(DOWNLOADS, file)).size > 1000, file ?? 'none');

    // ---- Official-grade verification on Verify Grades (adviser).
    await main.viewport(1366, 900);
    const addItem = async (name, component, max, role = '') => {
        await main.goto(cescUrl);
        await main.navigating(() => main.eval(`document.querySelector('[data-cr-add]').click()`));
        await main.navigating(() => main.eval(`(() => {
            const f = document.getElementById('assessmentForm');
            f.querySelector('[name=item_name]').value = ${JSON.stringify(name)};
            const c = f.querySelector('[name=component]'); c.value = ${JSON.stringify(component)}; c.dispatchEvent(new Event('change', { bubbles: true }));
            if (${JSON.stringify(role)}) f.querySelector('[name=exam_role]').value = ${JSON.stringify(role)};
            f.querySelector('[name=max_score]').value = ${JSON.stringify(String(max))};
            f.requestSubmit();
        })()`));
    };
    await addItem('Project 1', 'performance_task', 50);
    await addItem('Term Exam', 'examination', 40, 'term_exam');
    const cols = await main.eval(`[...document.querySelectorAll('thead th[data-item-head]')].map(th => th.querySelector('.cr-item-name').textContent.trim())`);
    const colOf = (name) => cols.indexOf(name);
    await focusCell(main, 0, colOf('Project 1')); await main.type('40');
    await focusCell(main, 0, colOf('Term Exam')); await main.type('30');
    // Two more learners with complete evidence, for the bulk action.
    await focusCell(main, 1, colOf('Project 1')); await main.type('35');
    await focusCell(main, 1, colOf('Term Exam')); await main.type('25');
    await focusCell(main, 3, colOf('Project 1')); await main.type('45');
    await focusCell(main, 3, colOf('Term Exam')); await main.type('36');
    // 6 typed cells + the other 5 learners' blanks in each of the two assessments (Missing).
    check('assigned teacher saves the remaining evidence', /Saved 16 change/.test(await saveAndWait(main)));

    // ---- Encode Grades and Verify Grades are two pages.
    const activeNav = (page) => page.eval(`[...document.querySelectorAll('aside a.nav-link-active')].map(a => a.textContent.trim())`);
    await main.goto(`${BASE}/adviser/grades?period=1`);
    check('Encode Grades: its own title, only its sidebar item active, no verification', JSON.stringify(await activeNav(main)) === '["Encode Grades"]'
        && await main.eval(`document.querySelector('.page-title').textContent.trim() === 'Encode Grades' && !document.querySelector('[data-grade-verification]') && !!document.getElementById('gradeEntryForm') && !/On this page/.test(document.body.innerText)`),
        JSON.stringify(await activeNav(main)));
    const verifyUrl = `${BASE}/adviser/verify-grades?period=1&subject_id=${cescId}`;
    await main.goto(verifyUrl);
    check('Verify Grades: its own title, only its sidebar item active, one workspace, no manual entry', JSON.stringify(await activeNav(main)) === '["Verify Grades"]'
        && await main.eval(`(() => {
            const ws = document.querySelector('[data-verify-workspace]');
            return document.querySelector('.page-title').textContent.trim() === 'Verify Grades' && !document.getElementById('gradeEntryForm')
                && document.querySelectorAll('section.card').length === 1
                && ['#verifyFilterForm', '[data-open-class-record]', '[data-verify-counts]', '#verifyAllRemainingBtn', 'tr[data-performance-row]'].every(s => ws.contains(document.querySelector(s)));
        })()`), JSON.stringify(await activeNav(main)));

    // Old bookmark to the combined page's #verify section lands on Verify Grades with its context.
    await main.goto(`${BASE}/adviser/grades?period=1&subject_id=${cescId}#verify`);
    await main.waitUntil(`location.pathname.endsWith('/adviser/verify-grades')`, 10000);
    check('an old #verify bookmark opens Verify Grades for the same subject', await main.eval(`new URLSearchParams(location.search).get('subject_id') === '${cescId}' && !location.hash`), await main.eval('location.href'));

    // ---- Individual verification: confirmation, server round trip, row state.
    const eligibleRow = await main.eval(`document.querySelector('tr[data-verify-state="eligible"]').dataset.studentId`);
    await main.eval(`document.querySelector('tr[data-student-id="${eligibleRow}"] [data-grade-verify-form] button').click()`);
    await main.waitUntil(`!document.getElementById('confirmActionModal').classList.contains('hidden')`);
    check('Verify asks first, naming the learner, the grade and that it becomes official', await main.eval(`/Set the official grade for .+ to \\d/.test(document.getElementById('confirmActionMessage').textContent) && /Verify official grade/.test(document.getElementById('confirmActionTitle').textContent)`));
    await main.navigating(() => main.eval(`document.getElementById('confirmActionBtn').click()`));
    check('after the server confirms, the page returns to the same row, now Official · verified', await main.eval(`location.pathname.endsWith('/adviser/verify-grades') && location.hash === '#learner-${eligibleRow}'
        && /Official · verified/.test(document.querySelector('tr[data-student-id="${eligibleRow}"] [data-official-grade-cell]').textContent)
        && document.querySelector('tr[data-student-id="${eligibleRow}"]').dataset.verifyState === 'verified'`), await main.eval('location.href'));
    check('a learner with incomplete evidence shows the specific reason and no Verify', await main.eval(`(() => {
        const r = document.querySelector('tr[data-verify-state="incomplete"]');
        return !!r && !r.querySelector('[data-grade-verify-form]') && /no computable result yet for/.test(r.textContent);
    })()`));

    // ---- Bulk: exact scope, and a changed set refreshes the confirmation instead of expanding.
    check('bulk action names its count and whole-class scope', await main.eval(`/Verify all eligible \\(2\\)/.test(document.getElementById('verifyAllRemainingBtn').textContent) && /not limited by the Status filter/.test(document.getElementById('verifyAllScope').textContent)`),
        await main.eval(`document.getElementById('verifyAllRemainingBtn').textContent.trim()`));
    await main.eval(`document.getElementById('verifyAllRemainingBtn').click()`);
    await main.waitUntil(`!document.getElementById('verifyAllRemainingModal').classList.contains('hidden')`);
    check('the confirmation lists subject, section, year, term and every included learner', await main.eval(`/Verify 2 eligible learners\\?/.test(document.getElementById('varTitle').textContent)
        && /Community Engagement — Agila, Term 1, SY 2026-2027/.test(document.getElementById('varContext').textContent)
        && document.querySelectorAll('#varIncluded li').length === 2`), await main.eval(`document.getElementById('varContext').textContent`));
    await main.screenshot(join(OUT, 'verify-grades-bulk-confirm-1366.png'));
    // Meanwhile one of the two is verified from another tab.
    const other = await main.eval(`[...document.querySelectorAll('tr[data-verify-state="eligible"]')].map(r => r.dataset.studentId)[0]`);
    const tabV = await cdp.newPage();
    await tabV.goto(verifyUrl);
    const tabStatus = await tabV.eval(`fetch('${BASE}/adviser/grades/verify', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify({ student_id: ${Number(other) || 0}, subject_id: ${Number(cescId)}, grading_period: 1 }) }).then(r => r.status)`);
    await tabV.close();
    await main.eval(`document.getElementById('varConfirmBtn').click()`);
    await main.waitUntil(`!document.getElementById('varChanged').classList.contains('hidden')`, 15000);
    check('a set that changed after confirmation verifies nothing and refreshes the confirmation', tabStatus === 200 && await main.eval(`/changed since the confirmation/.test(document.getElementById('varChanged').textContent)
        && /Verify 1 eligible learner\\?/.test(document.getElementById('varTitle').textContent)`), await main.eval(`document.getElementById('varTitle').textContent`));
    await main.navigating(() => main.eval(`document.getElementById('varConfirmBtn').click()`));
    await sleep(400);
    check('bulk success is shown only after the server confirms, and every row is now verified', await main.eval(`document.querySelectorAll('tr[data-verify-state="eligible"]').length === 0
        && document.querySelectorAll('tr[data-verify-state="verified"]').length === 3 && document.getElementById('verifyAllRemainingBtn').disabled`));

    // ---- Responsive layout of the consolidated adviser pages.
    for (const width of [390, 768, 1366]) {
        await main.viewport(width, 900);
        for (const path of ['/adviser/grades?period=1', `/adviser/verify-grades?period=1&subject_id=${cescId}`, '/adviser/advisory-overview?period=1', '/teacher/classes']) {
            await main.goto(BASE + path);
            const overflow = await main.eval(`document.documentElement.scrollWidth - window.innerWidth`);
            check(`${width}px ${path}: no page-level horizontal scroll`, overflow <= 1, `overflow ${overflow}px`);
        }
        await main.goto(`${BASE}/adviser/grades?period=1`);
        await main.screenshot(join(OUT, `encode-grades-${width}.png`));
        await main.goto(`${BASE}/adviser/verify-grades?period=1&subject_id=${cescId}`);
        await main.screenshot(join(OUT, `verify-grades-${width}.png`));
        const vg = await main.eval(`(() => {
            const btns = [...document.querySelectorAll('[data-verify-workspace] .btn, [data-verify-workspace] select')].filter(b => b.offsetParent).map(b => b.getBoundingClientRect());
            let overlaps = 0;
            for (let i = 0; i < btns.length; i++) for (let j = i + 1; j < btns.length; j++) {
                const a = btns[i], b = btns[j];
                if (a.left < b.right - 1 && b.left < a.right - 1 && a.top < b.bottom - 1 && b.top < a.bottom - 1) overlaps++;
            }
            const ws = document.querySelector('[data-verify-workspace]').getBoundingClientRect();
            const selects = [...document.querySelectorAll('#verifyFilterForm select')].every(s => s.getBoundingClientRect().right <= ws.right + 1);
            return { overlaps, selects, pageOverflow: document.documentElement.scrollWidth - innerWidth };
        })()`);
        check(`${width}px Verify Grades: filters inside the workspace, no overlapping controls`, vg.overlaps === 0 && vg.selects && vg.pageOverflow <= 1, JSON.stringify(vg));
    }
    // 200% zoom (emulated: half the CSS width at twice the pixel density).
    await main.viewport(683, 450, 2);
    for (const [path, name] of [['/adviser/grades?period=1', 'encode-grades'], [`/adviser/verify-grades?period=1&subject_id=${cescId}`, 'verify-grades']]) {
        await main.goto(BASE + path);
        const overflow = await main.eval(`document.documentElement.scrollWidth - window.innerWidth`);
        check(`200% zoom ${name}: no page-level horizontal scroll`, overflow <= 1, `overflow ${overflow}px`);
        await main.screenshot(join(OUT, `${name}-200pct.png`));
    }
    await main.viewport(1366, 900);
    await main.viewport(390, 800);
    // ---- Mobile header + drawer (shared layout).
    check('390px: the menu trigger lives in a header, not floating over content', await main.eval(`(() => {
        const t = document.getElementById('sidebarToggle');
        return !!t && !!t.closest('[data-mobile-header]') && getComputedStyle(t).position !== 'fixed'
            && t.getAttribute('aria-expanded') === 'false' && t.getAttribute('aria-controls') === 'sidebar' && /Open menu/.test(t.getAttribute('aria-label'));
    })()`));
    check('390px: the closed drawer is inert (its links are not Tab stops)', await main.eval(`document.getElementById('sidebar').inert === true`));
    await main.eval(`document.getElementById('sidebarToggle').focus()`);
    // A real Enter keypress (with its "\r" text), which a focused button turns into a click.
    await main.send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13, text: '\r' });
    await main.send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13 });
    await sleep(300);
    const mobileNav = await main.eval(`[...document.querySelectorAll('aside a')].filter(a => a.getBoundingClientRect().width > 0 && a.getBoundingClientRect().right > 0).map(a => a.textContent.trim())`);
    check('390px: the menu opens and lists Class Record without Assessments', mobileNav.includes('Class Record') && !mobileNav.includes('Assessments'), JSON.stringify(mobileNav));
    check('390px: an open drawer is a modal dialog: expanded, focus inside, page behind inert', await main.eval(`(() => {
        const s = document.getElementById('sidebar');
        return document.getElementById('sidebarToggle').getAttribute('aria-expanded') === 'true' && s.getAttribute('aria-modal') === 'true'
            && s.contains(document.activeElement) && document.getElementById('appContent').inert === true && !s.inert;
    })()`));
    check('390px: Encode Grades and Verify Grades are both in the phone menu', mobileNav.includes('Encode Grades') && mobileNav.includes('Verify Grades'), JSON.stringify(mobileNav));
    await main.screenshot(join(OUT, 'adviser-menu-390.png'));
    await main.key('Escape', { keyCode: 27 });
    await sleep(300);
    check('390px: Escape closes the drawer and returns focus to the trigger', await main.eval(`(() => {
        const s = document.getElementById('sidebar');
        return s.classList.contains('-translate-x-full') && s.inert && document.activeElement?.id === 'sidebarToggle'
            && document.getElementById('appContent').inert === false && document.getElementById('sidebarOverlay').classList.contains('hidden');
    })()`));
    // Tapping the overlay closes it too; resizing to desktop resets a stale open drawer.
    await main.eval(`document.getElementById('sidebarToggle').click()`);
    await sleep(250);
    await main.eval(`document.getElementById('sidebarOverlay').click()`);
    await sleep(250);
    check('390px: tapping outside closes the drawer', await main.eval(`document.getElementById('sidebar').classList.contains('-translate-x-full')`));
    await main.eval(`document.getElementById('sidebarToggle').click()`);
    await sleep(250);
    await main.viewport(1366, 900);
    await sleep(250);
    check('resizing to desktop with the drawer open leaves the page usable', await main.eval(`document.getElementById('appContent').inert === false && document.getElementById('sidebar').inert === false && document.getElementById('sidebarOverlay').classList.contains('hidden')`));

    // ---- Nothing fixed covers content on representative adviser pages, at four widths and 200% zoom.
    const adviserPages = [['my-classes', '/teacher/classes'], ['class-record', cescUrl.replace(BASE, '')], ['advisory', '/adviser/advisory-overview?period=1'],
        ['encode', '/adviser/grades?period=1'], ['verify', `/adviser/verify-grades?period=1&subject_id=${cescId}`]];
    for (const [w, h, dsf, tag] of [[390, 800, 1, '390'], [768, 900, 1, '768'], [1366, 900, 1, '1366'], [683, 450, 2, '200pct']]) {
        await main.viewport(w, h, dsf);
        for (const [name, path] of adviserPages) {
            await main.goto(BASE + path);
            const issues = await layoutAudit(main, `${name}-${tag}`);
            check(`${tag} ${name}: nothing covers content at top/middle/bottom, no sideways page scroll`, issues.length === 0, issues.join(' | '));
        }
    }
    // The Class Record save bar and grid scrollbar stay clear under the phone header.
    await main.viewport(390, 800);
    await main.goto(`${BASE}/teacher/class-record?section_id=3&period=1`);
    if (await main.eval(`!!document.getElementById('crSaveBar')`)) {
        await main.eval(`(() => { const rows = document.querySelectorAll('tbody tr[data-student-row]'); rows[rows.length - 1].querySelectorAll('input.cr-cell')[2].focus(); })()`);
        await sleep(250);
        check('390px Class Record: focused last-row cell is below the header and above the save bar', await main.eval(`(() => {
            const c = document.activeElement.getBoundingClientRect(), bar = document.getElementById('crSaveBar').getBoundingClientRect(), hd = document.querySelector('[data-mobile-header]').getBoundingClientRect();
            return c.top >= hd.bottom && c.bottom <= bar.top + 1;
        })()`));
    }
    await main.viewport(1366, 900);

    // ---- Responsive layout.
    for (const width of [390, 768, 1366]) {
        await main.viewport(width, 900);
        await main.goto(cescUrl);
        await main.eval(`document.querySelector('.cr-workspace').scrollIntoView({ block: 'start' })`);
        await sleep(100);
        const layout = await main.eval(`(() => {
            const scroll = document.querySelector('.cr-scroll');
            const card = document.querySelector('.cr-workspace').getBoundingClientRect();
            const copy = document.getElementById('crCopyMode').getBoundingClientRect();
            const name = document.querySelector('tbody .cr-sticky');
            const buttons = [...document.querySelectorAll('.cr-toolbar .btn, .cr-selection .btn, .cr-savebar .btn')].filter(b => b.offsetParent).map(b => b.getBoundingClientRect());
            let overlaps = 0;
            for (let i = 0; i < buttons.length; i++) for (let j = i + 1; j < buttons.length; j++) {
                const a = buttons[i], b = buttons[j];
                if (a.left < b.right - 1 && b.left < a.right - 1 && a.top < b.bottom - 1 && b.top < a.bottom - 1) overlaps++;
            }
            return { pageOverflow: document.documentElement.scrollWidth - window.innerWidth, tableScrolls: scroll.scrollWidth >= scroll.clientWidth,
                sticky: getComputedStyle(name).position, overlaps, copyInsideCard: copy.right <= card.right + 1 };
        })()`);
        await main.screenshot(join(OUT, `class-record-${width}.png`));
        check(`${width}px: no page-level horizontal scroll, sticky names, no overlapping buttons`, layout.pageOverflow <= 1 && layout.tableScrolls && layout.sticky === 'sticky' && layout.overlaps === 0 && layout.copyInsideCard, JSON.stringify(layout));
    }
    await main.viewport(1366, 900);

    // ---- Draft isolation on a shared computer.
    await main.goto(cescUrl);
    await focusCell(main, 7); await main.type('9');
    check('an unsaved draft exists before logout', await main.eval(`Object.keys(localStorage).some(k => k.startsWith('cr-draft:'))`));
    await logout(main);
    check('logout removes every Class Record draft', await main.eval(`Object.keys(localStorage).filter(k => k.startsWith('cr-draft:')).length === 0`), await main.eval('location.pathname'));
    await main.eval(`localStorage.setItem('cr-draft:999:2026-2027:1:1:1', JSON.stringify({ at: new Date().toISOString(), cells: { '1:1': { value: '99' } } }))`);

    // ---- Assignment replacement while the old teacher has a tab open.
    const darienCtx = await cdp.newContext();
    const darien = await cdp.newPage(darienCtx);
    await darien.viewport(1366, 900);
    await login(darien, 'darien@browser.test');
    await darien.goto(scienceUrl);
    check('Bosco can encode General Science before the change', await darien.eval(`document.getElementById('classRecord').dataset.canEdit === '1'`));
    // A Subject-Teacher-style workflow for a second account: add, then type.
    await darien.navigating(() => darien.eval(`document.querySelector('[data-cr-add]').click()`));
    await darien.navigating(() => darien.eval(`(() => {
        const f = document.getElementById('assessmentForm');
        f.querySelector('[name=item_name]').value = 'Activity 1';
        f.querySelector('[name=component]').value = 'performance_task';
        f.querySelector('[name=max_score]').value = '50';
        f.requestSubmit();
    })()`));
    check('Bosco added a Performance Task column', await darien.eval(`document.body.innerText.includes('Activity 1') && !!document.querySelector('th.cr-new')`));
    await focusCell(darien, 0); await darien.type('14');

    const adminCtx = await cdp.newContext();
    const admin = await cdp.newPage(adminCtx);
    await admin.viewport(1366, 900);
    await login(admin, 'admin@browser.test');
    await admin.goto(`${BASE}/admin/teaching-assignments`);
    const editHref = await admin.eval(`[...document.querySelectorAll('tr')].find(tr => tr.innerText.includes('General Science') && tr.innerText.includes('Bosco')).querySelector('[data-edit-assignment]').href`);
    await admin.goto(editHref);
    await admin.screenshot(join(OUT, 'admin-edit-assignment-1366.png'));
    check('edit page shows teacher, class and evidence state', await admin.eval(`document.querySelector('[data-current-teacher]').textContent.includes('Bosco') && !!document.querySelector('[data-evidence-count]')`));
    await admin.navigating(() => admin.eval(`(() => {
        const f = document.querySelector('form[action*="/teaching-assignments/"]');
        const opt = [...f.querySelector('[name=user_id]').options].find(o => o.textContent.includes('Diaz'));
        f.querySelector('[name=user_id]').value = opt.value;
        f.querySelector('[name=reason]').value = 'Browser check — teacher replaced';
        f.requestSubmit();
    })()`));
    check('Admin replaced the teacher with a reason', await admin.eval(`document.body.innerText.includes('now teaches General Science') && document.body.innerText.includes('Browser check — teacher replaced')`));

    const oldTab = await saveAndWait(darien);
    check('the old teacher\'s open tab can no longer save', /no longer open to you/.test(oldTab), oldTab);
    check('...and its draft is dropped', await darien.eval(`!Object.keys(localStorage).some(k => k.startsWith('cr-draft:'))`));
    await darien.goto(scienceUrl);
    check('the old teacher can no longer open that class', await darien.eval(`/403|Forbidden|not authorized|permission/i.test(document.body.innerText)`), await darien.eval(`document.title`));

    const carlaCtx = await cdp.newContext();
    const carla = await cdp.newPage(carlaCtx);
    await carla.viewport(390, 800);
    await login(carla, 'carla@browser.test');
    check('the new teacher lands on My Classes with the class listed', await carla.eval(`location.pathname.endsWith('/teacher/classes') && document.body.innerText.includes('General Science')`));
    await carla.goto(scienceUrl);
    check('the new teacher can encode it', await carla.eval(`document.getElementById('classRecord').dataset.canEdit === '1'`));
    await carla.screenshot(join(OUT, 'subject-teacher-class-record-390.png'));

    // Shared-computer: signing in as another account purges a foreign draft.
    await login(main, 'bong@browser.test');
    await main.goto(cescUrl);
    check('a foreign account\'s draft is purged on this account\'s Class Record', await main.eval(`localStorage.getItem('cr-draft:999:2026-2027:1:1:1') === null`));

    // The Subject Teacher cannot reach verification or unrelated subjects.
    await carla.goto(`${BASE}/adviser/grades`);
    check('Subject Teacher cannot open Encode Grades', await carla.eval(`/403|Forbidden/i.test(document.body.innerText + document.title)`));
    await carla.goto(`${BASE}/adviser/verify-grades`);
    check('Subject Teacher cannot open Verify Grades', await carla.eval(`/403|Forbidden/i.test(document.body.innerText + document.title)`));
    await carla.goto(cescUrl);
    check('Subject Teacher cannot open an unrelated subject', await carla.eval(`/403|Forbidden/i.test(document.body.innerText + document.title)`));

    // Principal cannot open the Class Record.
    const pCtx = await cdp.newContext();
    const principal = await cdp.newPage(pCtx);
    await login(principal, 'principal@browser.test');
    await principal.goto(cescUrl);
    check('Principal cannot open the Class Record', await principal.eval(`/403|Forbidden/i.test(document.body.innerText + document.title)`));
    await principal.goto(`${BASE}/principal/academic-monitoring`);
    check('Principal monitoring shows the Missing concern from the Class Record', await principal.eval(`/CRUZ/.test(document.body.innerText) && /Missing Requirement/.test(document.body.innerText)`));

    // ---- Shared layout on Principal and Admin pages.
    const roleSets = [[principal, [['monitoring', '/principal/academic-monitoring'], ['interventions', '/principal/interventions']]],
        [admin, [['assignments', '/admin/teaching-assignments'], ['edit-assignment', editHref.replace(BASE, '')]]]];
    for (const [w, h, dsf, tag] of [[390, 800, 1, '390'], [768, 900, 1, '768'], [1366, 900, 1, '1366'], [683, 450, 2, '200pct']]) {
        for (const [page, paths] of roleSets) {
            await page.viewport(w, h, dsf);
            for (const [name, path] of paths) {
                await page.goto(BASE + path);
                const issues = await layoutAudit(page, `${name}-${tag}`);
                check(`${tag} ${name}: nothing covers content at top/middle/bottom, no sideways page scroll`, issues.length === 0, issues.join(' | '));
            }
        }
    }

    // ---- Learner report: print view.
    await main.viewport(1366, 900);
    const reportHref = await main.eval(`(async () => { const d = new DOMParser().parseFromString(await (await fetch(${JSON.stringify(cescUrl)})).text(), 'text/html');
        return d.querySelector('tbody tr[data-student-row] a[href*="/report"]')?.href; })()`);
    await main.goto(reportHref);
    const reportLearner = await main.eval(`document.querySelector('h1').textContent.trim()`);
    await main.send('Emulation.setEmulatedMedia', { media: 'print' });
    const printView = await main.eval(`(() => ({
        controlsHidden: [...document.querySelectorAll('.no-print')].every(el => getComputedStyle(el).display === 'none'),
        noNav: !document.querySelector('aside, nav, #sidebarToggle'),
        context: /LRN \\d{12} · Grade \\d+ — .+ · School Year \\d{4}-\\d{4} · Term \\d/.test(document.querySelector('header').textContent),
        oneLearner: document.querySelectorAll('h1').length === 1,
        tableHeaderRepeats: [...document.querySelectorAll('thead')].every(t => getComputedStyle(t).display === 'table-header-group'),
        cardsBreakable: getComputedStyle(document.querySelectorAll('section.card')[1] ?? document.body).breakInside !== 'avoid',
    }))()`);
    await main.screenshot(join(OUT, 'learner-report-print.png'));
    const pdf = await main.send('Page.printToPDF', { printBackground: false, preferCSSPageSize: true });
    await main.send('Emulation.setEmulatedMedia', { media: '' });
    const pdfBytes = Buffer.from(pdf.data, 'base64');
    const { writeFileSync } = await import('node:fs');
    writeFileSync(join(OUT, 'learner-report.pdf'), pdfBytes);
    const pdfPages = (pdfBytes.toString('latin1').match(/\/Type\s*\/Page[^s]/g) || []).length;
    check('learner report prints one learner with year/term/section, no navigation or controls, repeating table headers', Object.values(printView).every(Boolean) && pdfPages >= 1,
        JSON.stringify({ ...printView, pdfPages, learner: reportLearner.split(',')[0] }));

    const errors = [main, darien, admin, carla, principal].flatMap((p) => p.console);
    check('no JavaScript exceptions on any page', errors.length === 0, errors.slice(0, 3).join(' | '));
} catch (e) {
    check('run completed without a driver error', false, e.stack);
} finally {
    try { await cdp.send('Browser.close'); } catch (e) { /* ignore */ }
    proc.kill();
}

const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} browser checks passed. Screenshots: ${OUT}`);
process.exit(failed.length ? 1 : 0);
