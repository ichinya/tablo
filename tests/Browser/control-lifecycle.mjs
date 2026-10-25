import {performance} from 'node:perf_hooks';

export class ControlDeadlineError extends Error {
  constructor(phase) {
    super(`Browser harness deadline exceeded: ${phase}`);
    this.name = 'ControlDeadlineError';
    this.phase = phase;
  }
}

function deferred() {
  let resolve;
  let reject;
  const promise = new Promise((done, fail) => { resolve = done; reject = fail; });
  return {promise, resolve, reject};
}

// One budget covers setup, route entry and DOM observation. Releasing a held
// request is independent of proving that either observation actually arrived.
export async function pendingNavigation(run, {timeoutMs = 8000} = {}) {
  const reached = deferred();
  const observed = deferred();
  const held = deferred();
  const expired = deferred();
  expired.promise.catch(() => {});
  let active = true;
  let released = false;
  let stage = 'pending-setup';
  let deadlineError;
  let entered = false;
  let observationReceived = false;
  const release = () => {
    if (!released) { released = true; held.resolve(); }
  };
  const timer = setTimeout(() => {
    deadlineError = new ControlDeadlineError(stage);
    release();
    expired.reject(deadlineError);
  }, timeoutMs);
  const wait = (promise, phase) => {
    stage = phase;
    if (!active) return Promise.reject(deadlineError || new Error('Pending phase already settled'));
    return Promise.race([Promise.resolve(promise), expired.promise]);
  };
  const phase = {
    held: held.promise,
    release,
    wait,
    entered() {
      if (!active || entered) return false;
      entered = true; reached.resolve(); return true;
    },
    observe(value) {
      if (!active || observationReceived) return false;
      observationReceived = true; observed.resolve(value); return true;
    },
    async observationAfter(click) {
      // Fulfilled click is not route entry. Rejection remains observable even
      // when it arrives after a deadline; no abandoned rejection is created.
      const clickFailure = new Promise((_, reject) => { Promise.resolve(click).catch(reject); });
      clickFailure.catch(() => {});
      await wait(Promise.race([reached.promise, clickFailure]), 'pending-route-entry');
      return wait(Promise.race([observed.promise, clickFailure]), 'pending-dom-observation');
    },
  };
  const work = Promise.resolve().then(() => run(phase));
  try { return await Promise.race([work, expired.promise]); }
  finally { active = false; clearTimeout(timer); release(); }
}

export function ownDirectChild(child, label) {
  const state = {label, pid: child.pid ?? null, exited: false, closed: false,
    exitCode: null, signal: null, spawnError: null, terminationRequests: [], detached: false};
  const closed = deferred();
  child.once('error', error => { state.spawnError = {name: error.name, code: error.code ?? null}; });
  child.once('exit', (code, signal) => { state.exited = true; state.exitCode = code; state.signal = signal; });
  child.once('close', (code, signal) => {
    state.exited = true; state.closed = true; state.exitCode = code; state.signal = signal;
    closed.resolve(snapshot());
  });
  // An already observed native exit does not prove that stdio/close settled.
  if (child.exitCode !== null && child.exitCode !== undefined) { state.exited = true; state.exitCode = child.exitCode; }
  if (child.signalCode) { state.exited = true; state.signal = child.signalCode; }
  function snapshot() {
    return {...state, terminationRequests: state.terminationRequests.map(item => ({...item})),
      ownership: 'direct-child-only', descendants: 'UNKNOWN'};
  }
  return {
    closed: closed.promise,
    snapshot,
    terminate(signal) {
      if (state.exited || state.closed) return false;
      const request = {signal, returned: false};
      try { request.returned = child.kill(signal); }
      catch (error) { request.error = error.name; }
      state.terminationRequests.push(request);
      return request.returned;
    },
    detach() {
      if (state.closed || state.detached) return;
      state.detached = true;
      // Only descriptors obtained from this owned ChildProcess are touched.
      for (const stream of new Set(child.stdio || [])) {
        if (!stream) continue;
        try { stream.unref?.(); } catch {}
        try { stream.destroy?.(); } catch {}
      }
      try { child.unref(); } catch {}
    },
  };
}

