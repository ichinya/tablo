import assert from 'node:assert/strict';

// Real authenticated application pages, using the launcher's existing transport,
// outbound restrictions and closure ownership. Only fixture rows are synthetic.
export async function availabilityScenarios({context, open, closeContext, command, fixture, requests,
  stateRequest, drainCaptures, record, mode, pageReady, directory}) {
  const seeded = await command('availability_seed');
  const ids = seeded.availabilityIds;
  const baseline = await command('availability_state');
  assert.match(baseline.availabilitySnapshot, /^[a-f0-9]{64}$/);
  const requestStart = requests.length;
  const ctx = await context();
  const page = await open(ctx, {clock: true});
  const history = (id, period) => `${fixture.base}/sites/${id}/history${period ? `?period=${period}` : ''}`;
  const counts = async target => Object.fromEntries(await target.locator('[data-availability-count]').evaluateAll(nodes => nodes.map(node => [node.dataset.availabilityCount, Number(node.textContent.trim())])));
  const settled = async target => { await target.locator('#availability-heading').waitFor(); await drainCaptures(); };
  const navigate = async (target, id, period) => {
    const response = await target.goto(history(id, period));
    assert.equal(response.status(), 200, 'history returns the real successful page');
    await settled(target);
  };
  const bounded = async (target, label) => {
    const geometry = await target.evaluate(() => ({width: document.documentElement.scrollWidth, viewport: innerWidth}));
    assert.ok(geometry.width <= geometry.viewport + 1, `${label}: page-wide horizontal overflow`);
    record('availability-document-geometry', {label, ...geometry});
  };
  const bounds = async (target, seconds) => {
    const from = await target.locator('[data-availability-from]').getAttribute('datetime');
    const to = await target.locator('[data-availability-to]').getAttribute('datetime');
    assert.match(from, /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/);
    assert.match(to, /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/);
    assert.equal(Date.parse(to) - Date.parse(from), seconds * 1000, 'exact rolling UTC interval');
    assert.match(await target.locator('.availability-window').textContent(), /включительно.*не включительно/);
    return {from, to};
  };
  const enter = async (view, id, name) => {
    await page.goto(fixture.base); await page.locator('[data-dashboard-controls]').waitFor({state: 'visible'});
    await mode(page, view);
    const link = page.locator(`[data-dashboard-view="${view}"] [data-site-id="${id}"] .history-link`);
    assert.equal(await link.getAttribute('href'), `/sites/${id}/history`);
    assert.equal(await link.getAttribute('aria-label'), `История проверок ${name}`);
    assert.equal(await link.getAttribute('data-check-button'), null);
    assert.equal(await link.evaluate(node => node.closest('form') === null), true, 'history is outside check forms');
    const targetSize = await link.evaluate(node => ({width: node.getBoundingClientRect().width, height: node.getBoundingClientRect().height}));
    assert.ok(targetSize.width >= 44 && targetSize.height >= 44, 'history target is at least 44px');
    await link.focus(); await Promise.all([page.waitForURL(history(id)), page.keyboard.press('Enter')]); await settled(page);
    assert.equal(page.url(), history(id));
  };
  const expectSummary = async (target, state, percentage, expected) => {
    assert.equal(await target.locator('[data-availability-state]').getAttribute('data-availability-state'), state);
    assert.equal((await target.locator('[data-availability-percent]').textContent()).trim(), percentage);
    const observed = await counts(target);
    for (const [key, value] of Object.entries(expected)) assert.equal(observed[key], value, `summary ${key}`);
    return observed;
  };

  await enter('tile', ids.mixed, seeded.availabilityName);
  assert.equal(await page.locator('[data-availability-site]').textContent(), seeded.availabilityName);
  assert.equal(await page.evaluate(() => window.availabilityUnsafe), undefined, 'site name cannot execute');
  assert.equal(await page.locator('script:not([src])').count(), 0);
  const html = await page.locator('html').innerHTML();
  for (const forbidden of ['availability_url_secret', 'availability_health_secret', 'availability_private_diagnostic', 'availability_private_version']) assert.ok(!html.includes(forbidden), `${forbidden} does not enter the history page`);
  const mixedCounts = await expectSummary(page, 'available', '75,0%', {total: 6, online: 3, offline: 1, unknown: 2, known: 4, response: 3, 'without-time': 1, revisions: 2});
  const initialBounds = await bounds(page, 86400);
  const chart = page.locator('[data-availability-chart]');
  assert.equal(await chart.count(), 1);
  assert.equal(await chart.locator('svg[role="img"][aria-labelledby]').count(), 1);
  assert.match(await chart.locator('desc').textContent(), /UTC.*не соединены/);
  assert.equal(await chart.locator('path,polyline,polygon').count(), 0, 'there are no connecting data segments');
  assert.equal(await chart.locator('[data-availability-mean]').count(), 2, 'only timed known buckets have mean markers');
  assert.ok(await chart.locator('[data-availability-gap]').count() > 0, 'gaps have visible text shapes');
  const points = await chart.locator('[data-availability-mean]').evaluateAll(nodes => nodes.map(node => ({index: Number(node.dataset.availabilityMean), x: Number(node.getAttribute('cx')), y: Number(node.getAttribute('cy'))})));
  for (const point of points) assert.ok(Number.isFinite(point.x) && point.x >= 64 && point.x <= 944 && Number.isFinite(point.y) && point.y >= 20 && point.y <= 252);

  const disclosure = page.locator('.availability-details>summary');
  await disclosure.focus(); await page.keyboard.press('Enter');
  assert.equal(await page.locator('.availability-details').getAttribute('open'), '');
  const table = page.locator('[data-availability-table]');
  assert.equal(await table.locator('caption').count(), 1);
  assert.equal(await table.locator('thead th[scope="col"]').count(), 10);
  assert.equal(await table.locator('tbody th[scope="row"]').count(), 120);
  const rows = await table.locator('[data-availability-bucket]').evaluateAll(nodes => nodes.map(node => ({
    index: Number(node.dataset.availabilityBucket),
    counts: Object.fromEntries([...node.querySelectorAll('[data-bucket-count]')].map(cell => [cell.dataset.bucketCount, Number(cell.textContent.trim())])),
    mean: node.querySelector('[data-bucket-mean]').textContent.trim(), range: node.querySelector('[data-bucket-range]').textContent.trim(),
    gap: node.querySelector('[data-bucket-gap]').textContent.trim(), times: [...node.querySelectorAll('time')].map(time => time.dateTime),
  })));
  assert.equal(rows.length, 120);
  for (const key of ['total', 'online', 'offline', 'unknown', 'response', 'without-time']) assert.equal(rows.reduce((sum, row) => sum + row.counts[key], 0), mixedCounts[key], `table conserves ${key}`);
  assert.equal(rows[0].times[0], initialBounds.from); assert.equal(rows.at(-1).times[1], initialBounds.to);
  for (let index = 0; index < rows.length; index++) {
    assert.equal(rows[index].index, index);
    assert.equal(Date.parse(rows[index].times[1]) - Date.parse(rows[index].times[0]), 720000);
    if (index > 0) assert.equal(rows[index - 1].times[1], rows[index].times[0]);
  }
  const simultaneous = rows.find(row => row.counts.total === 3);
  assert.ok(simultaneous, 'same-second observations are all retained');
  assert.deepEqual(simultaneous.counts, {total: 3, online: 1, offline: 1, unknown: 1, 'without-time': 0, response: 2});
  assert.equal(simultaneous.mean, '20,0'); assert.equal(simultaneous.range, '10 — 30'); assert.match(simultaneous.gap, /Unknown: 1/);
  const realZero = rows.find(row => row.counts.response === 1 && row.mean === '0,0');
  assert.ok(realZero, 'real zero timing remains present');
  assert.equal(points.find(point => point.index === realZero.index)?.y, 252, 'zero timing is on the baseline');
  const missing = rows.find(row => row.counts['without-time'] === 1);
  assert.ok(missing); assert.equal(missing.mean, '—'); assert.match(missing.gap, /Нет времени ответа: 1/);
  const empty = rows.find(row => row.counts.total === 0);
  assert.ok(empty); assert.equal(empty.mean, '—'); assert.equal(empty.gap, 'Нет проверок');
  assert.equal(points.some(point => point.index === empty.index || point.index === missing.index), false, 'missing values do not become zero markers');

  for (const [period, seconds, expected, percentage] of [
    ['7d', 604800, {total: 7, online: 4, offline: 1, unknown: 2, known: 5, response: 4}, '80,0%'],
    ['30d', 2592000, {total: 8, online: 4, offline: 2, unknown: 2, known: 6, response: 5}, '66,7%'],
    ['24h', 86400, {total: 6, online: 3, offline: 1, unknown: 2, known: 4, response: 3}, '75,0%'],
  ]) {
    const select = page.getByLabel('Период истории');
    await select.selectOption(period); await select.focus(); await page.keyboard.press('Tab');
    assert.equal(await page.getByRole('button', {name: 'Показать историю'}).evaluate(node => node === document.activeElement), true);
    await Promise.all([page.waitForURL(history(ids.mixed, period)), page.keyboard.press('Enter')]); await settled(page);
    assert.equal(page.url(), history(ids.mixed, period));
    assert.equal(await page.getByLabel('Период истории').inputValue(), period);
    await bounds(page, seconds); await expectSummary(page, 'available', percentage, expected);
  }
  await page.screenshot({path: `${directory}/availability-desktop.png`, fullPage: true});
  await page.setViewportSize({width: 375, height: 812}); await bounded(page, '375px overview');
  await page.screenshot({path: `${directory}/availability-mobile.png`, fullPage: true});
  await page.locator('.availability-details>summary').focus(); await page.keyboard.press('Enter');
  const region = page.getByRole('region', {name: 'Интервалы истории проверок'});
  assert.equal(await region.getAttribute('tabindex'), '0');
  await region.focus(); await page.keyboard.press('ArrowRight');
  await page.waitForFunction(() => document.querySelector('.availability-table-region').scrollLeft > 0);
  await bounded(page, '375px expanded table');
  await region.screenshot({path: `${directory}/availability-mobile-table.png`});
  const beforeHistoryWait = requests.length;
  await page.clock.runFor(60000); await drainCaptures();
  assert.equal(requests.slice(beforeHistoryWait).filter(stateRequest).length, 0, 'history has no dashboard polling');

  await page.setViewportSize({width: 1440, height: 1050});
  await enter('table', ids.mixed, seeded.availabilityName);
  await expectSummary(page, 'available', '75,0%', {total: 6});
  await enter('table', ids.paused, 'История на паузе');
  assert.match(await page.locator('.availability-paused').textContent(), /Сейчас на паузе.*Длительность прошлых пауз неизвестна/s);
  await expectSummary(page, 'available', '50,0%', {total: 2, online: 1, offline: 1, known: 2, response: 2});
  await navigate(page, ids.empty);
  await expectSummary(page, 'none', '—', {total: 0, online: 0, offline: 0, known: 0, unknown: 0});
  assert.equal(await page.locator('.availability-empty').count(), 1);
  assert.equal(await page.locator('[data-availability-chart],[data-availability-table]').count(), 0);
  await navigate(page, ids.single);
  await expectSummary(page, 'insufficient', '—', {total: 1, online: 1, known: 1, response: 1});
  assert.match(await page.locator('.availability-explanation').textContent(), /Недостаточно/);
  assert.equal(await page.locator('[data-availability-mean]').count(), 1);
  assert.equal(await page.locator('[data-availability-mean]').getAttribute('cy'), '252');
  await navigate(page, ids.unknown);
  await expectSummary(page, 'none', '—', {total: 2, unknown: 2, known: 0, response: 0});
  assert.equal(await page.locator('[data-availability-no-timing]').count(), 1);
  assert.equal(await page.locator('[data-availability-chart]').count(), 0);
  assert.equal(await page.locator('[data-availability-bucket]').count(), 120);
  await navigate(page, ids.overflow);
  assert.equal(await page.locator('[data-availability-overflow]').count(), 1);
  assert.match(await page.locator('[data-availability-overflow]').textContent(), /12 000.*короткий период/s);
  assert.equal(await page.locator('[data-availability-chart],[data-availability-table],[data-availability-percent],[data-availability-state]').count(), 0, 'overflow exposes no partial analytics');
  assert.equal(await page.locator('[data-availability-overflow] a').count(), 3);
  await closeContext(ctx);

  const noJs = await context({javaScriptEnabled: false, viewport: {width: 375, height: 812}});
  const fallback = await noJs.newPage(); await pageReady.get(fallback); await fallback.goto(fixture.base);
  assert.equal(await fallback.locator('[data-dashboard-controls]').isVisible(), false);
  await fallback.locator(`[data-dashboard-view="tile"] .history-link[href="/sites/${ids.mixed}/history"]`).click();
  await settled(fallback); await expectSummary(fallback, 'available', '75,0%', {total: 6});
  await fallback.getByLabel('Период истории').selectOption('7d');
  await fallback.getByRole('button', {name: 'Показать историю'}).click(); await settled(fallback);
  assert.equal(fallback.url(), history(ids.mixed, '7d'));
  await expectSummary(fallback, 'available', '80,0%', {total: 7});
  await fallback.locator('.availability-details>summary').focus(); await fallback.keyboard.press('Enter');
  assert.equal(await fallback.locator('.availability-details').getAttribute('open'), '');
  assert.equal(await fallback.locator('[data-availability-bucket]').count(), 120);
  await bounded(fallback, '375px JavaScript-disabled history');
  await closeContext(noJs);

  const after = await command('availability_state');
  assert.equal(after.availabilitySnapshot, baseline.availabilitySnapshot, 'history GETs preserve sites/history/incidents/notification/worker rows');
  assert.deepEqual(after.counts, baseline.counts);
  assert.equal(after.peerCount, baseline.peerCount, 'history navigation never checks a provider');
  assert.equal(requests.slice(requestStart).filter(request => request.method !== 'GET').length, 0, 'history scenarios submit only GETs');
  record('availability-browser-results', {realHttp: true, syntheticFixtureRows: true, syntheticPageClock: true,
    mixedCounts, bucketRows: rows.length, sameSecondBucket: simultaneous, realZeroBucket: realZero,
    meanMarkers: points.length, periodControls: ['24h', '7d', '30d'], entryModes: ['tile', 'table', 'paused'],
    states: ['mixed', 'empty', 'single', 'unknown', 'overflow'], mobileWidth: 375,
    javascriptDisabled: true, persistentSnapshotUnchanged: true, peerRequestsUnchanged: true});
}
