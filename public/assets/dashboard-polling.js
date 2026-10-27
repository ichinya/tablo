// Kept independent of the DOM so timing and transport failures can be exercised
// with the same implementation in deterministic Node tests.
globalThis.TabloDashboardPolling = (() => {
  const interval = 15000;
  const deadline = 10000;
  const maximumBytes = 2 * 1024 * 1024;
  class ProtocolError extends Error {}
  const aborted = () => new DOMException('Dashboard request aborted', 'AbortError');
  const plain = value => value !== null && typeof value === 'object' && !Array.isArray(value);
  const exactKeys = (value, keys) => plain(value) && Object.keys(value).length === keys.length
    && keys.every(key => Object.hasOwn(value, key));

  function envelope(value, csrf) {
    if (!exactKeys(value, ['dashboard_version', 'csrf', 'stats', 'html'])
      || value.dashboard_version !== 1 || value.csrf !== csrf || typeof value.html !== 'string'
      || !exactKeys(value.stats, ['total', 'online', 'attention', 'paused'])
      || Object.values(value.stats).some(count => !Number.isSafeInteger(count) || count < 0)
      || ['online', 'attention', 'paused'].some(key => value.stats[key] > value.stats.total)) {
      throw new ProtocolError('Incompatible dashboard snapshot');
    }
    return value;
  }

  async function readJSON(response, signal) {
    if (!response.body || typeof response.body.getReader !== 'function') throw new ProtocolError('Missing dashboard body');
    const reader = response.body.getReader();
    const chunks = [];
    let length = 0;
    try {
      while (true) {
        if (signal.aborted) throw aborted();
        const {done, value} = await reader.read();
        if (signal.aborted) throw aborted();
        if (done) break;
        if (!(value instanceof Uint8Array)) throw new ProtocolError('Invalid dashboard body');
        length += value.byteLength;
        if (length > maximumBytes) {
          // Cancellation is best effort; an uncooperative stream cannot delay
          // the already final size rejection or create an unhandled rejection.
          try { Promise.resolve(reader.cancel()).catch(() => {}); } catch {}
          throw new ProtocolError('Dashboard snapshot is too large');
        }
        chunks.push(value);
      }
      const bytes = new Uint8Array(length);
      let offset = 0;
      for (const chunk of chunks) { bytes.set(chunk, offset); offset += chunk.byteLength; }
      try { return JSON.parse(new TextDecoder('utf-8', {fatal: true}).decode(bytes)); }
      catch { throw new ProtocolError('Invalid dashboard JSON'); }
    } finally { reader.releaseLock(); }
  }

  function transport({url, csrf, fetch: fetchRequest = globalThis.fetch}) {
    return async signal => {
      const response = await fetchRequest(url, {method: 'GET', credentials: 'same-origin', cache: 'no-store',
        redirect: 'manual', headers: {Accept: 'application/json'}, signal});
      if (signal.aborted) throw aborted();
      if (response.type === 'opaqueredirect' || response.redirected || (response.status >= 300 && response.status < 400)
        || (response.url && response.url !== url) || [401, 403, 413].includes(response.status)) {
        throw new ProtocolError('Dashboard session or response changed');
      }
      if (!/^application\/json(?:\s*;|\s*$)/i.test(response.headers.get('content-type') || '')) {
        throw new ProtocolError('Unexpected dashboard content type');
      }
      if (!response.ok) throw new Error('Dashboard temporarily unavailable');
      return envelope(await readJSON(response, signal), csrf);
    };
  }

  function create({request, commit, onState = () => {}, now = () => performance.now(),
    setTimer = (fn, delay) => setTimeout(fn, delay), clearTimer = timer => clearTimeout(timer), available = true}) {
    let timer;
    let active;
    let epoch = 0;
    let stopped = false;
    let failures = 0;
    let nextEligible = now() + interval;
    const clear = () => { if (timer !== undefined) clearTimer(timer); timer = undefined; };
    const schedule = () => {
      if (stopped || !available || active || timer !== undefined) return;
      timer = setTimer(() => { timer = undefined; void run(); }, Math.max(0, nextEligible - now()));
    };
    const run = async () => {
      if (stopped || !available || active) return;
      const operation = {controller: new AbortController(), epoch, timedOut: false, expires: now() + deadline};
      active = operation;
      onState('loading');
      timer = setTimer(() => {
        timer = undefined;
        operation.timedOut = true;
        operation.controller.abort();
      }, deadline);
      try {
        const result = await request(operation.controller.signal);
        if (operation.timedOut || now() >= operation.expires) { operation.controller.abort(); throw aborted(); }
        if (stopped || !available || operation.epoch !== epoch || operation.controller.signal.aborted) return;
        commit(result);
        failures = 0;
        onState('current');
      } catch (error) {
        if (stopped || operation.epoch !== epoch || !available) return;
        if (error instanceof ProtocolError) {
          stopped = true;
          ++epoch;
          operation.controller.abort();
          onState('stopped');
        } else {
          ++failures;
          onState('retrying');
        }
      } finally {
        clear();
        operation.controller.abort();
        if (active === operation) active = undefined;
        const delay = failures ? Math.min(120000, 30000 * 2 ** Math.min(failures - 1, 2)) : interval;
        nextEligible = Math.max(nextEligible, now() + delay);
        schedule();
      }
    };
    schedule();
    onState('waiting');
    return {
      setAvailable(value) {
        if (stopped || available === value) return;
        available = value;
        if (!value) {
          ++epoch;
          clear();
          active?.controller.abort();
        } else schedule();
      },
      stop() { stopped = true; ++epoch; clear(); active?.controller.abort(); },
      // No response data or credentials are exposed by this diagnostic state.
      state: () => ({active: Boolean(active), scheduled: timer !== undefined, stopped, failures, nextEligible}),
    };
  }
  return {create, transport, ProtocolError};
})();
