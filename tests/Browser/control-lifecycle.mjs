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
  const deadline = performance.now() + timeoutMs;
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
  const timeout = () => {
    deadlineError ||= new ControlDeadlineError(stage);
    release();
    return deadlineError;
  };
  const pastDeadline = () => performance.now() >= deadline;
  const timer = setTimeout(() => expired.reject(timeout()), timeoutMs);
  const wait = (promise, phase) => {
    stage = phase;
    if (!active) return Promise.reject(deadlineError || new Error('Pending phase already settled'));
    return Promise.race([Promise.resolve(promise), expired.promise]).then(value => {
      // Timers cannot run during a blocked event loop. A settled promise must
      // still meet the original monotonic deadline before its value is used.
      if (pastDeadline()) throw timeout();
      return value;
    });
  };
  const phase = {
    held: held.promise,
    release,
    wait,
    entered() {
      if (!active || entered) return false;
      if (pastDeadline()) { timeout(); return false; }
      entered = true; reached.resolve(); return true;
    },
    observe(value) {
      if (!active || observationReceived) return false;
      if (pastDeadline()) { timeout(); return false; }
      observationReceived = true; observed.resolve(value); return true;
    },
    async observationAfter(click) {
      // Fulfilled click is not route entry. Rejection remains observable even
      // when it arrives after a deadline; no abandoned rejection is created.
      let clickRejected = false;
      let clickError;
      const clickFailure = new Promise((_, reject) => {
        Promise.resolve(click).catch(error => {
          clickRejected = true; clickError = error; reject(error);
        });
      });
      clickFailure.catch(() => {});
      try {
        await wait(Promise.race([reached.promise, clickFailure]), 'pending-route-entry');
        return await wait(Promise.race([observed.promise, clickFailure]), 'pending-dom-observation');
      } catch (error) {
        // A rejected click remains the primary failure even if delivery of its
        // rejection and the deadline check share a delayed microtask turn.
        throw clickRejected ? clickError : error;
      }
    },
  };
  const work = Promise.resolve().then(() => run(phase));
  try {
    const value = await Promise.race([work, expired.promise]);
    if (pastDeadline()) throw timeout();
    return value;
  }
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

async function within(promise, deadline, phase) {
  let timer;
  try {
    const value = await Promise.race([promise, new Promise((_, reject) => {
      timer = setTimeout(() => reject(new ControlDeadlineError(phase)), Math.max(1, deadline - performance.now()));
    })]);
    if (performance.now() >= deadline) throw new ControlDeadlineError(phase);
    return value;
  } finally { clearTimeout(timer); }
}

// The watchdog is independent of awaited cleanup. Its terminal fallback is a
// failing controller bound, never evidence that a child or its descendants died.
export async function settleOwnedCleanup({steps, owners, primary = null, record = () => {}, retain = () => {},
  cleanupMs = 20000, stepMs = 5000, terminationMs = 2000, terminalSlackMs = 1000,
  forceExit = code => process.exit(code), boundedWait = within}) {
  const started = performance.now();
  const deadline = started + cleanupMs;
  const terminalDeadline = deadline + 2 * terminationMs + terminalSlackMs;
  let firstFailure = primary;
  const failures = [];
  const pending = new Set();
  let forced = false;
  let evidenceFailed = false;
  let budgetExceeded = false;
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
    complete: !forced && performance.now() < deadline && !failures.length && !evidenceFailed && !pending.size && owners.every(owner => owner.snapshot().closed),
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
  const checkFinalBudget = () => {
    let changed = false;
    if (performance.now() >= deadline && !budgetExceeded) {
      budgetExceeded = true;
      fail(new ControlDeadlineError('cleanup-budget'), 'cleanup-budget');
      changed = true;
    }
    if (performance.now() >= terminalDeadline && !forced) {
      terminal('cleanup-terminal-watchdog');
      changed = true;
    }
    return changed;
  };
  const watchdog = setTimeout(() => terminal('cleanup-terminal-watchdog'), Math.max(1, terminalDeadline - performance.now()));
  try {
    for (const {label, run} of steps) {
      pending.add(label);
      const work = Promise.resolve().then(run);
      work.then(() => pending.delete(label), () => pending.delete(label));
      // The separate watchdog also owns failures of the bounded-wait mechanism.
      try { await boundedWait(work, Math.min(performance.now() + stepMs, deadline), label); }
      catch (error) { fail(error, label); }
    }
    const unclosed = () => owners.filter(owner => !owner.snapshot().closed);
    if (unclosed().length) {
      fail(new Error('Direct child did not close cooperatively'), 'direct-child-not-closed');
      for (const owner of unclosed()) owner.terminate('SIGTERM');
      try { await boundedWait(Promise.all(unclosed().map(owner => owner.closed)), performance.now() + terminationMs, 'direct-child-termination'); }
      catch (error) { fail(error, 'direct-child-termination'); }
    }
    if (unclosed().length) {
      for (const owner of unclosed()) owner.terminate('SIGKILL');
      try { await boundedWait(Promise.all(unclosed().map(owner => owner.closed)), performance.now() + terminationMs, 'direct-child-final-close'); }
      catch (error) { fail(error, 'direct-child-final-close'); }
    }
    checkFinalBudget();
    if (pending.size || unclosed().length || evidenceFailed) {
      terminal('cleanup-unconfirmed');
      return result();
    }
    keep(result());
    // Synchronous evidence callbacks can also delay the timer. Preserve a
    // corrected failing receipt before accepting the final controller result.
    if (checkFinalBudget()) keep(result());
    if (evidenceFailed) terminal('cleanup-evidence-failed');
    checkFinalBudget();
    return result();
  } finally { clearTimeout(watchdog); }
}
