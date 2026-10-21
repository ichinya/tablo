'use strict';
// Actual pinned owners, synthetic bytes and a closed in-memory filesystem only.
const test = require('node:test'), assert = require('node:assert/strict');
const fs = require('node:fs'), vm = require('node:vm'), path = require('node:path');
const { createHash, createHmac } = require('node:crypto');
const { EventEmitter } = require('node:events');
const { LinuxRecords, acceptEvent } = require('./server-supervisor.cjs');
const { projectFailure, eventPresence, cancelOwner } = require('./server-qualification-diagnostic.cjs');
const sha = value => createHash('sha256').update(value).digest('hex');
const policy = 'tgid-seccomp-ptrace-x64-v2';
const names = ['uring-handled','uring-repeat','uring-arguments','uring-threads','uring-forkexec','uring-mixed-security','uring-mixed-quota','uring-unhandled'];
const source = fs.readFileSync(__dirname + '/server-supervisor.cjs', 'utf8');
const qualifierSource = fs.readFileSync(__dirname + '/server-supervisor-linux-qualify.cjs', 'utf8');
const prefix = 'READY 7 ' + policy + ' 123\nSTARTED 8\nINPUT 0 0 0 1\nEXITED 8 0 0 0\n';
const closed = (d, i, w, failed = 0) => `CLOSED 0 0 1 1 0 0 ${d} 0 0 1 0 1 ${failed} ${i} ${w}\n`;
function parse(text, fragment = false) {
  const records = new LinuxRecords(), seen = [];
  const chunks = fragment ? [...Buffer.from(text)].map(byte => Buffer.from([byte])) : [Buffer.from(text)];
  for (const chunk of chunks) records.push(chunk, (kind, values) => seen.push({ kind, values }));
  return { records, seen };
}
function admission(d = 0, i = d, w = i, failed = false, limit = 8, births = 1, threads = 0) {
  return { configuredLimit: limit, liveTgids: 0, pendingReservations: 0, peakCommitted: births, processBirths: births,
    processReservations: births - 1, failedCreations: 0, deniedAdmissions: d, clone3Fallbacks: 0, threadBirths: threads,
    terminalWaits: births + threads, execRetiredIdentities: 0, waitEchild: true, failed, ioUringSetupAttempts: i, ioUringSetupEpermWrites: w };
}
function eventSet(a, root, marker, files, code = 0, signal = null, cancelled = false) {
  const capture = name => {
    const file = root + '/evidence/controller.' + name, bytes = Buffer.from(name === 'stdout' ? marker : '');
    files.set(file, bytes); return { path: file, bytes: bytes.length, sha256: sha(bytes), closed: true };
  };
  return { planId: 'synthetic', ready: { policy, helperSha256: null, sourceSha256: null }, started: { controllerPid: 8 },
    input: { mode: 'none', declaredBytes: 0, writtenBytes: 0, settled: true, stdinClosed: true, errorCode: null, sourceEofObserved: true },
    exited: { controllerPid: 8, rootExitObserved: true, exitCode: code, posixSignal: signal, terminationRequested: cancelled },
    closed: { planId: 'synthetic', controllerPid: 8, rootExitObserved: true, scopeObservedEmpty: true, inputSettled: true,
      stdinClosed: true, stdout: capture('stdout'), stderr: capture('stderr'), custodySettled: true,
      descendantStatuses: 'TERMINAL_WAIT_REGISTRY', admission: a }, failed: null, notStarted: null };
}
const nativeClose = (code = 0) => ({ code, signal: null, closeObserved: true, spawnError: false, protocolFailed: false,
  stdoutTrailingBytes: 0, stderrBytes: 0, nativeProtocol: 'private-inherited-pipes/v2' });
