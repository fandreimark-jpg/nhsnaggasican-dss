/**
 * Report submission boundary (2026-10-07) in a real browser, ISOLATED server only.
 * Fresh DB: php tests/Browser/prepare.php; php -S 127.0.0.1:8765 -t public tests/Browser/router.php
 * node tests/Browser/report-flow.mjs [outputDir]
 * Draft private → explicit submit → recipients see v1 → later grade change stays private → re-submit (confirm) → v2; other roles 403.
 */
import { launchChrome } from './cdp.mjs';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { existsSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
const BASE = 'http://127.0.0.1:8765';
const OUT = resolve(process.argv[2] ?? 'storage/framework/browser-testing/results-report-flow');
mkdirSync(OUT, { recursive: true });
const helper = (...a) => execFileSync('php', ['tests/Browser/isolated-artisan.php', ...a], { env: process.env }).toString().trim();
const chromePath = ['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'].find(existsSync);
const { cdp, proc } = await launchChrome({ chromePath, port: 9346 });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const results = [];
const check = (n, ok, d = '') => { results.push(!!ok); console.log(`${ok ? 'PASS' : 'FAIL'}  ${n}${!ok && d ? ' — ' + d : ''}`); };
async function session(email, w = 1366, h = 900) {
    const p = await cdp.newPage(await cdp.newContext());
    await p.viewport(w, h);
    await p.goto(`${BASE}/login`);
    await p.navigating(() => p.eval(`(() => { const f = document.querySelector('form[action$="/login"]'); f.querySelector('[name=email]').value = ${JSON.stringify(email)}; f.querySelector('[name=password]').value = 'Browser-Test-Only-1'; f.requestSubmit(); })()`));
    return p;
}
const text = (p) => p.eval('document.body.innerText');
const abadT1 = (p) => p.eval(`(() => { const sec = document.querySelector('[data-report-section]'); const card = [...document.querySelectorAll('[data-report-section]')].find(c => c.innerText.includes('Section Agila')); const row = [...card.querySelectorAll('tbody tr')].find(r => r.innerText.startsWith('ABAD')); return row ? row.children[1].innerText.trim() : null; })()`);
try {
    console.log(helper('seed-report-grades'));
    const bong = await session('bong@browser.test');
    const principal = await session('principal@browser.test');
    const admin = await session('admin@browser.test');

    // 1. Draft: adviser sees a private preview; recipients see "Not submitted", no figures.
    await bong.goto(`${BASE}/adviser/submit-report`);
    check('adviser: draft preview notice, Submit Term 1 offered', /Draft preview — only you see this page/.test(await text(bong)) && /Submit Term 1 Report/.test(await text(bong)));
    await bong.screenshot(`${OUT}/report-01-adviser-draft.png`);
    for (const [who, p] of [['principal', principal], ['admin', admin]]) {
        await p.goto(`${BASE}/${who}/reports?grading_period=1`);
        check(`${who}: Agila Term 1 "Not submitted", no draft figure`, (await abadT1(p)) === 'Not submitted' && /Submitted term reports only/.test(await text(p)), String(await abadT1(p)));
    }
    await principal.screenshot(`${OUT}/report-02-principal-before-submit.png`);

    // 2. Explicit submission.
    await bong.navigating(() => bong.eval(`[...document.querySelectorAll('form')].find(f => f.querySelector('[name=grading_period][value="1"]') && f.querySelector('[name=expected_version]')).requestSubmit()`));
    check('adviser: submitted (version 1)', /report submitted \(version 1\)/.test(await text(bong)) && /v1 — /.test(await text(bong)));
    await principal.goto(`${BASE}/principal/reports?grading_period=1`);
    const v1 = await abadT1(principal);
    check('principal: Submitted · v1 with the frozen figure', /Submitted · v1/.test(await text(principal)) && /^\d+\.\d\d$/.test(v1), v1);
    await admin.goto(`${BASE}/admin/reports?grading_period=1`);
    check('admin: same submitted version', (await abadT1(admin)) === v1);

    // 3. Revision privacy: a later correction is in the adviser's draft only.
    helper('set-grade', 'ABAD', '99');
    await bong.goto(`${BASE}/adviser/submit-report`);
    await principal.goto(`${BASE}/principal/reports?grading_period=1`);
    check('principal: still sees version 1 after the grade change', (await abadT1(principal)) === v1 && /Submitted · v1/.test(await text(principal)));
    check('adviser: page explains recipients see v1 until re-submit', /\(what recipients see\)/.test(await text(bong)) && /Re-submit/.test(await text(bong)));

    // 4. Re-submit through the confirmation dialog.
    await bong.eval(`[...document.querySelectorAll('form[data-resubmit]')].find(f => f.querySelector('[name=grading_period][value="1"]')).querySelector('button[type=submit]').click()`);
    await sleep(400);
    await bong.screenshot(`${OUT}/report-03-resubmit-confirm.png`);
    const confirmBtn = await bong.eval(`(() => { const b = [...document.querySelectorAll('#confirmResubmitModal button')].find(b => /Yes, Re-submit/.test(b.innerText)); if (b) { return b.id + ':' + b.innerText.trim(); } return null; })()`);
    check('re-submit asks for confirmation', !!confirmBtn, String(confirmBtn));
    await bong.eval(`document.getElementById('confirmResubmitBtn').click()`);
    await bong.waitUntil(`document.readyState === 'complete' && /version 2/.test(document.body.innerText)`, 15000);
    check('adviser: re-submitted (version 2), both versions listed', /re-submitted \(version 2\)/.test(await text(bong)) && /v2 — /.test(await text(bong)) && /v1 — /.test(await text(bong)));
    await principal.goto(`${BASE}/principal/reports?grading_period=1`);
    const v2 = await abadT1(principal);
    check('principal: now Submitted · v2 with the corrected figure', /Submitted · v2/.test(await text(principal)) && v2 !== v1, `${v1} -> ${v2}`);
    await principal.screenshot(`${OUT}/report-04-principal-v2.png`);

    // 5. Access control in the browser: other roles cannot post a submission.
    for (const email of ['principal@browser.test', 'carla@browser.test', 'admin@browser.test']) {
        const p = await session(email);
        const st = await p.eval(`fetch('/adviser/submit-report', { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'grading_period=1&expected_version=2' }).then(r => r.status)`);
        check(`${email.split('@')[0]}: direct submit POST refused (403)`, st === 403, String(st));
    }

    // 6. Mobile.
    await bong.viewport(390, 844, 2);
    await bong.goto(`${BASE}/adviser/submit-report`);
    check('390 px: adviser page has no sideways page scroll; one scroll area', await bong.eval(`document.documentElement.scrollWidth <= innerWidth + 1 && document.scrollingElement.scrollHeight <= innerHeight + 1`));
    await bong.screenshot(`${OUT}/report-05-adviser-390.png`);
    await principal.viewport(390, 844, 2);
    await principal.goto(`${BASE}/principal/reports?grading_period=1`);
    check('390 px: principal reports no sideways page scroll', await principal.eval(`document.documentElement.scrollWidth <= innerWidth + 1`));
    await principal.screenshot(`${OUT}/report-06-principal-390.png`);
    const errs = [bong, principal, admin].flatMap((p) => p.console);
    check('no console errors', errs.length === 0, errs.slice(0, 3).join(' | '));
} catch (e) {
    check('driver error', false, e.stack);
} finally {
    try { await cdp.send('Browser.close'); } catch (e) {}
    proc.kill();
}
console.log(`${results.filter(Boolean).length}/${results.length} report-flow checks passed.`);
process.exit(results.every(Boolean) ? 0 : 1);
