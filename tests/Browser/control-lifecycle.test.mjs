import test from 'node:test';
import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import {setTimeout as delay} from 'node:timers/promises';
import {pendingNavigation, ownDirectChild, settleOwnedCleanup, ControlDeadlineError} from './control-lifecycle.mjs';

const options = {timeout: 4000};
const never = () => new Promise(() => {});
const tight = {cleanupMs: 180, stepMs: 25, terminationMs: 400, terminalSlackMs: 150};

function blockEventLoop(milliseconds) {
  const until = performance.now() + milliseconds;
  while (performance.now() < until) {} // Deliberately prevent deadline timers from running.
}

test('fulfilled click without a POST fails and releases the held route', options, async () => {
  let phase;
  await assert.rejects(pendingNavigation(control => {
    phase = control;
    return control.observationAfter(Promise.resolve());
  }, {timeoutMs: 25}), error => error instanceof ControlDeadlineError && error.phase === 'pending-route-entry');
  await phase.held;
  assert.equal(phase.entered(), false);
  assert.equal(phase.observe({late: true}), false);
});

test('route entry without the submit callback fails rather than accepting the hold release', options, async () => {
  await assert.rejects(pendingNavigation(phase => {
    phase.entered();
    phase.release();
    return phase.observationAfter(Promise.resolve());
  }, {timeoutMs: 25}), error => error.phase === 'pending-dom-observation');
});

test('route and DOM observation share the original phase budget', options, async () => {
  let phase;
  const entry = setTimeout(() => phase.entered(), 30);
  const observation = setTimeout(() => phase.observe('too late for the original budget'), 100);
  try {
    await assert.rejects(pendingNavigation(control => {
      phase = control;
      return control.observationAfter(Promise.resolve());
    }, {timeoutMs: 80}), ControlDeadlineError);
  } finally { clearTimeout(entry); clearTimeout(observation); }
});

test('click failure remains the original primary and late rejections are consumed', options, async () => {
  const primary = new Error('original click failure');
  await assert.rejects(pendingNavigation(phase => phase.observationAfter(Promise.reject(primary)), {timeoutMs: 50}), error => error === primary);
  let rejectClick;
  const click = new Promise((_, reject) => { rejectClick = reject; });
  await assert.rejects(pendingNavigation(phase => phase.observationAfter(click), {timeoutMs: 20}), ControlDeadlineError);
  rejectClick(new Error('late click rejection'));
  await delay(10); // node:test treats any unhandled rejection as a failure.
});

test('normal observations pass once and release without a late timeout', options, async () => {
  let phase;
  const expected = {before: [true, true], after: [true, true]};
  const result = await pendingNavigation(control => {
    phase = control;
    assert.equal(control.entered(), true);
    assert.equal(control.entered(), false);
    assert.equal(control.observe(expected), true);
    assert.equal(control.observe({wrong: true}), false);
    return control.observationAfter(Promise.resolve());
  }, {timeoutMs: 30});
  assert.equal(result, expected);
  await phase.held;
  assert.equal(phase.observe({late: true}), false);
  await delay(40);
});

test('a late direct phase result cannot beat its delayed deadline timer', options, async () => {
  await assert.rejects(pendingNavigation(() => {
    blockEventLoop(40);
    return 'late direct result';
  }, {timeoutMs: 5}), ControlDeadlineError);
});

test('blocked event loop cannot admit late route entry or DOM observation', options, async () => {
  const admissions = [];
  await assert.rejects(pendingNavigation(phase => {
    blockEventLoop(40);
    admissions.push(phase.entered(), phase.observe('late-observation'));
    return phase.observationAfter(Promise.resolve());
  }, {timeoutMs: 5}), ControlDeadlineError);
  assert.deepEqual(admissions, [false, false]);
});

test('an on-time route does not admit an observation after the shared deadline', options, async () => {
  let lateObservation;
  await assert.rejects(pendingNavigation(phase => {
    assert.equal(phase.entered(), true);
    blockEventLoop(40);
    lateObservation = phase.observe('too late');
    return phase.observationAfter(Promise.resolve());
  }, {timeoutMs: 5}), ControlDeadlineError);
  assert.equal(lateObservation, false);
});

test('already settled observations still need an on-time successful continuation', options, async () => {
  await assert.rejects(pendingNavigation(phase => {
    phase.entered(); phase.observe('on-time admission');
    const observation = phase.observationAfter(Promise.resolve());
    blockEventLoop(40);
    return observation;
  }, {timeoutMs: 5}), ControlDeadlineError);
});