function ownerModule(files, transport = null) {
  const effects = [];
  const tripwire = effect => { effects.push(effect); throw Error('SANDBOX_EFFECT ' + effect); };
  const directories = new Set(['/']);
  const refresh = () => { for (const file of files.keys()) { let dir = path.posix.dirname(file); while (dir !== '/') { directories.add(dir); dir = path.posix.dirname(dir); } } };
  const bytes = file => { if (!files.has(file)) tripwire('UNEXPECTED_MEMORY_FILE ' + file); return files.get(file); };
  const metadata = file => {
    refresh(); if (!files.has(file) && !directories.has(file)) tripwire('UNEXPECTED_MEMORY_METADATA ' + file);
    return { size: files.has(file) ? bytes(file).length : 0, isFile: () => files.has(file), isDirectory: () => directories.has(file), isSymbolicLink: () => false };
  };
  const context = { kernel: 'synthetic-kernel', arch: 'x64', uid: 1000, gid: 1000, mountNamespace: 'mnt:synthetic', userNamespace: 'user:synthetic', pidNamespace: 'pid:synthetic' };
  const filesystem = new Proxy({ lstat: async file => metadata(file), stat: async file => metadata(file),
    readFile: async (file, encoding) => encoding ? bytes(file).toString(encoding) : Buffer.from(bytes(file)),
    readlink: async file => {
      const links = { '/proc/self/ns/mnt': context.mountNamespace, '/proc/self/ns/user': context.userNamespace, '/proc/self/ns/pid': context.pidNamespace };
      if (!Object.hasOwn(links, file)) tripwire('UNEXPECTED_MEMORY_LINK'); return links[file];
    } }, { get(target, key) { if (!(key in target)) tripwire('UNEXPECTED_FS_EFFECT ' + String(key)); return target[key]; } });
  const module = { exports: {} };
  const sandbox = { module, exports: module.exports, __filename: '/synthetic/source/tools/server-supervisor.cjs', __dirname: '/synthetic/source/tools', Buffer,
    process: new Proxy({ platform: 'linux', arch: 'x64', version: 'v24.13.0', hrtime: process.hrtime, getuid: () => 1000, getgid: () => 1000 },
      { get(target,key) { if (!(key in target)) tripwire('UNEXPECTED_PROCESS_ACCESS ' + String(key)); return target[key]; } }),
    require(name) {
      if (name === 'node:fs/promises') return filesystem;
      if (name === 'node:fs') return { createReadStream: file => ({ async *[Symbol.asyncIterator]() { yield Buffer.from(bytes(file)); } }) };
      if (name === 'node:path') return path.posix;
      if (name === 'node:os') return { release: () => context.kernel };
      if (name === 'node:child_process') return { spawn: () => tripwire('FORBIDDEN_SPAWN') };
      if (name === 'node:net') return transport ?? new Proxy({}, { get() { tripwire('FORBIDDEN_NETWORK'); } });
      if (['node:crypto','node:util'].includes(name)) return require(name);
      tripwire('UNEXPECTED_IMPORT ' + name);
    } };
  vm.runInNewContext(source + '\nObject.assign(module.exports, { observerFailure, latchObserverFailure, listen, controllerRequest, prepareDiagnostic, PREPARE_STAGES, PREPARE_CLASSES, PREPARE_SOURCES });', sandbox, { filename: sandbox.__filename });
  return { api: module.exports, context, effects };
}
function receiptFixture() {
  const files = new Map(), put = (file, value) => { const bytes = Buffer.isBuffer(value) ? value : Buffer.from(typeof value === 'string' ? value : JSON.stringify(value)); files.set(file, bytes); return { path: file, sha256: sha(bytes) }; };
  const nonce = 'a'.repeat(32), root = '/synthetic/cell/tablo-linux-' + nonce, sourceRoot = '/synthetic/source/tools';
  const owner = put(sourceRoot + '/server-supervisor.cjs', source), qualifier = put(sourceRoot + '/server-supervisor-linux-qualify.cjs', qualifierSource);
  const controlsSource = put(sourceRoot + '/server-supervisor-linux-controls.c', fs.readFileSync(__dirname + '/server-supervisor-linux-controls.c'));
  const binary = put('/synthetic/compiler/server-supervisor-linux', 'synthetic-new-policy-binary');
  const controls = put('/synthetic/compiler/server-supervisor-linux-controls', 'synthetic-controls-binary');
  const cSource = put(sourceRoot + '/server-supervisor-linux.c', fs.readFileSync(__dirname + '/server-supervisor-linux.c'));
  const recipe = put(sourceRoot + '/server-supervisor-linux-build.sh', fs.readFileSync(__dirname + '/server-supervisor-linux-build.sh'));
  const hash = sha('synthetic-compiler'), build = { schema: 'tablo/linux-adapter-build/v1', policy, target: 'linux-x86_64',
    sourceSha256: cSource.sha256, recipeSha256: recipe.sha256, binarySha256: binary.sha256, compilerImageDigest: 'sha256:' + hash,
    compilerSha256: hash, linkerSha256: hash, libcArchiveSha256: hash };
  const buildPin = put('/synthetic/compiler/build.json', build);
  const runtimes = Object.fromEntries(['node','php','composer','shell','docker'].map(name => [name, put('/synthetic/tools/' + name, 'synthetic-runtime-' + name)]));
  const ini = put('/synthetic/tools/php.ini', '[PHP]\n'), phpBootstrap = { iniFiles: [ini], extensionFiles: [] };
  const prepared = put('/synthetic/cell/prepared-platform.json', { schema: 'tablo/linux-prepared-platform/v1', nonce, kind: 'host' });
  const platformAdmission = put('/synthetic/cell/platform-admission.json', { schema: 'tablo/linux-platform-admission/v1', nonce, kind: 'host' });
  const loaded = ownerModule(files), platform = { schema: 'tablo/linux-qualified-platform/v1', nonce, context: loaded.context, runtimes, phpBootstrap,
    preparedReceipt: prepared, admission: platformAdmission };
  const originals = [['limit8',8,8,'ok'],['limit9',9,8,'ok'],['overflow8',8,8,'denied'],['overflow9',9,8,'denied'],
    ['threads',8,8,'ok'],['threadfork',8,8,'ok'],['threadexec',8,8,'exit23'],['concurrent',8,8,'denied'],['vfork',8,8,'ok'],
    ['spawn',8,8,'ok'],['short',8,8,'ok'],['failedfork',8,8,'ok'],['clone3',8,8,'ok'],['untraced',8,8,'denied'],
    ['filter',8,8,'denied'],['namespace',8,8,'denied'],['ptrace',8,8,'denied'],['x32',8,8,'sigsys'],['i386',8,8,'sigsys'],
    ['stdin0',8,8,'ok'],['stdin65536',8,8,'ok'],['exit23',8,8,'exit23'],['signal',8,8,'signal'],['rootfirst',8,8,'cancelled'],
    ['owner-eof',8,8,'cancelled'],['deadline',8,8,'cancelled'],['deadline',8,20,'cancelled']];
  const neutral = { status: 'passed', nonce, count: 30, externalNamespaceDenial: false, nativeNamespaceLatchCoverage: true,
    controlsSourceSha256: controlsSource.sha256, controlsBinarySha256: controls.sha256, outcomes: originals.map(([mode,limit,seconds,expectation], index) => {
      const failed = !['ok','exit23','signal'].includes(expectation), count = mode.startsWith('limit') || mode.startsWith('overflow') ? limit : 1;
      const a = admission(expectation === 'denied' ? 1 : 0, 0, 0, failed, limit, count, mode === 'threads' ? 4 : 0);
      if (mode === 'failedfork') { a.failedCreations = 1; a.processReservations = 1; }
      return { mode, limit, seconds, expectation, status: 'passed', adapterClose: nativeClose(failed ? 125 : 0),
        events: eventSet(a, root + '/case-' + index, '', files, expectation === 'exit23' ? 23 : expectation === 'signal' || expectation === 'sigsys' ? null : 0,
          expectation === 'signal' ? 10 : expectation === 'sigsys' ? 31 : null, expectation === 'cancelled') };
    }) };
  for (const kind of ['partial-input','unresponsive-input','invalid-capture']) neutral.outcomes.push({ kind, status: 'passed', expectedFailure: true,
    nativeStatus: { code: 125, signal: null }, seen: kind === 'invalid-capture' ? [] : [
      { event: 'INPUT', values: [65536,kind === 'partial-input' ? 1 : 0,1,0] },
      { event: 'CLOSED', values: [0,0,1,1,0,0,0,0,0,1,0,1,1,0,0] }] });
  const fallbackResults = names.map((name, index) => {
    const count = ['uring-repeat','uring-arguments','uring-threads'].includes(name) ? 4 : 1;
    const extra = name === 'uring-mixed-security' ? 8 : name === 'uring-mixed-quota' ? 1 : 0;
    return { name, status: 'passed', adapterClose: nativeClose(extra ? 125 : 0),
      events: eventSet(admission(count + extra,count,count,extra > 0,8,name === 'uring-mixed-quota' ? 8 : name === 'uring-forkexec' ? 2 : 1,
        name === 'uring-threads' ? 4 : 0), root + '/fallback-' + index, 'IO_URING_EPERM ' + name + '\n', files, name === 'uring-unhandled' ? 23 : 0) };
  });
  const clientResults = Object.keys(runtimes).map(label => ({ label, adapterClose: nativeClose(),
    events: eventSet(admission(label === 'node' ? 3 : 0), root + '/client-' + label, '', files) }));
  for (const result of clientResults) Object.assign(result.events.ready, { helperSha256: binary.sha256, sourceSha256: cSource.sha256 });
  const runtime = { status: 'passed', nonce, runtimes, phpBootstrap, owner, qualifier,
    witness: { stdout: 'WITNESS_CLOSED 3 3 SIGKILL ECHILD\n', status: { code: 0, signal: null }, expired: false, overflow: false }, clients: [] };
  const fallback = { schema: 'tablo/linux-io-uring-fallback-controls/v1', policy, status: 'passed', nonce,
    sourceSha256: cSource.sha256, recipeSha256: recipe.sha256, binarySha256: binary.sha256, buildSha256: buildPin.sha256,
    controlsSourceSha256: controlsSource.sha256, controlsBinarySha256: controls.sha256, owner: { ...owner }, qualifier: { ...qualifier },
    platformReceipt: { ...prepared }, runtimes, count: 8, outcomes: [] };
  const q = { schema: 'tablo/linux-adapter-qualification/v3', policy, status: 'qualified', binarySha256: binary.sha256,
    sourceSha256: cSource.sha256, recipeSha256: recipe.sha256, buildSha256: buildPin.sha256, controlsSourceSha256: controlsSource.sha256,
    controlsBinarySha256: controls.sha256, runtimeControlsSha256: '', platformReceiptSha256: '', nonce, runtimes,
    runtimeControls: null, platformReceipt: null, neutralControls: null, fallbackControlsSha256: '', fallbackControls: null };
  const observer = { linuxAdapter: binary, linuxAdapterSource: cSource, linuxAdapterRecipe: recipe, linuxAdapterBuild: buildPin, linuxAdapterQualification: null };
  const selected = { nonce, runtimes, phpBootstrap };
  const bind = (events,planId,executable,limit) => {
    events.planId=planId;
    for(const kind of ['ready','started','input','exited','closed'])events[kind].planId=planId;
    Object.assign(events.ready,{helperSha256:binary.sha256,sourceSha256:cSource.sha256,recipeSha256:recipe.sha256,
      buildSha256:buildPin.sha256,configuredLimit:limit,limitUnit:'process/TGID'});
    Object.assign(events.started,{actualImagePath:executable.path,actualImageSha256:executable.sha256});
  };
  neutral.outcomes.slice(0,27).forEach((row,index)=>bind(row.events,nonce+'/linux-controller/'+index,controls,row.limit));
  fallbackResults.forEach((row,index)=>bind(row.events,nonce+'/fallback/'+index,controls,8));
  clientResults.forEach(row=>bind(row.events,nonce+'/client/'+row.label,runtimes[row.label==='composer'?'php':row.label],8));
  function seal() {
    runtime.clients = clientResults.map(result => ({ label: result.label, executable:runtimes[result.label==='composer'?'php':result.label],
      receipt: put(root + '/client-' + result.label + '/result.json', result) }));
    fallback.outcomes = fallbackResults.map((result,index) => ({ name: names[index], receipt: put(root + '/fallback-' + index + '/result.json', result) }));
    q.runtimeControls = put(root + '/runtime-controls.json', runtime); q.runtimeControlsSha256 = q.runtimeControls.sha256;
    q.platformReceipt = put(root + '/platform.json', platform); q.platformReceiptSha256 = q.platformReceipt.sha256;
    q.neutralControls = put(root + '/neutral-controls.json', neutral);
    q.fallbackControls = put(root + '/fallback-controls.json', fallback); q.fallbackControlsSha256 = q.fallbackControls.sha256;
    observer.linuxAdapterQualification = put(root + '/qualification.json', q);
  }
  seal();
  return { ...loaded, files, put, root, observer, selected, q, build, fallback, fallbackResults, runtime, clientResults, neutral, platform, seal,
    updateQ: () => { observer.linuxAdapterQualification = put(root + '/qualification.json', q); } };
}

