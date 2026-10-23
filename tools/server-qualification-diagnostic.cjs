'use strict';

// Public failure projection only. Never serialize the input object or exception.
const STAGES = ['SETUP', 'NAMESPACE_PROBE', 'NEUTRAL_CONTROL', 'INPUT_BOUNDARY', 'FALLBACK_CONTROL', 'TRACER_WITNESS', 'CLIENT_RUNTIME', 'PUBLICATION'];
const SUBJECTS = ['namespace', 'limit8', 'limit9', 'overflow8', 'overflow9', 'threads', 'threadfork', 'threadexec',
  'concurrent', 'vfork', 'spawn', 'short', 'failedfork', 'clone3', 'untraced', 'filter', 'ptrace', 'x32', 'i386',
  'stdin0', 'stdin65536', 'exit23', 'signal', 'rootfirst', 'owner-eof', 'deadline', 'partial-input',
  'unresponsive-input', 'invalid-capture', 'witness', 'node', 'php', 'composer', 'shell', 'docker',
  'uring-handled', 'uring-repeat', 'uring-arguments', 'uring-threads', 'uring-forkexec', 'uring-mixed-security', 'uring-mixed-quota', 'uring-unhandled'];
const SIGNALS = ['SIGABRT', 'SIGALRM', 'SIGBUS', 'SIGCHLD', 'SIGCONT', 'SIGFPE', 'SIGHUP', 'SIGILL', 'SIGINT',
  'SIGKILL', 'SIGPIPE', 'SIGQUIT', 'SIGSEGV', 'SIGSTOP', 'SIGSYS', 'SIGTERM', 'SIGTRAP', 'SIGTSTP', 'SIGTTIN',
  'SIGTTOU', 'SIGURG', 'SIGUSR1', 'SIGUSR2', 'SIGXCPU', 'SIGXFSZ'];
const UNKNOWN = 'UNKNOWN';
// Independent public enum validation; never copy native bytes, IDs or exception data.
const NATIVE_FIRST_FAILURE_SITES = Object.freeze(['UNKNOWN', 'INTERRUPT', 'RESERVE', 'HARD_END', 'COMMAND_CHANNEL',
  'COMMAND_LIMIT', 'COMMAND_LAUNCH', 'COMMAND_PARSE', 'OWNER_CANCEL', 'WAIT_RESULT', 'WAIT_REGISTRY', 'EARLY_STOP',
  'WAIT_EVENT', 'SIGNAL_INFO', 'RESUME', 'SUPPRESS_REGS', 'GATE_METADATA', 'GATE_NOT_LAUNCHED', 'COUNTER_BOUND',
  'POLICY_DENIAL', 'EXIT_METADATA', 'EXIT_URING_ACCOUNTING', 'EXIT_GETREGS', 'EXIT_SETREGS', 'EXIT_BIRTH_ACCOUNTING',
  'BIRTH_METADATA', 'BIRTH_TASK', 'BIRTH_RESERVATION', 'GROUP_ADMISSION', 'EXEC_METADATA', 'EXEC_ACCOUNTING',
  'TERMINAL_ACCOUNTING', 'TERMINAL_SIGSYS', 'ROOT_DESCENDANTS', 'INPUT_READ', 'INPUT_WRITE', 'INPUT_EOF', 'EVENT_WRITE', 'CANCEL_SIGNAL']);
const CHECK_IDS = Object.freeze(Array.from({ length: 149 }, (_, index) => 'Q' + String(index + 1).padStart(3, '0')));
const enumeration = (value, values) => values.includes(value) ? value : UNKNOWN;
const number = (value, min = 0, max = 2147483647) => Number.isSafeInteger(value) && value >= min && value <= max ? value : UNKNOWN;
const boolean = value => typeof value === 'boolean' ? value : UNKNOWN;
const nullable = (value, project) => value === null ? null : project(value);
const { DENIAL_FIELDS, linuxDenialDiagnostic } = require('./server-supervisor.cjs');
function projectDenials(value, deniedCount) {
  try {
    if (!value || typeof value !== 'object' || Array.isArray(value)
        || Object.keys(value).length !== DENIAL_FIELDS.length || !DENIAL_FIELDS.every(key => Object.hasOwn(value, key))) return UNKNOWN;
    return linuxDenialDiagnostic(DENIAL_FIELDS.map(key => value[key]), deniedCount);
  } catch { return UNKNOWN; }
}