test('a click rejection delayed with the deadline retains its original reason', options, async () => {
  const primary = new Error('click failed before the blocked continuation');
  await assert.rejects(pendingNavigation(phase => {
    phase.entered(); phase.observe('admitted');
    const observation = phase.observationAfter(Promise.reject(primary));
    blockEventLoop(40);
    return observation;
  }, {timeoutMs: 5}), error => error === primary);
});

test('a blocked cleanup step cannot pass its own deadline or erase the primary', options, async () => {
  const primary = new Error('original pending failure');
  const order = [];
  const result = await settleOwnedCleanup({primary, owners: [], cleanupMs: 500, stepMs: 5,
    steps: [{label: 'blocked-step', run: () => blockEventLoop(40)},
      {label: 'later-release', run: () => order.push('later-release')}],
    forceExit: () => assert.fail('Closed cleanup needs no terminal exit')});
  assert.equal(result.primary, primary);
  assert.equal(result.complete, false);
  assert.ok(result.cleanupFailures.some(failure => failure.phase === 'blocked-step' && failure.name === 'ControlDeadlineError'));
  assert.deepEqual(order, ['later-release']);
});

test('slow final evidence cannot turn an expired cleanup budget into COMPLETE', options, async () => {
  const retained = [];
  const result = await settleOwnedCleanup({owners: [], steps: [], cleanupMs: 5, terminationMs: 50, terminalSlackMs: 20,
    retain: value => { retained.push(value); if (retained.length === 1) blockEventLoop(40); },
    forceExit: () => assert.fail('Closed cleanup remains inside the terminal bound')});
  assert.equal(result.complete, false);
  assert.ok(result.primary instanceof ControlDeadlineError);
  assert.equal(result.primary.phase, 'cleanup-budget');
  assert.equal(retained.at(-1).complete, false, 'The corrected failing receipt must be retained');
});

test('a delayed terminal timer cannot be cleared after its absolute controller bound', options, async () => {
  const primary = new Error('original primary before terminal delay');
  const exits = [];
  const result = await settleOwnedCleanup({primary, owners: [], cleanupMs: 5, stepMs: 5, terminationMs: 5, terminalSlackMs: 5,
    steps: [{label: 'blocked-step', run: () => blockEventLoop(40)}], forceExit: code => exits.push(code)});
  assert.equal(result.primary, primary);
  assert.equal(result.complete, false);
  assert.deepEqual(exits, [1]);
  assert.ok(result.cleanupFailures.some(failure => failure.phase === 'cleanup-terminal-watchdog'));
});

test('a stuck cleanup step cannot prevent later cooperative releases or erase the primary', options, async () => {
  const primary = new Error('original pending failure');
  const order = [];
  const retained = [];
  const exits = [];
  const result = await settleOwnedCleanup({primary, owners: [], ...tight,
    steps: [{label: 'stuck-context', run: never}, {label: 'later-php-close', run: () => { order.push('later-php-close'); }}],
    retain: state => retained.push(state), forceExit: code => exits.push(code)});
  assert.deepEqual(order, ['later-php-close']);
  assert.equal(result.primary, primary);
  assert.equal(result.complete, false);
  assert.deepEqual(result.pendingSteps, ['stuck-context']);
  assert.deepEqual(exits, [1]);
  assert.equal(retained.at(-1).primary, primary);
});

async function ownedDummy(t, source) {
  const child = spawn(process.execPath, ['-e', source], {windowsHide: true, stdio: ['pipe', 'pipe', 'pipe']});
  const owner = ownDirectChild(child, 'synthetic direct Node child');
  let text = '';
  child.stdout.setEncoding('utf8'); child.stdout.on('data', data => { text += data; });
  t.after(async () => {
    if (!owner.snapshot().closed) {
      child.kill('SIGKILL');
      await Promise.race([owner.closed, delay(500)]);
      if (!owner.snapshot().closed) owner.detach();
    }
    assert.equal(owner.snapshot().closed, true, 'The test owner must settle its actual child');
  });
  const started = performance.now();
  while (!text.includes('READY')) {
    assert.equal(owner.snapshot().exited, false, 'Dummy must really remain live');
    assert.ok(performance.now() - started < 2000, 'Independent dummy admission bound');
    await delay(10);
  }
  return {child, owner};
}

