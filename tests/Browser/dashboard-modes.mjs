import fs from 'node:fs';
import path from 'node:path';
import net from 'node:net';
import http from 'node:http';
import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import {spawn} from 'node:child_process';
import {pathToFileURL} from 'node:url';

// The caller provisions qualified libraries/executable and an immutable source copy.
// No package install, application API or installation environment is used here.
const config = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const directory = config.evidence;
fs.mkdirSync(directory, {recursive: true});
const {chromium} = await import(pathToFileURL(config.playwrightModule));
const records = [];
const record = (kind, data = {}) => { records.push({utc: new Date().toISOString(), kind, ...data}); fs.writeFileSync(`${directory}/receipt.json`, JSON.stringify(records, null, 2)); };
const digest = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
const errors = [];
const pageErrors = [];
const unexpected = [];
// A private run may qualify this exact, denied environment injection. Strict is
// the default. This never permits traffic or changes application behavior.
const environmentScript = 'http://gc.kis.v2.scr.kaspersky-labs.com/FD126C42-EBFA-4E12-B309-BB3FDD723AC1/main.js';
const qualification = config.deniedEnvironmentScript;
if (qualification !== undefined) assert.equal(qualification, environmentScript, 'exact environment qualification URL');
function environmentURLAllowed(value) {
  if (qualification !== environmentScript) return false;
  // Validate the original spelling before URL parsing can normalize bad input.
  if (value === environmentScript) return true;
  if (config.deniedEnvironmentAttr !== true || !value.startsWith(environmentScript + '?attr=')) return false;
  const encoded = value.slice((environmentScript + '?attr=').length);
  if (!encoded || Buffer.byteLength(encoded) > 8192 || /[&#\s]/.test(encoded) || /%(?![a-fA-F0-9]{2})/.test(encoded)) return false;
  try { decodeURIComponent(encoded); } catch { return false; }
  const url = new URL(value);
  return !url.username && !url.password && !url.hash && url.origin + url.pathname === environmentScript
    && [...url.searchParams.keys()].length === 1 && url.searchParams.has('attr');
}
const denials = [];
const denialByRequest = new Map();
const consoleEvents = [];
const parsedScripts = [];
const responseJobs = [];
const pageReady = new Map();
function verifyDenial(attempt) {
  assert.equal(qualification, environmentScript, 'strict run rejects environment attempts');
  assert.equal(environmentURLAllowed(attempt.url), true, 'wrong URL cannot be classified');
  assert.equal(attempt.method, 'GET');
  assert.equal(attempt.resourceType, 'script');
  assert.equal(attempt.intercepted, true);
  assert.equal(attempt.aborted, true);
  assert.equal(attempt.failed, 'net::ERR_FAILED');
  assert.equal(attempt.response, false, 'loaded external script cannot be classified');
  assert.equal(attempt.finished, false, 'completed external script cannot be classified');
  assert.equal(parsedScripts.some(url => environmentURLAllowed(url)), false, 'external script cannot execute');
}
const transportRecords = [];
const transportByRequest = new Map();
const transportPages = new Map();
const transportJobs = new Set();
const browserCaptures = new Map();
const pageTokens = new Map();
let nextPageToken = 0;
let nativeHold;
const hopHeaders = new Set(['connection', 'keep-alive', 'proxy-connection', 'transfer-encoding', 'trailer', 'te', 'upgrade']);
if (config.loopbackTransport !== undefined) assert.ok(['node-http-verbatim', 'node-http-cdp-verbatim'].includes(config.loopbackTransport));
const cdpTransport = config.loopbackTransport === 'node-http-cdp-verbatim';
function completeBrowserCapture(key, item, failure) {
  const capture = browserCaptures.get(key);
  if (!capture || capture.item !== item) return;
  if (failure) { errors.push(String(failure)); record('browser-capture-failure', {id: item.id, url: item.url, message: String(failure)}); }
  clearTimeout(capture.timer); browserCaptures.delete(key); capture.resolve();
}
function beginBrowserCapture(key, item) {
  const previous = browserCaptures.get(key);
  if (previous) {
    assert.ok(previous.item.status >= 300 && previous.item.status < 400, 'network ID reused only for native redirect');
    completeBrowserCapture(key, previous.item);
  }
  let resolve;
  const promise = new Promise(done => { resolve = done; });
  const timer = setTimeout(() => completeBrowserCapture(key, item, Error('Browser response capture timed out')), 20000);
  browserCaptures.set(key, {item, promise, resolve, timer});
}
function requireCleanApplicationBytes(bytes) {
  assert.ok(!bytes.includes(Buffer.from(environmentScript)), 'environment URL in application HTTP response');
}
function requireVerbatimBytes(raw, delivered) {
  assert.ok(raw.equals(delivered), 'adapter response bytes must be verbatim');
}
function requireOwnedBackend(value) {
  const url = new URL(value);
  assert.equal(url.origin, fixture.base, 'adapter refuses an unexpected origin before HTTP');
  assert.equal(url.protocol, 'http:');
  assert.equal(url.username + url.password, '');
  assert.ok(['127.0.0.1', 'localhost'].includes(url.hostname), 'adapter only addresses owned loopback');
  return url;
}
async function fulfillFromOwnedBackend(route) {
  const request = route.request();
  const url = requireOwnedBackend(request.url());
  assert.equal(transportByRequest.has(request), false, 'browser request cannot be replayed by adapter');
  const id = transportRecords.length;
  const item = {id, url: request.url(), method: request.method(), started: new Date().toISOString()};
  transportRecords.push(item); transportByRequest.set(request, item);
  if (request.cdp) {
    item.cdp = request.cdp;
    transportPages.set(item, request.page);
    beginBrowserCapture(request.cdp.captureKey, item);
  }
  item.browserHeaders = await request.allHeaders();
  const headers = Object.fromEntries(Object.entries(item.browserHeaders).filter(([name]) => !hopHeaders.has(name.toLowerCase())).map(([name,value]) => [name.toLowerCase(),value]));
  headers['user-agent'] = config.backendUserAgent;
  const body = request.postDataBuffer();
  item.requestBodySha256 = digest(body || Buffer.alloc(0));
  item.requestBodyBytes = body?.length || 0;
  item.forwardedHeaders = headers;
  item.normalizedRequestHeaders = Object.keys(item.browserHeaders).filter(name => hopHeaders.has(name.toLowerCase()) || name.toLowerCase() === 'user-agent');
  if (body) fs.writeFileSync(`${directory}/transport-${id}.request`, body);
  record('transport-request', item);
  const raw = await new Promise((resolve, reject) => {
    const outgoing = http.request(url, {method: request.method(), headers}, response => {
      const chunks = []; response.on('data', bytes => chunks.push(bytes));
      response.on('end', () => resolve({status: response.statusCode, headers: response.headers, rawHeaders: response.rawHeaders, bytes: Buffer.concat(chunks)}));
      response.on('error', reject);
    });
    outgoing.setTimeout(8000, () => outgoing.destroy(Error('Owned HTTP adapter timeout')));
    outgoing.on('error', reject); outgoing.end(body || undefined);
  });
  fs.writeFileSync(`${directory}/transport-${id}.response`, raw.bytes, {flag: 'wx'});
  item.nativeRawBodySha256 = digest(raw.bytes); item.rawHeaders = raw.rawHeaders; item.status = raw.status;
  record('transport-raw-received', item);
  requireCleanApplicationBytes(raw.bytes);
  if (raw.headers.location) requireOwnedBackend(new URL(raw.headers.location, url).href);
  const responseHeaders = {};
  for (const [name, value] of Object.entries(raw.headers)) {
    if (hopHeaders.has(name) || value === undefined) continue;
    responseHeaders[name] = Array.isArray(value) ? value.join(name === 'set-cookie' ? '\n' : ', ') : value;
  }
  item.status = raw.status; item.rawHeaders = raw.rawHeaders; item.responseHeaders = responseHeaders;
  item.nativeHeaderEntries = [];
  for (let index = 0; index < raw.rawHeaders.length; index += 2) {
    if (!hopHeaders.has(raw.rawHeaders[index].toLowerCase())) item.nativeHeaderEntries.push({name: raw.rawHeaders[index], value: raw.rawHeaders[index + 1]});
  }
  item.normalizedResponseHeaders = Object.keys(raw.headers).filter(name => hopHeaders.has(name));
  const delivered = Buffer.from(raw.bytes);
  requireVerbatimBytes(raw.bytes, delivered);
  item.rawBodySha256 = digest(raw.bytes); item.deliveredBodySha256 = digest(delivered); item.responseBytes = raw.bytes.length;
  item.expectedCookies = raw.headers['set-cookie'] || [];
  record('transport-response', item);
  await route.fulfill({status: raw.status, headers: responseHeaders, body: delivered, ...(request.cdp ? {nativeHeaderEntries: item.nativeHeaderEntries} : {})});
  item.fulfilled = true;
  if (request.cdp && raw.status >= 300 && raw.status < 400) {
    item.redirectBodyDelivered = true;
    completeBrowserCapture(request.cdp.captureKey, item);
  }
}
async function drainCaptures() {
  let complete = 0;
  do {
    await Promise.all([...transportJobs]);
    await Promise.all([...browserCaptures.values()].map(capture => capture.promise));
    const end = responseJobs.length;
    await Promise.all(responseJobs.slice(complete, end)); complete = end;
  } while (transportJobs.size > 0 || browserCaptures.size > 0 || complete < responseJobs.length);
}
async function configureCdpTransport(ctx, page, session, pageToken) {
  const byNetwork = new Map();
  const deniedByNetwork = new Map();
  const seenFetchIds = new Set();
  session.on('Network.requestWillBeSent', event => record('cdp-native-request', {pageToken, networkId: event.requestId, url: event.request.url, method: event.request.method, redirectStatus: event.redirectResponse?.status || null}));
  session.on('Network.responseReceived', event => {
    const attempt = deniedByNetwork.get(event.requestId);
    if (attempt) { attempt.response = true; record('forbidden-environment-response', {url: event.response.url, status: event.response.status}); }
  });
  session.on('Network.loadingFailed', event => {
    const attempt = deniedByNetwork.get(event.requestId);
    if (attempt) { attempt.failed = event.errorText; record('environment-request-failed', attempt); }
    const item = byNetwork.get(event.requestId);
    if (item) completeBrowserCapture(item.cdp.captureKey, item, Error(event.errorText));
  });
  session.on('Network.loadingFinished', event => {
    const attempt = deniedByNetwork.get(event.requestId);
    if (attempt) attempt.finished = true;
    const item = byNetwork.get(event.requestId);
    if (!item || (item.status >= 300 && item.status < 400)) return;
    responseJobs.push((async () => {
      try {
        const result = await session.send('Network.getResponseBody', {requestId: event.requestId});
        const bytes = Buffer.from(result.body, result.base64Encoded ? 'base64' : 'utf8');
        fs.writeFileSync(`${directory}/cdp-body-${item.id}.body`, bytes, {flag: 'wx'});
        requireCleanApplicationBytes(bytes);
        assert.equal(digest(bytes), item.rawBodySha256, 'browser receives exact independent HTTP bytes');
        item.browserBodyCaptured = true;
        record('cdp-browser-body', {id: item.id, networkId: event.requestId, bytes: bytes.length, sha256: digest(bytes)});
        completeBrowserCapture(item.cdp.captureKey, item);
      } catch (error) { completeBrowserCapture(item.cdp.captureKey, item, error); }
    })());
  });
  session.on('Fetch.requestPaused', event => {
    const job = (async () => {
      assert.equal(seenFetchIds.has(event.requestId), false, 'CDP request cannot replay'); seenFetchIds.add(event.requestId);
      const url = new URL(event.request.url);
      record('cdp-request-paused', {pageToken, fetchId: event.requestId, networkId: event.networkId, redirectedRequestId: event.redirectedRequestId, url: event.request.url, method: event.request.method});
      if (url.origin !== fixture.base) {
        if (environmentURLAllowed(event.request.url)) {
          const attempt = {id: denials.length + 1, url: event.request.url, method: event.request.method, resourceType: event.resourceType.toLowerCase(), intercepted: true, aborted: false, response: false, finished: false, failed: null};
          denials.push(attempt); deniedByNetwork.set(event.networkId, attempt);
          await session.send('Fetch.failRequest', {requestId: event.requestId, errorReason: 'Failed'}); attempt.aborted = true; record('environment-request-aborted', attempt);
        } else { unexpected.push(event.request.url); await session.send('Fetch.failRequest', {requestId: event.requestId, errorReason: 'Failed'}); }
        return;
      }
      requireOwnedBackend(event.request.url);
      assert.ok(event.networkId, 'native network ID required for every owned request');
      if (nativeHold?.context === ctx && event.request.method === 'POST' && /\/sites\/\d+\/check$/.test(url.pathname)) {
        record('pending-navigation-held'); nativeHold.entered(); await nativeHold.held;
      }
      const entries = event.request.postDataEntries;
      const body = entries?.length ? Buffer.concat(entries.map(entry => { assert.equal(typeof entry.bytes, 'string'); return Buffer.from(entry.bytes, 'base64'); }))
        : event.request.postData === undefined ? null : Buffer.from(event.request.postData);
      assert.ok(!event.request.hasPostData || body !== null, 'complete native POST bytes required');
      const request = {url: () => event.request.url, method: () => event.request.method, allHeaders: async () => event.request.headers, postDataBuffer: () => body,
        page, cdp: {pageToken, fetchId: event.requestId, networkId: event.networkId, redirectedRequestId: event.redirectedRequestId || null, captureKey: `${pageToken}:${event.networkId}`}};
      const route = {request: () => request, fulfill: async options => {
        const responseHeaders = options.nativeHeaderEntries;
        assert.ok(responseHeaders, 'original individual native response headers required');
        await session.send('Fetch.fulfillRequest', {requestId: event.requestId, responseCode: options.status, responseHeaders, body: options.body.toString('base64')});
      }};
      const handling = fulfillFromOwnedBackend(route);
      const item = transportByRequest.get(request);
      assert.ok(item); byNetwork.set(event.networkId, item);
      await handling;
    })();
    const handled = job.catch(async error => {
      errors.push(String(error)); record('adapter-failure', {message: String(error), stack: error.stack, fetchId: event.requestId});
      try { await session.send('Fetch.failRequest', {requestId: event.requestId, errorReason: 'Failed'}); }
      catch (abort) { errors.push(String(abort)); }
    }).finally(() => transportJobs.delete(handled));
    transportJobs.add(handled);
  });
  await session.send('Network.enable', {maxTotalBufferSize: 64000000, maxResourceBufferSize: 8000000, maxPostDataSize: 1048576, enableDurableMessages: true});
  await session.send('Network.setCacheDisabled', {cacheDisabled: true});
  await session.send('Fetch.enable', {patterns: [{urlPattern: '*', requestStage: 'Request'}]});
  record('cdp-transport-enabled', {pageToken, durableBodies: true, cacheDisabled: true, allRedirectsIntercepted: 'Must be established by final one-to-one ledger; API enable alone is not proof'});
}
let launcher;
let server;
let browser;
const contexts = new Set();
const requests = [];
let fixture;
let primary;
let pendingRouteRelease;
let chromeExit;
let browserPort;
let launcherExit;
let lines = [];
let waiting = [];
const nextLine = () => lines.length ? Promise.resolve(lines.shift()) : new Promise((resolve, reject) => {
  const timer = setTimeout(() => reject(Error('Fixture protocol timed out')), 20000);
  waiting.push(line => { clearTimeout(timer); resolve(line); });
});
const command = async (operation, data = {}) => {
  launcher.stdin.write(JSON.stringify({operation, ...data}) + '\n');
  const response = JSON.parse(await nextLine());
  record('fixture-response', response);
  return response;
};
const absent = port => new Promise(resolve => {
  const socket = net.connect({host: '127.0.0.1', port});
  socket.once('connect', () => { socket.destroy(); resolve(false); });
  socket.once('error', () => resolve(true));
});
const readCookies = () => fs.readFileSync(fixture.cookieJar, 'utf8').split(/\r?\n/).filter(line => line.includes('\ttablo_session\t')).map(line => {
  const parts = line.replace(/^#HttpOnly_/, '').split('\t');
  return {name: parts[5], value: parts[6], domain: new URL(fixture.base).hostname, path: '/', httpOnly: true, secure: false, sameSite: 'Lax'};
});
async function context(options = {}, init, initArg) {
  const ctx = await browser.newContext({viewport: {width: 1440, height: 1050}, ...options});
  ctx.setDefaultTimeout(10000);
  ctx.setDefaultNavigationTimeout(15000);
  contexts.add(ctx);
  await ctx.addCookies(readCookies());
  if (init) await ctx.addInitScript(init, initArg);
  if (!cdpTransport) await ctx.route('**/*', async route => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.origin !== fixture.base) {
      if (environmentURLAllowed(request.url())) {
        const attempt = {id: denials.length + 1, url: request.url(), method: request.method(), resourceType: request.resourceType(), intercepted: true, aborted: false, response: false, finished: false, failed: null};
        denials.push(attempt); denialByRequest.set(request, attempt);
        await route.abort(); attempt.aborted = true; record('environment-request-aborted', attempt); return;
      }
      unexpected.push(request.url()); await route.abort(); return;
    }
    if (config.loopbackTransport) {
      const job = fulfillFromOwnedBackend(route); transportJobs.add(job);
      try { await job; }
      catch (error) { errors.push(String(error)); record('adapter-failure', {message: String(error), stack: error.stack}); await route.abort(); }
      finally { transportJobs.delete(job); }
    } else await route.continue();
  });
  ctx.on('request', request => requests.push({method: request.method(), url: request.url()}));
  ctx.on('requestfailed', request => { const attempt = denialByRequest.get(request); if (attempt) { attempt.failed = request.failure()?.errorText; record('environment-request-failed', attempt); } });
  ctx.on('requestfinished', request => { const attempt = denialByRequest.get(request); if (attempt) attempt.finished = true; });
  ctx.on('response', response => {
    const attempt = denialByRequest.get(response.request());
    if (attempt) attempt.response = true;
    if (new URL(response.url()).origin !== fixture.base) { unexpected.push(response.url()); return; }
    const index = responseJobs.length;
    responseJobs.push((async () => {
      const item = cdpTransport ? transportRecords.find(item => !item.browserHeadersCaptured && transportPages.get(item) === response.request().frame().page() && item.url === response.url() && item.method === response.request().method() && item.status === response.status()) : transportByRequest.get(response.request());
      if (cdpTransport) { assert.ok(item, 'browser response requires its unique CDP/backend request'); item.browserHeadersCaptured = true; }
      if (config.loopbackTransport) {
        assert.ok(item, 'browser response requires its one real HTTP request');
        assert.equal(response.status(), item.status);
        assert.deepEqual(await response.headerValues('set-cookie'), item.expectedCookies, 'all native cookie headers preserved');
        for (const header of ['content-security-policy', 'x-content-type-options', 'x-frame-options', 'referrer-policy', 'location']) {
          const expected = item.nativeHeaderEntries.filter(entry => entry.name.toLowerCase() === header).map(entry => entry.value);
          assert.deepEqual(await response.headerValues(header), expected, `preserved individual ${header} headers`);
        }
      }
      if (cdpTransport || (response.status() >= 300 && response.status() < 400)) return;
      const bytes = await response.body();
      fs.writeFileSync(`${directory}/app-response-${index}.body`, bytes);
      record('app-http-response', {url: response.url(), status: response.status(), bytes: bytes.length, sha256: digest(bytes), file: `app-response-${index}.body`});
      requireCleanApplicationBytes(bytes);
      if (item) assert.equal(digest(bytes), item.rawBodySha256, 'browser receives exact independent HTTP bytes');
    })().catch(error => { errors.push(String(error)); }));
  });
  ctx.on('page', page => {
    const pageToken = ++nextPageToken; pageTokens.set(page, pageToken);
    page.on('pageerror', error => { pageErrors.push({name: error.name, message: error.message, stack: error.stack, pageURL: page.url()}); errors.push(String(error)); });
    page.on('console', message => { if (message.type() === 'error') consoleEvents.push({text: message.text(), location: message.location()}); });
    pageReady.set(page, (async () => {
      const session = await ctx.newCDPSession(page);
      if (cdpTransport) await configureCdpTransport(ctx, page, session, pageToken);
      if (options.javaScriptEnabled === false) { record('script-execution-disabled', {reason: 'No-JavaScript fallback context'}); return; }
      session.on('Debugger.scriptParsed', event => { parsedScripts.push(event.url); });
      await session.send('Debugger.enable');
    })());
  });
  return ctx;
}
async function closeContext(ctx) { await drainCaptures(); await ctx.close(); contexts.delete(ctx); record('context-closed'); }
async function open(ctx) { const page = await ctx.newPage(); await pageReady.get(page); await page.goto(fixture.base); await page.locator('[data-dashboard-controls]').waitFor({state: 'visible'}); await drainCaptures(); return page; }
const visibleIds = (page, mode) => page.locator(`[data-dashboard-view="${mode}"] [data-site]:visible`).evaluateAll(nodes => nodes.map(node => Number(node.dataset.siteId)));
const mode = async (page, view) => { await drainCaptures(); await page.locator(`[data-view="${view}"]`).click(); assert.equal(await page.locator(`[data-view="${view}"]`).getAttribute('aria-pressed'), 'true'); };
async function checkPrivacy(page) {
  const surfaces = await page.evaluate(() => ({html: document.documentElement.outerHTML, local: {...localStorage}, session: {...sessionStorage}}));
  const all = JSON.stringify(surfaces);
  const forbidden = ['fixture_private_query_marker', ...(config.forbiddenMarkers || [])];
  if (config.control === 'secret') forbidden.push('Storefront');
  for (const marker of forbidden) assert.ok(!all.includes(marker), `Forbidden marker leaked: ${marker}`);
  assert.equal(await page.locator('script:not([src])').count(), 0);
  assert.equal(await page.evaluate(() => window.dashboardUnsafe), undefined);
}
async function bounded(page, label) {
  const geometry = await page.evaluate(() => ({width: document.documentElement.scrollWidth, viewport: innerWidth,
    outside: [...document.querySelectorAll('body *')].filter(element => !element.closest('.dashboard-table-region') && element.getClientRects().length && element.getBoundingClientRect().right > innerWidth + 1).slice(0, 12).map(element => ({tag: element.tagName, class: element.className, right: element.getBoundingClientRect().right}))}));
  record('document-geometry', {label, ...geometry});
  assert.ok(geometry.width <= geometry.viewport + 1, `${label}: document overflows`);
  const region = page.locator('.dashboard-table-region');
  await region.focus();
  await page.keyboard.press('End');
  await region.evaluate(element => { element.scrollLeft = element.scrollWidth; });
  const last = page.locator('[data-dashboard-view="table"] [data-site]:visible').first().locator('td:last-child a');
  await last.focus();
  const access = await last.evaluate(element => {
    const rect = element.getBoundingClientRect();
    const region = element.closest('.dashboard-table-region').getBoundingClientRect();
    return {left: rect.left, right: rect.right, regionLeft: region.left, regionRight: region.right, bodyWidth: document.documentElement.scrollWidth, viewport: innerWidth};
  });
  assert.ok(access.left >= access.regionLeft - 1 && access.right <= access.regionRight + 1, `${label}: rightmost action unreachable`);
  record('overflow-and-actions', {label, ...access});
}
try {
  const output = fs.openSync(`${directory}/fixture.stderr`, 'wx');
  launcher = spawn(config.php, ['tests/browser-dashboard.php', ...(config.screenshots ? ['--screenshots'] : [])], {
    cwd: config.source, env: config.environment, windowsHide: true, stdio: ['pipe', 'pipe', output],
  });
  fs.closeSync(output);
  let pendingText = '';
  launcher.stdout.on('data', bytes => {
    fs.appendFileSync(`${directory}/fixture.stdout`, bytes);
    pendingText += bytes.toString();
    while (pendingText.includes('\n')) {
      const index = pendingText.indexOf('\n');
      const line = pendingText.slice(0, index); pendingText = pendingText.slice(index + 1);
      if (waiting.length) waiting.shift()(line); else lines.push(line);
    }
  });
  launcherExit = new Promise(resolve => launcher.once('close', (exit, signal) => { record('fixture-parent-closed', {pid: launcher.pid, exit, signal}); resolve(exit); }));
  record('fixture-parent-started', {pid: launcher.pid, php: config.php, source: config.source});
  fixture = JSON.parse(await nextLine());
  assert.equal(fixture.ready, true);
  if (config.appHostname) {
    assert.equal(config.appHostname, 'localhost', 'only the owned loopback alias is permitted');
    const nativeBase = fixture.base;
    const alias = new URL(nativeBase); alias.hostname = config.appHostname;
    fixture.base = alias.origin;
    record('owned-loopback-alias', {nativeBase, browserBase: fixture.base, sameAppPort: true});
  }
  record('fixture-ready', fixture);
  if (config.loopbackTransport) {
    assert.equal(config.backendUserAgent, 'TabloSyntheticDashboardFixture/1.0');
    const hits = [];
    function inspect(dir) {
      for (const entry of fs.readdirSync(`${config.source}/${dir}`, {withFileTypes: true})) {
        const relative = `${dir}/${entry.name}`;
        if (entry.isDirectory()) inspect(relative);
        else if (entry.name.endsWith('.php')) {
          const bytes = fs.readFileSync(`${config.source}/${relative}`);
          if (/user.?agent/i.test(bytes.toString())) hits.push({path: relative, sha256: digest(bytes)});
        }
      }
    }
    inspect('app'); inspect('public');
    assert.deepEqual(hits.map(hit => hit.path).sort(), ['app/CurlGetOptions.php', 'app/CurlWebhookOptions.php']);
    record('incoming-user-agent-audit', {hits, interpretation: 'Only reviewed outgoing CURLOPT_USERAGENT constants; no incoming UA-dependent application auth/business branch', syntheticBackendUserAgent: config.backendUserAgent});
  }
  // Independent raw HTTP capture excludes DOM hooks and browser response changes.
  for (const pathname of ['/', '/assets/app.js', '/assets/app.css']) {
    const raw = await new Promise((resolve, reject) => {
      const request = http.get(fixture.base + pathname, {headers: {Cookie: readCookies().map(cookie => `${cookie.name}=${cookie.value}`).join('; ')}}, response => {
        const chunks = []; response.on('data', bytes => chunks.push(bytes));
        response.on('end', () => resolve({status: response.statusCode, bytes: Buffer.concat(chunks)}));
        response.on('error', reject);
      }); request.on('error', reject);
    });
    assert.equal(raw.status, 200);
    assert.ok(!raw.bytes.includes(Buffer.from(environmentScript)), 'environment URL in independent raw application response');
    const file = `raw-${pathname === '/' ? 'dashboard' : path.basename(pathname)}.body`;
    fs.writeFileSync(`${directory}/${file}`, raw.bytes);
    record('independent-raw-http', {pathname, status: raw.status, bytes: raw.bytes.length, sha256: digest(raw.bytes), file});
  }
  server = await chromium.launchServer({executablePath: config.chrome, headless: true, env: config.environment,
    args: ['--no-proxy-server', '--disable-background-networking', '--disable-component-update', '--no-first-run']});
  const chrome = server.process();
  browserPort = Number(new URL(server.wsEndpoint()).port);
  const profile = chrome.spawnargs.find(argument => argument.startsWith('--user-data-dir='))?.slice('--user-data-dir='.length);
  assert.ok(profile?.replaceAll('\\', '/').startsWith(config.environment.TEMP.replaceAll('\\', '/') + '/'), 'browser profile outside owned TEMP');
  chromeExit = new Promise(resolve => chrome.once('close', (exit, signal) => { record('browser-process-closed', {pid: chrome.pid, exit, signal}); resolve(exit); }));
  record('browser-started', {pid: chrome.pid, executable: config.chrome, argv: chrome.spawnargs, profile, browserPort, childCensus: 'Direct PID observed; renderer/utility child census incomplete, no child closures claimed'});
  browser = await chromium.connect(server.wsEndpoint());
  assert.equal(browser.version(), config.chromeVersion);
  record('browser-version', {actual: browser.version(), nodePid: process.pid, nodeVersion: process.version, nodeExecutable: process.execPath});
  const ctx = await context();
  const page = await open(ctx);
  await checkPrivacy(page);
  const expected = [fixture.ids[1], fixture.ids[2], fixture.ids[0], ...fixture.ids.slice(3)];
  assert.deepEqual(await visibleIds(page, 'tile'), expected);
  const initial = await command('state');
  const networkStart = requests.length;
  for (const filter of ['all', 'attention', 'paused']) {
    await page.locator(`[data-filter="${filter}"]`).click();
    for (const query of ['', ' PORTAL ', 'absent fixture']) {
      await page.locator('#site-search').fill(query);
      await mode(page, 'tile'); const tiles = await visibleIds(page, 'tile');
      await mode(page, 'table'); const table = await visibleIds(page, 'table');
      assert.deepEqual(table, tiles, `${filter}/${query}: same logical set/order`);
      assert.equal(await page.locator('.no-results').isVisible(), tiles.length === 0);
      assert.equal(await page.locator('.dashboard-table-region').isVisible(), tiles.length > 0);
      if (query.includes('absent')) assert.deepEqual(table, []);
    }
  }
  assert.equal(requests.length, networkStart, 'view/search/filter sends no requests');
  const localAfter = await command('state');
  assert.deepEqual(localAfter.timestamps, initial.timestamps, 'local interaction grants no session activity');
  assert.deepEqual(localAfter.counts, initial.counts);
  assert.equal(localAfter.peerCount, initial.peerCount);
  await page.locator('[data-filter="all"]').click();
  await page.locator('#site-search').fill('Portal');
  await mode(page, 'table');
  await page.waitForTimeout(200);
  assert.equal(await page.evaluate(() => localStorage.getItem('tablo.dashboard.view.v1')), 'table');
  if (config.control === 'view') assert.equal(await page.locator('[data-view="tile"]').getAttribute('aria-pressed'), 'true', 'wrong expected view canary');
  if (config.control === 'count') assert.equal((await visibleIds(page, 'table')).length, 999, 'wrong logical count canary');
  await drainCaptures(); await page.reload();
  await page.locator('[data-dashboard-controls]').waitFor({state: 'visible'});
  assert.equal(await page.locator('[data-view="table"]').getAttribute('aria-pressed'), 'true');
  assert.equal(await page.locator('#site-search').inputValue(), 'Portal');
  await drainCaptures(); await page.locator('[data-dashboard-view="table"] [data-site]:visible a[href$="/edit"]').click();
  await page.locator('[data-site-form]').waitFor();
  await drainCaptures(); await page.goBack(); await page.locator('[data-dashboard-controls]').waitFor({state: 'visible'});
  assert.equal(await page.locator('#site-search').inputValue(), 'Portal');
  assert.deepEqual(await visibleIds(page, 'table'), [fixture.ids[1]]);
  await drainCaptures(); await page.goForward(); await page.locator('[data-site-form]').waitFor(); await drainCaptures(); await page.goBack();
  await page.locator('[data-dashboard-controls]').waitFor({state: 'visible'});
  await page.locator('[data-filter="all"]').click(); await page.locator('#site-search').fill('');
  await page.locator('[data-view="tile"]').focus(); await page.keyboard.press('Space');
  assert.equal(await page.locator('[data-view="tile"]').getAttribute('aria-pressed'), 'true');
  await page.keyboard.press('Tab'); assert.equal(await page.locator('[data-view="table"]').evaluate(element => element === document.activeElement), true);
  await page.keyboard.press('Enter'); assert.equal(await page.locator('[data-view="table"]').getAttribute('aria-pressed'), 'true');
  await page.keyboard.press('Shift+Tab'); assert.equal(await page.locator('[data-view="tile"]').evaluate(element => element === document.activeElement), true);
  const focusable = await page.evaluate(() => [...document.querySelectorAll('a,button,input,[tabindex]')].filter(element => element.getClientRects().length && !element.disabled));
  assert.ok(focusable.length > 0);
  assert.equal(await page.locator('[data-dashboard-view="tile"] [data-check-button]:visible').count(), 0, 'hidden actions absent from keyboard view');
  await page.locator('details.check-error').filter({visible: true}).first().locator('summary').focus();
  await page.keyboard.press('Enter'); assert.equal(await page.locator('details.check-error[open]:visible').count(), 1);
  await bounded(page, '1440x1050');
  await closeContext(ctx);
  // Each storage/history error is independently injected before the production script.
  for (const fault of ['getter', 'read', 'write', 'history', 'malformed']) {
    const faultContext = await context({}, ({fault, origin}) => {
      // Storage/history fault inputs belong to the fixture, not about:blank.
      if (location.origin !== origin) return;
      if (fault === 'getter') Object.defineProperty(window, 'localStorage', {get() { throw Error('synthetic storage getter'); }});
      if (fault === 'read') Storage.prototype.getItem = () => { throw Error('synthetic storage read'); };
      if (fault === 'write') Storage.prototype.setItem = () => { throw Error('synthetic storage write'); };
      if (fault === 'history') history.replaceState = () => { throw Error('synthetic history write'); };
      if (fault === 'malformed') { localStorage.setItem('tablo.dashboard.view.v1', 'invalid'); history.replaceState({foreign: 7, tabloDashboard: {query: 4, filter: 'invalid', secret: 'ignored'}}, ''); }
    }, {fault, origin: fixture.base});
    const faultPage = await open(faultContext);
    assert.equal(await faultPage.locator('[data-view="tile"]').getAttribute('aria-pressed'), 'true');
    await mode(faultPage, 'table'); await faultPage.locator('#site-search').fill('Portal');
    assert.deepEqual(await visibleIds(faultPage, 'table'), [fixture.ids[1]]);
    await faultPage.waitForTimeout(200);
    if (fault === 'malformed') assert.deepEqual(await faultPage.evaluate(() => history.state), {foreign: 7, tabloDashboard: {query: 'Portal', filter: 'all'}});
    record('failsoft', {fault}); await closeContext(faultContext);
  }
  const noJs = await context({javaScriptEnabled: false});
  const fallback = await noJs.newPage(); await pageReady.get(fallback); await fallback.goto(fixture.base);
  assert.equal(await fallback.locator('[data-dashboard-controls]').isVisible(), false);
  assert.deepEqual(await visibleIds(fallback, 'tile'), expected);
  assert.equal(await fallback.locator('[data-dashboard-view="table"]').isVisible(), false);
  assert.equal(await fallback.locator('[data-dashboard-view="tile"] a[href$="/edit"]').count(), expected.length);
  await closeContext(noJs);
  if (!config.screenshots) {
    const actionsContext = await context();
    const actions = await open(actionsContext);
    for (const view of ['tile', 'table']) {
      await mode(actions, view);
      const before = await command('state');
      const navigation = actions.waitForEvent('framenavigated', frame => frame === actions.mainFrame());
      await actions.locator(`[data-dashboard-view="${view}"] [data-site-id="${fixture.ids[0]}"] [data-check-button]`).click();
      await navigation; await actions.waitForLoadState('load'); await actions.locator('[data-dashboard-controls]').waitFor({state: 'visible'});
      const after = await command('state');
      assert.equal(after.peerCount, before.peerCount + 2);
      assert.equal(after.counts.check_history, before.counts.check_history + 1);
      assert.equal(await actions.locator('.notice').count(), 1);
      assert.equal(await actions.locator('#site-search').inputValue(), '', 'native redirect has fresh filter state');
    }
    // Hold the actual native navigation in routing to observe pending controls, then release once.
    let release;
    const held = new Promise(resolve => { release = resolve; pendingRouteRelease = resolve; });
    const holdLimit = setTimeout(release, 8000);
    let entered;
    const reached = new Promise(resolve => { entered = resolve; });
    if (cdpTransport) nativeHold = {context: actionsContext, entered, held};
    else await actionsContext.route('**/sites/*/check', async route => { record('pending-navigation-held'); entered(); await held; await route.fallback(); });
    await mode(actions, 'table');
    const beforeHeld = await command('state');
    const postStart = requests.filter(request => request.method === 'POST').length;
    let observe;
    const observation = new Promise(resolve => { observe = resolve; });
    await actions.exposeFunction('dashboardPendingObservation', result => { record('pending-dom-observation', result); observe(result); });
    await actions.evaluate(ids => {
      let observing = false;
      document.getElementById(`check-site-${ids[0]}`).addEventListener('submit', () => {
        if (observing) return;
        observing = true;
        const disabled = id => [...document.querySelectorAll(`button[form="check-site-${id}"]`)].map(node => node.disabled);
        const before = disabled(ids[0]);
        document.querySelector('[data-view="tile"]').click(); document.querySelector('[data-filter="attention"]').click();
        window.dispatchEvent(new PageTransitionEvent('pageshow', {persisted: true})); document.getElementById(`check-site-${ids[0]}`).requestSubmit();
        window.dashboardPendingObservation({before, after: disabled(ids[0]), paused: disabled(ids[4])}).catch(() => {});
      });
    }, fixture.ids);
    const submitClick = actions.locator(`[data-dashboard-view="table"] [data-site-id="${fixture.ids[0]}"] [data-check-button]`).click({noWaitAfter: true});
    await Promise.race([reached, submitClick.then(() => new Promise(() => {}))]);
    record('pending-route-reached');
    const pending = await observation;
    assert.deepEqual(pending, {before: [true, true], after: [true, true], paused: [true, true]});
    record('pending-both-disabled', pending);
    assert.equal(requests.filter(request => request.method === 'POST').length, postStart + 1);
    const heldNavigation = actions.waitForEvent('framenavigated', frame => frame === actions.mainFrame());
    release(); clearTimeout(holdLimit); pendingRouteRelease = null; await submitClick; await heldNavigation; await actions.waitForLoadState('load'); await actions.locator('[data-dashboard-controls]').waitFor({state: 'visible'});
    const afterHeld = await command('state');
    assert.equal(afterHeld.peerCount, beforeHeld.peerCount + 2); assert.equal(afterHeld.counts.check_history, beforeHeld.counts.check_history + 1);
    if (cdpTransport) nativeHold = null;
    else await actionsContext.unroute('**/sites/*/check');
    await drainCaptures(); await actions.goBack(); await actions.locator('[data-dashboard-controls]').waitFor({state: 'visible'});
    assert.deepEqual(await actions.locator(`button[form="check-site-${fixture.ids[4]}"]`).evaluateAll(nodes => nodes.map(node => node.disabled)), [true, true]);
    await closeContext(actionsContext);
  }
  // A separate screenshot launcher has only neutral example domains, with no real checks.
  if (config.screenshots) {
    const shots = await context(); const shot = await open(shots);
    await shot.screenshot({path: `${directory}/dashboard-desktop.png`, fullPage: true});
    await mode(shot, 'table'); await shot.screenshot({path: `${directory}/dashboard-table-desktop.png`, fullPage: true});
    await shot.setViewportSize({width: 390, height: 844}); await bounded(shot, '390x844');
    await shot.locator('.dashboard-table-region').evaluate(element => { element.scrollLeft = 0; });
    await shot.screenshot({path: `${directory}/dashboard-table-mobile.png`, fullPage: true});
    await bounded(shot, '390x844 right edge'); await shot.screenshot({path: `${directory}/dashboard-table-mobile-right.png`, fullPage: true});
    await shot.setViewportSize({width: 360, height: 800}); await bounded(shot, '360x800');
    await shot.evaluate(() => { document.documentElement.style.zoom = '2'; }); await bounded(shot, '360x800 at 200% CSS zoom');
    await closeContext(shots);
    const touch = await context({viewport: {width: 390, height: 844}, isMobile: true, hasTouch: true});
    const mobile = await open(touch); await mode(mobile, 'table'); await bounded(mobile, '390 touch emulation'); await closeContext(touch);
  }
  await command('long');
  const longContext = await context(); const long = await open(longContext); await mode(long, 'table');
  await long.setViewportSize({width: 360, height: 800}); await bounded(long, 'long Unicode/URL/branch/error'); await mode(long, 'tile');
  assert.ok(await long.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'long tiles overflow'); await closeContext(longContext);
  await command('empty');
  const emptyContext = await context(); const empty = await open(emptyContext);
  await mode(empty, 'table'); assert.equal(await empty.locator('.dashboard-table-region').isVisible(), false);
  assert.equal(await empty.locator('.first-site-hint').count(), 1); assert.equal(await empty.locator('[data-table-add]:visible').count(), 1);
  assert.equal(await empty.locator('.no-results').isVisible(), false); await closeContext(emptyContext);
  await drainCaptures();
  for (const attempt of denials) verifyDenial(attempt);
  assert.equal(parsedScripts.some(url => environmentURLAllowed(url)), false, 'environment script cannot parse or execute');
  for (const event of consoleEvents) {
    if (environmentURLAllowed(event.location.url) && event.text === 'Failed to load resource: net::ERR_FAILED' && denials.some(attempt => attempt.url === event.location.url)) {
      record('environment-abort-console', event);
    } else errors.push(JSON.stringify(event));
  }
  record('environment-qualification', {enabled: qualification !== undefined, url: qualification || null, attempts: denials, parsedExternalScripts: parsedScripts.filter(url => environmentURLAllowed(url)), consoleEvents, strictBrowserPass: qualification === undefined && !config.loopbackTransport});
  if (config.loopbackTransport) {
    assert.equal(transportRecords.length, requests.filter(request => new URL(request.url).origin === fixture.base).length, 'one intended browser request to one backend HTTP request');
    assert.ok(transportRecords.every(item => item.fulfilled && item.rawBodySha256 === item.deliveredBodySha256));
    if (cdpTransport) assert.ok(transportRecords.every(item => item.browserHeadersCaptured && (item.browserBodyCaptured || item.redirectBodyDelivered)), 'every CDP response retains observed native headers and response body');
    record('transport-qualified', {transport: config.loopbackTransport, requests: transportRecords.length, allDeliveredBytesVerbatim: true, realHttpBackend: true, directBrowserProductionAcceptance: false});
  }
  assert.deepEqual(unexpected, [], 'unexpected outbound browser request');
  assert.deepEqual(errors, [], 'browser script/console errors');
  record('assertions-passed', {source: config.source, control: config.control || null, qualifiedRealDevice: false, qualifiedScreenReader: false});
} catch (error) {
  primary = error;
  record('primary-failure', {message: String(error), stack: error.stack});
  fs.writeFileSync(`${directory}/failed-input.json`, JSON.stringify({config, fixture, requests, errors, pageErrors, unexpected, denials, consoleEvents, parsedScripts}, null, 2));
} finally {
  pendingRouteRelease?.();
  const releaseSteps = [
    async () => { if (fixture && launcher) { const released = await command('release_database'); assert.equal(released.databaseReleased, true); } },
    async () => { for (const ctx of contexts) await closeContext(ctx); },
    async () => { if (browser) await browser.close(); },
    async () => { if (server) { await server.close(); assert.equal(await chromeExit, 0, 'Chrome direct process exit'); } },
    async () => { if (launcher) { launcher.stdin.end(JSON.stringify({operation: 'close'}) + '\n'); assert.equal(await launcherExit, 0, 'PHP launcher exit'); } },
    async () => { if (fixture) for (const port of fixture.ports) assert.equal(await absent(port), true, 'owned app/peer port closure'); },
    async () => { if (browserPort) assert.equal(await absent(browserPort), true, 'owned browser websocket port closure'); },
  ];
  let cleanupFailed = false;
  for (const step of releaseSteps) {
    try { await step(); }
    catch (cleanup) { cleanupFailed = true; record('cleanup-failure', {message: String(cleanup)}); primary ||= cleanup; }
  }
  if (!cleanupFailed) record('closure-verified', {directBrowserExit: server ? 0 : null, directPhpExit: launcher ? 0 : null, portsAbsent: Boolean(fixture), rendererChildCensus: 'Incomplete; no renderer exit claims'});
  fs.writeFileSync(`${directory}/requests.json`, JSON.stringify(requests, null, 2));
  fs.writeFileSync(`${directory}/denials.json`, JSON.stringify({qualification, denials, consoleEvents, parsedScripts}, null, 2));
  fs.writeFileSync(`${directory}/transport.json`, JSON.stringify(transportRecords, null, 2));
  fs.writeFileSync(`${directory}/streams.json`, JSON.stringify(['fixture.stdout', 'fixture.stderr'].filter(file => fs.existsSync(`${directory}/${file}`)).map(file => ({file,sha256: digest(fs.readFileSync(`${directory}/${file}`))})), null, 2));
}
if (primary) { process.stderr.write(String(primary) + '\n'); process.exitCode = 1; }
