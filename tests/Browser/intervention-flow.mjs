/**
 * Intervention scheduling end to end in a real browser, against the
 * ISOLATED server only. Needs a FRESH isolated database:
 *
 *   php tests/Browser/prepare.php                       (or BROWSER_ISOLATION=mysql …)
 *   php -S 127.0.0.1:8765 -t public tests/Browser/router.php     (separate shell)
 *   node tests/Browser/intervention-flow.mjs [outputDir]
 *
 * 0. The Principal reads "Performance by Grade and Section" on the dashboard
 *    and opens Agila in Academic Monitoring (filters kept).
 * 1. The Principal records BAUTISTA's General Science intervention from the
 *    open Missing concern; the form shows Bong (Agila's adviser) as the
 *    responsible adviser, read-only (2026-10-07).
 * 2. Bong sets the schedule and extends it directly (no approval), records
 *    completion; the Principal sees it with the history.
 * 3. The GS intervention expires (time simulated by moving the schedule back
 *    — the clock is never changed); Darien (teaches GS in Agila, advises
 *    another section) is refused; Bong reopens it with a reason.
 * 4. Carla (subject teacher) has no Interventions item and every intervention
 *    URL is 403; her assigned Class Record still opens editable.
 * Plus: a reversed schedule / missing reason shows its error once; 390 px checks.
 */
import { launchChrome } from './cdp.mjs';
import { execFileSync } from 'node:child_process';
import { mkdirSync, existsSync } from 'node:fs';
import { resolve, join } from 'node:path';