test('v2 CLOSED retains authoritative zero, positive, mixed and incomplete facts through byte fragments', () => {
  for (const [d,i,w,f] of [[0,0,0,0],[1,1,1,0],[5,5,5,0],[6,5,5,1],[5,5,4,1],[5,5,5,1],[2147483647,2147483647,2147483647,0]]) {
    const result = parse(prefix + closed(d,i,w,f), true);
    assert.deepEqual(result.seen.at(-1).values.slice(-2), [i,w]); assert.equal(result.records.denialDiagnostic, 'UNKNOWN');
  }
});
test('old policy/arity, absent authority, impossible counts, overflow and hostile fragments fail actual parser', () => {
  for (const text of [prefix.replace(policy,'tgid-seccomp-ptrace-x64-v1') + closed(0,0,0), prefix + closed(5,6,6), prefix + closed(5,5,6),
    prefix + closed(2147483648,0,0), prefix + closed(5,-1,0), prefix + closed(5,'01',0), prefix + closed(5,'1.5',0),
    prefix + closed(5,'1e0',0), prefix + closed(5,'PRIVATE\u0000',0), prefix + closed(0,0,0).replace(/ 0 0\n$/, '\n'),
    prefix + closed(0,0,0).replace(/\n$/, ' extra\n'), prefix + closed(0,0,0).replace(/\n$/, '\r\n')]) assert.throws(() => parse(text,true), /LINUX_/);
  assert.throws(() => parse(prefix + closed(0,0,'9'.repeat(513))), /LINUX_/);
  assert.throws(() => parse(closed(0,0,0)), /LINUX_CLOSE_ORDER/);
  assert.throws(() => parse(prefix+closed(0,0,0)+closed(0,0,0)), /LINUX_CLOSE_ORDER/);
});
test('actual admission validator accepts failed incomplete scopes but refuses missing/impossible authority', () => {
  const base = eventSet(admission(), '/synthetic', '', new Map()).closed;
  const plan = { schema: 'tablo/server-observer-linux-plan/v1', planId: 'synthetic', job: { activeProcessLimit: 8 } };
  const state = () => ({ planId: 'synthetic', closed: null, input: {}, started: { controllerPid: 8 }, exited: {} });
  for (const a of [admission(),admission(5),admission(6,5,5,true),admission(5,5,4,true)]) assert.doesNotThrow(() => acceptEvent(state(),'closed',{...base,admission:a},plan,{},''));
  for (const change of [{ioUringSetupAttempts:6},{ioUringSetupEpermWrites:6},{deniedAdmissions:2147483648},{ioUringSetupAttempts:'5'},{extra:0}]) {
    assert.throws(() => acceptEvent(state(),'closed',{...base,admission:{...admission(5),...change}},plan,{},''), /ACCOUNTING/);
  }
  for (const name of ['ioUringSetupAttempts','ioUringSetupEpermWrites']) { const a=admission(); delete a[name]; assert.throws(() => acceptEvent(state(),'closed',{...base,admission:a},plan,{},''), /ACCOUNTING/); }
});
test('actual armed owner admits only complete qv3 with all30+8 and pinned positive Node coverage', async () => {
  const f=receiptFixture(); await f.api.linuxPins(f.observer,false,f.selected); assert.deepEqual(f.effects,[]);
  for (const count of [1,7,2147483647]) { f.clientResults[0].events.closed.admission=admission(count); f.seal(); await f.api.linuxPins(f.observer,false,f.selected); assert.deepEqual(f.effects,[]); }
});
for (const [label, mutate] of [
  ['qv2',f=>{f.q.schema='tablo/linux-adapter-qualification/v2';f.updateQ();}],
  ['policyv1',f=>{f.q.policy='tgid-seccomp-ptrace-x64-v1';f.updateQ();}],
  ['relabelled old binary',f=>{f.q.binarySha256=sha('old-binary');f.updateQ();}],
  ['old actual binary bytes',f=>f.files.set(f.observer.linuxAdapter.path,Buffer.from('old-binary'))],
  ['q order',f=>{const entries=Object.entries(f.q);[entries[0],entries[1]]=[entries[1],entries[0]];f.observer.linuxAdapterQualification=f.put(f.root+'/qualification.json',Object.fromEntries(entries));}],
  ['missing fallback',f=>{delete f.q.fallbackControls;f.updateQ();}],
  ['wrong fallback hash',f=>{f.q.fallbackControlsSha256=sha('wrong');f.updateQ();}],
  ['foreign nonce',f=>{f.q.nonce='b'.repeat(32);f.updateQ();}],
  ['foreign runtime',f=>{f.selected.runtimes={...f.selected.runtimes,php:{...f.selected.runtimes.php,sha256:sha('wrong')}};}],
  ['runtime order',f=>{f.q.runtimes=Object.fromEntries(Object.entries(f.q.runtimes).reverse());f.updateQ();}],
  ['fallback nonce',f=>{f.fallback.nonce='b'.repeat(32);f.seal();}],
  ['fallback source',f=>{f.fallback.sourceSha256=sha('wrong');f.seal();}],
  ['fallback build',f=>{f.fallback.buildSha256=sha('wrong');f.seal();}],
  ['fallback owner',f=>{f.fallback.owner.sha256=sha('wrong');f.seal();}],
  ['fallback qualifier',f=>{f.fallback.qualifier.sha256=sha('wrong');f.seal();}],
  ['fallback platform',f=>{f.fallback.platformReceipt.path='/foreign/platform.json';f.seal();}],
  ['platform context',f=>{f.platform.context={...f.platform.context,kernel:'wrong'};f.seal();}],
  ['fallback count',f=>{f.fallback.count=7;f.seal();}],
  ['fallback event nonce',f=>{f.fallbackResults[0].events.planId='foreign/fallback/0';f.seal();}],
  ['fallback helper identity',f=>{f.fallbackResults[0].events.ready.helperSha256=sha('wrong');f.seal();}],
  ['fallback label',f=>{f.fallbackResults[0].name='uring-repeat';f.seal();}],
  ['fallback missing outcome',f=>{f.fallbackResults.pop();f.seal();}],
  ['fallback swapped outcomes',f=>{[f.fallbackResults[0],f.fallbackResults[1]]=[f.fallbackResults[1],f.fallbackResults[0]];f.seal();}],
  ['fallback host-only EPERM',f=>{f.fallbackResults[0].events.closed.admission=admission();f.seal();}],
  ['fallback marker',f=>f.files.set(f.root+'/fallback-0/evidence/controller.stdout',Buffer.from('HOST_EPERM_ONLY\n'))],
  ['mixed wrongly passed',f=>{f.fallbackResults[5].adapterClose.code=0;f.fallbackResults[5].events.closed.admission.failed=false;f.seal();}],
  ['mixed missing fatal coverage',f=>{f.fallbackResults[5].events.closed.admission.deniedAdmissions=1;f.seal();}],
  ['quota wrong peak',f=>{f.fallbackResults[6].events.closed.admission.peakCommitted=7;f.seal();}],
  ['unhandled root falsely successful',f=>{f.fallbackResults[7].events.exited.exitCode=0;f.seal();}],
  ['old30 missing',f=>{f.neutral.outcomes.pop();f.seal();}],
  ['old30 swapped',f=>{[f.neutral.outcomes[0],f.neutral.outcomes[1]]=[f.neutral.outcomes[1],f.neutral.outcomes[0]];f.seal();}],
  ['old30 expectation relabel',f=>{f.neutral.outcomes[2].expectation='ok';f.seal();}],
  ['old30 failure',f=>{f.neutral.outcomes[0].status='failed';f.seal();}],
  ['old30 peak',f=>{f.neutral.outcomes[0].events.closed.admission.peakCommitted=7;f.seal();}],
  ['old30 helper identity',f=>{f.neutral.outcomes[0].events.ready.helperSha256=sha('wrong');f.seal();}],
  ['diagnostic namespace exception',f=>{f.neutral.externalNamespaceDenial=true;f.seal();}],
  ['Node zero coverage',f=>{f.clientResults[0].events.closed.admission=admission();f.seal();}],
  ['Node mixed',f=>{f.clientResults[0].events.closed.admission=admission(6,5,5);f.seal();}],
  ['Node incomplete',f=>{f.clientResults[0].events.closed.admission=admission(5,5,4);f.seal();}],
  ['Node failed matching',f=>{f.clientResults[0].events.closed.admission=admission(5,5,5,true);f.seal();}],
  ['Node missing authority',f=>{delete f.clientResults[0].events.closed.admission.ioUringSetupAttempts;f.seal();}],
  ['missing client',f=>{f.clientResults.pop();f.seal();}],
  ['client root23',f=>{f.clientResults[0].events.exited.exitCode=23;f.seal();}],
  ['client event nonce',f=>{f.clientResults[0].events.closed.planId='foreign/client/node';f.seal();}],
  ['client recipe identity',f=>{f.clientResults[0].events.ready.recipeSha256=sha('wrong');f.seal();}],
  ['client executable identity',f=>{f.clientResults[0].events.started.actualImageSha256=sha('wrong');f.seal();}],
  ['client signal',f=>{f.clientResults[0].events.exited.posixSignal=10;f.seal();}],
  ['client no input',f=>{f.clientResults[0].events.input=null;f.seal();}],
  ['client closure unknown',f=>{f.clientResults[0].adapterClose.closeObserved=false;f.seal();}],
  ['client protocolv1',f=>{f.clientResults[0].adapterClose.nativeProtocol='private-inherited-pipes/v1';f.seal();}],
  ['client protocol failed',f=>{f.clientResults[0].adapterClose.protocolFailed=true;f.seal();}],
  ['client adapter125',f=>{f.clientResults[0].adapterClose.code=125;f.seal();}],
]) test('actual qv3 authority rejects '+label, async()=>{const f=receiptFixture();mutate(f);await assert.rejects(f.api.linuxPins(f.observer,false,f.selected));assert.deepEqual(f.effects,[]);});

test('actual product custody equation preserves all root/input/close/failure gates', async()=>{
  for(const a of [admission(),admission(5),admission(6,5,5),admission(5,5,4),admission(5,5,5,true)]) {
    const files=new Map(), root='/synthetic/product', state=eventSet(a,root,'',files), {api,effects}=ownerModule(files);
    const owner={phase:{root,kind:'engine'},control:true,plan:{job:{activeProcessLimit:8},launch:{input:{mode:'none',bytes:0}}},
      leases:{active:new Set()},cancelReason:null,adapterClose:nativeClose()};
    if(!a.failed&&a.deniedAdmissions===a.ioUringSetupAttempts&&a.ioUringSetupAttempts===a.ioUringSetupEpermWrites) await api.verifyCustody(owner,state);
    else await assert.rejects(api.verifyCustody(owner,state),/CONTROLLER_NATIVE_FAILURE/);
    assert.deepEqual(effects,[]);state.exited.exitCode=23;await assert.rejects(api.verifyCustody(owner,state));assert.deepEqual(effects,[]);
  }
});
test('actual custody refusal variants retain their exact owner classification with no effect attempts', async () => {
  for (const [code, mutate] of [
    ['OBSERVER_CLOSURE_UNKNOWN', (owner, state) => { state.input = null; }],
    ['CONTROLLER_INPUT_UNSETTLED', (owner, state) => { state.input.settled = false; }],
    ['CONTROLLER_NATIVE_FAILURE', (owner, state) => { state.closed.admission = admission(6,5,5); }],
    ['CONTROLLER_CUSTODY', (owner, state) => { state.closed.stdout.sha256 = '0'.repeat(64); }],
  ]) {
    const files = new Map(), root = '/synthetic/custody', state = eventSet(admission(), root, '', files);
    const { api, effects } = ownerModule(files);
    const owner = { phase: { root, kind: 'engine' }, control: true, plan: { job: { activeProcessLimit: 8 }, launch: { input: { mode: 'none', bytes: 0 } } },
      leases: { active: new Set() }, cancelReason: null, phaseResults: [], adapterClose: nativeClose(), adapterState: state };
    mutate(owner, state); let error;
    try { await api.verifyCustody(owner, state); } catch (caught) { error = caught; }
    assert.deepEqual(effects, []); assert(error); const failure = api.observerFailure(); failure.stage = 'custody'; failure.phase = 'engine';
    api.latchObserverFailure(failure, error, owner); assert.equal(failure.first.refusal, code);
    assert.equal(failure.first.stage, 'custody'); assert.equal(failure.first.issueAccepted, false);
  }
});
test('safe projection v3 preserves both new counters without minting success or raising bounds',()=>{
  const result=projectFailure({stage:'FALLBACK_CONTROL',subject:'uring-mixed-quota',index:6,check:'Q146',completed:38,
    owner:{adapterState:{closed:{admission:admission(6,5,4,true)}}}});
  assert.equal(result.schema,'tablo/linux-safe-qualification-failure/v3');assert.equal(result.admission.ioUringSetupAttempts,5);
  assert.equal(result.admission.ioUringSetupEpermWrites,4);assert.equal(result.completed,38);assert.equal(result.checkId,'Q146');
  assert.equal(result.qualified,false);assert.equal(result.rootCause,'UNKNOWN');assert(Buffer.byteLength(JSON.stringify(result,null,2)+'\n')<=4096);
});