async function within(promise, milliseconds, phase) {
  let timer;
  try {
    return await Promise.race([promise, new Promise((_, reject) => {
      timer = setTimeout(() => reject(new ControlDeadlineError(phase)), Math.max(1, milliseconds));
    })]);
  } finally { clearTimeout(timer); }
}

// The watchdog is independent of awaited cleanup. Its terminal fallback is a
// failing controller bound, never evidence that a child or its descendants died.
export async function settleOwnedCleanup({steps, owners, primary = null, record = () => {}, retain = () => {},
  cleanupMs = 20000, stepMs = 5000, terminationMs = 2000, terminalSlackMs = 1000,
  forceExit = code => process.exit(code)}) {
  let firstFailure = primary;
  const failures = [];
  const pending = new Set();
  let forced = false;
  let evidenceFailed = false;
  const emit = (kind, data) => {
    try { record(kind, data); }
    catch { evidenceFailed = true; firstFailure ||= new Error('Cleanup evidence could not be retained'); }
  };
  const fail = (error, phase) => {
    firstFailure ||= error;
    failures.push({phase, name: error.name, message: String(error.message)});
    emit('cleanup-failure', failures.at(-1));
  };
  const result = () => ({primary: firstFailure, cleanupFailures: failures.map(item => ({...item})),
    children: owners.map(owner => owner.snapshot()), pendingSteps: [...pending],
    complete: !failures.length && !evidenceFailed && !pending.size && owners.every(owner => owner.snapshot().closed),
    descendantClosure: 'UNKNOWN', rendererClosure: 'UNKNOWN'});
  const keep = value => {
    try { retain(value); }
    catch { evidenceFailed = true; firstFailure ||= new Error('Final cleanup evidence could not be retained'); }
  };
  const terminal = phase => {
    if (forced) return;
    forced = true;
    fail(new ControlDeadlineError(phase), phase);
    for (const owner of owners) { owner.terminate('SIGKILL'); if (!owner.snapshot().closed) owner.detach(); }
    const value = result();
    emit('cleanup-unconfirmed', {children: value.children, pendingSteps: value.pendingSteps,
      descendantClosure: 'UNKNOWN', rendererClosure: 'UNKNOWN'});
    keep(value);
    forceExit(1);
  };
  const watchdog = setTimeout(() => terminal('cleanup-terminal-watchdog'), cleanupMs + 2 * terminationMs + terminalSlackMs);
  const deadline = performance.now() + cleanupMs;
  try {
    for (const {label, run} of steps) {
      pending.add(label);
      const work = Promise.resolve().then(run);
      work.then(() => pending.delete(label), () => pending.delete(label));
      try { await within(work, Math.min(stepMs, deadline - performance.now()), label); }
      catch (error) { fail(error, label); }
    }
    const unclosed = () => owners.filter(owner => !owner.snapshot().closed);
    if (unclosed().length) {
      fail(new Error('Direct child did not close cooperatively'), 'direct-child-not-closed');
      for (const owner of unclosed()) owner.terminate('SIGTERM');
      try { await within(Promise.all(unclosed().map(owner => owner.closed)), terminationMs, 'direct-child-termination'); }
      catch (error) { fail(error, 'direct-child-termination'); }
    }
    if (unclosed().length) {
      for (const owner of unclosed()) owner.terminate('SIGKILL');
      try { await within(Promise.all(unclosed().map(owner => owner.closed)), terminationMs, 'direct-child-final-close'); }
      catch (error) { fail(error, 'direct-child-final-close'); }
    }
    const value = result();
    if (pending.size || unclosed().length || evidenceFailed) {
      terminal('cleanup-unconfirmed');
      return result();
    }
    keep(value);
    if (evidenceFailed) terminal('cleanup-evidence-failed');
    return result();
  } finally { clearTimeout(watchdog); }
}
