'use strict';
// Synthetic bytes through the production parser/projector; no native execution.
const test = require('node:test'), assert = require('node:assert/strict');
const { LinuxRecords, DENIAL_FIELDS, NATIVE_FIRST_FAILURE_SITES, nativeFirstFailureSite } = require('./server-supervisor.cjs');
const { projectFailure } = require('./server-qualification-diagnostic.cjs');
const ready = 'READY 7 tgid-seccomp-ptrace-x64-v2 123\nSTARTED 8\n';
const input = 'INPUT 0 0 0 1\n', exited = 'EXITED 8 0 0 0\n';
const before = ready + input + exited;
const record = values => 'DENIALS ' + values.join(' ') + '\n';
const closed = (denied, failed = denied > 0 ? 1 : 0) => `CLOSED 0 0 2 2 1 0 ${denied} 1 7 9 0 1 ${failed} 0 0\n`;
function parse(bytes, fragmented = false) {
  const parser = new LinuxRecords(), seen = [];
  if (fragmented) for (const byte of Buffer.from(bytes)) parser.push(Buffer.from([byte]), (kind, values) => seen.push({ kind, values }));
  else parser.push(Buffer.from(bytes), (kind, values) => seen.push({ kind, values }));
  return { parser, seen };
}
function failure(parser, denied = 5) {
  return projectFailure({ stage: 'CLIENT_RUNTIME', subject: 'node', index: 0, check: 'Q101', completed: 30,
    owner: { nativeDenialDiagnostic: parser.denialDiagnostic, nativeFirstFailureSite: parser.nativeFirstFailureSite, cancelReason: null,
      adapterClose: { code: 125, signal: null, closeObserved: true, spawnError: false, protocolFailed: false, stderrBytes: 0, stdoutTrailingBytes: 0 },
      adapterState: { exited: { exitCode: 0, posixSignal: null, rootExitObserved: true, terminationRequested: false },
        closed: { scopeObservedEmpty: true, custodySettled: true, inputSettled: true, stdinClosed: true,
          stdout: { closed: true }, stderr: { closed: true }, admission: { deniedAdmissions: denied, failed: true, waitEchild: true } } } } });
}
for (let index = 0; index < 13; index++) test('native denial production parser/projector bucket ' + DENIAL_FIELDS[index], () => {
  const values = Array(15).fill(0); values[index] = 5; values[index >= 9 && index <= 11 ? 14 : 13] = 5;
  const { parser, seen } = parse(before + record(values) + closed(5), true);
  assert.equal(seen.length, 6); assert.equal(parser.pending.length, 0);
  assert.deepEqual(Object.keys(parser.denialDiagnostic), DENIAL_FIELDS);
  assert.equal(parser.denialDiagnostic[DENIAL_FIELDS[index]], 5);
  assert.deepEqual(failure(parser).nativeDenialDiagnostic, parser.denialDiagnostic);
  const projected = failure(parser);
  assert.equal(projected.root.exitCode, 0); assert.equal(projected.adapter.exitCode, 125);
  assert.equal(projected.admission.failed, true); assert.equal(projected.closure.custodySettled, true);
  assert.equal(projected.qualified, false); assert.equal(projected.status, 'failed'); assert.equal(projected.rootCause, 'UNKNOWN');
});

const sites = ['UNKNOWN','INTERRUPT','RESERVE','HARD_END','COMMAND_CHANNEL','COMMAND_LIMIT','COMMAND_LAUNCH','COMMAND_PARSE',
  'OWNER_CANCEL','WAIT_RESULT','WAIT_REGISTRY','EARLY_STOP','WAIT_EVENT','SIGNAL_INFO','RESUME','SUPPRESS_REGS','GATE_METADATA',
  'GATE_NOT_LAUNCHED','COUNTER_BOUND','POLICY_DENIAL','EXIT_METADATA','EXIT_URING_ACCOUNTING','EXIT_GETREGS','EXIT_SETREGS',
  'EXIT_BIRTH_ACCOUNTING','BIRTH_METADATA','BIRTH_TASK','BIRTH_RESERVATION','GROUP_ADMISSION','EXEC_METADATA','EXEC_ACCOUNTING',
  'TERMINAL_ACCOUNTING','TERMINAL_SIGSYS','ROOT_DESCENDANTS','INPUT_READ','INPUT_WRITE','INPUT_EOF','EVENT_WRITE','CANCEL_SIGNAL'];
