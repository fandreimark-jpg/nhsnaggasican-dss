/**
 * Bounded multi-user workload + DB-level concurrency, against the ISOLATED
 * MySQL database only (BROWSER_ISOLATION=mysql, naggasican_dss_loadtest; the
 * router refuses any other database). Start N single-threaded PHP servers:
 *
 *   BROWSER_ISOLATION=mysql php tests/Browser/prepare.php
 *   (x4) BROWSER_ISOLATION=mysql php -S 127.0.0.1:878N -t public tests/Browser/router.php
 *   node tests/Browser/loadtest.mjs <virtualUsers> <seconds> [ports=8781,8782,8783,8784]
 *
 * Virtual users are real HTTP sessions (login with CSRF, cookies kept):
 *   - teachers (Bong): open the 45-learner Class Record and save one score in
 *     their OWN learner row (correct base value), repeatedly;
 *   - advisers/principal: read Verify Grades, Advisory Overview, Academic
 *     Monitoring and the Principal dashboard.
 * Reports latency percentiles, throughput and every non-2xx response.
 */
const [, , vuArg = '8', secArg = '30', portsArg = '8781,8782,8783,8784'] = process.argv;
const VUS = Number(vuArg), SECONDS = Number(secArg);
const PORTS = portsArg.split(',').map(Number);
const PASSWORD = 'Browser-Test-Only-1';

