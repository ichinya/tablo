import assert from 'node:assert/strict';

// These cases use the existing fixture's real authenticated HTTP responses.
// Only page time and lifecycle inputs are synthetic; fetch/HTML are untouched.
export async function pollingScenarios({context, open, closeContext, command, fixture, requests,
  stateRequest, drainCaptures, record, mode, visibleIds}) {
  const ctx = await context({}, () => {
    window.addEventListener('pageshow', event => { window.dashboardLastPageshow = {trusted: event.isTrusted, persisted: event.persisted}; });
  });
  const page = await open(ctx, {clock: true});
  const start = requests.length;
  const initial = await command('state');
  await page.evaluate(() => {
    window.dashboardPollingCommits = 0;
    new MutationObserver(() => { ++window.dashboardPollingCommits; }).observe(document.querySelector('[data-dashboard-snapshot]'), {childList: true});
  });
  let commits = 0;
  const stateRequests = () => requests.slice(start).filter(stateRequest);
  const advance = async milliseconds => { await page.clock.runFor(milliseconds); await drainCaptures(); };
  const refreshed = async milliseconds => {
    ++commits;
    await advance(milliseconds);
    await page.waitForFunction(expected => window.dashboardPollingCommits === expected, commits, {polling: 50});
  };
  await advance(14999); assert.equal(stateRequests().length, 0, 'initial delay is fifteen seconds');
  await refreshed(1); assert.equal(stateRequests().length, 1);
  const first = await command('state');
  assert.deepEqual(first.counts, initial.counts, 'saved-state refresh creates no check/incident/notification work');
  assert.equal(first.peerCount, initial.peerCount, 'saved-state refresh probes no upstream');
  assert.equal(first.timestamps.authenticated_at, initial.timestamps.authenticated_at);
  assert.equal(first.timestamps.last_activity_at, initial.timestamps.last_activity_at, 'poll is not human session activity');

  await mode(page, 'table');
  await page.evaluate(id => {
    window.dashboardRetainedForm = document.getElementById(`check-site-${id}`);
  }, fixture.ids[0]);
  const changed = await command('polling_mutate');
  await refreshed(15000);
  const updated = page.locator(`[data-dashboard-view="table"] [data-site-id="${fixture.ids[0]}"]`);
  assert.equal(await updated.locator('.table-site-name').textContent(), 'Polling updated site');
  assert.equal(await updated.locator('td.table-metric').nth(0).textContent(), '0');
  assert.equal(await updated.locator('td.table-metric').nth(1).textContent(), '—');
  assert.equal((await updated.locator('td.table-metric').nth(2).textContent()).trim(), '0');
  assert.equal(await page.evaluate(id => document.getElementById(`check-site-${id}`) === window.dashboardRetainedForm, fixture.ids[0]), true, 'canonical form identity retained');
  const ids = await visibleIds(page, 'table');
  assert.ok(ids.includes(changed.addedId)); assert.ok(!ids.includes(fixture.ids[2]));
  assert.deepEqual(await page.locator(`button[form="check-site-${fixture.ids[1]}"]`).evaluateAll(nodes => nodes.map(node => node.disabled)), [true, true]);
  assert.equal(await page.locator('form[data-check]').count(), ids.length);
  assert.equal(await page.locator('[data-check-button]').count(), ids.length * 2);
  for (const value of await page.locator('[data-dashboard-count="total"]').allTextContents()) assert.equal(Number(value), ids.length);
  await mode(page, 'tile'); assert.deepEqual(await visibleIds(page, 'tile'), ids);
  await mode(page, 'table');

  await updated.locator('details').evaluate(item => { item.open = true; });
  await updated.locator('a[href$="/edit"]').focus();
  await page.locator('[data-dashboard-view="table"]').evaluate(item => { item.scrollLeft = item.scrollWidth; });
  const savedPosition = await page.evaluate(() => ({x: scrollX, y: scrollY, table: document.querySelector('[data-dashboard-view="table"]').scrollLeft}));
  await refreshed(15000);
  assert.equal(await updated.locator('details').getAttribute('open'), '');
  assert.equal(await updated.locator('a[href$="/edit"]').evaluate(item => item === document.activeElement), true, 'action identity survives replacement');
  assert.deepEqual(await page.evaluate(() => ({x: scrollX, y: scrollY, table: document.querySelector('[data-dashboard-view="table"]').scrollLeft})), savedPosition);
  await page.locator('#site-search').fill('Polling');
  await advance(150); // Persist the query in history without a wall-clock sleep.
  await page.locator('[data-filter="attention"]').click();
  const preference = await page.evaluate(() => ({history: history.state, view: localStorage.getItem('tablo.dashboard.view.v1'), query: document.querySelector('#site-search').value}));
  await refreshed(15000);
  assert.deepEqual(await page.evaluate(() => ({history: history.state, view: localStorage.getItem('tablo.dashboard.view.v1'), query: document.querySelector('#site-search').value})), preference);
  assert.equal(await page.locator('[data-filter="attention"]').getAttribute('aria-pressed'), 'true');
  assert.equal(await page.locator('[data-view="table"]').getAttribute('aria-pressed'), 'true');

  // Hidden/visible transitions are explicit fixture lifecycle inputs. They use
  // the document event consumed by production code and keep the HTTP path real.
  await page.evaluate(() => {
    window.dashboardFixtureHidden = true;
    Object.defineProperty(document, 'visibilityState', {configurable: true, get: () => window.dashboardFixtureHidden ? 'hidden' : 'visible'});
    document.dispatchEvent(new Event('visibilitychange'));
  });
  const hiddenStart = stateRequests().length;
  await advance(60000); assert.equal(stateRequests().length, hiddenStart);
  await page.evaluate(() => { window.dashboardFixtureHidden = false; document.dispatchEvent(new Event('visibilitychange')); });
  await refreshed(1); assert.equal(stateRequests().length, hiddenStart + 1, 'visible resumes only after previous eligibility');
  await ctx.setOffline(true);
  await page.waitForFunction(() => navigator.onLine === false, undefined, {polling: 50});
  const offlineStart = stateRequests().length;
  await advance(60000); assert.equal(stateRequests().length, offlineStart);
  await ctx.setOffline(false);
  await page.waitForFunction(() => navigator.onLine === true, undefined, {polling: 50});
  await refreshed(1); assert.equal(stateRequests().length, offlineStart + 1);

  await page.locator('[data-filter="all"]').click(); await page.locator('#site-search').fill('');
  await advance(150);
  const large = await command('polling_large');
  await refreshed(15000);
  const complete = await visibleIds(page, 'table');
  assert.equal(complete.length, ids.length + 125, 'no one-hundred-site truncation');
  for (const id of large.addedIds) assert.ok(complete.includes(id));
  await mode(page, 'tile'); assert.deepEqual(await visibleIds(page, 'tile'), complete);
  await mode(page, 'table');
  const beforeEmpty = await command('state');
  assert.deepEqual(beforeEmpty.counts, changed.counts, 'polls preserve the saved-state producer counts'); assert.equal(beforeEmpty.peerCount, initial.peerCount);
  assert.equal(beforeEmpty.timestamps.last_activity_at, initial.timestamps.last_activity_at);

  // Deleting the focused site exercises the same atomic snapshot and fallback.
  await page.locator('[data-dashboard-view="table"] [data-site]').first().locator('a[href$="/edit"]').focus();
  await command('empty');
  await refreshed(15000);
  assert.equal(await page.locator('form[data-check]').count(), 0);
  assert.equal(await page.locator('[data-site]').count(), 0);
  assert.equal(await page.locator('.first-site-hint').count(), 1);
  assert.equal(await page.locator('[data-table-add]').isVisible(), true);
  assert.equal(await page.locator('.no-results').isVisible(), false);
  assert.equal(await page.locator('[data-view="table"]').evaluate(item => item === document.activeElement), true);
  for (const value of await page.locator('[data-dashboard-count]').allTextContents()) assert.equal(value, '0');

  // Same-tab navigation must synchronously abort/pause before pagehide.
  const beforeNavigation = stateRequests().length;
  await page.locator('a[href="/sites/new"]').first().click();
  await page.locator('[data-site-form]').waitFor();
  await advance(60000); assert.equal(stateRequests().length, beforeNavigation);
  await page.goBack(); await page.locator('[data-dashboard-controls]').waitFor({state: 'visible'});
  const restored = await page.evaluate(() => window.dashboardLastPageshow);
  assert.equal(restored.trusted, true);
  const resumeStart = stateRequests().length;
  const resumed = page.waitForResponse(response => response.request().method() === 'GET' && response.url() === fixture.base + '/dashboard/state');
  await advance(15000);
  const resumedResponse = await resumed;
  assert.equal(await resumedResponse.finished(), null, 'resumed state response finishes before context closure');
  await resumedResponse.body();
  await drainCaptures();
  assert.ok(stateRequests().length > resumeStart, 'trusted native return resumes saved-state refresh');
  record('polling-browser-saved-results', {commits, initialSites: ids.length, largeSites: complete.length,
    stateRequests: stateRequests().length, realHttp: true, syntheticClock: true,
    syntheticVisibility: true, nativeOfflineEvents: true, restoredPageshow: restored,
    transportFaults: 'deterministic production scheduler/transport Node suite'});
  await closeContext(ctx);
}
