const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(`${__dirname}/../../public/ui.js`, 'utf8');

function harness({marker = true, checkout = false, statusUrl, respond} = {}) {
    let now = 0;
    let nextId = 0;
    const timers = new Map();
    const listeners = new Map();
    const requests = [];
    const navigations = [];
    const submissions = [];
    const form = {};
    const document = {
        hidden: false,
        querySelectorAll(selector) {
            if (selector === '[data-payment-status-poll]' && marker) return [{dataset: {
                statusUrl: statusUrl || 'https://cabinet.test/cabinet/payments/7/status',
                showUrl: 'https://cabinet.test/cabinet/payments/7'
            }}];
            if (selector === 'form[data-webpay-auto-submit]' && checkout) return [form];
            return [];
        }
    };
    const window = {
        location: {href: 'https://cabinet.test/cabinet/payments/7/return', origin: 'https://cabinet.test', assign: url => navigations.push(url)},
        addEventListener: (name, callback) => listeners.set(name, callback),
        removeEventListener: name => listeners.delete(name)
    };
    const json = status => ({ok: true, headers: {get: () => 'application/json'}, json: async () => ({status})});
    const context = {
        document, window, URL, AbortController, Date: {now: () => now},
        setTimeout(callback, delay) { const id = ++nextId; timers.set(id, {at: now + delay, callback}); return id; },
        clearTimeout: id => timers.delete(id),
        HTMLFormElement: {prototype: {submit() { submissions.push(this); }}},
        fetch(url, options) {
            requests.push({url, options, at: now});
            return respond ? respond({url, options, json}) : Promise.resolve(json('pending'));
        }
    };
    vm.runInNewContext(source, context);
    const flush = async () => { for (let i = 0; i < 10; i++) await Promise.resolve(); };
    return {
        document, requests, navigations, submissions, form, timers, flush,
        pagehide() { listeners.get('pagehide')?.(); },
        jump(time) { now = time; },
        async advance(ms) {
            const end = now + ms;
            while (true) {
                const entry = [...timers].sort((a, b) => a[1].at - b[1].at)[0];
                if (!entry || entry[1].at > end) break;
                now = Math.max(now, entry[1].at);
                timers.delete(entry[0]);
                entry[1].callback();
                await flush();
            }
            now = end;
            await flush();
        }
    };
}

test('pending uses GET every 5 seconds and stops at 120 seconds', async () => {
    const h = harness();
    await h.advance(4999);
    assert.equal(h.requests.length, 0);
    await h.advance(115001);
    assert.deepEqual(h.requests.map(r => r.at), Array.from({length: 23}, (_, i) => (i + 1) * 5000));
    for (const {url, options} of h.requests) {
        assert.equal(url, 'https://cabinet.test/cabinet/payments/7/status');
        assert.equal(options.method, 'GET');
        assert.equal(options.credentials, 'same-origin');
        assert.equal(options.cache, 'no-store');
        assert.equal(options.redirect, 'error');
        assert.equal(options.headers.Accept, 'application/json');
    }
    await h.advance(120000);
    assert.equal(h.requests.length, 23);
    assert.equal(h.navigations.length, 0);
    assert.equal(h.timers.size, 0);
});

for (const status of ['succeeded', 'failed', 'cancelled', 'refunded']) {
    test(`${status} navigates once to canonical show, never return/cancel`, async () => {
        const h = harness({respond: ({json}) => Promise.resolve(json(status))});
        await h.advance(180000);
        assert.equal(h.requests.length, 1);
        assert.deepEqual(h.navigations, ['https://cabinet.test/cabinet/payments/7']);
        assert.equal(h.timers.size, 0);
    });
}

test('one in-flight request, timeout abort and no automatic retry', async () => {
    const h = harness({respond: ({options}) => new Promise((resolve, reject) => {
        options.signal.addEventListener('abort', () => reject(new Error('timeout')));
    })});
    await h.advance(9999);
    assert.equal(h.requests.length, 1);
    assert.equal(h.requests[0].options.signal.aborted, false);
    await h.advance(1);
    assert.equal(h.requests[0].options.signal.aborted, true);
    await h.advance(120000);
    assert.equal(h.requests.length, 1);
    assert.equal(h.navigations.length, 0);
});

for (const failure of ['offline', 'html', 'bad-json', 'unknown', 401, 403, 404, 500]) {
    test(`${failure} leaves manual fallback untouched and stops`, async () => {
        const h = harness({respond: ({json}) => {
            if (failure === 'offline') return Promise.reject(new Error('offline'));
            if (failure === 'html') return Promise.resolve({ok: true, headers: {get: () => 'text/html'}});
            if (failure === 'bad-json') return Promise.resolve({...json('pending'), json: async () => { throw new Error('invalid'); }});
            if (failure === 'unknown') return Promise.resolve(json('created'));
            return Promise.resolve({ok: false, status: failure});
        }});
        await h.advance(180000);
        assert.equal(h.requests.length, 1);
        assert.equal(h.navigations.length, 0);
        assert.equal(h.submissions.length, 0);
        assert.equal(h.timers.size, 0);
    });
}

test('hidden tab skips requests and retains original wall-clock budget', async () => {
    const h = harness();
    h.document.hidden = true;
    await h.advance(60000);
    assert.equal(h.requests.length, 0);
    h.document.hidden = false;
    await h.advance(60000);
    assert.equal(h.requests.length, 11);
    assert.equal(h.requests[0].at, 65000);
    assert.equal(h.timers.size, 0);
});

test('delayed timers and late responses cannot navigate after deadline', async () => {
    let resolve;
    const h = harness({respond: ({json}) => new Promise(done => { resolve = () => done(json('succeeded')); })});
    await h.advance(5000);
    h.jump(120001);
    resolve();
    await h.flush();
    assert.equal(h.navigations.length, 0);
    await h.advance(10000);
    assert.equal(h.requests.length, 1);
    assert.equal(h.timers.size, 0);
    const hidden = harness();
    hidden.jump(120001);
    await hidden.advance(0);
    assert.equal(hidden.requests.length, 0);
});

test('pagehide aborts and ignores even a later terminal response', async () => {
    let resolve;
    const h = harness({respond: ({json}) => new Promise(done => { resolve = () => done(json('succeeded')); })});
    await h.advance(5000);
    h.pagehide();
    assert.equal(h.requests[0].options.signal.aborted, true);
    assert.equal(h.timers.size, 0);
    resolve();
    await h.advance(180000);
    assert.equal(h.requests.length, 1);
    assert.equal(h.navigations.length, 0);
    assert.equal(h.timers.size, 0);
});

test('no marker or cross-origin configuration means no polling; checkout still submits', async () => {
    for (const options of [{marker: false}, {statusUrl: 'https://provider.invalid/status'}]) {
        const h = harness({...options, checkout: true});
        await h.advance(180000);
        assert.equal(h.requests.length, 0);
        assert.equal(h.submissions.length, 1);
        assert.equal(h.submissions[0], h.form);
    }
});