function eventPresence(events) {
  const present = name => !events || typeof events !== 'object' || Array.isArray(events) || !Object.hasOwn(events,name) ? UNKNOWN
    : events[name] === null ? false : events[name] && typeof events[name] === 'object' && !Array.isArray(events[name]) ? true : UNKNOWN;
  return Object.freeze(Object.fromEntries(['ready','started','input','exited','closed'].map(name=>[name,present(name)])));
}
function cancelOwner(owner, reason) {
  if (owner.cancelReason) return;
  owner.cancelReason = reason;
  try { owner.eventsAtCancellation = eventPresence(owner.adapterState); } catch { /* Cancellation remains authoritative. */ }
  owner.send?.('cancel', {reason});
}
function cancellationPresence(value) {
  const keys=['ready','started','input','exited','closed'];
  return value && typeof value === 'object' && !Array.isArray(value) && Object.keys(value).join(',') === keys.join(',')
    && keys.every(key=>[true,false,UNKNOWN].includes(value[key])) ? Object.freeze(Object.fromEntries(keys.map(key=>[key,value[key]]))) : eventPresence();
}

function projectFailure(input) {
  const owner = input?.owner, events = owner?.adapterState;
  const invocation = input?.nativeStatus;
  const close = owner?.adapterClose ?? (input?.stage === 'INPUT_BOUNDARY' ? invocation : undefined);
  const root = events?.exited, closed = events?.closed, admission = closed?.admission;
  return {
    schema: 'tablo/linux-safe-qualification-failure/v4', status: 'failed', qualified: false, rootCause: UNKNOWN,
    stage: enumeration(input?.stage, STAGES), subject: enumeration(input?.subject, SUBJECTS),
    index: number(input?.index, 0, 31), checkId: enumeration(input?.check, CHECK_IDS), completed: number(input?.completed, 0, 38),
    adapter: { exitCode: nullable(close?.code, value => number(value, -1, 255)),
      signal: nullable(close?.signal, value => enumeration(value, SIGNALS)), closeObserved: boolean(close?.closeObserved),
      spawnError: boolean(close?.spawnError), protocolFailed: boolean(close?.protocolFailed),
      stderrBytes: number(close?.stderrBytes, 0, 65536), stdoutTrailingBytes: number(close?.stdoutTrailingBytes, 0, 8192) },
    invocation: { exitCode: nullable(invocation?.code, value => number(value, -1, 255)),
      signal: nullable(invocation?.signal, value => enumeration(value, SIGNALS)), closeObserved: boolean(invocation?.closeObserved),
      spawnError: boolean(invocation?.spawnError), deadlineExpired: boolean(invocation?.deadlineExpired),
      outputOverflow: boolean(invocation?.outputOverflow), protocolFaultCount: number(invocation?.protocolFaultCount, 0, 65536) },
    root: { exitCode: nullable(root?.exitCode, value => number(value, 0, 255)),
      signal: nullable(root?.posixSignal, value => number(value, 1, 64)), exitObserved: boolean(root?.rootExitObserved),
      terminationRequested: boolean(root?.terminationRequested) },
    cancellation: nullable(owner?.cancelReason, value => enumeration(value, ['deadline', 'protocol'])),
    eventsAtFailure: eventPresence(events), eventsAtCancellation: cancellationPresence(owner?.eventsAtCancellation),
    nativeDenialDiagnostic: projectDenials(owner?.nativeDenialDiagnostic, admission?.deniedAdmissions),
    nativeFirstFailureSite: owner?.cancelReason === 'protocol' || close?.protocolFailed === true ? UNKNOWN
      : enumeration(owner?.nativeFirstFailureSite, NATIVE_FIRST_FAILURE_SITES),
    closure: { scopeObservedEmpty: boolean(closed?.scopeObservedEmpty), custodySettled: boolean(closed?.custodySettled),
      inputSettled: boolean(closed?.inputSettled), stdinClosed: boolean(closed?.stdinClosed),
      stdoutClosed: boolean(closed?.stdout?.closed), stderrClosed: boolean(closed?.stderr?.closed) },
    admission: Object.fromEntries(['configuredLimit', 'liveTgids', 'pendingReservations', 'peakCommitted', 'processBirths',
      'processReservations', 'failedCreations', 'deniedAdmissions', 'clone3Fallbacks', 'threadBirths', 'terminalWaits',
      'execRetiredIdentities'].map(key => [key, number(admission?.[key])]).concat([
      ['waitEchild', boolean(admission?.waitEchild)], ['failed', boolean(admission?.failed)],
      ['ioUringSetupAttempts', number(admission?.ioUringSetupAttempts)], ['ioUringSetupEpermWrites', number(admission?.ioUringSetupEpermWrites)] ]))
  };
}