function qualifierModule(mutate = () => {}) {
  const f=receiptFixture(), effects=[], tripwire=effect=>{effects.push(effect);throw Error('QUALIFIER_SANDBOX_EFFECT '+effect);};
  const base='/synthetic/qualifier/tablo-linux-'+f.selected.nonce, files=f.files, clients=[];
  files.set(base+'/neutral-controls.json',Buffer.from(JSON.stringify(f.neutral)));
  const read=file=>{if(!files.has(file))tripwire('UNKNOWN_READ '+file);return files.get(file);};
  const owned=file=>typeof file==='string'&&(file===base||file.startsWith(base+'/'));
  const write=(file,value)=>{if(!owned(file))tripwire('FOREIGN_WRITE '+file);files.set(file,Buffer.from(value));};
  const api={
    regular:async file=>{read(file);return file;},pinned:async pin=>{assert.equal(sha(read(pin.path)),pin.sha256);},
    readPlan:async(file,hash)=>{assert.equal(sha(read(file)),hash);return JSON.parse(read(file));},
    retain:async(file,value)=>write(file,JSON.stringify(value)),linuxContext:async()=>f.context,
    runLinuxAdapter:async(owner,plan)=>{
      if(!owned(owner.phase.root))tripwire('FOREIGN_ADAPTER');
      const name=plan.launch.argv[0], fallback=names.includes(name);
      const label=fallback?name:owner.phase.root.slice(owner.phase.root.lastIndexOf('/client-')+8);
      if(!fallback&&!['node','php','composer','shell','docker'].includes(label))tripwire('UNKNOWN_CLIENT');
      const count=fallback?(['uring-repeat','uring-arguments','uring-threads'].includes(name)?4:1):(label==='node'?3:0);
      const extra=name==='uring-mixed-security'?8:name==='uring-mixed-quota'?1:0;
      const a=admission(count+extra,count,count,extra>0,8,name==='uring-mixed-quota'?8:name==='uring-forkexec'?2:1,name==='uring-threads'?4:0);
      const marker=fallback?'IO_URING_EPERM '+name+'\n':({node:'NODE_WORKER_CHILD_OK v24.13.0\n',php:'PHP_PROC_EOF_OK 8.5.0',composer:'Composer version 2.9.0',shell:'SHELL_CHILD_OK',docker:'Docker version synthetic'})[label];
      const events=eventSet(a,owner.phase.root,marker,files,name==='uring-unhandled'?23:0);
      owner.adapterClose=nativeClose(extra?125:0);owner.adapterState=events;
      mutate({label,events,owner,files,plan});clients.push(label);return events;
    }
  };
  const spec={nonce:f.selected.nonce,runtimes:f.selected.runtimes,phpBootstrap:f.selected.phpBootstrap,owner:f.runtime.owner,qualifier:f.runtime.qualifier,
    controls:{path:'/synthetic/compiler/server-supervisor-linux-controls',sha256:sha(files.get('/synthetic/compiler/server-supervisor-linux-controls'))},
    controlsSource:{path:'/synthetic/source/tools/server-supervisor-linux-controls.c',sha256:f.q.controlsSourceSha256},
    platformReceipt:f.platform.preparedReceipt,admission:f.platform.admission,mode:'qualify',outputQualification:base+'/qualification.json'};
  const filesystem=new Proxy({mkdir:async file=>{if(!owned(file))tripwire('FOREIGN_MKDIR');},
    readFile:async(file,encoding)=>encoding?read(file).toString(encoding):Buffer.from(read(file)),
    writeFile:async(file,bytes)=>write(file,bytes)}, {get(target,key){if(!(key in target))tripwire('UNKNOWN_FS '+String(key));return target[key];}});
  const module={exports:{}}, sandbox={module,exports:module.exports,__filename:'/synthetic/source/tools/server-supervisor-linux-qualify.cjs',
    __dirname:'/synthetic/source/tools',Buffer,
    process:new Proxy({hrtime:process.hrtime,execPath:spec.runtimes.node.path,platform:'linux',arch:'x64',version:'v24.13.0'},
      {get(target,key){if(!(key in target))tripwire('UNKNOWN_PROCESS '+String(key));return target[key];}}),
    setInterval:()=>({synthetic:true}),clearInterval:timer=>{if(timer?.synthetic!==true)tripwire('UNKNOWN_TIMER');},
    require(name){
      if(name==='node:fs/promises')return filesystem;
      if(name==='./server-supervisor.cjs')return api;
      if(name==='./server-qualification-diagnostic.cjs')return {projectFailure,cancelOwner};
      if(name==='node:path')return path.posix;
      if(name==='node:child_process')return {spawn(executable,argv,options){
        if(executable!==spec.controls.path||argv[0]!=='witness'||argv[1]!==f.observer.linuxAdapter.path
          ||argv[2]!==spec.controls.path||argv.length!==4||options.cwd!==base+'/tracer-death')tripwire('UNEXPECTED_SPAWN');
        const child=new EventEmitter();child.stdout=new EventEmitter();child.stderr=new EventEmitter();
        child.kill=()=>tripwire('UNEXPECTED_KILL');
        queueMicrotask(()=>{child.stdout.emit('data',Buffer.from('WITNESS_CLOSED 3 3 SIGKILL ECHILD\n'));child.emit('close',0,null);});
        return child;
      }};
      if(['node:crypto','node:assert/strict'].includes(name))return require(name);
      tripwire('UNEXPECTED_IMPORT '+name);
    }};
  vm.runInNewContext(qualifierSource,sandbox,{filename:sandbox.__filename});
  return {api:module.exports,spec,base,observer:f.observer,files,effects,clients};
}
test('actual qualifier executes all eight supplemental checks and unchanged five-client order in memory',async()=>{
  const f=qualifierModule();await f.api.qualifyFallback(f.spec,f.base,f.observer,f.spec.controls,process.hrtime.bigint()+1800_000_000_000n);
  await f.api.qualifyRemaining(f.spec,f.base,f.observer,f.spec.controls,[],process.hrtime.bigint()+1800_000_000_000n);
  assert.deepEqual(f.effects,[]);assert.deepEqual(f.clients,[...names,'node','php','composer','shell','docker']);
  const q=JSON.parse(f.files.get(f.spec.outputQualification));assert.equal(q.schema,'tablo/linux-adapter-qualification/v3');
  assert.deepEqual(Object.keys(q).slice(-3),['neutralControls','fallbackControlsSha256','fallbackControls']);
});
for(const [label,change] of [
  ['zero Node coverage',({events})=>{events.closed.admission=admission();}],
  ['mixed Node denials',({events})=>{events.closed.admission=admission(6,5,5);}],
  ['incomplete Node writes',({events})=>{events.closed.admission=admission(5,5,4);}],
  ['failed matching Node',({events})=>{events.closed.admission=admission(5,5,5,true);}],
  ['missing Node authority',({events})=>{delete events.closed.admission.ioUringSetupAttempts;}],
  ['root23',({events})=>{events.exited.exitCode=23;}],
  ['adapter125',({owner})=>{owner.adapterClose.code=125;}],
])test('actual original client checks reject '+label,async()=>{
  const f=qualifierModule(row=>{if(row.label==='node')change(row);});
  await assert.rejects(f.api.qualifyRemaining(f.spec,f.base,f.observer,f.spec.controls,[],process.hrtime.bigint()+1800_000_000_000n));
  assert.deepEqual(f.effects,[]);assert(!f.files.has(f.spec.outputQualification));
});
for(const [label,change] of [
  ['external-only EPERM',({events})=>{events.closed.admission=admission();}],
  ['incomplete write',({events})=>{events.closed.admission.ioUringSetupEpermWrites=0;}],
  ['wrong marker',({files,plan})=>{files.set(plan.launch.stdout,Buffer.from('EXTERNAL_EPERM\n'));}],
])test('actual supplemental checks reject '+label,async()=>{
  const f=qualifierModule(row=>{if(row.label==='uring-handled')change(row);});
  await assert.rejects(f.api.qualifyFallback(f.spec,f.base,f.observer,f.spec.controls,process.hrtime.bigint()+1800_000_000_000n));
  assert.deepEqual(f.effects,[]);assert(!f.files.has(f.base+'/fallback-controls.json'));
});

