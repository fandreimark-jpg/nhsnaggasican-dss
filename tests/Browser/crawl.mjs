/**
 * Link crawl for the ISOLATED browser server only (see tests/Browser/
 * isolation.php and router.php): signs in as each role, follows every
 * same-origin GET link reachable from that role's pages (breadth-first,
 * bounded), and reports HTTP >= 500, 404s on in-app links, error text in the
 * page body (exception/SQL traces) and JavaScript console errors.
 *
 *   php tests/Browser/prepare.php
 *   php -S 127.0.0.1:8765 -t public tests/Browser/router.php
 *   node tests/Browser/crawl.mjs [outFile]
 */
import { launchChrome, sleep } from './cdp.mjs';
import { existsSync, writeFileSync } from 'node:fs';

const BASE = 'http://127.0.0.1:8765';
const PASSWORD = 'Browser-Test-Only-1';
const MAX_PAGES = 90;
const accounts = [['admin', 'admin@browser.test'], ['adviser', 'bong@browser.test'], ['subject_teacher', 'carla@browser.test'], ['principal', 'principal@browser.test']];
const chromePath = ['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'].find(existsSync);
const { cdp, proc } = await launchChrome({ chromePath, port: 9335 });
const report = {};
let failures = 0;

try {
    for (const [role, email] of accounts) {
        const ctx = await cdp.newContext();
        const page = await cdp.newPage(ctx);
        await page.viewport(1366, 900);
        await page.goto(`${BASE}/login`);
        await page.navigating(() => page.eval(`(() => { const f = document.querySelector('form[action$="/login"]'); f.email.value = ${JSON.stringify(email)}; f.password.value = ${JSON.stringify(PASSWORD)}; f.requestSubmit(); })()`));
        const start = await page.eval('location.pathname + location.search');
        if (start.includes('/login')) throw new Error(`${role} could not sign in — not the isolated server?`);

        const seen = new Set();
        const queue = [start];
        const results = [];
        while (queue.length && seen.size < MAX_PAGES) {
            const path = queue.shift();
            const key = path.replace(/[?&]_=\d+/, '');
            if (seen.has(key)) continue;
            seen.add(key);
            const r = await page.eval(`(async () => {
                const res = await fetch(${JSON.stringify(BASE + path)}, { credentials: 'same-origin', redirect: 'follow', headers: { Accept: 'text/html' } });
                const type = res.headers.get('content-type') || '';
                const text = type.includes('html') ? await res.text() : '';
                const bad = /(Whoops|SQLSTATE|Stack trace|ErrorException|Undefined (variable|array key|index)|Call to (a member|undefined))/i.exec(text)?.[0] || null;
                const links = type.includes('html') ? [...new DOMParser().parseFromString(text, 'text/html').querySelectorAll('a[href]')]
                    .map(a => a.getAttribute('href')).filter(h => h && !h.startsWith('#') && !h.startsWith('mailto:') && !h.startsWith('javascript:'))
                    .map(h => new URL(h, ${JSON.stringify(BASE + path)})).filter(u => u.origin === ${JSON.stringify(BASE)})
                    .map(u => u.pathname + u.search) : [];
                return { status: res.status, finalPath: new URL(res.url).pathname, type: type.split(';')[0], bad, links };
            })()`);
            results.push({ path, status: r.status, type: r.type, bad: r.bad, redirectedTo: r.finalPath !== path.split('?')[0] ? r.finalPath : null });
            for (const l of r.links) {
                if (/\/logout|\/export|\/copy|\/template/.test(l)) continue;      // not pages
                if (!seen.has(l) && !queue.includes(l)) queue.push(l);
            }
        }
        // Visit every HTML page for real to collect console errors.
        const consoleErrors = [];
        for (const res of results.filter(x => x.type === 'text/html' && x.status === 200)) {
            page.console.length = 0;
            await page.goto(BASE + res.path);
            await sleep(150);
            if (page.console.length) consoleErrors.push({ path: res.path, errors: page.console.slice(0, 3) });
        }
        const problems = results.filter(x => x.status >= 500 || x.status === 404 || x.bad);
        failures += problems.length + consoleErrors.length;
        report[role] = { pages: results.length, byStatus: results.reduce((a, x) => ((a[x.status] = (a[x.status] || 0) + 1), a), {}), problems, consoleErrors };
        console.log(`${role}: ${results.length} URLs, statuses ${JSON.stringify(report[role].byStatus)}, problems ${problems.length}, console-error pages ${consoleErrors.length}`);
        problems.forEach(p => console.log('   PROBLEM', JSON.stringify(p)));
        consoleErrors.forEach(p => console.log('   CONSOLE', JSON.stringify(p)));
    }
} finally {
    try { await cdp.send('Browser.close'); } catch (e) { /* ignore */ }
    proc.kill();
}
if (process.argv[2]) writeFileSync(process.argv[2], JSON.stringify(report, null, 2));
console.log(failures ? `${failures} problem(s) found` : 'No server errors, broken in-app links, error text or console errors found.');
process.exit(failures ? 1 : 0);