const BASE = 'http://127.0.0.1:8765';
const PASSWORD = 'Browser-Test-Only-1';
const OUT = resolve(process.argv[2] ?? 'storage/framework/browser-testing/results-intervention-flow');
mkdirSync(OUT, { recursive: true });
const results = [];
const check = (name, ok, detail = '') => { results.push({ name, ok: !!ok }); console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail && !ok ? ' — ' + detail : ''}`); };
const helper = (...args) => execFileSync('php', ['tests/Browser/isolated-artisan.php', ...args], { env: process.env }).toString().trim();

/** "YYYY-MM-DDTHH:MM" in Asia/Manila, minutes from now. */
function manila(minutes) {
    const d = new Date(Date.now() + minutes * 60000);
    const p = Object.fromEntries(new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
        .formatToParts(d).map((x) => [x.type, x.value]));
    return `${p.year}-${p.month}-${p.day}T${p.hour}:${p.minute}`;
}

const chromePath = ['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'].find(existsSync);
const { cdp, proc } = await launchChrome({ chromePath, port: 9336 });

async function login(page, email) {
    await page.goto(`${BASE}/login`);
    await page.navigating(() => page.eval(`(() => { const f = document.querySelector('form[action$="/login"]');
        f.querySelector('[name=email]').value = ${JSON.stringify(email)}; f.querySelector('[name=password]').value = ${JSON.stringify(PASSWORD)}; f.requestSubmit(); })()`));
}
const text = (page) => page.eval(`document.body.innerText`);
const lifecycle = (page) => page.eval(`document.querySelector('[data-schedule-summary] [data-lifecycle]')?.dataset.lifecycle`);
async function submitForm(page, actionSuffix, values, button) {
    await page.navigating(() => page.eval(`(() => {
        const f = [...document.querySelectorAll('form')].find(f => f.action.endsWith(${JSON.stringify(actionSuffix)}));
        const v = ${JSON.stringify(values)};
        for (const [k, val] of Object.entries(v)) { const el = f.querySelector('[name="' + k + '"]'); if (el.tagName === 'SELECT') { el.value = [...el.options].find(o => o.text.includes(val) || o.value === val)?.value ?? ''; } else { el.value = val; } }
        const b = ${JSON.stringify(button ?? null)};
        if (b) { f.querySelector('button[value="' + b + '"]').click(); } else { f.requestSubmit(); }
    })()`));
}
const csrf = `document.querySelector('meta[name=csrf-token]')?.content ?? document.querySelector('input[name=_token]')?.value`;

try {
    const ids = JSON.parse(helper('seed-interventions'));
    const pCtx = await cdp.newContext();
    const principal = await cdp.newPage(pCtx);
    await principal.viewport(1366, 900);
    await login(principal, 'principal@browser.test');

    // ---- 0. Dashboard: performance by grade and section, drill-down into Academic Monitoring.
    await principal.goto(`${BASE}/principal/dashboard?perf_term=1`);
    const dash = await text(principal);
    check('dashboard: "Performance by Grade and Section" with learner vs subject-grade labels', /Performance by Grade and Section/.test(dash) && /subject grades/i.test(dash)
        && (await principal.eval(`!!document.querySelector('[data-perf-row]')`)));
    // One scroll area: the document never scrolls (absolutely positioned .sr-only
    // text inside <main> once stretched it — double scrollbar, blank below footer).
    const oneScroll = `document.scrollingElement.scrollHeight <= innerHeight + 1 && document.getElementById('main').scrollHeight > document.getElementById('main').clientHeight`;
    check('dashboard: only <main> scrolls (no document scroll)', await principal.eval(oneScroll));
    await principal.goto(`${BASE}/principal/dashboard?perf_term=1&perf_grade=11#performance-by-section`);
    check('dashboard: a direct #performance-by-section link lands on the section heading, document unscrolled',
        await principal.eval(`(() => { const t = document.getElementById('performance-by-section').getBoundingClientRect().top; return t >= 0 && t <= 40 && document.scrollingElement.scrollTop === 0; })()`)
        && await principal.eval(`document.getElementById('perfGrade').value === '11'`));
    // Refresh restores the ROOT scroll, not <main>'s: dashboard.js re-targets the fragment once.
    await principal.navigating(() => principal.send('Page.reload', {}));
    check('dashboard: Refresh of a filtered #performance-by-section page lands on the section again',
        await principal.eval(`(() => { const t = document.getElementById('performance-by-section').getBoundingClientRect().top; return t >= 0 && t <= 40; })()`));
    const openHref = await principal.eval(`[...document.querySelectorAll('[data-perf-table] tbody tr')].find(r => r.innerText.includes('Agila'))?.querySelector('a')?.href ?? ''`);
    check('dashboard: the section link keeps year, term, grade and section', /academic-monitoring\?/.test(openHref) && /term=1/.test(openHref) && /section_id=/.test(openHref) && /grade_level=11/.test(openHref), openHref);
    await principal.screenshot(join(OUT, '00-dashboard-performance.png'));
    await principal.goto(openHref);
    check('Academic Monitoring opens filtered to Agila, showing BAUTISTA\'s open concern', /BAUTISTA/.test(await text(principal))
        && (await principal.eval(`document.querySelector('[name=section_id]')?.selectedOptions?.[0]?.innerText ?? ''`)).includes('Agila'));

    // ---- 1. The Principal records a subject intervention from the concern; Bong (section adviser) is shown, read-only.
    await principal.eval(`document.querySelector('[data-record-intervention]').click()`);
    await principal.waitUntil(`!document.getElementById('concernInterventionModal').classList.contains('hidden')`);
    check('record form: the section adviser is shown as responsible, no staff picker', (await principal.eval(`document.getElementById('civResponsible').innerText`)).includes('Bong Cruz')
        && (await principal.eval(`!document.querySelector('#concernInterventionModal [name=responsible_user_id]')`)));
    await principal.navigating(() => principal.eval(`(() => { const f = document.querySelector('#concernInterventionModal form'); f.querySelector('#civType').selectedIndex = 1; f.requestSubmit(); })()`));
    check('recorded and approved; the message names Bong as responsible', /Bong Cruz \(section adviser\) is responsible/.test(await text(principal)));
    ids.second = Number(helper('intervention-for-concern', String(ids.concern)));
    check('the recorded intervention exists', ids.second > 0, String(ids.second));

    const p1 = `${BASE}/principal/interventions/${ids.first}`;
    await principal.goto(p1);
    check('principal: no schedule/reopen form, no staff picker, read-only responsible adviser card',
        await principal.eval(`!document.querySelector('form[action$="/schedule"]') && !document.querySelector('form[action$="/reopen"]') && !document.querySelector('[name=responsible_user_id]') && !!document.querySelector('[data-responsible-card]')`)
        && /Waiting for Bong Cruz to set the schedule/.test(await text(principal)));

    // ---- 2. Bong sets, extends, completes — no approval.
    const tCtx = await cdp.newContext();
    const bong = await cdp.newPage(tCtx);
    await bong.viewport(1366, 900);
    await login(bong, 'bong@browser.test');
    await bong.goto(`${BASE}/adviser/dashboard`);
    const nav = await bong.eval(`[...document.querySelectorAll('nav a.nav-link')].map(a => a.innerText.trim())`);
    check('adviser nav: exactly one Interventions item', nav.filter(t => t === 'Interventions').length === 1, JSON.stringify(nav));
    await bong.goto(`${BASE}/teacher/interventions/${ids.first}`);
    check('old adviser bookmark redirects to the adviser page of the same record', await bong.eval(`location.pathname`) === `/adviser/interventions/${ids.first}`);
    await bong.goto(`${BASE}/adviser/interventions?grading_period=1`);
    await bong.eval(`[...document.querySelectorAll('tr[data-intervention-row]')].find(r => r.dataset.studentName.startsWith('ABAD')).querySelector('[data-open-intervention]').click()`);
    await bong.waitUntil(`location.pathname === '/adviser/interventions/${ids.first}' && !!document.querySelector('form[data-form="schedule"]')`);
    check('adviser (responsible): "Set schedule" is offered', /Set schedule/.test(await text(bong)));
    await submitForm(bong, `/interventions/${ids.first}/schedule`, { title: 'Re-teaching session', starts_at: manila(60), deadline_at: manila(30) });
    await bong.waitUntil(`!!document.querySelector('[data-form-error]')`);
    const badCount = ((await text(bong)).match(/later than the start/g) ?? []).length;
    check('adviser: a reversed schedule shows its error once and saves nothing', badCount === 1 && (await lifecycle(bong)) === 'unscheduled', `count=${badCount}`);
    await submitForm(bong, `/interventions/${ids.first}/schedule`, { title: 'Re-teaching session', starts_at: manila(2), deadline_at: manila(62), completion_criteria: 'Attended the session' });
    const t1 = await text(bong);
    check('adviser: schedule set directly (no approval), Scheduled, times in Manila', /Schedule set\./.test(t1) && (await lifecycle(bong)) === 'scheduled' && /Manila time/.test(t1));
    await submitForm(bong, `/interventions/${ids.first}/extend`, { deadline_at: manila(180), reason: 'Learner asked for an afternoon slot.' });
    check('adviser: deadline extended directly with a reason', /Deadline extended/.test(await text(bong)) && (await lifecycle(bong)) === 'scheduled');
    await bong.screenshot(join(OUT, '01-adviser-scheduled-extended.png'));
    helper('shift', String(ids.first), '10'); // the start is now 8 minutes ago
    await bong.goto(`${BASE}/adviser/interventions?grading_period=1`);
    const row = await bong.eval(`[...document.querySelectorAll('tr[data-intervention-row]')].find(r => r.dataset.studentName.startsWith('ABAD'))?.innerText ?? ''`);
    check('Interventions list: decision, schedule (Ongoing) and responsible (you) on one row', /Approved/.test(row) && /Ongoing/.test(row) && /Bong Cruz \(you\)/.test(row), row.slice(0, 200));
    await bong.goto(`${BASE}/adviser/interventions/${ids.first}`);
    await submitForm(bong, `/interventions/${ids.first}/complete`, { completed_at: manila(-1), notes: 'Session held; worksheet completed.' });
    check('adviser: completion recorded', /Completion recorded/.test(await text(bong)) && (await lifecycle(bong)) === 'completed');

    await principal.goto(p1);
    const t2 = await text(principal);
    check('principal: sees completion and the history (assigned, set, extended, completed)', /Session held; worksheet completed/.test(t2)
        && (await principal.eval(`['reassigned', 'scheduled', 'extended', 'completed'].every(e => !!document.querySelector('tr[data-event="' + e + '"]'))`))
        && (await principal.eval(`document.querySelector('tr[data-event="extended"]').innerText`)).includes('Bong Cruz'));
    const refused = await principal.eval(`fetch(location.pathname + '/schedule', { method: 'POST', headers: { 'X-CSRF-TOKEN': ${csrf}, 'Accept': 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded' }, body: 'title=X&starts_at=2030-01-01T10:00&deadline_at=2030-01-01T12:00&schedule_version=0' }).then(r => r.status)`);
    check('principal: the retired schedule endpoint answers 410 (refused)', refused === 410, String(refused));

    // ---- 3. The recorded GS intervention: Bong schedules; it expires; Bong reopens with a reason.
    await bong.goto(`${BASE}/adviser/interventions/${ids.second}`);
    await submitForm(bong, `/interventions/${ids.second}/schedule`, { title: 'GS catch-up', starts_at: manila(2), deadline_at: manila(30) });
    check('adviser: second schedule set', (await lifecycle(bong)) === 'scheduled');
    helper('shift', String(ids.second), '120'); // the deadline is now about 90 minutes ago
    const p2 = `${BASE}/principal/interventions/${ids.second}`;
    await principal.goto(p2);
    check('overdue reads Expired before the expiry job; the page wrote nothing', (await lifecycle(principal)) === 'expired' && !(await principal.eval(`!!document.querySelector('tr[data-event="expired"]')`)));
    const ran = helper('expire');
    await principal.goto(p2);
    check('the expiry job persisted it and the history shows the system expiry', /Expired 1/.test(ran) && (await principal.eval(`document.querySelector('tr[data-event="expired"]')?.innerText ?? ''`)).includes('System'), ran);
    check('the linked academic concern is still open', /still open/.test(await text(principal)));

    // Darien: advises another section, teaches GS in Agila — no access to this record.
    const dCtx = await cdp.newContext();
    const darien = await cdp.newPage(dCtx);
    await darien.viewport(1366, 900);
    await login(darien, 'darien@browser.test');
    const dStatus = await darien.eval(`fetch('/adviser/interventions/${ids.second}').then(r => r.status)`);
    const dPost = await darien.eval(`fetch('/adviser/interventions/${ids.second}/reopen', { method: 'POST', headers: { 'X-CSRF-TOKEN': ${csrf},
        'Content-Type': 'application/x-www-form-urlencoded' }, body: 'starts_at=2030-01-01T10:00&deadline_at=2030-01-02T10:00&reason=Not%20mine&schedule_version=3' }).then(r => r.status)`);
    check('another adviser (teaches the class, not its adviser): view 403 and direct reopen POST 403', dStatus === 403 && dPost === 403, `${dStatus}/${dPost}`);

    await bong.viewport(390, 844);
    await bong.goto(`${BASE}/adviser/interventions/${ids.second}`);
    const noScroll = await bong.eval(`document.documentElement.scrollWidth <= window.innerWidth + 1`);
    const labelled = await bong.eval(`[...document.querySelectorAll('main input:not([type=hidden]), main textarea, main select')].every(el => el.labels?.length || el.getAttribute('aria-label'))`);
    check('390 px: no sideways page scroll; every field has a label', noScroll && labelled);
    check('expired: normal completion not offered; "Reopen and set schedule" is', !(await bong.eval(`!!document.querySelector('form[action$="/complete"]')`)) && /Reopen and set schedule/.test(await text(bong)));
    // The browser blocks an empty required reason; drop that to prove the SERVER refuses it too.
    await bong.eval(`(() => { const r = document.getElementById('reReason'); r.removeAttribute('required'); r.removeAttribute('minlength'); })()`);
    await submitForm(bong, `/interventions/${ids.second}/reopen`, { starts_at: manila(2), deadline_at: manila(2 * 24 * 60), reason: '' });
    check('reopen without a reason is refused by the server, error shown once', (await lifecycle(bong)) === 'expired' && ((await text(bong)).match(/reason field is required/g) ?? []).length === 1);
    await submitForm(bong, `/interventions/${ids.second}/reopen`, { starts_at: manila(2), deadline_at: manila(2 * 24 * 60), reason: 'Learner was absent; needs one more session.' });
    check('adviser: reopened directly with a new schedule (no approval)', /reopened with the new schedule/.test(await text(bong)) && (await lifecycle(bong)) === 'scheduled');
    await bong.screenshot(join(OUT, '02-adviser-reopened-390.png'));
    await bong.viewport(1366, 900);

    await principal.goto(p2);
    const t4 = await text(principal);
    check('principal: history shows the expiry and the adviser reopening with the reason', await principal.eval(`!!document.querySelector('tr[data-event="expired"]') && (document.querySelector('tr[data-event="reopened"]')?.innerText ?? '').includes('Bong')`)
        && /needs one more session/.test(t4));
    check('the academic concern is still open after reopening', /still open/.test(t4));
    await principal.screenshot(join(OUT, '03-principal-history.png'));

    // ---- 4. Subject teacher: no intervention entry point or access; Class Record still works.
    const cCtx = await cdp.newContext();
    const carla = await cdp.newPage(cCtx);
    await carla.viewport(390, 844);
    await login(carla, 'carla@browser.test');
    const cNav = await carla.eval(`[...document.querySelectorAll('nav a.nav-link')].map(a => a.innerText.trim())`);
    check('subject teacher nav: no Interventions item, Class Record kept', !cNav.includes('Interventions') && cNav.includes('Class Record'), JSON.stringify(cNav));
    const cStatuses = await carla.eval(`Promise.all(['/teacher/interventions', '/teacher/interventions/${ids.first}', '/adviser/interventions', '/adviser/interventions/${ids.first}'].map(u => fetch(u, { redirect: 'manual' }).then(r => r.status)))`);
    check('subject teacher: every intervention URL is refused (403)', cStatuses.every(s => s === 403), JSON.stringify(cStatuses));
    await carla.goto(`${BASE}/teacher/class-record?section_id=${ids.curie}&subject_id=${ids.cesc}&period=1`);
    check('subject teacher: the assigned Class Record still opens and is editable', await carla.eval(`document.getElementById('classRecord')?.dataset.canEdit === '1'`));
    await carla.screenshot(join(OUT, '04-subject-teacher-class-record-390.png'));

    for (const [page, url] of [[bong, `${BASE}/adviser/interventions?grading_period=1`], [principal, `${BASE}/principal/interventions?grading_period=1`], [principal, `${BASE}/principal/dashboard?perf_term=1`]]) {
        await page.viewport(390, 844);
        await page.goto(url);
        check(`390 px: ${new URL(url).pathname} has no sideways page scroll`, await page.eval(`document.documentElement.scrollWidth <= window.innerWidth + 1`));
    }
    await principal.screenshot(join(OUT, '05-principal-dashboard-390.png'));

    const errors = [principal, bong, darien, carla].flatMap((p) => p.console);
    check('no JavaScript exceptions', errors.length === 0, errors.slice(0, 3).join(' | '));
} catch (e) {
    check('run completed without a driver error', false, e.stack);
} finally {
    try { await cdp.send('Browser.close'); } catch (e) { /* ignore */ }
    proc.kill();
}
const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} intervention-flow checks passed. Screenshots: ${OUT}`);
process.exit(failed.length ? 1 : 0);