// Exercise the actual main/supervise flow only inside a non-main VM. Every
// reachable external effect is closed; the finite seams below are synthetic.
function observerHarness(options = {}) {
  const effects = [], calls = [], output = [], writes = [], listeners = new Set(), timers = new Set();
  const tripwire = name => { effects.push(name); throw Error('FORBIDDEN_OBSERVER_EFFECT'); };
  const original = options.error ?? new Error('PRIVATE secret /private/path ' + 'x'.repeat(8192));
  const retentionError = new TypeError('PRIVATE retention');
  const step = name => { calls.push(name); if (options.at === name) throw original; };
  const file = '/synthetic/plan.json', root = '/synthetic/observer';
  const phases = ['prepare','baseline','engine'].map(kind => ({ kind, root: root + '/' + kind }));
  const observer = { root, phases, node: {}, nodeOwner: {}, bootstrapIniFiles: [{ path: 'synthetic.ini' }],
    bootstrapIni: 'synthetic.ini', bootstrapIniScanDirectory: '', bootstrapExtensionFiles: [] };
  const plan = { executionMode: Object.hasOwn(options, 'mode') ? options.mode : 'qualified', observer, php: {}, nonce: 'a'.repeat(32) };
  const memoryDirectories = new Set([root, ...['private','evidence','phases'].map(name => root + '/' + name),
    ...phases.flatMap(phase => [phase.root, ...['private','evidence','cwd'].map(name => phase.root + '/' + name)])]);
  const retained = new Set([root + '/evidence/leases.json', root + '/evidence/observer-result.json',
    ...phases.map(phase => phase.root + '/evidence/phase-result.json')]);
  const closed = target => new Proxy(target, { get(value, key) {
    if (!Object.hasOwn(value, key)) tripwire('UNEXPECTED_ACCESS ' + String(key)); return value[key];
  } });
  const module = { exports: {} };
  let expired = false;
  const sandbox = { module, exports: module.exports, Buffer, Error, TypeError, RangeError, SyntaxError,
    JSON: { parse: JSON.parse, stringify(...values) { if (options.serializationFails) throw retentionError; return JSON.stringify(...values); } },
    __filename: '/synthetic/owner.cjs',
    setInterval(callback, delay) { assert.equal(delay, 10); step('timer'); const token = {}; timers.add(token); return token; },
    clearInterval(token) { assert(timers.delete(token)); step('timer-clear'); },
    process: closed({ version: options.version ?? 'v24.13.0', platform: 'linux', arch: 'x64', execPath: '/synthetic/node',
      hrtime: { bigint: () => expired ? 1801_000_000_000n : 1n }, once(signal) { assert(['SIGINT','SIGTERM'].includes(signal)); listeners.add(signal); },
      removeListener(signal) { assert(listeners.delete(signal)); },
      stderr: { write(bytes) { step('output'); if (options.outputFails) throw retentionError; output.push(bytes); } } }),
    require(name) {
      if (name === 'node:crypto') return { createHash, createHmac: () => tripwire('HMAC'), timingSafeEqual: () => tripwire('COMPARE'),
        randomBytes: length => { assert.equal(length, 32); step('entropy'); return Buffer.alloc(length, 1); } };
      if (name === 'node:fs') return closed({ createReadStream: () => tripwire('STREAM') });
      if (name === 'node:fs/promises') return closed({ mkdir: async (directory, mode) => {
        assert.equal(mode.mode, 0o700); assert.equal(Object.keys(mode).join(','), 'mode');
        if (!memoryDirectories.has(directory)) tripwire('DIRECTORY');
        step(directory.startsWith(root + '/prepare') ? 'phase-layout' : 'root-layout');
      } });
      if (name === 'node:net') return closed({});
      if (name === 'node:child_process') return closed({ spawn: () => tripwire('SPAWN') });
      if (name === 'node:path') return path.posix;
      if (name === 'node:util') return { TextDecoder };
      return tripwire('IMPORT ' + name);
    } };
  // require.main differs from module, so the real executable entry never fires.
  sandbox.require.main = {};
  vm.runInNewContext(source + `\nmodule.exports.testOnly = { main, supervise, observerFailure, latchObserverFailure, emitObserverFailure, fail,
    setSeams(seams) { readPlan=seams.readPlan; declaration=seams.declaration; pinned=seams.pinned; linuxPins=seams.linuxPins;
      regular=seams.regular; listen=seams.listen; controllerLaunch=seams.controllerLaunch; adapterPlan=seams.adapterPlan;
      runAdapter=seams.runAdapter; verifyCustody=seams.verifyCustody; verifyPhase=seams.verifyPhase; retain=seams.retain; digest=seams.digest; }
  };`, sandbox, { filename: sandbox.__filename });
  const api = module.exports.testOnly;
  observer.node.path = '/synthetic/node'; observer.nodeOwner.path = sandbox.__filename;
  api.setSeams({
    readPlan: async () => { step('preflight'); return plan; },
    declaration: () => { step('declaration'); return observer; },
    pinned: async () => step('pins'), linuxPins: async () => step('linux-pins'),
    regular: async value => { if (value !== file) tripwire('REGULAR'); step('root-layout'); return value; },
    listen: async owner => { step('listener'); owner.connections = 0; owner.server = { close() { step('server-close'); } }; },
    controllerLaunch: async () => { step('launch-description'); return {}; },
    adapterPlan: async () => { step('adapter-plan'); return {}; },
    runAdapter: async owner => { step('adapter-run'); owner.adapterClose = { code: 0 };
      if (options.prepareDiagnostic !== undefined) owner.phase.prepareDiagnostic = options.prepareDiagnostic;
      owner.adapterState = { exited: { exitCode: 0 }, input: { settled: true }, closed: { scopeObservedEmpty: true } };
      if (options.cancel) owner.cancel(options.cancel); return owner.adapterState; },
    verifyCustody: async () => { step('custody'); return {}; }, verifyPhase: async () => { step('phase-proof'); return {}; },
    retain: async (value, record) => {
      if (!retained.has(value)) tripwire('RETAIN');
      const final = value === root + '/evidence/leases.json' || value === root + '/evidence/observer-result.json';
      step(final ? 'retention' : 'phase-retention');
      if (final && options.retentionFails) throw retentionError;
      writes.push({ value, status: record.status });
      if (options.expireAtRetention && value === root + '/evidence/observer-result.json') expired = true;
    },
    digest: async value => { if (!retained.has(value)) tripwire('DIGEST'); step('phase-digest'); return 'a'.repeat(64); }
  });
  return { api, effects, calls, output, writes, listeners, timers, original, retentionError,
    run: () => api.main(['--execute=' + file, '--sha256=' + 'a'.repeat(64)]) };
}
function observerClosed(harness) {
  assert.deepEqual(harness.effects, []); assert.equal(harness.listeners.size, 0); assert.equal(harness.timers.size, 0);
}
for (const stage of ['preflight','declaration','pins','linux-pins','root-layout','listener','phase-layout',
  'launch-description','adapter-plan','adapter-run','custody','phase-proof','phase-retention','retention']) {
  test('actual observer first-failure stage: ' + stage, async () => {
    const h = observerHarness({ at: stage });
    await assert.rejects(h.run(), error => error === h.original);
    observerClosed(h);
    assert.equal(h.output.length, 1); const record = JSON.parse(h.output[0]);
    assert.equal(record.stage, stage); assert.equal(record.status, 'failed'); assert.equal(record.rootCause, 'UNKNOWN');
    assert.equal(record.issueAccepted, false); assert.equal(record.errorClass, 'Error'); assert.equal(record.refusal, 'UNKNOWN');
    assert(Buffer.byteLength(h.output[0]) <= 4096); assert(!h.output[0].includes('PRIVATE')); assert(!h.output[0].includes('/synthetic'));
    assert.equal(record.phase, stage === 'retention' ? 'engine' : ['phase-layout','launch-description','adapter-plan','adapter-run','custody','phase-proof','phase-retention'].includes(stage) ? 'prepare' : 'MISSING');
    if (['preflight','declaration','pins','linux-pins','root-layout'].includes(stage)) assert.deepEqual(h.writes, []);
  });
}
test('actual observer successful evaluation order and cleanup stay intact without failure output', async () => {
  const h = observerHarness(); await h.run(); observerClosed(h); assert.deepEqual(h.output, []);
  assert.deepEqual(h.calls.filter(name => ['listener','launch-description','adapter-plan','adapter-run','custody','phase-proof','phase-retention','phase-digest'].includes(name)),
    ['listener', ...Array.from({length:3}, () => ['launch-description','adapter-plan','adapter-run','custody','phase-proof','phase-retention','phase-digest']).flat()]);
  assert.equal(h.writes.at(-1).status, 'passed');
});
test('actual observer preserves original exception and first stage across failed retention and failed output', async () => {
  for (const outputFails of [false, true]) {
    const h = observerHarness({ at: 'custody', retentionFails: true, outputFails });
    await assert.rejects(h.run(), error => error === h.original); observerClosed(h);
    assert(h.calls.includes('retention')); assert.equal(h.output.length, outputFails ? 0 : 1);
    if (!outputFails) assert.equal(JSON.parse(h.output[0]).stage, 'custody');
  }
});
test('actual observer admission refusal stays before pins or layout and retains exact owner refusal identity', async () => {
  for (const mode of ['diagnostic', null, 'UNKNOWN']) {
    const h = observerHarness({ mode });
    await assert.rejects(h.run(), /EXECUTION_MODE_UNQUALIFIED/); observerClosed(h);
    const record = JSON.parse(h.output[0]); assert.equal(record.stage, 'admission'); assert.equal(record.refusal, 'EXECUTION_MODE_UNQUALIFIED');
    assert.deepEqual(h.calls, ['preflight','output']); assert.deepEqual(h.writes, []);
  }
});
test('actual argument and version refusals remain static before admission', async () => {
  const arguments_ = observerHarness();
  await assert.rejects(arguments_.api.main([]), /EXACT_ARGUMENTS/); observerClosed(arguments_);
  assert.equal(JSON.parse(arguments_.output[0]).stage, 'arguments'); assert.equal(JSON.parse(arguments_.output[0]).refusal, 'EXACT_ARGUMENTS');
  assert.deepEqual(arguments_.calls, ['output']);
  const version = observerHarness({ version: 'PRIVATE' });
  await assert.rejects(version.run(), /NODE_VERSION/); observerClosed(version);
  assert.equal(JSON.parse(version.output[0]).stage, 'preflight'); assert.equal(JSON.parse(version.output[0]).refusal, 'NODE_VERSION');
  assert.deepEqual(version.calls, ['output']);
});
test('observer error classes use exact prototypes without reading names or messages', () => {
  for (const type of [Error, TypeError, RangeError, SyntaxError]) {
    const h = observerHarness(), failure = h.api.observerFailure();
    h.api.latchObserverFailure(failure, new type('PRIVATE')); assert.equal(failure.first.errorClass, type.name); observerClosed(h);
  }
});
test('observer finite projection distinguishes missing, null and unknown and discards hostile fields', () => {
  const h = observerHarness(), failure = h.api.observerFailure(); failure.stage = 'custody'; failure.phase = 'engine';
  const privateReads = [], hostile = new Error('PRIVATE');
  // Materialize V8's lazy stack before installing independently latched getters.
  void hostile.stack;
  for (const field of ['message','stack','name']) Object.defineProperty(hostile, field, { get() { privateReads.push(field); throw Error('PRIVATE_READ'); } });
  h.api.latchObserverFailure(failure, hostile, { cancelReason: null, phaseResults: [], adapterClose: { code: null },
    adapterState: { exited: { exitCode: 2147483648 }, input: { settled: 'PRIVATE' }, closed: { scopeObservedEmpty: false } }, secret: 'PRIVATE' });
  assert(h.api.emitObserverFailure(failure)); const record = JSON.parse(h.output[0]);
  assert.equal(record.cancellation, null); assert.equal(record.adapterExit, null); assert.equal(record.completedPhases, 0);
  assert.equal(record.controllerExit, 'UNKNOWN'); assert.equal(record.inputSettled, 'UNKNOWN'); assert.equal(record.scopeObservedEmpty, false);
  assert(!h.output[0].includes('PRIVATE')); const first = failure.first;
  failure.stage = 'retention'; h.api.latchObserverFailure(failure, new TypeError('PRIVATE')); assert.equal(failure.first, first);
  const missing = h.api.observerFailure(); h.api.latchObserverFailure(missing, 'PRIVATE');
  assert.equal(missing.first.adapterExit, 'MISSING'); assert.equal(missing.first.cancellation, 'MISSING');
  assert.equal(missing.first.errorClass, 'UNKNOWN'); assert.deepEqual(privateReads, []); observerClosed(h);
});
test('observer cancellation enums and hostile stage/phase cannot escape bounded projection', () => {
  for (const reason of ['deadline','owner-loss','protocol',null,undefined,'PRIVATE']) {
    const h = observerHarness(), f = h.api.observerFailure(); f.stage = 'PRIVATE'.repeat(8192); f.phase = 'PRIVATE';
    h.api.latchObserverFailure(f, new Error('CONTROLLER_NATIVE_FAILURE'), { cancelReason: reason, phaseResults: { length: 2147483648 } });
    assert(h.api.emitObserverFailure(f)); const record = JSON.parse(h.output[0]);
    assert.equal(record.stage, 'UNKNOWN'); assert.equal(record.phase, 'UNKNOWN'); assert.equal(record.refusal, 'UNKNOWN');
    assert.equal(record.completedPhases, 'UNKNOWN'); assert.equal(record.cancellation, reason === undefined ? 'MISSING' : reason === 'PRIVATE' ? 'UNKNOWN' : reason);
    assert(Buffer.byteLength(h.output[0]) <= 4096); assert(!h.output[0].includes('PRIVATE')); observerClosed(h);
  }
});
test('observer serialization failure cannot replace original failure or create a successful result', async () => {
  const serialized = observerHarness({ at: 'custody', serializationFails: true });
  await assert.rejects(serialized.run(), value => value === serialized.original); observerClosed(serialized);
  assert.deepEqual(serialized.output, []); assert.equal(serialized.writes.at(-1).status, 'failed');
  const error = new Proxy({}, { getPrototypeOf() { throw Error('PRIVATE'); } });
  const h = observerHarness({ at: 'custody', error }); await assert.rejects(h.run(), value => value === error);
  observerClosed(h); assert.equal(JSON.parse(h.output[0]).stage, 'custody'); assert.equal(h.writes.at(-1).status, 'failed');
});
test('actual cancellation and final deadline refusals retain their phase and never promote success', async () => {
  const cancelled = observerHarness({ cancel: 'deadline' });
  await assert.rejects(cancelled.run(), /PRIOR_PHASE_UNSETTLED/); observerClosed(cancelled);
  const first = JSON.parse(cancelled.output[0]); assert.equal(first.stage, 'phase-admission'); assert.equal(first.phase, 'baseline');
  assert.equal(first.cancellation, 'deadline'); assert.equal(first.completedPhases, 1);
  const expired = observerHarness({ expireAtRetention: true });
  await assert.rejects(expired.run(), /CELL_FAILED/); observerClosed(expired);
  const last = JSON.parse(expired.output[0]); assert.equal(last.stage, 'settlement'); assert.equal(last.refusal, 'CELL_FAILED');
});

