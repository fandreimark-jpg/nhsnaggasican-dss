/**
 * Minimal Chrome DevTools Protocol client for the browser checks
 * (tests/Browser/run.mjs). No dependencies: Node's built-in WebSocket and
 * fetch drive a locally installed Chrome in headless mode.
 */
import { spawn } from 'node:child_process';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

export async function launchChrome({ chromePath, port = 9333 }) {
    const profile = mkdtempSync(join(tmpdir(), 'dss-cdp-'));
    const proc = spawn(chromePath, [
        '--headless=new', `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`,
        '--no-first-run', '--no-default-browser-check', '--disable-gpu', '--disable-extensions', 'about:blank',
    ], { stdio: 'ignore' });
    let version;
    for (let i = 0; i < 100; i += 1) {
        try {
            version = await (await fetch(`http://127.0.0.1:${port}/json/version`)).json();
            break;
        } catch (e) { await sleep(150); }
    }
    if (!version) throw new Error('Chrome did not start');
    const cdp = new Cdp(version.webSocketDebuggerUrl);
    await cdp.open();
    return { cdp, proc, profile };
}

export class Cdp {
    constructor(url) { this.url = url; this.id = 0; this.pending = new Map(); this.listeners = []; }

    open() {
        return new Promise((resolve, reject) => {
            this.ws = new WebSocket(this.url);
            this.ws.onopen = () => resolve();
            this.ws.onerror = (e) => reject(e);
            this.ws.onmessage = (msg) => {
                const data = JSON.parse(msg.data);
                if (data.id && this.pending.has(data.id)) {
                    const { resolve: ok, reject: fail } = this.pending.get(data.id);
                    this.pending.delete(data.id);
                    data.error ? fail(new Error(`${data.error.message} ${data.error.data ?? ''}`)) : ok(data.result);
                } else if (data.method) {
                    this.listeners.forEach((l) => l(data));
                }
            };
        });
    }

    send(method, params = {}, sessionId = undefined) {
        const id = ++this.id;
        this.ws.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }));
        return new Promise((resolve, reject) => this.pending.set(id, { resolve, reject }));
    }

    on(fn) { this.listeners.push(fn); return () => { this.listeners = this.listeners.filter((l) => l !== fn); }; }

    waitFor(method, sessionId, predicate = () => true, timeout = 15000) {
        return new Promise((resolve, reject) => {
            const timer = setTimeout(() => { off(); reject(new Error(`timeout waiting for ${method}`)); }, timeout);
            const off = this.on((e) => {
                if (e.method === method && (!sessionId || e.sessionId === sessionId) && predicate(e.params)) {
                    clearTimeout(timer); off(); resolve(e.params);
                }
            });
        });
    }

    async newContext() {
        return (await this.send('Target.createBrowserContext')).browserContextId;
    }

    async newPage(browserContextId) {
        const { targetId } = await this.send('Target.createTarget', { url: 'about:blank', ...(browserContextId ? { browserContextId } : {}) });
        const { sessionId } = await this.send('Target.attachToTarget', { targetId, flatten: true });
        const page = new Page(this, sessionId, targetId);
        await page.init();
        return page;
    }
}

export class Page {
    constructor(cdp, sessionId, targetId) { this.cdp = cdp; this.sessionId = sessionId; this.targetId = targetId; this.dialogs = []; this.console = []; }

    send(method, params) { return this.cdp.send(method, params, this.sessionId); }

    async init() {
        await this.send('Page.enable');
        await this.send('Runtime.enable');
        await this.send('Network.enable');
        this.cdp.on((e) => {
            if (e.sessionId !== this.sessionId) return;
            if (e.method === 'Page.javascriptDialogOpening') {
                this.dialogs.push(e.params);
                this.send('Page.handleJavaScriptDialog', { accept: true }).catch(() => {});
            }
            if (e.method === 'Runtime.exceptionThrown') this.console.push('EXCEPTION ' + (e.params.exceptionDetails?.exception?.description ?? e.params.exceptionDetails?.text));
            if (e.method === 'Runtime.consoleAPICalled' && e.params.type === 'error') this.console.push('console.error ' + e.params.args.map((a) => a.value ?? a.description).join(' '));
        });
    }

    async goto(url) {
        const loaded = this.cdp.waitFor('Page.loadEventFired', this.sessionId);
        await this.send('Page.navigate', { url });
        await loaded;
    }

    /** Run an action that navigates, and wait for the new page's load event. */
    async navigating(action) {
        const loaded = this.cdp.waitFor('Page.loadEventFired', this.sessionId);
        await action();
        await loaded;
    }

    async eval(expression) {
        const r = await this.send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true, userGesture: true });
        if (r.exceptionDetails) throw new Error('eval failed: ' + (r.exceptionDetails.exception?.description ?? r.exceptionDetails.text) + '\n' + expression.slice(0, 200));
        return r.result.value;
    }

    async waitUntil(expression, timeout = 10000) {
        const until = Date.now() + timeout;
        while (Date.now() < until) {
            if (await this.eval(`!!(${expression})`)) return true;
            await sleep(100);
        }
        throw new Error('condition not met: ' + expression);
    }

    async viewport(width, height = 900, deviceScaleFactor = 1) {
        await this.send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor, mobile: width < 500 });
    }

    /** A real mouse click (press + release) at the centre of the element. */
    async click(selector) {
        const box = await this.eval(`(() => { const r = document.querySelector(${JSON.stringify(selector)}).getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2 }; })()`);
        for (const type of ['mousePressed', 'mouseReleased']) {
            await this.send('Input.dispatchMouseEvent', { type, x: box.x, y: box.y, button: 'left', clickCount: 1 });
        }
    }

    async screenshot(file) {
        const { data } = await this.send('Page.captureScreenshot', { format: 'png' });
        writeFileSync(file, Buffer.from(data, 'base64'));
    }

    async key(key, { code = key, keyCode = 0, modifiers = 0 } = {}) {
        await this.send('Input.dispatchKeyEvent', { type: 'keyDown', key, code, windowsVirtualKeyCode: keyCode, modifiers });
        await this.send('Input.dispatchKeyEvent', { type: 'keyUp', key, code, windowsVirtualKeyCode: keyCode, modifiers });
    }

    async type(text) { await this.send('Input.insertText', { text }); }

    async offline(on) {
        await this.send('Network.emulateNetworkConditions', { offline: on, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
    }

    async close() { await this.cdp.send('Target.closeTarget', { targetId: this.targetId }); }
}

export { sleep };
