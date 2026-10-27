import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const scope = {AbortController, DOMException, TextDecoder, Uint8Array};
vm.runInNewContext(fs.readFileSync(new URL('../../public/assets/dashboard-polling.js', import.meta.url), 'utf8'), scope);
const {create, transport, ProtocolError} = scope.TabloDashboardPolling;
const settle = async () => { for (let index = 0; index < 12; ++index) await Promise.resolve(); };
const deferred = () => {
  let resolve; let reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return {promise, resolve, reject};
};
function clock() {
  let time = 0;
  let sequence = 0;
  const jobs = new Map();
  return {
    now: () => time,
    setTimer(fn, delay) { const id = ++sequence; jobs.set(id, {fn, due: time + delay}); return id; },
    clearTimer(id) { jobs.delete(id); },
    count: () => jobs.size,
    async tick(milliseconds) {
      const end = time + milliseconds;
      while (true) {
        const next = [...jobs.entries()].sort((a, b) => a[1].due - b[1].due)[0];
        if (!next || next[1].due > end) break;
        time = next[1].due; jobs.delete(next[0]); next[1].fn(); await settle();
      }
      time = end; await settle();
    },
    // Delayed JS timer delivery must not let a response miss its deadline.
    advanceWithoutTimers(milliseconds) { time += milliseconds; },
  };
}
function rig(request, options = {}) {
  const time = clock();
  const commits = [];
  const states = [];
  const scheduler = create({...time, request, commit: value => commits.push(value), onState: value => states.push(value), ...options});
  return {time, commits, states, scheduler};
}
const snapshot = () => ({dashboard_version: 1, csrf: 'initial-token', stats: {total: 0, online: 0, attention: 0, paused: 0}, html: ''});
const url = 'https://tablo.example/dashboard/state';
function response(value = snapshot(), extra = {}) {
  const bytes = new TextEncoder().encode(JSON.stringify(value));
  let sent = false;
  return {ok: true, status: 200, url, headers: {get: () => 'application/json; charset=UTF-8'},
    body: {getReader: () => ({read: async () => sent ? {done: true} : (sent = true, {done: false, value: bytes}),
      releaseLock() {}, cancel: async () => {}})}, ...extra};
}

test('first request is delayed; next interval starts only after full settlement', async () => {
  const pending = deferred(); let calls = 0;
  const run = rig(() => { ++calls; return calls === 1 ? pending.promise : Promise.resolve('second'); });
  await run.time.tick(14999); assert.equal(calls, 0);
  await run.time.tick(1); assert.equal(calls, 1); assert.equal(run.time.count(), 1);
  await run.time.tick(9000); assert.equal(calls, 1);
  pending.resolve('first'); await settle();
  assert.deepEqual(run.commits, ['first']);
  await run.time.tick(14999); assert.equal(calls, 1);
  await run.time.tick(1); assert.equal(calls, 2); assert.deepEqual(run.commits, ['first', 'second']);
  run.scheduler.stop(); assert.equal(run.time.count(), 0);
});

test('timeout aborts but retains sole operation until ignored abort settles', async () => {
  const pending = deferred(); let calls = 0; let signal;
  const run = rig(current => { ++calls; signal = current; return pending.promise; });
  await run.time.tick(25000); assert.equal(signal.aborted, true);
  await run.time.tick(300000); assert.equal(calls, 1); assert.equal(run.scheduler.state().active, true);
  pending.resolve('late'); await settle();
  assert.deepEqual(run.commits, []); assert.equal(run.scheduler.state().failures, 1);
  await run.time.tick(29999); assert.equal(calls, 1);
  await run.time.tick(1); assert.equal(calls, 2); run.scheduler.stop();
});

test('backoff is 30/60/120/120 seconds and resets only after success', async () => {
  let calls = 0;
  const run = rig(() => ++calls <= 4 ? Promise.reject(Error('network')) : Promise.resolve('saved'));
  await run.time.tick(15000); assert.equal(calls, 1);
  for (const delay of [30000, 60000, 120000, 120000]) {
    await run.time.tick(delay - 1); const before = calls;
    await run.time.tick(1); assert.equal(calls, before + 1);
  }
  assert.deepEqual(run.commits, ['saved']); assert.equal(run.scheduler.state().failures, 0);
  await run.time.tick(14999); assert.equal(calls, 5);
  await run.time.tick(1); assert.equal(calls, 6); run.scheduler.stop();
});

test('hidden/offline toggles retain next eligibility and cannot reset failure backoff', async () => {
  let calls = 0;
  const run = rig(() => { ++calls; return Promise.reject(Error('offline')); });
  await run.time.tick(15000);
  for (let index = 0; index < 6; ++index) { run.scheduler.setAvailable(false); await run.time.tick(1000); run.scheduler.setAvailable(true); }
  await run.time.tick(23999); assert.equal(calls, 1);
  await run.time.tick(1); assert.equal(calls, 2); assert.equal(run.scheduler.state().failures, 2); run.scheduler.stop();
});

test('suspension invalidates a body response even after visibility returns', async () => {
  const pending = deferred(); let signal;
  const run = rig(current => { signal = current; return pending.promise; });
  await run.time.tick(15000);
  run.scheduler.setAvailable(false); assert.equal(signal.aborted, true); assert.equal(run.time.count(), 0);
  run.scheduler.setAvailable(true); assert.equal(run.time.count(), 0);
  pending.resolve('stale-session'); await settle(); assert.deepEqual(run.commits, []);
  assert.equal(run.scheduler.state().failures, 0); assert.equal(run.time.count(), 1); run.scheduler.stop();
});

