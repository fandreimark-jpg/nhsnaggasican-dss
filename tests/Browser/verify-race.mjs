/**
 * Concurrent verification race against the ISOLATED MySQL load-test servers
 * (see loadtest.mjs; needs the narra@browser.test adviser added to
 * naggasican_dss_loadtest for section Narra). Two sessions of the section
 * adviser fire, at the same moment: two bulk "Verify all eligible" requests
 * (each with the set its own preview showed) and individual verifies of the
 * same learners. Afterwards the database must hold one grade per learner and
 * one verification log entry per learner, and every request must have been
 * either applied, refused as changed (409), or reported unchanged.
 */
const PORTS = [8781, 8782, 8783, 8784];
const PASSWORD = 'Browser-Test-Only-1';

class Session {
    constructor(base) { this.base = base; this.cookies = new Map(); }
    keep(res) { for (const c of res.headers.getSetCookie?.() ?? []) { const [p] = c.split(';'); const i = p.indexOf('='); this.cookies.set(p.slice(0, i), p.slice(i + 1)); } }
    async req(method, path, opts = {}) {
        const res = await fetch(this.base + path, { method, redirect: 'manual', ...opts, headers: { Cookie: [...this.cookies].map(([k, v]) => `${k}=${v}`).join('; '), ...(opts.headers || {}) } });
        this.keep(res);
        return { status: res.status, text: await res.text() };
    }
    token(html) { return /name="csrf-token" content="([^"]+)"/.exec(html)?.[1]; }
    async login(email) {
        const p = await this.req('GET', '/login');
        await this.req('POST', '/login', { body: new URLSearchParams({ _token: this.token(p.text), email, password: PASSWORD }), headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
        const page = await this.req('GET', '/adviser/verify-grades?period=1');
        this.csrf = this.token(page.text);
        // The fixture's evidence is in General Mathematics (the large class).
        this.subject = /<option value="(\d+)"[^>]*>\s*General Mathematics/.exec(page.text)?.[1];
        return page;
    }
    json(path, data) {
        return this.req('POST', path, { body: JSON.stringify(data), headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf } });
    }
}

const a = new Session(`http://127.0.0.1:${PORTS[0]}`), b = new Session(`http://127.0.0.1:${PORTS[1]}`),
      c = new Session(`http://127.0.0.1:${PORTS[2]}`), d = new Session(`http://127.0.0.1:${PORTS[3]}`);
await Promise.all([a, b, c, d].map(s => s.login('narra@browser.test')));
const subject = a.subject;
const preview = JSON.parse((await a.req('GET', `/adviser/grades/verify-all/preview?subject_id=${subject}&grading_period=1`, { headers: { Accept: 'application/json' } })).text);
const ids = preview.eligible_student_ids;
console.log(`eligible before the race: ${ids.length}`);
if (ids.length < 2) { console.log('not enough eligible learners in the fixture'); process.exit(1); }

const results = await Promise.all([
    a.json('/adviser/grades/verify-all', { subject_id: +subject, grading_period: 1, expected_student_ids: ids }),
    b.json('/adviser/grades/verify-all', { subject_id: +subject, grading_period: 1, expected_student_ids: ids }),
    c.json('/adviser/grades/verify', { subject_id: +subject, grading_period: 1, student_id: ids[0] }),
    d.json('/adviser/grades/verify', { subject_id: +subject, grading_period: 1, student_id: ids[1] }),
]);
results.forEach((r, i) => console.log(`request ${i + 1}: HTTP ${r.status} ${r.text.slice(0, 140)}`));
const bad = results.filter(r => ![200, 409, 422].includes(r.status));
console.log(bad.length ? `UNEXPECTED STATUS: ${bad.map(r => r.status)}` : 'every request was applied, refused as changed, or reported unchanged');
console.log(JSON.stringify({ subject, ids }));
process.exit(bad.length ? 1 : 0);
