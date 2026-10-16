/**
 * Missing -> concern -> score -> re-evaluation, end to end in a real browser,
 * against the ISOLATED server only (see tests/Browser/isolation.php). Needs a
 * FRESH isolated database (it adds its own assessments to Agila / CESC):
 *
 *   php tests/Browser/prepare.php                       (or BROWSER_ISOLATION=mysql …)
 *   php -S 127.0.0.1:8765 -t public tests/Browser/router.php     (separate shell)
 *   node tests/Browser/concern-flow.mjs [outputDir]
 *
 * Scenario (learner BAUTISTA, BEN — row 2 of the MALE block):
 *  1. Quiz A + Quiz B saved with one score each -> every other blank becomes Missing.
 *  2. Quiz C saved with NO score typed, via "Save this assessment's blanks as Missing".
 *  3. Ben: For Review, 3 open concerns (adviser My Students; Principal monitoring).
 *  4. Ben's Quiz A Missing replaced by 12 -> save -> reload: 12 persists,
 *     that concern is Resolved, the other two keep him For Review (2).
 *  5. History keeps blank -> Missing (automatic) -> marker removed -> 12.
 *  6. Quiz B and Quiz C scored -> No Current Concern.
 */
import { launchChrome, sleep } from './cdp.mjs';
import { mkdirSync, existsSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = 'http://127.0.0.1:8765';
const PASSWORD = 'Browser-Test-Only-1';
const OUT = resolve(process.argv[2] ?? 'storage/framework/browser-testing/results-concern-flow');
mkdirSync(OUT, { recursive: true });

const results = [];
function check(name, ok, detail = '') {
    results.push({ name, ok: !!ok, detail });
    console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? ' — ' + detail : ''}`);
}

const chromePath = ['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'].find(existsSync);
const { cdp, proc } = await launchChrome({ chromePath, port: 9334 });

async function login(page, email) {
    await page.goto(`${BASE}/login`);
    await page.navigating(() => page.eval(`(() => {
        const f = document.querySelector('form[action$="/login"]');
        f.querySelector('[name=email]').value = ${JSON.stringify(email)};
        f.querySelector('[name=password]').value = ${JSON.stringify(PASSWORD)};
        f.requestSubmit();
    })()`));
}
const statusText = (page) => page.eval(`document.getElementById('crStatus')?.textContent.trim()`);
async function saveAndWait(page) {
    await page.eval(`document.getElementById('crSave').click()`);
    await page.waitUntil(`!/Saving/.test(document.getElementById('crStatus').textContent)`, 15000);
    return statusText(page);
}
const colOf = (page, name) => page.eval(`[...document.querySelectorAll('thead th[data-item-head]')].findIndex(th => th.querySelector('.cr-item-name').textContent.trim() === ${JSON.stringify(name)})`);
const cellAt = (page, row, col) => page.eval(`document.querySelectorAll('tbody tr[data-student-row]')[${row}].querySelectorAll('input.cr-cell')[${col}].value`);
async function focusCell(page, row, col) {
    await page.eval(`(() => { const c = document.querySelectorAll('tbody tr[data-student-row]')[${row}].querySelectorAll('input.cr-cell')[${col}]; c.focus(); c.select(); })()`);
}
async function addItem(page, url, name, max) {
    await page.goto(url);
    await page.navigating(() => page.eval(`document.querySelector('[data-cr-add]').click()`));
    await page.navigating(() => page.eval(`(() => {
        const f = document.getElementById('assessmentForm');
        f.querySelector('[name=item_name]').value = ${JSON.stringify(name)};
        f.querySelector('[name=component]').value = 'written_work';
        f.querySelector('[name=max_score]').value = ${JSON.stringify(String(max))};
        f.requestSubmit();
    })()`));
}
/** Ben's overall monitoring on the adviser's My Students page. */
async function myStudentsStatus(page, studentId) {
    await page.goto(`${BASE}/adviser/students?period=1`);
    return page.eval(`(() => {
        const el = document.querySelector('tr[data-student-row="${studentId}"] [data-monitoring-status]');
        return el ? { status: el.dataset.monitoringStatus, open: el.dataset.openConcerns ?? null, text: el.textContent.replace(/\\s+/g, ' ').trim() } : null;
    })()`);
}
/** Ben's concern blocks on the Principal's Academic Monitoring page. */
async function principalConcerns(page, status) {
    await page.goto(`${BASE}/principal/academic-monitoring?status=${status}&term=1`);
    return page.eval(`[...document.querySelectorAll('[data-concern-group]')]
        .filter(g => /BAUTISTA, BEN/.test(g.textContent) && /Community Engagement/.test(g.textContent))
        .flatMap(g => [...g.querySelectorAll('[data-concern-id]')].map(c => c.textContent.replace(/\\s+/g, ' ').trim()))`);
}

try {
    const main = await cdp.newPage();
    await main.viewport(1366, 900);
    await login(main, 'bong@browser.test');
    const isolated = (await main.eval('location.pathname')).includes('/adviser/dashboard');
    check('isolated server: isolated-only account signs in', isolated, await main.eval('location.pathname'));
    if (!isolated) throw new Error('not isolated — stopping');

    const cescUrl = await main.eval(`(async () => {
        const d = new DOMParser().parseFromString(await (await fetch('${BASE}/teacher/class-record')).text(), 'text/html');
        const sec = [...d.querySelectorAll('#crSection option')].find(o => o.textContent.includes('Agila'));
        const d2 = new DOMParser().parseFromString(await (await fetch('${BASE}/teacher/class-record?section_id=' + sec.value + '&period=1')).text(), 'text/html');
        const sub = [...d2.querySelectorAll('#crSubject option')].find(o => o.textContent.includes('Community Engagement'));
        return '${BASE}/teacher/class-record?section_id=' + sec.value + '&subject_id=' + sub.value + '&period=1';
    })()`);
    check('fresh fixture: CESC in Agila has no assessments yet', await (async () => { await main.goto(cescUrl); return main.eval(`document.querySelectorAll('thead th[data-item-head]').length === 0`); })());

    // ---- 1. Two assessments saved together; blanks become Missing.
    await addItem(main, cescUrl, 'Quiz A', 20);
    await addItem(main, cescUrl, 'Quiz B', 10);
    const [qa, qb] = [await colOf(main, 'Quiz A'), await colOf(main, 'Quiz B')];
    const ben = await main.eval(`(() => { const tr = document.querySelectorAll('tbody tr[data-student-row]')[1]; return { id: tr.dataset.studentRow, name: tr.textContent }; })()`);
    check('row 2 is BAUTISTA, BEN (MALE block, recorded gender)', /BAUTISTA/.test(ben.name), ben.name.replace(/\s+/g, ' ').slice(0, 60));
    await focusCell(main, 0, qa); await main.type('15');
    await focusCell(main, 0, qb); await main.type('8');
    const s1 = await saveAndWait(main);
    check('save: 2 typed scores + 7 blanks in each assessment saved as Missing', /Saved 16 change/.test(s1), s1);
    await main.goto(cescUrl);
    check('after reload Ben shows M in Quiz A and Quiz B', (await cellAt(main, 1, qa)) === 'M' && (await cellAt(main, 1, qb)) === 'M');

    // ---- 2. An all-blank assessment saved as Missing without typing a score.
    await addItem(main, cescUrl, 'Quiz C', 10);
    const qc = await colOf(main, 'Quiz C');
    await focusCell(main, 1, qc);
    check('the assessment-scoped action is offered for the selected cell', await main.eval(`!document.getElementById('crScopeAssessment').disabled && document.getElementById('crSave').disabled`));
    await main.click('#crScopeAssessment');
    const scoped = await main.eval(`({ pressed: document.getElementById('crScopeAssessment').getAttribute('aria-pressed'), scope: document.getElementById('crScope').textContent,
        unsaved: document.getElementById('crUnsaved').textContent, save: document.getElementById('crSave').disabled, typed: [...document.querySelectorAll('input.cr-cell')].filter(c => c.value !== '' && c.value !== 'M' && c.dataset.a === document.activeElement.dataset.a).length,
        pending: document.querySelectorAll('input.cr-cell.cr-pending-missing').length })`);
    check('choosing it names the scope, counts 8 blanks, enables Save; no cell value was typed', scoped.pressed === 'true' && /Being saved: Quiz C — 8 blank entries will become Missing\./.test(scoped.scope)
        && /8 blank entries to save as Missing/.test(scoped.unsaved) && !scoped.save && scoped.typed === 0 && scoped.pending === 8, JSON.stringify(scoped));
    await main.click('#crScopeAssessment');
    check('choosing it again takes the assessment back out (nothing to save)', await main.eval(`document.getElementById('crSave').disabled && document.getElementById('crScope').textContent === '' && !document.querySelector('.cr-pending-missing')`));
    // The choice survives a reload as this account's local draft (nothing saved).
    await main.click('#crScopeAssessment');
    const draftKey = await main.eval(`document.getElementById('classRecord').dataset.draftKey`);
    const draftScope = () => main.eval(`(JSON.parse(localStorage.getItem(${JSON.stringify(draftKey)}) || 'null') || {}).scope ?? null`);
    check('the choice is kept in the draft for this account and class', JSON.stringify(await draftScope()) === JSON.stringify([String(await main.eval(`document.querySelectorAll('thead th[data-item-head]')[${qc}].dataset.itemHead`))]), JSON.stringify(await draftScope()));
    await main.goto(cescUrl);
    const notice = () => main.eval(`document.getElementById('crDraftNotice').classList.contains('hidden') ? '' : document.getElementById('crDraftNotice').textContent`);
    check('after reload the choice is offered again by name with its current blank count; nothing was saved', /Quiz C chosen to save its blanks as Missing \(8 blank entries now\)/.test(await notice())
        && await main.eval(`[...document.querySelectorAll('tbody tr[data-student-row]')].every(tr => tr.querySelectorAll('input.cr-cell')[${qc}].value === '')`), await notice());
    await main.eval(`[...document.querySelectorAll('#crDraftNotice button')].find(b => b.textContent === 'Discard').click()`);
    check('Discard clears the restored choice from the draft', (await draftScope()) === null);
    await main.goto(cescUrl);
    check('...and it is not offered again', (await notice()) === '');

    // Choose again; meanwhile ANOTHER session records a score in one Quiz C cell.
    await focusCell(main, 1, qc);
    await main.click('#crScopeAssessment');
    const other = await main.eval(`(async () => {
        const c = document.querySelectorAll('tbody tr[data-student-row]')[7].querySelectorAll('input.cr-cell')[${qc}];
        const r = document.getElementById('classRecord');
        const res = await fetch(r.dataset.saveUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
            body: JSON.stringify({ section_id: +r.dataset.sectionId, subject_id: +r.dataset.subjectId, period: +r.dataset.period,
                cells: [{ assessment_id: +c.dataset.a, student_id: +c.dataset.s, score: '6', base_score: null, base_missing: false }] }) });
        return res.status;
    })()`);
    await main.goto(cescUrl);
    check('a concurrent score shrinks the restored choice to the cells still blank (7)', other === 200 && /Quiz C chosen to save its blanks as Missing \(7 blank entries now\)/.test(await notice()), await notice());
    await main.eval(`[...document.querySelectorAll('#crDraftNotice button')].find(b => b.textContent === 'Restore them').click()`);
    check('restored: the save bar names the scope and its 7 blanks', /Being saved: Quiz C — 7 blank entries will become Missing\./.test(await main.eval(`document.getElementById('crScope').textContent`))
        && !(await main.eval(`document.getElementById('crSave').disabled`)), await main.eval(`document.getElementById('crScope').textContent`));
    const s2 = await saveAndWait(main);
    check('save: the 7 remaining blanks of Quiz C saved as Missing, nothing else', /Saved 7 change/.test(s2), s2);
    check('a successful save clears the draft', (await draftScope()) === null);
    await main.goto(cescUrl);
    check('after reload the concurrent 6 is kept and every other Quiz C cell is M; Quiz A/B unchanged', await main.eval(`[...document.querySelectorAll('tbody tr[data-student-row]')].every((tr, i) => tr.querySelectorAll('input.cr-cell')[${qc}].value === (i === 7 ? '6' : 'M'))`)
        && (await cellAt(main, 0, qa)) === '15' && (await cellAt(main, 0, qb)) === '8' && (await notice()) === '');

    // ---- 3. The concerns exist.
    let ms = await myStudentsStatus(main, ben.id);
    check('My Students: Ben is For Review with 3 open concerns', ms?.status === 'for-review' && ms.open === '3', JSON.stringify(ms));
    const pCtx = await cdp.newContext();
    const principal = await cdp.newPage(pCtx);
    await principal.viewport(1366, 900);
    await login(principal, 'principal@browser.test');
    let pc = await principalConcerns(principal, 'open');
    check('Principal monitoring: Ben has 3 For Review Missing concerns (Quiz A, B, C)', pc.length === 3 && ['Quiz A', 'Quiz B', 'Quiz C'].every(q => pc.some(t => t.includes(q) && /For Review/.test(t))), JSON.stringify(pc));

    // ---- 4. Missing replaced by a valid score.
    await main.goto(cescUrl);
    await focusCell(main, 1, qa); await main.type('12');
    const s3 = await saveAndWait(main);
    check('save: Ben\'s Quiz A Missing replaced by 12 (one change)', /Saved 1 change/.test(s3), s3);
    await main.goto(cescUrl);
    check('after reload: Quiz A = 12, Quiz B and Quiz C still M', (await cellAt(main, 1, qa)) === '12' && (await cellAt(main, 1, qb)) === 'M' && (await cellAt(main, 1, qc)) === 'M');
    check('the running Initial Grade now reads Ben\'s Quiz A score', await main.eval(`document.querySelectorAll('tbody tr[data-student-row]')[1].querySelector('[data-cr-out="written_work:total"]').textContent.trim() === '12 / 40'`), 'workbook TOTAL: 12 earned / HPS 20 + 10 + 10 (Quiz B and C are Missing, their HPS still counts)');
    await main.screenshot(join(OUT, 'concern-flow-class-record.png'));

    ms = await myStudentsStatus(main, ben.id);
    check('another open concern keeps Ben For Review (2), not No Current Concern', ms?.status === 'for-review' && ms.open === '2', JSON.stringify(ms));
    pc = await principalConcerns(principal, 'all');
    const resolvedA = pc.find(t => t.includes('Quiz A'));
    check('Principal: the Quiz A concern is Resolved, from evidence ("was Missing · now Completed")', /Resolved/.test(resolvedA ?? '') && /was Missing · now\s*Completed/.test(resolvedA ?? ''), resolvedA);
    check('Principal: Quiz B and Quiz C stay For Review', ['Quiz B', 'Quiz C'].every(q => /For Review/.test(pc.find(t => t.includes(q)) ?? '')), JSON.stringify(pc));
    await principal.screenshot(join(OUT, 'concern-flow-principal.png'));

    // ---- 5. History preserved.
    const qaId = await (async () => { await main.goto(cescUrl); return main.eval(`document.querySelectorAll('thead th[data-item-head]')[${qa}].dataset.itemHead`); })();
    await main.goto(`${BASE}/teacher/assessments/${qaId}/history`);
    const hist = await main.eval(`[...document.querySelectorAll('tbody tr')].filter(tr => /BAUTISTA, BEN/.test(tr.textContent))
        .map(tr => [...tr.querySelectorAll('td')].map(td => td.textContent.trim())).map(c => [c[2], c[3], c[5]])`);
    const has = (b, a, src) => hist.some(([x, y, s]) => x.startsWith(b) && y === a && (src === null || s === src));
    check('history keeps blank -> Missing (automatic), Missing -> marker removed, -> 12', hist.length === 3
        && has('Not Yet Given', 'Missing', 'class_record_auto_missing') && has('Missing', 'Not Yet Given / No Evidence Yet', null) && has('Not Yet Given', '12', 'class_record'), JSON.stringify(hist));

    // ---- 6. The remaining evidence resolves the rest.
    await main.goto(cescUrl);
    await focusCell(main, 1, qb); await main.type('7');
    await focusCell(main, 1, qc); await main.type('9');
    const s4 = await saveAndWait(main);
    check('save: Quiz B and Quiz C scored for Ben', /Saved 2 change/.test(s4), s4);
    ms = await myStudentsStatus(main, ben.id);
    check('with every concern resolved Ben reads No Current Concern', ms?.status === 'none', JSON.stringify(ms));
    pc = await principalConcerns(principal, 'open');
    check('Principal For Review no longer lists Ben', pc.length === 0, JSON.stringify(pc));

    const errors = [main, principal].flatMap((p) => p.console);
    check('no JavaScript exceptions', errors.length === 0, errors.slice(0, 3).join(' | '));
} catch (e) {
    check('run completed without a driver error', false, e.stack);
} finally {
    try { await cdp.send('Browser.close'); } catch (e) { /* ignore */ }
    proc.kill();
}

const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} concern-flow checks passed. Screenshots: ${OUT}`);
process.exit(failed.length ? 1 : 0);