test('owned native child ignoring graceful close is terminated with its actual close outcome', options, async t => {
  const {child, owner} = await ownedDummy(t, "process.on('SIGTERM',()=>{});process.stdin.resume();console.log('READY');setInterval(()=>{},1000)");
  const primary = new Error('original browser failure');
  const exits = [];
  const retained = [];
  const result = await settleOwnedCleanup({primary, owners: [owner], ...tight,
    steps: [{label: 'cooperative-close', run: () => { child.stdin.end(); return owner.closed; }}],
    retain: state => retained.push(state), forceExit: code => exits.push(code)});
  const state = result.children[0];
  assert.equal(result.primary, primary);
  assert.equal(result.complete, false, 'Forced termination cannot become a successful cleanup');
  assert.equal(state.closed, true);
  assert.equal(state.exited, true);
  assert.ok(Number.isInteger(state.exitCode) || typeof state.signal === 'string', 'Actual native exit/signal must be retained');
  assert.equal(state.exitCode, child.exitCode, 'The receipt must retain the real native exit');
  assert.equal(state.signal, child.signalCode, 'The receipt must retain the real native signal');
  assert.notEqual(state.exitCode, 0, 'Forced termination must not fabricate a successful native exit');
  assert.ok(state.terminationRequests.some(request => request.returned === true));
  assert.equal(state.descendants, 'UNKNOWN');
  assert.deepEqual(exits, []);
  assert.equal(retained.at(-1).children[0].closed, true);
  t.diagnostic(JSON.stringify({pid: state.pid, exitCode: state.exitCode, signal: state.signal, closed: state.closed, descendants: state.descendants}));
});

test('normal cooperative order and actual zero exit remain successful', options, async t => {
  const {child, owner} = await ownedDummy(t, "process.stdin.resume();process.stdin.on('end',()=>process.exit(0));console.log('READY')");
  const order = [];
  const result = await settleOwnedCleanup({owners: [owner], cleanupMs: 1000, stepMs: 800, terminationMs: 200, terminalSlackMs: 200,
    steps: [{label: 'database', run: () => { order.push('database'); }},
      {label: 'browser', run: () => { order.push('browser'); }},
      {label: 'php', run: async () => { order.push('php'); child.stdin.end(); await owner.closed; }}],
    forceExit: () => assert.fail('Normal cleanup must not force controller exit')});
  assert.deepEqual(order, ['database', 'browser', 'php']);
  assert.equal(result.primary, null);
  assert.equal(result.complete, true);
  assert.equal(result.children[0].exitCode, 0);
  assert.deepEqual(result.children[0].terminationRequests, []);
});

test('unconfirmed closure cannot hold the controller event loop or overwrite its original error', options, async t => {
  const helper = new URL('./control-lifecycle.mjs', import.meta.url).href;
  const script = `import fs from 'node:fs';import {EventEmitter} from 'node:events';
    import {ownDirectChild,settleOwnedCleanup} from ${JSON.stringify(helper)};
    const child=new EventEmitter();child.pid=null;child.exitCode=null;child.signalCode=null;
    child.kill=()=>false;child.unref=()=>{};child.stdio=[];
    const owner=ownDirectChild(child,'simulated unconfirmed direct child');
    setInterval(()=>{},1000);
    await settleOwnedCleanup({primary:new Error('original-primary'),owners:[owner],
      steps:[{label:'unconfirmed-close',run:()=>new Promise(()=>{})}],cleanupMs:40,stepMs:10,terminationMs:10,terminalSlackMs:30,
      retain:state=>fs.writeSync(1,JSON.stringify({primary:state.primary.message,complete:state.complete,
        children:state.children,pendingSteps:state.pendingSteps,descendantClosure:state.descendantClosure})+'\\n')});`;
  const child = spawn(process.execPath, ['--input-type=module', '-e', script], {windowsHide: true, stdio: ['ignore', 'pipe', 'pipe']});
  const owner = ownDirectChild(child, 'owned terminal controller control');
  let stdout = ''; let stderr = ''; let expired = false;
  child.stdout.setEncoding('utf8'); child.stderr.setEncoding('utf8');
  child.stdout.on('data', bytes => { stdout += bytes; }); child.stderr.on('data', bytes => { stderr += bytes; });
  const outer = setTimeout(() => { expired = true; child.kill('SIGKILL'); }, 1500);
  try {
    await Promise.race([owner.closed, delay(2200).then(() => { throw new Error('Outer controller close not observed'); })]);
    assert.equal(expired, false, 'An exitCode-only or missing-watchdog mutant must fail this outer owner');
    assert.equal(owner.snapshot().exitCode, 1);
    assert.equal(stderr, '');
    const state = JSON.parse(stdout.trim());
    assert.equal(state.primary, 'original-primary');
    assert.equal(state.complete, false);
    assert.equal(state.children[0].closed, false);
    assert.equal(state.children[0].exitCode, null);
    assert.equal(state.children[0].detached, true);
    assert.equal(state.descendantClosure, 'UNKNOWN');
    t.diagnostic(JSON.stringify({controllerPid: owner.snapshot().pid, nativeExit: owner.snapshot().exitCode, unknownChildClosure: true}));
  } finally {
    clearTimeout(outer);
    if (!owner.snapshot().closed) { child.kill('SIGKILL'); await Promise.race([owner.closed, delay(500)]); }
    if (!owner.snapshot().closed) owner.detach();
  }
});