test('native first-failure IDs remain finite and EXITED authority receives exactly the original four values', () => {
  assert.deepEqual(NATIVE_FIRST_FAILURE_SITES, sites);
  for (let id = 0; id < sites.length; id++) for (const fragmented of [false, true]) {
    const { parser, seen } = parse(ready + `EXITED 8 0 0 0 ${id}\n` + input + closed(0), fragmented);
    assert.equal(parser.nativeFirstFailureSite, sites[id]);
    assert.deepEqual(seen.find(row => row.kind === 'EXITED').values, [8, 0, 0, 0]);
    assert.equal(failure(parser, 0).nativeFirstFailureSite, sites[id]);
    assert.equal(failure(parser, 0).schema, 'tablo/linux-safe-qualification-failure/v4');
    assert.equal(failure(parser, 0).qualified, false); assert.equal(failure(parser, 0).rootCause, 'UNKNOWN');
  }
  for (const bytes of [before, before + closed(0)]) assert.equal(parse(bytes, true).parser.nativeFirstFailureSite, 'UNKNOWN');
});
test('accepted EXITED publishes only after the callback and retains its site without CLOSED', () => {
  const parser = new LinuxRecords(); let callback = false;
  parser.push(Buffer.from(ready + 'EXITED 8 -1 9 1 34\n'), (kind, values) => {
    if (kind === 'EXITED') { callback = true; assert.equal(parser.nativeFirstFailureSite, 'UNKNOWN'); assert.deepEqual(values, [8,-1,9,1]); }
  });
  assert(callback); assert.equal(parser.nativeFirstFailureSite, 'INPUT_READ'); assert.equal(parser.closed, false);
  assert.equal(failure(parser).nativeFirstFailureSite, 'INPUT_READ'); assert.equal(failure(parser).nativeDenialDiagnostic, 'UNKNOWN');
  const rejected = new LinuxRecords(), primary = Error('PRIVATE');
  assert.throws(() => rejected.push(Buffer.from('EXITED 8 0 0 0 34\n'), () => { throw primary; }), error => error === primary);
  assert.equal(rejected.nativeFirstFailureSite, 'UNKNOWN');
});
test('native first-failure parser rejects arity, spelling and IDs and protocol UNKNOWN stays sticky', () => {
  const invalid = ['',' 1 2',' -1',' 39',' 2147483647',' 2147483648',' 00',' 01',' -0',' +1',' 1.0',' 1e0',' NaN',' UNKNOWN',' PRIVATE', ' 1\r', ' 1\u0080'];
  for (const tail of invalid.filter(value => value !== '')) {
    const parser = new LinuxRecords();
    assert.throws(() => parser.push(Buffer.from('EXITED 8 0 0 0' + tail + '\n'), () => {}), /LINUX_/);
    assert.equal(parser.nativeFirstFailureSite, 'UNKNOWN');
  }
  for (const values of ['8 0 0','8 0','8','']) assert.throws(() => parse('EXITED ' + values + '\n'), /LINUX_/);
  const parser = parse(ready + 'EXITED 8 0 0 0 34\n').parser;
  assert.equal(parser.nativeFirstFailureSite, 'INPUT_READ');
  assert.throws(() => parser.push(Buffer.from('BOGUS 1\n'), () => {}), /LINUX_/);
  parser.push(Buffer.from('EXITED 8 0 0 0 38\n'), () => {});
  assert.equal(parser.nativeFirstFailureSite, 'UNKNOWN');
  for (const bytes of [Buffer.alloc(8193), Buffer.from('STARTED 8\n'.repeat(9)), Buffer.from('EXITED ' + '0'.repeat(513) + '\n')]) {
    const bounded = new LinuxRecords(); assert.throws(() => bounded.push(bytes, () => {}), /LINUX_/); assert.equal(bounded.nativeFirstFailureSite, 'UNKNOWN');
  }
});
test('native first-failure projection rejects hostile values without conversion or extra payload', () => {
  const callbacks = [], hostile = { toString() { callbacks.push('convert'); return 'INPUT_READ'; }, toJSON() { callbacks.push('serialize'); return 'PRIVATE'; } };
  for (const value of [null, undefined, 0, 34, -1, [], {}, hostile, new String('INPUT_READ'), 'PRIVATE', 'input_read', 'INPUT_READ\n']) {
    assert.equal(nativeFirstFailureSite(value), 'UNKNOWN');
    const result = failure({ nativeFirstFailureSite: value }, 0);
    assert.equal(result.nativeFirstFailureSite, 'UNKNOWN'); assert(!JSON.stringify(result).includes('PRIVATE')); assert.equal(result.qualified, false);
  }
  assert.deepEqual(callbacks, []);
  const result = failure({ nativeFirstFailureSite: 'EXIT_URING_ACCOUNTING' }, 0);
  assert.equal(result.nativeFirstFailureSite, 'EXIT_URING_ACCOUNTING'); assert(Buffer.byteLength(JSON.stringify(result, null, 2) + '\n') <= 4096);
});