function prepareRecord(changes = {}) {
  return { schema: 'tablo/server-prepare-failure/v1', status: 'failed', phase: 'prepare', rootCause: 'UNKNOWN', issueAccepted: false,
    stage: 'EXECUTE', errorClass: 'RuntimeException', source: 'tools/server-cell.php', line: 123, ...changes };
}
async function prepareChannel(options = {}) {
  let accept;
  const server = new EventEmitter();
  server.listen = (address, callback) => { assert.deepEqual(JSON.parse(JSON.stringify(address)), { host: '127.0.0.1', port: 46000, exclusive: true }); callback(); };
  server.address = () => ({ address: '127.0.0.1', port: 46000 });
  const f = ownerModule(new Map(), { createServer(callback) { accept = callback; return server; } });
  const key = Buffer.alloc(32, 7), nonce = 'b'.repeat(32), cellNonce = 'a'.repeat(32);
  const phase = { kind: 'prepare', nonce, authority: key, sequence: 0, prepareDiagnosticOpen: true };
  const cancellations = [], owner = { plan: { nonce: cellNonce, executionMode: 'qualified' }, phase,
    observer: { host: '127.0.0.1', port: 46000 }, leases: new f.api.Leases(100_000_000_000n, () => 1n),
    control: false, cancelReason: null, phaseResults: [], cancel(reason) { cancellations.push(reason); this.cancelReason = reason; } };
  if (options.owner) Object.assign(owner, options.owner);
  if (options.phase) Object.assign(phase, options.phase);
  await f.api.listen(owner);
  const connect = () => {
    const socket = new EventEmitter(); socket.output = []; socket.destroyed = false;
    socket.setTimeout = milliseconds => assert.equal(milliseconds, 1000);
    socket.destroy = () => { socket.destroyed = true; };
    socket.end = (bytes, callback) => { if (options.outputThrows) throw Error('PRIVATE output'); socket.output.push(bytes); callback(options.ackError ? Error('PRIVATE ack') : null); };
    accept(socket); return socket;
  };
  const wire = (raw, changes = {}) => {
    const encoded = Buffer.from(raw).toString('base64'), selectedNonce = changes.nonce ?? nonce, kind = changes.kind ?? 'prepare-failure';
    const mac = createHmac('sha256', key).update(`tablo-i39-windows-adapter/1\ncontroller-to-owner\n${selectedNonce}\n1\n${kind}\n${encoded}`).digest('hex');
    return Buffer.from(JSON.stringify({ v: 1, nonce: selectedNonce, seq: 1, kind, payload: encoded, mac, ...changes }) + '\n');
  };
  const payload = (changes = {}) => ({ sequence: phase.sequence + 1, cellNonce, diagnostic: prepareRecord(), ...changes });
  const canonical = value => JSON.stringify(value).replaceAll('/', '\\/');
  const send = (value = payload(), changes = {}) => { const socket = connect(); socket.emit('data', wire(typeof value === 'string' ? value : canonical(value), changes)); return socket; };
  const closed = () => { assert.deepEqual(f.effects, []); assert.equal(owner.leases.active.size, 0); assert.equal(owner.leases.events.length, 0); assert.equal(owner.phaseResults.length, 0); };
  return { ...f, owner, phase, key, nonce, cellNonce, cancellations, payload, wire, canonical, connect, send, closed };
}
test('actual prepare-failure channel authenticates and records a finite first failure without capability or completion', async () => {
  const h = await prepareChannel(), socket = h.send(); h.closed();
  assert.equal(socket.destroyed, false); assert.deepEqual(h.cancellations, []);
  assert.deepEqual(JSON.parse(JSON.stringify(h.phase.prepareDiagnostic)), prepareRecord());
  const receiver = new h.api.Frames(h.key, h.nonce, 'controller-to-owner', 'owner-to-controller', 4096);
  const replies = []; receiver.push(socket.output[0], value => replies.push(value));
  assert.deepEqual(JSON.parse(JSON.stringify(replies)), [{ kind: 'accepted', payload: { sequence: 1, recorded: true } }]);
  const first = h.phase.prepareDiagnostic; const second = h.send(h.payload({ diagnostic: prepareRecord({ stage: 'AUTOLOAD' }) }));
  h.closed(); assert(second.destroyed); assert.equal(h.phase.prepareDiagnostic, first); assert.deepEqual(h.cancellations, ['protocol']);
});
test('actual prepare projector consumer accepts every finite stage class and source and revalidates console independently', async () => {
  const h = await prepareChannel();
  for (const stage of h.api.PREPARE_STAGES) assert.equal(h.api.prepareDiagnostic(prepareRecord({ stage })).stage, stage);
  for (const errorClass of h.api.PREPARE_CLASSES) assert.equal(h.api.prepareDiagnostic(prepareRecord({ errorClass })).errorClass, errorClass);
  for (const source of h.api.PREPARE_SOURCES) assert.equal(h.api.prepareDiagnostic(prepareRecord({ source })).source, source);
  for (const line of [1, 1000000]) assert.equal(h.api.prepareDiagnostic(prepareRecord({ line })).line, line);
  assert.equal(h.api.prepareDiagnostic(prepareRecord({ source: null, line: null })).source, null); h.closed();
  const safe = observerHarness({ at: 'custody', prepareDiagnostic: prepareRecord(), retentionFails: true });
  await assert.rejects(safe.run(), value => value === safe.original); observerClosed(safe);
  const record = JSON.parse(safe.output[0]); assert.deepEqual(record.prepareDiagnostic, prepareRecord());
  assert.equal(record.stage, 'custody'); assert.equal(record.completedPhases, 0); assert.equal(record.controllerExit, 0);
  assert(Buffer.byteLength(safe.output[0]) <= 4096);
  const invalid = observerHarness({ at: 'custody', prepareDiagnostic: prepareRecord({ message: 'PRIVATE canary' }) });
  await assert.rejects(invalid.run(), value => value === invalid.original); observerClosed(invalid);
  assert.equal(JSON.parse(invalid.output[0]).prepareDiagnostic, 'MISSING'); assert(!invalid.output[0].includes('PRIVATE'));
});
test('actual controller1 custody refusal survives an accepted prepare diagnostic without reading captures or completing a phase', async () => {
  const files = new Map(), root = '/synthetic/product', state = eventSet(admission(), root, '', files, 1);
  const { api, effects } = ownerModule(files);
  const owner = { phase: { root, kind: 'prepare', prepareDiagnostic: api.prepareDiagnostic(prepareRecord()) }, control: false,
    plan: {}, leases: { active: new Set() }, cancelReason: null, phaseResults: [], adapterClose: nativeClose(), adapterState: state };
  let error; try { await api.verifyCustody(owner, state); } catch (caught) { error = caught; }
  assert.deepEqual(effects, []); assert(error); assert.equal(owner.phaseResults.length, 0);
  const failure = api.observerFailure(); failure.stage = 'custody'; failure.phase = 'prepare'; api.latchObserverFailure(failure, error, owner);
  assert.equal(failure.first.refusal, 'CONTROLLER_NATIVE_FAILURE'); assert.equal(failure.first.controllerExit, 1);
  assert.equal(failure.first.prepareDiagnostic.status, 'failed'); assert.equal(failure.first.completedPhases, 0);
});
for (const [label, mutate] of [
  ['wrong MAC', h => h.send(h.payload(), { mac: '0'.repeat(64) })],
  ['wrong nonce', h => h.send(h.payload(), { nonce: 'c'.repeat(32) })],
  ['wrong cell', h => h.send(h.payload({ cellNonce: 'c'.repeat(32) }))],
  ['replay', h => h.send(h.payload({ sequence: 0 }))],
  ['extra command field', h => h.send({ ...h.payload(), extra: 'PRIVATE' })],
  ['unknown command', h => h.send(h.payload(), { kind: 'diagnostic' })],
  ['duplicate field', h => h.send(h.canonical(h.payload()).replace('"stage":', '"stage":"EXECUTE","stage":'))],
  ['escape equivalent duplicate', h => h.send(h.canonical(h.payload()).replace('"stage":', '"st\\u0061ge":"EXECUTE","stage":'))],
  ['extra projected field', h => h.send(h.payload({ diagnostic: prepareRecord({ message: 'PRIVATE' }) }))],
  ['noncanonical slash', h => h.send(JSON.stringify(h.payload()))],
  ['noncanonical whitespace', h => h.send(' ' + h.canonical(h.payload()))],
  ['noncanonical order', h => h.send({ cellNonce: h.cellNonce, sequence: 1, diagnostic: prepareRecord() })],
  ['unknown stage', h => h.send(h.payload({ diagnostic: prepareRecord({ stage: 'PRIVATE' }) }))],
  ['unknown class', h => h.send(h.payload({ diagnostic: prepareRecord({ errorClass: 'PrivateException' }) }))],
  ['unknown origin', h => h.send(h.payload({ diagnostic: prepareRecord({ source: '/private/session' }) }))],
  ['uncoupled line', h => h.send(h.payload({ diagnostic: prepareRecord({ source: null }) }))],
  ['zero line', h => h.send(h.payload({ diagnostic: prepareRecord({ line: 0 }) }))],
  ['large line', h => h.send(h.payload({ diagnostic: prepareRecord({ line: 1000001 }) }))],
  ['string line', h => h.send(h.payload({ diagnostic: prepareRecord({ line: '123' }) }))],
  ['success status', h => h.send(h.payload({ diagnostic: prepareRecord({ status: 'passed' }) }))],
  ['guessed cause', h => h.send(h.payload({ diagnostic: prepareRecord({ rootCause: 'PRIVATE' }) }))],
  ['accepted issue', h => h.send(h.payload({ diagnostic: prepareRecord({ issueAccepted: true }) }))],
  ['oversize projection', h => h.send(h.payload({ diagnostic: prepareRecord({ stage: 'PRIVATE'.repeat(400) }) }))],
  ['oversize payload', h => h.send(h.payload({ diagnostic: prepareRecord({ stage: 'x'.repeat(32769) }) }))],
  ['oversize frame', h => { const socket = h.connect(); socket.emit('data', Buffer.alloc(65537, 120)); return socket; }],
  ['malformed JSON', h => h.send('{')],
  ['malformed UTF8', h => { const socket = h.connect(); socket.emit('data', h.wire(Buffer.from([0xff]))); return socket; }],
]) test('actual authenticated prepare-failure refuses ' + label, async () => {
  const h = await prepareChannel(); const socket = mutate(h); h.closed();
  assert.equal(h.phase.prepareDiagnostic, undefined); assert(socket.destroyed); assert.deepEqual(h.cancellations, ['protocol']);
});
for (const options of [
  { owner: { control: true } }, { owner: { plan: { nonce: 'a'.repeat(32), executionMode: 'diagnostic-only' } } },
  { phase: { kind: 'baseline' } }, { phase: { kind: 'engine' } }, { phase: { kind: 'control' } },
  { phase: { prepareDiagnosticOpen: false } }, { owner: { cancelReason: 'deadline' } },
]) test('actual prepare-failure refuses inactive context ' + JSON.stringify(options), async () => {
  const h = await prepareChannel(options), socket = h.send(); h.closed(); assert(socket.destroyed); assert.equal(h.phase.prepareDiagnostic, undefined);
});
test('actual prepare-failure refuses expired owner old-phase and one-connection repetition; broken ACK never grants success', async () => {
  const expired = await prepareChannel(); expired.owner.leases.parentEnd = 1n; expired.send(); expired.closed(); assert.equal(expired.phase.prepareDiagnostic, undefined);
  const old = await prepareChannel(), socket = old.connect(); old.owner.phase = { ...old.phase };
  socket.emit('data', old.wire(old.canonical(old.payload()))); old.closed(); assert.equal(old.phase.prepareDiagnostic, undefined);
  const repeated = await prepareChannel(), connection = repeated.connect(), raw = repeated.wire(repeated.canonical(repeated.payload()));
  connection.emit('data', Buffer.concat([raw, raw])); repeated.closed(); assert(connection.destroyed); assert(repeated.phase.prepareDiagnostic);
  for (const option of [{ ackError: true }, { outputThrows: true }]) {
    const broken = await prepareChannel(option); broken.send(); broken.closed(); assert.deepEqual(broken.cancellations, ['protocol']);
    assert.equal(broken.phase.prepareDiagnostic.status, 'failed'); assert.equal(broken.owner.phaseResults.length, 0);
  }
});