test('the independent watchdog fires when the bounded-wait mechanism stalls', options, async t => {
  const helper = new URL('./control-lifecycle.mjs', import.meta.url).href;
  const script = `import fs from 'node:fs';import {EventEmitter} from 'node:events';
    import {ownDirectChild,settleOwnedCleanup} from ${JSON.stringify(helper)};
    const child=new EventEmitter();child.pid=null;child.exitCode=null;child.signalCode=null;
    child.kill=()=>false;child.unref=()=>{};child.stdio=[];
    const owner=ownDirectChild(child,'simulated unknown direct child');
    setInterval(()=>{},1000);
    await settleOwnedCleanup({primary:new Error('original-watchdog-primary'),owners:[owner],
      steps:[{label:'stalled-wait',run:()=>new Promise(()=>{})}],
      boundedWait:()=>new Promise(()=>{}), // Supported fault injection: only the independent watchdog can settle this controller.
      cleanupMs:40,stepMs:10,terminationMs:10,terminalSlackMs:30,
      retain:state=>fs.writeSync(1,JSON.stringify({primary:state.primary.message,complete:state.complete,
        phases:state.cleanupFailures.map(failure=>failure.phase),children:state.children,
        pendingSteps:state.pendingSteps,descendantClosure:state.descendantClosure})+'\\n')});`;
  const child = spawn(process.execPath, ['--input-type=module', '-e', script], {windowsHide: true, stdio: ['ignore', 'pipe', 'pipe']});
  const owner = ownDirectChild(child, 'owned independent-watchdog controller');
  let stdout = ''; let stderr = ''; let expired = false;
  child.stdout.setEncoding('utf8'); child.stderr.setEncoding('utf8');
  child.stdout.on('data', bytes => { stdout += bytes; }); child.stderr.on('data', bytes => { stderr += bytes; });
  const outer = setTimeout(() => { expired = true; child.kill('SIGKILL'); }, 1500);
  try {
    await Promise.race([owner.closed, delay(2200).then(() => { throw new Error('Independent watchdog controller close not observed'); })]);
    assert.equal(expired, false, 'Removing only the watchdog must reach the independent outer owner deadline');
    assert.equal(owner.snapshot().exitCode, 1);
    assert.equal(stderr, '');
    const state = JSON.parse(stdout.trim());
    assert.equal(state.primary, 'original-watchdog-primary');
    assert.deepEqual(state.phases, ['cleanup-terminal-watchdog'], 'The actual independent timer must own this terminal path');
    assert.deepEqual(state.pendingSteps, ['stalled-wait']);
    assert.equal(state.complete, false);
    assert.equal(state.children[0].closed, false);
    assert.equal(state.children[0].exitCode, null);
    assert.equal(state.children[0].detached, true);
    assert.equal(state.descendantClosure, 'UNKNOWN');
    t.diagnostic(JSON.stringify({watchdogControllerPid: owner.snapshot().pid, nativeExit: owner.snapshot().exitCode, unknownChildClosure: true}));
  } finally {
    clearTimeout(outer);
    if (!owner.snapshot().closed) { child.kill('SIGKILL'); await Promise.race([owner.closed, delay(500)]); }
    if (!owner.snapshot().closed) owner.detach();
    assert.equal(owner.snapshot().closed, true, 'The outer test owner must settle its controller');
  }
});
