/**
 * Screenshot helper for visual review (isolated server only — see
 * tests/Browser/isolation.php). Usage:
 *   node tests/Browser/shots.mjs <outDir> <email> <path> [width=1366] [height=900] [scale=1]
 * Saves <outDir>/<name>-top.png, -mid.png and -end.png (the <main> scroller
 * at its top, middle and end) and prints a few layout measurements.
 */
import { launchChrome, sleep } from './cdp.mjs';
import { mkdirSync, existsSync } from 'node:fs';
import { join, resolve } from 'node:path';

const [outDir, email, path, width = '1366', height = '900', scale = '1'] = process.argv.slice(2);
const BASE = 'http://127.0.0.1:8765';
const OUT = resolve(outDir);
mkdirSync(OUT, { recursive: true });
const chromePath = ['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'].find(existsSync);
const { cdp, proc } = await launchChrome({ chromePath, port: 9334 });
try {
    const page = await cdp.newPage();
    await page.send('Emulation.setDeviceMetricsOverride', { width: +width, height: +height, deviceScaleFactor: +scale, mobile: +width < 500 });
    await page.goto(`${BASE}/login`);
    await page.navigating(() => page.eval(`(() => { const f = document.querySelector('form[action$="/login"]'); f.email.value = ${JSON.stringify(email)}; f.password.value = 'Browser-Test-Only-1'; f.requestSubmit(); })()`));
    await page.goto(BASE + path);
    const name = `${path.replace(/[^a-z0-9]+/gi, '_').slice(0, 60)}-${width}`;
    for (const [label, frac] of [['top', 0], ['mid', 0.5], ['end', 1]]) {
        await page.eval(`(() => { const m = document.getElementById('main'); m.scrollTop = (m.scrollHeight - m.clientHeight) * ${frac}; })()`);
        await sleep(250);
        await page.screenshot(join(OUT, `${name}-${label}.png`));
    }
    console.log(JSON.stringify(await page.eval(`(() => {
        const m = document.getElementById('main'), s = document.querySelector('.cr-scroll');
        return { mainScroll: m.scrollHeight - m.clientHeight, gridInnerScroll: s ? s.scrollHeight - s.clientHeight : null,
            pageOverflowX: document.documentElement.scrollWidth - innerWidth, errors: 0 };
    })()`)));
} finally {
    try { await cdp.send('Browser.close'); } catch (e) { /* ignore */ }
    proc.kill();
}