test('deadline is enforced when the JS timer itself has been delayed', async () => {
  const pending = deferred(); const run = rig(() => pending.promise);
  await run.time.tick(15000); run.time.advanceWithoutTimers(10001);
  pending.resolve('late'); await settle(); assert.deepEqual(run.commits, []); assert.equal(run.scheduler.state().failures, 1); run.scheduler.stop();
});

test('protocol rejection is terminal across lifecycle toggles', async () => {
  let calls = 0;
  const run = rig(() => { ++calls; throw new ProtocolError('session'); });
  await run.time.tick(15000); assert.equal(run.scheduler.state().stopped, true);
  run.scheduler.setAvailable(false); run.scheduler.setAvailable(true); await run.time.tick(1000000);
  assert.equal(calls, 1); assert.equal(run.time.count(), 0); assert.equal(run.states.at(-1), 'stopped');
});

test('transport uses only native same-origin GET credentials and accepts saved envelope', async () => {
  let options;
  const request = transport({url, csrf: 'initial-token', fetch: async (actual, init) => { assert.equal(actual, url); options = init; return response(); }});
  const signal = new AbortController().signal;
  assert.equal((await request(signal)).csrf, 'initial-token');
  assert.equal(options.method, 'GET'); assert.equal(options.credentials, 'same-origin'); assert.equal(options.redirect, 'manual');
  assert.equal(options.cache, 'no-store'); assert.deepEqual(Object.keys(options.headers), ['Accept']); assert.equal(options.signal, signal);
});

for (const [label, extra] of [
  ['401', {ok: false, status: 401}], ['403', {ok: false, status: 403}], ['413', {ok: false, status: 413}],
  ['redirect', {ok: false, status: 302}], ['opaque redirect', {type: 'opaqueredirect', status: 0}],
  ['followed redirect', {redirected: true}], ['unexpected URL', {url: url + '/other'}],
  ['HTML', {headers: {get: () => 'text/html'}}],
]) test(`${label} permanently rejects the response`, async () => {
  const request = transport({url, csrf: 'initial-token', fetch: async () => response(snapshot(), extra)});
  await assert.rejects(request(new AbortController().signal), ProtocolError);
});

for (const [label, mutate] of [
  ['CSRF rotation', value => { value.csrf = 'new-token'; }],
  ['version change', value => { value.dashboard_version = 2; }],
  ['unknown envelope field', value => { value.sites = []; }],
  ['invalid stats', value => { value.stats.online = 1; }],
  ['invalid html', value => { value.html = null; }],
]) test(`${label} preserves old snapshot by rejecting the envelope`, async () => {
  const value = snapshot(); mutate(value);
  const request = transport({url, csrf: 'initial-token', fetch: async () => response(value)});
  await assert.rejects(request(new AbortController().signal), ProtocolError);
});

test('actual streamed bytes are bounded without trusting Content-Length', async () => {
  let reads = 0; let canceled = false;
  const oversized = response(snapshot(), {headers: {get: name => name === 'content-type' ? 'application/json' : '1'},
    body: {getReader: () => ({read: async () => { ++reads; return {done: false, value: new Uint8Array(1024 * 1024 + 1)}; },
      cancel: async () => { canceled = true; }, releaseLock() {}})}});
  const request = transport({url, csrf: 'initial-token', fetch: async () => oversized});
  await assert.rejects(request(new AbortController().signal), ProtocolError);
  assert.equal(reads, 2); assert.equal(canceled, true);
});

test('a complete envelope exactly at the byte limit remains valid', async () => {
  const value = snapshot();
  value.html = 'x'.repeat(2 * 1024 * 1024 - new TextEncoder().encode(JSON.stringify(value)).byteLength);
  const request = transport({url, csrf: 'initial-token', fetch: async () => response(value)});
  assert.equal((await request(new AbortController().signal)).html.length, value.html.length);
});

test('one deadline includes headers and streaming body; no second fetch while body is pending', async () => {
  const headers = deferred(); const body = deferred(); let calls = 0; let signal;
  const request = transport({url, csrf: 'initial-token', fetch: async (_, options) => { ++calls; signal = options.signal; return headers.promise; }});
  const run = rig(request);
  await run.time.tick(15000); await run.time.tick(6000);
  headers.resolve(response(snapshot(), {body: {getReader: () => ({read: () => body.promise, releaseLock() {}})}})); await settle();
  await run.time.tick(4000); assert.equal(signal.aborted, true);
  await run.time.tick(120000); assert.equal(calls, 1);
  body.resolve({done: true}); await settle(); assert.deepEqual(run.commits, []); assert.equal(run.scheduler.state().failures, 1); run.scheduler.stop();
});

test('truncated/invalid UTF-8 JSON is terminal while network failures retry', async () => {
  const request = transport({url, csrf: 'initial-token', fetch: async () => response(snapshot(), {
    body: {getReader: () => { let sent = false; return {read: async () => sent ? {done: true} : (sent = true, {done: false, value: new Uint8Array([0xff])}), releaseLock() {}}; }},
  })});
  await assert.rejects(request(new AbortController().signal), ProtocolError);
  const transient = transport({url, csrf: 'initial-token', fetch: async () => response(snapshot(), {ok: false, status: 503})});
  await assert.rejects(transient(new AbortController().signal), error => !(error instanceof ProtocolError));
});