function registerTests() {
  const test = require('node:test'), assert = require('node:assert/strict');
  test('qualification failure projection rejects hostile strings and extra private payload', () => {
    const hostile = 'PRIVATE_ENV_ARGV_CREDENTIAL_EXCEPTION_STACK_NATIVE_DIAGNOSTIC';
    const payload = { stage: hostile, subject: hostile, index: hostile, check: hostile, completed: hostile,
      owner: { cancelReason: hostile, adapterClose: { code: hostile, signal: hostile, closeObserved: hostile,
        spawnError: hostile, protocolFailed: hostile, stderrBytes: hostile, stdoutTrailingBytes: hostile, stack: hostile },
      adapterState: { exited: { exitCode: hostile, posixSignal: hostile, rootExitObserved: hostile, terminationRequested: hostile },
        closed: { scopeObservedEmpty: hostile, custodySettled: hostile, inputSettled: hostile, stdinClosed: hostile,
          stdout: { closed: hostile, path: hostile }, stderr: { closed: hostile, bytes: hostile },
          admission: Object.fromEntries(['configuredLimit','liveTgids','pendingReservations','peakCommitted','processBirths',
            'processReservations','failedCreations','deniedAdmissions','clone3Fallbacks','threadBirths','terminalWaits',
            'execRetiredIdentities','waitEchild','failed'].map(key => [key, hostile])) } } },
      status: 'qualified', qualified: true, exception: hostile, env: hostile, argv: [hostile], privateFiles: [hostile] };
    payload.nativeStatus = Object.fromEntries(['code', 'signal', 'closeObserved', 'spawnError', 'deadlineExpired', 'outputOverflow', 'protocolFaultCount'].map(key => [key, hostile]));
    const result = projectFailure(payload);
    assert(!JSON.stringify(result).includes(hostile));
    assert.equal(result.stage, 'UNKNOWN'); assert.equal(result.checkId, 'UNKNOWN');
    assert.equal(result.adapter.exitCode, 'UNKNOWN'); assert.equal(result.root.signal, 'UNKNOWN');
    assert.equal(result.status, 'failed'); assert.equal(result.qualified, false);
    assert(!('exception' in result)); assert(!('argv' in result));
    assert(Buffer.byteLength(JSON.stringify(result)) < 4096);
  });
  test('qualification failure retains bounded observed facts and never promotes zero exits', () => {
    const result = projectFailure({ stage: 'NEUTRAL_CONTROL', subject: 'namespace', index: 15, check: 'Q001', completed: 15,
      owner: { cancelReason: null, adapterClose: { code: 125, signal: null, closeObserved: true, spawnError: false,
        protocolFailed: false, stderrBytes: 0, stdoutTrailingBytes: 0 }, adapterState: {
        exited: { exitCode: 0, posixSignal: null, rootExitObserved: true, terminationRequested: false },
        closed: { scopeObservedEmpty: true, custodySettled: true, inputSettled: true, stdinClosed: true,
          stdout: { closed: true }, stderr: { closed: true }, admission: { failed: true, deniedAdmissions: 1,
            waitEchild: true, liveTgids: 0, pendingReservations: 0, peakCommitted: 8 } } } } }, ['Q001']);
    assert.equal(result.checkId, 'Q001'); assert.equal(result.index, 15); assert.equal(result.adapter.exitCode, 125);
    assert.equal(result.root.exitCode, 0); assert.equal(result.root.signal, null); assert.equal(result.cancellation, null);
    assert.equal(result.admission.failed, true); assert.equal(result.admission.deniedAdmissions, 1);
    assert.equal(result.closure.scopeObservedEmpty, true); assert.equal(result.admission.processBirths, 'UNKNOWN');
    assert.equal(result.rootCause, 'UNKNOWN'); assert.equal(result.status, 'failed'); assert.equal(result.qualified, false);
    const zero = projectFailure({ owner: { adapterClose: { code: 0 } } }, ['Q001']);
    assert.equal(zero.status, 'failed'); assert.equal(zero.qualified, false); assert.equal(zero.stage, 'UNKNOWN');
  });
  test('qualification failure projection bounds all numbers and preserves unknown observations', () => {
    for (const value of [-2, 256, NaN, Infinity, 1.5, '0', {}, []]) {
      const result = projectFailure({ index: value, completed: value, owner: { adapterClose: { code: value },
        adapterState: { exited: { exitCode: value, posixSignal: value }, closed: { admission: { liveTgids: value } } } } }, []);
      assert.equal(result.adapter.exitCode, 'UNKNOWN'); assert.equal(result.root.exitCode, 'UNKNOWN');
      assert.equal(result.index, 'UNKNOWN'); assert.equal(result.completed, 'UNKNOWN');
    }
    const absent = projectFailure({}, []);
    assert.equal(absent.adapter.exitCode, 'UNKNOWN'); assert.equal(absent.adapter.signal, 'UNKNOWN');
    assert.equal(absent.root.exitObserved, 'UNKNOWN'); assert.equal(absent.cancellation, 'UNKNOWN');
    assert.equal(projectFailure({ owner: { adapterState: { closed: { admission: { liveTgids: 2147483648 } } } } }, []).admission.liveTgids, 'UNKNOWN');
  });
  test('diagnostic assertion tracking preserves the exact thrown failure and its check identity', () => {
    const { checked } = require('./server-supervisor-linux-qualify.cjs');
    const progress = { stage: 'CLIENT_RUNTIME', subject: 'node', index: 0, completed: 30 };
    let original;
    try { assert.equal(125, 0); } catch (error) { original = error; }
    assert.throws(() => checked(progress, 'Q100', () => { throw original; }), error => error === original);
    const result = projectFailure(progress);
    assert.equal(result.checkId, 'Q100'); assert.equal(result.status, 'failed'); assert.equal(result.qualified, false);
    assert.equal(checked(progress, 'Q101', assert.equal, 0, 0), undefined);
    assert.equal(projectFailure(progress).status, 'failed');
  });
}

module.exports = { projectFailure, eventPresence, cancelOwner };
if (require.main === module) {
  if (process.argv.length === 3 && process.argv[2] === '--self-test') registerTests();
  else { process.stderr.write('QUALIFICATION_DIAGNOSTIC_ARGUMENTS_REFUSED\n'); process.exitCode = 1; }
}
