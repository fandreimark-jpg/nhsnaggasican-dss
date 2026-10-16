/**
 * Slow-network, failure and concurrency checks for the Class Record, against
 * the ISOLATED browser server only (run after prepare.php; independent of
 * run.mjs). Every scenario asserts: no misleading "Saved", no silent loss,
 * no duplicate write, and an explicit recovery path.
 *
 *   node tests/Browser/netfail.mjs
 */
import { launchChrome, sleep } from './cdp.mjs';
import { existsSync } from 'node:fs';

const BASE = 'http://127.0.0.1:8765';
const PASSWORD = 'Browser-Test-Only-1';
const results = [];
const check = (name, ok, detail = '') => { results.push({ name, ok: !!ok }); console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? ' — ' + detail : ''}`); };
const chromePath = ['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'].find(existsSync);
const { cdp, proc } = await launchChrome({ chromePath, port: 9336 });

async function login(page, email) {
    await page.goto(`${BASE}/login`);
    await page.navigating(() => page.eval(`(() => { const f = document.querySelector('form[action$="/login"]'); f.email.value = ${JSON.stringify(email)}; f.password.value = ${JSON.stringify(PASSWORD)}; f.requestSubmit(); })()`));
}
const RECORD = `${BASE}/teacher/class-record?section_id=3&period=1`;   // Narra, General Mathematics (Bong teaches it)
const setCell = (page, row, col, value) => page.eval(`(() => { const c = document.querySelectorAll('tbody tr[data-student-row]')[${row}].querySelectorAll('input.cr-cell')[${col}]; c.focus(); c.value = ${JSON.stringify(value)}; c.dispatchEvent(new Event('input', { bubbles: true })); return c.dataset.a + ':' + c.dataset.s; })()`);
const state = (page) => page.eval(`({ state: document.getElementById('crStatus').dataset.state, text: document.getElementById('crStatus').textContent.trim(), errors: document.getElementById('crErrors').textContent.trim(), dirty: document.querySelectorAll('input.cr-cell.cr-dirty').length })`);
const waitIdle = (page) => page.waitUntil(`document.getElementById('crStatus').dataset.state !== 'saving'`, 20000);
async function storedValue(page, key) {
    // A fresh server read of the page — what is actually stored now.
    return page.eval(`(async () => { const d = new DOMParser().parseFromString(await (await fetch(${JSON.stringify(RECORD)}, { credentials: 'same-origin' })).text(), 'text/html');
        const [a, s] = ${JSON.stringify(key)}.split(':'); return d.querySelector('input.cr-cell[data-a="' + a + '"][data-s="' + s + '"]')?.value ?? null; })()`);
}
async function adminPost(admin, path) {
    return admin.eval(`fetch(${JSON.stringify(BASE + path)}, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, Accept: 'text/html' } }).then(r => r.status)`);
}

try {
    const ctx = await cdp.newContext();
    const page = await cdp.newPage(ctx);
    await page.viewport(1366, 900);
    await login(page, 'bong@browser.test');
    await page.goto(RECORD);
    if (!(await page.eval(`document.getElementById('classRecord')?.dataset.canEdit === '1'`))) throw new Error('Bong cannot edit the Narra record — run prepare.php first');

    // S1. Slow network + repeated clicks: exactly one request, success only after the server answers.
    let saves = 0;
    const off = cdp.on((e) => { if (e.sessionId === page.sessionId && e.method === 'Network.requestWillBeSent' && e.params.request.url.includes('/class-record/save')) saves++; });
    await page.send('Network.emulateNetworkConditions', { offline: false, latency: 2000, downloadThroughput: 50000, uploadThroughput: 50000 });
    const k1 = await setCell(page, 0, 0, '11');
    await page.eval(`(() => { for (let i = 0; i < 5; i++) document.getElementById('crSave').click(); })()`);
    await sleep(300);
    const during = await state(page);
    check('S1 slow network: status says Saving… (not Saved) while pending', during.state === 'saving', JSON.stringify(during));
    await waitIdle(page);
    const after1 = await state(page);
    check('S1 five rapid clicks send exactly one save request', saves === 1, `requests ${saves}`);
    check('S1 Saved appears only after the server confirmed', after1.state === 'saved' && after1.dirty === 0, JSON.stringify(after1));
    await page.send('Network.emulateNetworkConditions', { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
    check('S1 the value is stored', (await storedValue(page, k1)) === '11');
    off();

    // S2. Response lost after the server committed: failure shown, entry kept, retry is idempotent.
    await page.send('Fetch.enable', { patterns: [{ urlPattern: '*class-record/save*', requestStage: 'Response' }] });
    const offFetch = cdp.on((e) => {
        if (e.sessionId === page.sessionId && e.method === 'Fetch.requestPaused') page.send('Fetch.failRequest', { requestId: e.params.requestId, errorReason: 'ConnectionReset' }).catch(() => {});
    });
    const k2 = await setCell(page, 1, 0, '12');
    await page.eval(`document.getElementById('crSave').click()`);
    await waitIdle(page);
    const lost = await state(page);
    offFetch();
    await page.send('Fetch.disable');
    check('S2 lost response: no "Saved", entry still marked unsaved', lost.state === 'offline' && lost.dirty >= 1, JSON.stringify(lost));
    check('S2 the server had in fact committed the value', (await storedValue(page, k2)) === '12');
    await page.eval(`document.getElementById('crSave').click()`);
    await waitIdle(page);
    const retried = await state(page);
    check('S2 retry succeeds without a false conflict or a second write', retried.state === 'saved' && retried.dirty === 0
        && await page.eval(`document.getElementById('crConflicts').classList.contains('hidden')`), JSON.stringify(retried));

    // S3. Two tabs of the same teacher editing DIFFERENT learners: both saves succeed.
    const tab2 = await cdp.newPage(ctx);
    await tab2.viewport(1366, 900);
    await tab2.goto(RECORD);
    await page.goto(RECORD);
    const ka = await setCell(page, 2, 1, '9');
    const kb = await setCell(tab2, 3, 1, '8');
    await Promise.all([page.eval(`document.getElementById('crSave').click()`), tab2.eval(`document.getElementById('crSave').click()`)]);
    await waitIdle(page); await waitIdle(tab2);
    check('S3 concurrent saves of different learners both succeed', (await state(page)).state === 'saved' && (await state(tab2)).state === 'saved'
        && (await storedValue(page, ka)) === '9' && (await storedValue(page, kb)) === '8');
    // ...and of the SAME cell: the later one is refused as a conflict, nothing overwritten.
    await page.goto(RECORD); await tab2.goto(RECORD);
    const kc = await setCell(page, 4, 1, '7');
    await setCell(tab2, 4, 1, '6');
    await page.eval(`document.getElementById('crSave').click()`); await waitIdle(page);
    await tab2.eval(`document.getElementById('crSave').click()`); await waitIdle(tab2);
    check('S3 the same cell from two tabs: second save is a conflict, first value kept', (await state(tab2)).state === 'conflict' && (await storedValue(page, kc)) === '7');
    await tab2.close();

    // S4. Term closed during an open session: the save is refused, never "Saved".
    const actx = await cdp.newContext();
    const admin = await cdp.newPage(actx);
    await login(admin, 'admin@browser.test');
    await admin.goto(`${BASE}/admin/academic-terms`);
    const termId = await admin.eval(`(() => { const f = [...document.querySelectorAll('form[action*="/close"]')][0]; return f ? f.action.match(/academic-terms\\/(\\d+)\\/close/)[1] : null; })()`);
    await page.goto(RECORD);
    const kd = await setCell(page, 5, 1, '5');
    const before4 = await storedValue(page, kd);
    const closeStatus = await adminPost(admin, `/admin/academic-terms/${termId}/close`);
    await page.eval(`document.getElementById('crSave').click()`); await waitIdle(page);
    const closed = await state(page);
    check('S4 term closed mid-session: save refused, value unchanged', [200, 302].includes(closeStatus) && closed.state === 'forbidden' && (await storedValue(page, kd)) === before4, JSON.stringify(closed));
    await adminPost(admin, `/admin/academic-terms/${termId}/open`);

    // S5. Account disabled mid-session: explicit refusal, entry and draft kept.
    await page.goto(RECORD);
    const ke = await setCell(page, 6, 1, '4');
    const before5 = await storedValue(page, ke);
    await admin.goto(`${BASE}/admin/users`);
    const bongId = await admin.eval(`(() => { const row = [...document.querySelectorAll('tr')].find(r => r.textContent.includes('bong@browser.test') || r.textContent.includes('Cruz, Bong')); const f = row?.querySelector('form[action*="/disable"]'); return f ? f.action.match(/users\\/(\\d+)\\/disable/)[1] : null; })()`);
    const disableStatus = await adminPost(admin, `/admin/users/${bongId}/disable`);
    await page.eval(`document.getElementById('crSave').click()`); await waitIdle(page);
    const disabled = await state(page);
    const draftKept = await page.eval(`Object.keys(localStorage).some(k => k.startsWith('cr-draft:'))`);
    check('S5 disabled mid-session: no "Saved", explicit message, entry and draft kept', [200, 302].includes(disableStatus) && disabled.state === 'forbidden'
        && /disabled/i.test(disabled.errors) && disabled.dirty >= 1 && draftKept, JSON.stringify({ ...disabled, draftKept }));
    await adminPost(admin, `/admin/users/${bongId}/activate`);

    // S6. Session expired (cookie gone): explicit, entries kept, nothing written.
    await login(page, 'bong@browser.test');
    await page.goto(RECORD);
    check('S5 the disabled account really wrote nothing', (await storedValue(page, ke)) === before5);
    const kf = await setCell(page, 7, 1, '3');
    const before6 = await storedValue(page, kf);
    const cookies = (await page.send('Network.getCookies', { urls: [BASE] })).cookies;
    for (const c of cookies) await page.send('Network.deleteCookies', { name: c.name, url: BASE });
    await page.eval(`document.getElementById('crSave').click()`); await waitIdle(page);
    const expired = await state(page);
    check('S6 expired session: "session has ended", entry kept on the page', expired.state === 'forbidden' && /session has ended/i.test(expired.errors) && expired.dirty >= 1, JSON.stringify(expired));
    await login(page, 'bong@browser.test');
    await page.goto(RECORD);
    check('S6 nothing was written after the session ended', (await storedValue(page, kf)) === before6);

    const errors = [page, admin].flatMap((p) => p.console).filter((m) => !/Failed to load resource/.test(m));
    check('no JavaScript exceptions', errors.length === 0, errors.slice(0, 3).join(' | '));
} catch (e) {
    check('run completed without a driver error', false, e.stack);
} finally {
    try { await cdp.send('Browser.close'); } catch (e) { /* ignore */ }
    proc.kill();
}
const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} network/concurrency checks passed.`);
process.exit(failed.length ? 1 : 0);