const capabilityRecord = (changes={}) => ({schema:'tablo/php-capability-observation/v1',reportedExit:1,stdout:'OTHER',stderr:'EMPTY',category:'MISSING_EXTENSION',missingExtension:'curl',...changes});
const prepareV2 = capability => prepareRecord({schema:'tablo/server-prepare-failure/v2',capability});
test('actual authenticated prepare v2 preserves finite capability observations and v1 compatibility',async()=>{
  for(const diagnostic of [prepareRecord(),prepareV2(null),prepareV2(capabilityRecord())]){
    const h=await prepareChannel();h.send(h.payload({diagnostic}));h.closed();assert.deepEqual(h.cancellations,[]);
    assert.deepEqual(JSON.parse(JSON.stringify(h.phase.prepareDiagnostic)),diagnostic);
    assert(Buffer.byteLength(JSON.stringify(diagnostic))<=2048);
  }
  for(const reportedExit of [null,'UNKNOWN',-2147483648,2147483647]){
    const h=await prepareChannel();h.send(h.payload({diagnostic:prepareV2(capabilityRecord({reportedExit}))}));h.closed();assert(h.phase.prepareDiagnostic);
  }
  for(const category of ['MISSING_SOURCE','MISSING_INI','MISSING_LOADED_INI','INI_MISMATCH','TEMP_MISMATCH','READY_WRITE_FAILED','UNKNOWN']){
    const h=await prepareChannel();h.send(h.payload({diagnostic:prepareV2(capabilityRecord({category,missingExtension:null}))}));h.closed();assert(h.phase.prepareDiagnostic);
  }
  for(const missingExtension of ['curl','openssl','pdo','pdo_mysql','pdo_sqlite','mbstring','dom','xml','xmlwriter']){
    const h=await prepareChannel();h.send(h.payload({diagnostic:prepareV2(capabilityRecord({missingExtension}))}));h.closed();assert(h.phase.prepareDiagnostic);
  }
  for(const stdout of ['READY','EMPTY','OTHER','UNKNOWN'])for(const stderr of ['EMPTY','NONEMPTY','UNKNOWN']){
    const h=await prepareChannel();h.send(h.payload({diagnostic:prepareV2(capabilityRecord({stdout,stderr,category:'UNKNOWN',missingExtension:null}))}));h.closed();assert(h.phase.prepareDiagnostic);
  }
});
for(const change of [{reportedExit:'1'},{reportedExit:2147483648},{reportedExit:{}},{stdout:'PRIVATE'},{stderr:'PRIVATE'},
  {category:'PRIVATE'},{missingExtension:'PRIVATE'},{missingExtension:null},{category:'INI_MISMATCH'},{category:'UNKNOWN'},
  {category:'INI_MISMATCH',missingExtension:null,stdout:'READY'},{category:'UNKNOWN',missingExtension:null,stdout:'READY',reportedExit:0},
  {extra:'PRIVATE'},{schema:'tablo/php-capability-observation/v2'}])test('actual prepare v2 rejects hostile or inconsistent capability '+JSON.stringify(change),async()=>{
  const h=await prepareChannel();h.send(h.payload({diagnostic:prepareV2(capabilityRecord(change))}));h.closed();assert.equal(h.phase.prepareDiagnostic,undefined);assert.deepEqual(h.cancellations,['protocol']);
});
test('prepare v2 rejects missing duplicate extra and oversized data without replacing first failure',async()=>{
  for(const diagnostic of [prepareRecord({capability:null}),prepareRecord({schema:'tablo/server-prepare-failure/v2'}),prepareRecord({schema:'tablo/server-prepare-failure/v3'}),
    prepareV2(capabilityRecord({category:'PRIVATE'.repeat(1024)}))]){
    const h=await prepareChannel();h.send(h.payload({diagnostic}));h.closed();assert.equal(h.phase.prepareDiagnostic,undefined);
  }
  const duplicate=await prepareChannel();duplicate.send(duplicate.canonical(duplicate.payload({diagnostic:prepareV2(capabilityRecord())})).replace('"reportedExit":','"reportedExit":null,"reportedExit":'));duplicate.closed();assert.equal(duplicate.phase.prepareDiagnostic,undefined);
  const hostile=await prepareChannel(),callbacks=[];
  assert.throws(()=>hostile.api.prepareDiagnostic(prepareV2(capabilityRecord({category:{toJSON(){callbacks.push('PRIVATE');return 'UNKNOWN';}}}))));
  assert.deepEqual(callbacks,[]);hostile.closed();
  for(const outputFails of [false,true]){
    const h=observerHarness({at:'custody',retentionFails:true,outputFails,prepareDiagnostic:prepareV2(capabilityRecord())});
    await assert.rejects(h.run(),error=>error===h.original);observerClosed(h);
    if(!outputFails){const record=JSON.parse(h.output[0]);assert.equal(record.prepareDiagnostic.capability.reportedExit,1);assert.equal(record.completedPhases,0);assert(Buffer.byteLength(h.output[0])<=4096);}
  }
});
const stderrKinds = ['NONE','MODULE_LOAD_MESSAGE','ZEND_LOADER_MESSAGE','DUPLICATE_MODULE_MESSAGE','PHP_STARTUP_WARNING','PHP_WARNING','PHP_DEPRECATION','PHP_ERROR','UNKNOWN'];
const capabilityV2 = (changes={}) => ({...capabilityRecord(),schema:'tablo/php-capability-observation/v2',stderrKind:'NONE',...changes});
const prepareV3 = capability => prepareRecord({schema:'tablo/server-prepare-failure/v3',capability});
for(const stderrKind of stderrKinds)test('actual prepare v3 carries only the finite stderr shape '+stderrKind,async()=>{
  const diagnostic=prepareV3(capabilityV2({reportedExit:stderrKind==='NONE'?1:0,stdout:'READY',stderr:stderrKind==='NONE'?'EMPTY':'NONEMPTY',category:'UNKNOWN',missingExtension:null,stderrKind}));
  const h=await prepareChannel();h.send(h.payload({diagnostic}));h.closed();assert.deepEqual(h.cancellations,[]);
  assert.deepEqual(JSON.parse(JSON.stringify(h.phase.prepareDiagnostic)),diagnostic);assert.equal(h.owner.phaseResults.length,0);
  assert.equal(diagnostic.rootCause,'UNKNOWN');assert.equal(diagnostic.issueAccepted,false);assert(Buffer.byteLength(JSON.stringify(diagnostic))<=2048);
});
test('prepare consumers explicitly preserve all three version pairings and null cases',async()=>{
  for(const diagnostic of [prepareRecord(),prepareV2(null),prepareV2(capabilityRecord()),prepareV3(null),prepareV3(capabilityV2()),prepareV3(capabilityV2({stderr:'UNKNOWN',stderrKind:'UNKNOWN'}))]) {
    const h=await prepareChannel();h.send(h.payload({diagnostic}));h.closed();assert.deepEqual(h.cancellations,[]);
    assert.deepEqual(JSON.parse(JSON.stringify(h.phase.prepareDiagnostic)),diagnostic);
  }
});
for(const [label,diagnostic] of [
  ['outer1 nested1',prepareRecord({capability:capabilityRecord()})],['outer1 nested2',prepareRecord({capability:capabilityV2()})],
  ['outer2 nested2',prepareV2(capabilityV2())],['outer3 nested1',prepareV3(capabilityRecord())],
  ['unknown outer',prepareRecord({schema:'tablo/server-prepare-failure/v4',capability:capabilityV2()})],
  ['unknown nested',prepareV3(capabilityV2({schema:'tablo/php-capability-observation/v3'}))],
  ['missing kind',prepareV3(capabilityRecord({schema:'tablo/php-capability-observation/v2'}))],
  ['old extra kind',prepareV2({...capabilityRecord(),stderrKind:'NONE'})],['extra nested',prepareV3(capabilityV2({extra:'PRIVATE'}))],
  ['empty unknown',prepareV3(capabilityV2({stderrKind:'UNKNOWN'}))],['empty warning',prepareV3(capabilityV2({stderrKind:'PHP_WARNING'}))],
  ['unknown none',prepareV3(capabilityV2({stderr:'UNKNOWN'}))],['unknown warning',prepareV3(capabilityV2({stderr:'UNKNOWN',stderrKind:'PHP_WARNING'}))],
  ['nonempty none',prepareV3(capabilityV2({stderr:'NONEMPTY'}))],['private kind',prepareV3(capabilityV2({stderrKind:'PRIVATE'}))],
  ['numeric kind',prepareV3(capabilityV2({stderrKind:0}))],['null kind',prepareV3(capabilityV2({stderrKind:null}))],
  ['ready success',prepareV3(capabilityV2({reportedExit:0,stdout:'READY',category:'UNKNOWN',missingExtension:null}))],
  ['reordered nested',prepareV3(Object.fromEntries(Object.entries(capabilityV2()).reverse()))],
])test('actual prepare v3 refuses '+label,async()=>{
  const h=await prepareChannel();h.send(h.payload({diagnostic}));h.closed();assert.equal(h.phase.prepareDiagnostic,undefined);assert.deepEqual(h.cancellations,['protocol']);
});
test('prepare v3 raw duplicate canonical and authentication checks remain closed',async()=>{
  for(const mutate of [raw=>raw.replace('"stderrKind":','"stderrKind":"NONE","stderrKind":'),raw=>raw.replace('"stderrKind":','"stderrK\\u0069nd":"NONE","stderrKind":'),raw=>' '+raw]) {
    const h=await prepareChannel();h.send(mutate(h.canonical(h.payload({diagnostic:prepareV3(capabilityV2())}))));h.closed();assert.equal(h.phase.prepareDiagnostic,undefined);
  }
  const bad=await prepareChannel();bad.send(bad.payload({diagnostic:prepareV3(capabilityV2())}),{mac:'0'.repeat(64)});bad.closed();assert.equal(bad.phase.prepareDiagnostic,undefined);
  const callbacks=[],h=await prepareChannel();
  assert.throws(()=>h.api.prepareDiagnostic(prepareV3(capabilityV2({stderrKind:{toString(){callbacks.push('convert');return 'NONE';},toJSON(){callbacks.push('serialize');return 'NONE';}}}))));
  assert.deepEqual(callbacks,[]);h.closed();
});
test('prepare v3 preserves first authenticated failure and secondary ACK failures',async()=>{
  const diagnostic=prepareV3(capabilityV2({reportedExit:0,stdout:'READY',stderr:'NONEMPTY',category:'UNKNOWN',missingExtension:null,stderrKind:'PHP_WARNING'}));
  const h=await prepareChannel();h.send(h.payload({diagnostic}));const first=h.phase.prepareDiagnostic;
  h.send(h.payload({diagnostic:prepareV3(capabilityV2())}));h.closed();assert.equal(h.phase.prepareDiagnostic,first);assert.deepEqual(h.cancellations,['protocol']);
  for(const option of [{ackError:true},{outputThrows:true}]) {
    const broken=await prepareChannel(option);broken.send(broken.payload({diagnostic}));broken.closed();assert.equal(broken.phase.prepareDiagnostic.status,'failed');assert.equal(broken.owner.phaseResults.length,0);
  }
});
test('prepare v3 console and retention revalidate shape without promoting success or masking first error',async()=>{
  const diagnostic=prepareV3(capabilityV2({reportedExit:0,stdout:'READY',stderr:'NONEMPTY',category:'UNKNOWN',missingExtension:null,stderrKind:'ZEND_LOADER_MESSAGE'}));
  for(const outputFails of [false,true]) {
    const h=observerHarness({at:'custody',retentionFails:true,outputFails,prepareDiagnostic:diagnostic});
    await assert.rejects(h.run(),error=>error===h.original);observerClosed(h);
    if(!outputFails) {const record=JSON.parse(h.output[0]);assert.deepEqual(record.prepareDiagnostic,diagnostic);assert.equal(record.completedPhases,0);assert.equal(record.rootCause,'UNKNOWN');assert(Buffer.byteLength(h.output[0])<=4096);}
  }
  const invalid=observerHarness({at:'custody',prepareDiagnostic:prepareV3(capabilityRecord({extra:'PRIVATE'}))});
  await assert.rejects(invalid.run(),error=>error===invalid.original);observerClosed(invalid);
  assert.equal(JSON.parse(invalid.output[0]).prepareDiagnostic,'MISSING');assert(!invalid.output[0].includes('PRIVATE'));
});