class Session {
    constructor(base) { this.base = base; this.cookies = new Map(); }
    cookieHeader() { return [...this.cookies].map(([k, v]) => `${k}=${v}`).join('; '); }
    keep(res) {
        for (const c of res.headers.getSetCookie?.() ?? []) {
            const [pair] = c.split(';'); const i = pair.indexOf('=');
            this.cookies.set(pair.slice(0, i), pair.slice(i + 1));
        }
    }
    async req(method, path, { body, headers = {}, json } = {}) {
        const t = performance.now();
        const res = await fetch(this.base + path, { method, redirect: 'manual', headers: { Cookie: this.cookieHeader(), ...headers,
            ...(json ? { 'Content-Type': 'application/json', Accept: 'application/json' } : {}) }, body: json ? JSON.stringify(json) : body });
        this.keep(res);
        const text = await res.text();
        return { status: res.status, ms: performance.now() - t, text, location: res.headers.get('location') };
    }
    csrf(html) { return /name="csrf-token" content="([^"]+)"/.exec(html)?.[1] ?? /name="_token" value="([^"]+)"/.exec(html)?.[1]; }
    async login(email) {
        const page = await this.req('GET', '/login');
        const token = this.csrf(page.text);
        const r = await this.req('POST', '/login', { body: new URLSearchParams({ _token: token, email, password: PASSWORD }), headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
        if (r.status !== 302 || /login/.test(r.location ?? '')) throw new Error(`login failed for ${email}: ${r.status} ${r.location}`);
    }
}

const samples = [];
const errors = [];
const record = (label, r) => {
    samples.push({ label, ms: r.ms, status: r.status });
    if (r.status >= 500 || r.status === 419 || r.status === 404) errors.push({ label, status: r.status, body: r.text.slice(0, 160) });
};
const pct = (arr, p) => { const s = [...arr].sort((a, b) => a - b); return s.length ? s[Math.min(s.length - 1, Math.floor((p / 100) * s.length))] : 0; };

async function teacher(i, deadline) {
    const s = new Session(`http://127.0.0.1:${PORTS[i % PORTS.length]}`);
    await s.login('bong@browser.test');
    let page = await s.req('GET', '/teacher/class-record?section_id=3&period=1'); record('GET class-record (45 learners)', page);
    const token = s.csrf(page.text);
    const ids = /data-section-id="(\d+)" data-subject-id="(\d+)"/.exec(page.text);
    // This virtual user's own learner row: the i-th row, first assessment column.
    const rows = [...page.text.matchAll(/<input[^>]*class="cr-cell[^"]*"[^>]*>/g)].map(m => m[0]);
    const attr = (tag, name) => new RegExp(`data-${name}="([^"]*)"`).exec(tag)?.[1];
    const learners = [...new Set(rows.map(t => attr(t, 's')))];
    const sid = learners[i % learners.length];
    const cell = rows.find(t => attr(t, 's') === sid);
    const aid = attr(cell, 'a'), max = Number(attr(cell, 'max'));
    let base = attr(cell, 'base-score') || null;
    let saved = 0, conflicts = 0;
    while (performance.now() < deadline) {
        const score = String(1 + Math.floor(Math.random() * max));
        const r = await s.req('POST', '/teacher/class-record/save', { headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
            json: { section_id: +ids[1], subject_id: +ids[2], period: 1, cells: [{ assessment_id: +aid, student_id: +sid, score, missing: false, base_score: base, base_missing: attr(cell, 'base-missing') === '1' && base === null && saved === 0 }] } });
        record('POST class-record save', r);
        if (r.status === 200) { base = score; saved++; } else if (r.status === 409) { conflicts++; base = JSON.parse(r.text).conflicts?.[`${aid}:${sid}`]?.current_score || null; }
        page = await s.req('GET', '/teacher/class-record?section_id=3&period=1'); record('GET class-record (45 learners)', page);
    }
    return { saved, conflicts };
}

async function reader(i, email, paths, deadline) {
    const s = new Session(`http://127.0.0.1:${PORTS[i % PORTS.length]}`);
    await s.login(email);
    while (performance.now() < deadline) {
        for (const p of paths) record(`GET ${p.split('?')[0]}`, await s.req('GET', p));
    }
}

const deadline = performance.now() + SECONDS * 1000;
const started = performance.now();
const jobs = [];
for (let i = 0; i < VUS; i++) {
    if (i % 2 === 0) jobs.push(teacher(i / 2, deadline));
    else if (i % 4 === 1) jobs.push(reader(i, 'bong@browser.test', ['/adviser/verify-grades?period=1', '/adviser/advisory-overview?period=1'], deadline)); // Bong advises Agila (prepare.php); no Narra adviser account exists
    else jobs.push(reader(i, 'principal@browser.test', ['/principal/academic-monitoring', '/principal/dashboard'], deadline));
}
const out = await Promise.allSettled(jobs);
const elapsed = (performance.now() - started) / 1000;
const failures = out.filter(o => o.status === 'rejected').map(o => String(o.reason).slice(0, 200));
const teacherTotals = out.filter(o => o.status === 'fulfilled' && o.value).map(o => o.value);

const byLabel = {};
for (const x of samples) (byLabel[x.label] ??= []).push(x.ms);
console.log(`virtual users ${VUS} (servers ${PORTS.length}), ${SECONDS}s, ${samples.length} requests, ${(samples.length / elapsed).toFixed(1)} req/s overall`);
for (const [label, ms] of Object.entries(byLabel)) {
    console.log(`  ${label.padEnd(34)} n=${String(ms.length).padStart(4)}  p50=${pct(ms, 50).toFixed(0)}ms  p95=${pct(ms, 95).toFixed(0)}ms  max=${Math.max(...ms).toFixed(0)}ms`);
}
const statuses = samples.reduce((a, x) => ((a[x.status] = (a[x.status] || 0) + 1), a), {});
console.log(`  statuses ${JSON.stringify(statuses)}; saves ok ${teacherTotals.reduce((a, t) => a + t.saved, 0)}, conflicts ${teacherTotals.reduce((a, t) => a + t.conflicts, 0)}`);
if (failures.length) console.log('  VU failures:', failures);
if (errors.length) console.log('  errors (first 5):', JSON.stringify(errors.slice(0, 5)));
console.log(JSON.stringify({ vus: VUS, seconds: SECONDS, requests: samples.length, statuses, errors: errors.length, vuFailures: failures.length,
    saves: teacherTotals.reduce((a, t) => a + t.saved, 0), conflicts: teacherTotals.reduce((a, t) => a + t.conflicts, 0),
    p95: Object.fromEntries(Object.entries(byLabel).map(([k, v]) => [k, Math.round(pct(v, 95))])) }));
process.exit(errors.length || failures.length ? 1 : 0);