// The production owner runs only against these closed in-memory seams. No native
// executable, filesystem mutation, socket, real timer or external process exists.
async function nativeOwner(text, options = {}) {
  const fs = require('node:fs'), vm = require('node:vm'), path = require('node:path');
  const { EventEmitter } = require('node:events');
  const crypto = require('node:crypto'), source = fs.readFileSync(__dirname + '/server-supervisor.cjs', 'utf8');
  const effects = [], trip = name => { effects.push(name); throw Error('FORBIDDEN_' + name); };
  const files = new Map(), root = '/synthetic/phase', hash = 'a'.repeat(64), commands = [], signals = [], captures = [];
  const closedObject = target => new Proxy(target, { get(object, key) { if (!Object.hasOwn(object, key)) return trip(String(key)); return object[key]; } });
  const child = new EventEmitter(); child.pid = 7;
  child.stdin = new EventEmitter(); child.stdout = new EventEmitter(); child.stderr = new EventEmitter();
  child.stdin.destroyed = false; child.stdin.write = bytes => commands.push(bytes); child.stdin.end = () => commands.push('END');
  child.kill = signal => signals.push(signal); child.unref = () => captures.push('unref');
  child.stdout.destroy = () => captures.push('stdout-destroy'); child.stderr.destroy = () => captures.push('stderr-destroy');
  const filesystem = closedObject({
    writeFile: async (file, bytes) => { assert(file.startsWith(root + '/')); assert(!files.has(file)); files.set(file, Buffer.from(bytes)); },
    open: async (file, mode) => {
      assert(file.startsWith(root + '/')); if (mode === 'wx') { assert(!files.has(file)); files.set(file, Buffer.alloc(0)); } else assert(files.has(file));
      return closedObject({ then: undefined, fd: file.endsWith('stdout') ? 3 : file.endsWith('stderr') ? 4 : 5,
        stat: async () => ({ isFile: () => true }), sync: async () => captures.push('sync'), close: async () => captures.push('close') });
    }, stat: async file => { assert(files.has(file)); return { size: files.get(file).length }; }
  });
  const module = { exports: {} }, sandbox = { module, exports: module.exports, Buffer, Error, TypeError, RangeError, SyntaxError,
    __filename: '/synthetic/owner.cjs', process: closedObject({ platform: 'linux', arch: 'x64', hrtime: { bigint: () => 1000n } }),
    require(name) {
      if (name === 'node:fs/promises') return filesystem;
      if (name === 'node:fs') return closedObject({ createReadStream: () => trip('STREAM') });
      if (name === 'node:crypto') return crypto;
      if (name === 'node:path') return path.posix;
      if (name === 'node:util') return require(name);
      if (name === 'node:net') return closedObject({});
      if (name === 'node:child_process') return closedObject({ spawn: () => {
        setImmediate(() => {
          for (const bytes of options.fragmented ? [...Buffer.from(text)].map(byte => Buffer.from([byte])) : [Buffer.from(text)]) child.stdout.emit('data', bytes);
          if (options.stderrOverflow) child.stderr.emit('data', Buffer.alloc(65537));
          if (options.stdinError) child.stdin.emit('error', Error('PRIVATE'));
          if (options.spawnError) child.emit('error', Error('PRIVATE'));
          if (options.externalProtocol) owner.cancel('protocol');
          if (options.afterFaultText) child.stdout.emit('data', Buffer.from(options.afterFaultText));
          if (options.expire) { owner.cancel('deadline'); owner.expire(); } else child.emit('close', options.closeCode ?? 125, null);
        }); return child;
      } });
      return trip('IMPORT_' + name);
    } };
  sandbox.require.main = {};
  vm.runInNewContext(source + `\nObject.assign(module.exports, { observerFailure, latchObserverFailure, emitObserverFailure,
    testSeams() { retain=async(file,value)=>{ if (!file.startsWith('${root}/')) throw Error('FOREIGN'); };
      digest=async()=> '${hash}'; pinned=async()=>{}; regular=async file=>file; } });`, sandbox, { filename: sandbox.__filename });
  const api = module.exports; api.testSeams();
  const pin = { path: '/synthetic/native', sha256: hash };
  const plan = { schema: 'tablo/server-observer-linux-plan/v1', nonce: 'a'.repeat(32), planId: 'synthetic', adapter: pin,
    linux: { policy: 'tgid-seccomp-ptrace-x64-v2', unit: 'process/TGID', deadlineNanoseconds: '8000000000', ownerMonotonicNanoseconds: '1', source: pin, recipe: pin, build: pin },
    job: { activeProcessLimit: 8 }, launch: { executable: pin, argv: [], cwd: '/synthetic', environment: {},
      input: { mode: 'none', path: null, sha256: null, bytes: 0 }, stdout: root + '/evidence/controller.stdout', stderr: root + '/evidence/controller.stderr' } };
  const owner = { phase: { root, kind: 'engine', adapterAuthority: Buffer.alloc(32), diagnostic: { state: null, close: null, nativeFirstFailureSite: 'POLICY_DENIAL' } },
    nativeFirstFailureSite: 'POLICY_DENIAL', cancelReason: null,
    cancel(reason) { if (!this.cancelReason) this.cancelReason = reason; } };
  const state = await api.runLinuxAdapter(owner, plan);
  const failure = api.observerFailure(); failure.stage = 'custody'; failure.phase = 'engine';
  api.latchObserverFailure(failure, new Error('PRIVATE'), owner);
  assert.deepEqual(effects, []);
  const authenticated = files.get(root + '/evidence/adapter-authenticated.ndjson').toString('utf8').trim().split('\n').filter(Boolean)
    .map(line => JSON.parse(Buffer.from(JSON.parse(line).payload, 'base64').toString('utf8')));
  return { api, owner, plan, state, failure: failure.first, authenticated, commands, signals, captures };
}
test('actual native owner retains accepted EXITED site after missing CLOSED or owner expiry and emits no extra authority', async () => {
  for (const expire of [false, true]) {
    const h = await nativeOwner(ready + input + 'EXITED 8 0 0 0 34\n', { expire, fragmented: true });
    assert.equal(h.owner.nativeFirstFailureSite, 'INPUT_READ'); assert.equal(h.failure.nativeFirstFailureSite, 'INPUT_READ');
    assert.equal(h.failure.schema, 'tablo/server-safe-observer-failure/v5'); assert.equal(h.state.closed, null);
    assert.equal(h.owner.nativeDenialDiagnostic, 'UNKNOWN'); assert.equal(h.owner.adapterClose.closeObserved, !expire);
    assert.equal(h.owner.adapterClose.nativeProtocol, 'private-inherited-pipes/v3');
    assert.equal(projectFailure({owner:h.owner}).nativeFirstFailureSite, 'INPUT_READ');
    assert.deepEqual(Object.keys(h.state.exited), ['planId','controllerPid','rootExitObserved','exitCode','posixSignal','terminationRequested']);
    assert(h.authenticated.every(value => !Object.hasOwn(value, 'nativeFirstFailureSite')));
    assert.equal(h.failure.rootCause, 'UNKNOWN'); assert.equal(h.failure.issueAccepted, false); assert(!JSON.stringify(h.failure).includes('PRIVATE'));
    assert(Buffer.byteLength(JSON.stringify(h.failure, null, 2)) <= 4096);
  }
  const complete = await nativeOwner(ready + input + 'EXITED 8 0 0 0 34\n' + closed(0));
  assert(complete.state.closed); assert.equal(complete.owner.nativeFirstFailureSite, 'INPUT_READ');
  assert(!JSON.stringify(complete.state.closed.admission).includes('nativeFirstFailureSite'));
});
test('actual native owner refuses rejected EXITED and any protocol fault, and fresh adapters inherit no site', async () => {
  for (const [text, options] of [[ready + input + 'EXITED 9 0 0 0 34\n', {}],
    [ready + input + 'EXITED 8 256 0 0 34\n', {}], [ready + input + 'EXITED 8 0 0 2 34\n', {}],
    [ready + input + 'EXITED 8 0 0 0 34\nBOGUS 1\n', {}], [ready + input + 'EXITED 8 0 0 0 34\nPARTIAL', {}],
    [ready + input + 'EXITED 8 0 0 0 34\nEXITED 8 0 0 0 38\n', {}],
    [ready + input + 'EXITED 8 0 0 0 34\n', {stdinError:true}], [ready + input + 'EXITED 8 0 0 0 34\n', {stderrOverflow:true}],
    [ready + input + 'EXITED 8 0 0 0 34\n', {externalProtocol:true}], [ready, {}], [before, {}], ['', {}]]) {
    const h = await nativeOwner(text, options);
    assert.equal(h.owner.nativeFirstFailureSite, 'UNKNOWN'); assert.equal(h.failure.nativeFirstFailureSite, 'UNKNOWN');
    assert.equal(projectFailure({owner:h.owner}).nativeFirstFailureSite, 'UNKNOWN'); assert.equal(h.state.closed, null);
  }
});
test('observer independently validates every finite site and cannot inherit an older phase or serialize hostile values', async () => {
  const h = await nativeOwner(ready + input + 'EXITED 8 0 0 0 34\n');
  const callbacks = [], hostile = {toString(){callbacks.push('convert');return 'INPUT_READ';},toJSON(){callbacks.push('serialize');return 'PRIVATE';}};
  for (const value of [...sites, null, undefined, 34, {}, [], hostile, new String('INPUT_READ'), 'PRIVATE']) {
    h.owner.phase.diagnostic.nativeFirstFailureSite = value;
    const first = h.api.observerFailure(); first.stage = 'custody'; first.phase = 'engine';
    h.api.latchObserverFailure(first, Error('PRIVATE'), h.owner);
    assert.equal(first.first.nativeFirstFailureSite, sites.includes(value) ? value : 'UNKNOWN');
    assert(!JSON.stringify(first.first).includes('PRIVATE'));
    h.owner.phase.diagnostic.nativeFirstFailureSite = 'CANCEL_SIGNAL';
    h.api.latchObserverFailure(first, Error('PRIVATE later'), h.owner);
    assert.equal(first.first.nativeFirstFailureSite, sites.includes(value) ? value : 'UNKNOWN');
  }
  assert.deepEqual(callbacks, []);
  h.owner.phase = { kind:'baseline', diagnostic:{state:null,close:null} };
  assert.equal(h.owner.nativeFirstFailureSite, 'INPUT_READ');
  const fresh = h.api.observerFailure(); h.api.latchObserverFailure(fresh, Error('PRIVATE'), h.owner);
  assert.equal(fresh.first.nativeFirstFailureSite, 'UNKNOWN');
});
test('diagnostic protocol faults preserve original native authority flags, closure behavior and custody refusals', async () => {
  const prefix = ready + input + 'EXITED 8 0 0 0 34\n', complete = prefix + closed(0);
  const nominal = await nativeOwner(complete, {closeCode:0});
  Object.assign(nominal.owner, {plan:nominal.plan,control:true,leases:{active:new Set()}});
  await nominal.api.verifyCustody(nominal.owner, nominal.state);
  assert.equal(nominal.owner.adapterClose.protocolFailed, false); assert(nominal.state.closed);
  for (const [text, options, expected] of [
    [complete, {stdinError:true}, {closed:true,cancel:'protocol',stderr:0,spawn:false,trailing:0,protocol:false}],
    [complete, {spawnError:true}, {closed:true,cancel:'protocol',stderr:0,spawn:true,trailing:0,protocol:false}],
    [complete, {stderrOverflow:true}, {closed:true,cancel:'protocol',stderr:65536,spawn:false,trailing:0,protocol:false}],
    [complete, {externalProtocol:true}, {closed:true,cancel:'protocol',stderr:0,spawn:false,trailing:0,protocol:false}],
    [complete+'PARTIAL', {}, {closed:false,cancel:null,stderr:0,spawn:false,trailing:7,protocol:false}],
    [complete+'BOGUS 1\n', {}, {closed:false,cancel:'protocol',stderr:0,spawn:false,trailing:0,protocol:true}],
    [prefix, {stdinError:true,afterFaultText:closed(0)}, {closed:true,cancel:'protocol',stderr:0,spawn:false,trailing:0,protocol:false}],
  ]) {
    const h = await nativeOwner(text, {...options,closeCode:0});
    Object.assign(h.owner, {plan:h.plan,control:true,leases:{active:new Set()}});
    assert.equal(h.owner.adapterClose.protocolFailed, expected.protocol);
    assert.equal(h.owner.adapterClose.spawnError, expected.spawn); assert.equal(h.owner.adapterClose.stderrBytes, expected.stderr);
    assert.equal(h.owner.adapterClose.stdoutTrailingBytes, expected.trailing); assert.equal(h.owner.cancelReason, expected.cancel);
    assert.equal(h.state.closed !== null, expected.closed); assert.equal(h.owner.adapterClose.closeObserved, true);
    assert.equal(h.owner.nativeFirstFailureSite, 'UNKNOWN'); assert.equal(h.failure.nativeFirstFailureSite, 'UNKNOWN');
    await assert.rejects(h.api.verifyCustody(h.owner, h.state), /OBSERVER_CLOSURE_UNKNOWN/);
  }
});
test('mixed decisions, fewer completed writes and either INPUT/EXITED order are valid', () => {
  const values = Array(13).fill(1).concat([7, 2]);
  for (const prefix of [before, ready + exited + input]) {
    const { parser } = parse(prefix + record(values) + closed(13));
    assert.equal(parser.denialDiagnostic.epermRegisterWrites, 7);
    assert.equal(parser.denialDiagnostic.eagainRegisterWrites, 2);
    assert.equal(failure(parser, 13).qualified, false);
  }
});
test('absent diagnostics, zero denials and incomplete observations remain UNKNOWN', () => {
  for (const bytes of [before + closed(0), before + closed(5), before + record([5, ...Array(12).fill(0), 0, 0])]) {
    assert.equal(failure(parse(bytes).parser).nativeDenialDiagnostic, 'UNKNOWN');
  }
  const parser = parse(before + closed(0)).parser;
  assert.equal(failure(parser, 0).qualified, false);
});
test('production parser rejects diagnostic arity, spelling, bounds, ordering, duplication and inconsistent totals', () => {
  const good = [5, ...Array(12).fill(0), 5, 0], line = record(good);
  const invalidValues = [Array(15).fill(0), good.slice(1), good.concat(0), [-1, ...good.slice(1)],
    [2147483648, ...good.slice(1)], ['00', ...good.slice(1)], ['1.5', ...good.slice(1)],
    ['1e0', ...good.slice(1)], ['+5', ...good.slice(1)], [5, ...Array(12).fill(0), 6, 0],
    [5, ...Array(12).fill(0), 0, 1], [2147483647, 1, ...Array(11).fill(0), 0, 0]];
  const bad = invalidValues.map(values => before + record(values) + closed(5));
  bad.push(before + line + closed(4), before + line + closed(0), before + line + line + closed(5),
    line + before + closed(5), ready + input + line + exited + closed(5), ready + exited + line + input + closed(5),
    before + line + input + closed(5), before + closed(5) + line, before + line + closed(5) + line,
    before + line + closed(5) + closed(5),
    before + line.replace('DENIALS', 'DENIAL') + closed(5), before + line.replace('5', 'PRIVATE_ENV_ARGV_PATH') + closed(5),
    before + line.replace('\n', '\r\n') + closed(5), before + line.replace('5', '\u0080') + closed(5),
    before + line.replace('5', '0'.repeat(513)) + closed(5));
  for (const bytes of bad) {
    const parser = new LinuxRecords(); assert.throws(() => parser.push(Buffer.from(bytes), () => {}), /LINUX_/);
    assert.equal(parser.denialDiagnostic, 'UNKNOWN');
  }
});
test('fixed existing native budgets stay finite across chunks and public projection stays within4096 bytes', () => {
  const parser = new LinuxRecords(); assert.throws(() => parser.push(Buffer.alloc(8193), () => {}), /LIMIT/);
  assert.throws(() => parse('STARTED 8\n'.repeat(9)), /ENCODING/);
  const cumulative = new LinuxRecords();
  for (let i = 0; i < 8; i++) cumulative.push(Buffer.from('STARTED 8\n'), () => {});
  assert.throws(() => cumulative.push(Buffer.alloc(8192), () => {}), /LIMIT/);
  const maximal = [2147483647, ...Array(12).fill(0), 2147483647, 0];
  assert.equal(Buffer.byteLength(record(Array(15).fill(2147483647))), 173);
  const projected = failure(parse(before + record(maximal) + closed(2147483647)).parser, 2147483647);
  for (const key of Object.keys(projected.admission)) if (typeof projected.admission[key] === 'number' || projected.admission[key] === 'UNKNOWN') projected.admission[key] = 2147483647;
  assert.ok(Buffer.byteLength(JSON.stringify(projected, null, 2) + '\n') <= 4096);
  const share = Math.floor(2147483647 / 13), mixedMax = Array(13).fill(share).concat([10 * share, 3 * share]);
  const maximum = failure(parse(before + record(mixedMax) + closed(13 * share)).parser, 13 * share);
  maximum.stage = 'NAMESPACE_PROBE'; maximum.subject = 'unresponsive-input'; maximum.index = 31;
  for (const group of ['admission', 'invocation', 'adapter', 'root']) {
    for (const key of Object.keys(maximum[group])) if (maximum[group][key] === 'UNKNOWN' || typeof maximum[group][key] === 'number') maximum[group][key] = 2147483647;
  }
  assert.ok(Buffer.byteLength(JSON.stringify(maximum, null, 2) + '\n') <= 4096);
});
test('projector rejects hostile diagnostics, extra fields and mismatched original admission', () => {
  const values = [5, ...Array(12).fill(0), 5, 0], parsed = parse(before + record(values) + closed(5)).parser;
  assert.equal(failure(parsed, 4).nativeDenialDiagnostic, 'UNKNOWN');
  for (const value of ['PRIVATE_ENV_ARGV_PATH', Array(15).fill(5), { ...parsed.denialDiagnostic, secret: 'PRIVATE_ENV_ARGV_PATH' },
    { ...parsed.denialDiagnostic, ptrace: 'PRIVATE_ENV_ARGV_PATH' }, { ...parsed.denialDiagnostic, ptrace: -1 },
    { ...parsed.denialDiagnostic, epermRegisterWrites: 6 }]) {
    const result = failure({ denialDiagnostic: value });
    assert.equal(result.nativeDenialDiagnostic, 'UNKNOWN');
    assert.ok(!JSON.stringify(result).includes('PRIVATE_ENV_ARGV_PATH')); assert.equal(result.qualified, false);
  }
});