test('qualification v3 distinguishes uninitialized and admitted absent events and latches first cancellation snapshot',()=>{
  const unknown={ready:'UNKNOWN',started:'UNKNOWN',input:'UNKNOWN',exited:'UNKNOWN',closed:'UNKNOWN'};
  assert.deepEqual(eventPresence(),unknown);assert.deepEqual(eventPresence({}),unknown);
  const state={ready:null,started:null,input:null,exited:null,closed:null};
  const owner={adapterState:state,cancelReason:null}, sent=[];owner.send=(kind,payload)=>sent.push({kind,payload});
  assert.deepEqual(eventPresence(state),{ready:false,started:false,input:false,exited:false,closed:false});
  state.ready={private:'PRIVATE'};cancelOwner(owner,'deadline');const first=owner.eventsAtCancellation;
  state.started={};state.input={};state.exited={};state.closed={};cancelOwner(owner,'protocol');
  assert.equal(owner.eventsAtCancellation,first);assert.equal(owner.cancelReason,'deadline');assert.equal(sent.length,1);
  const value=projectFailure({stage:'CLIENT_RUNTIME',subject:'docker',check:'Q100',owner});
  assert.equal(value.schema,'tablo/linux-safe-qualification-failure/v3');assert.deepEqual(value.eventsAtCancellation,{ready:true,started:false,input:false,exited:false,closed:false});
  assert.deepEqual(value.eventsAtFailure,{ready:true,started:true,input:true,exited:true,closed:true});assert(!JSON.stringify(value).includes('PRIVATE'));assert(Buffer.byteLength(JSON.stringify(value,null,2))<=4096);
  const primary=Error('PRIVATE send'),broken={cancelReason:null,send(){throw primary;},adapterState:state};
  assert.throws(()=>cancelOwner(broken,'deadline'),error=>error===primary);const snapshot=broken.eventsAtCancellation;cancelOwner(broken,'protocol');assert.equal(broken.eventsAtCancellation,snapshot);
  const reads=[],hostile={cancelReason:null,adapterState:new Proxy({}, {getOwnPropertyDescriptor(){reads.push('projection');throw primary;}})};
  cancelOwner(hostile,'deadline');assert.equal(hostile.cancelReason,'deadline');assert.deepEqual(reads,['projection']);
  assert.deepEqual(projectFailure({owner:{eventsAtCancellation:{ready:'PRIVATE'}}}).eventsAtCancellation,unknown);
});
