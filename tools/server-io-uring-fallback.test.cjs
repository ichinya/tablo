'use strict';
// Actual pinned owners, synthetic bytes and a closed in-memory filesystem only.
const test = require('node:test'), assert = require('node:assert/strict');
const fs = require('node:fs'), vm = require('node:vm'), path = require('node:path');
const { createHash } = require('node:crypto');
const { EventEmitter } = require('node:events');
const { LinuxRecords, acceptEvent } = require('./server-supervisor.cjs');
const { projectFailure } = require('./server-qualification-diagnostic.cjs');
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
function ownerModule(files) {
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
      if (name === 'node:net') return new Proxy({}, { get() { tripwire('FORBIDDEN_NETWORK'); } });
      if (['node:crypto','node:util'].includes(name)) return require(name);
      tripwire('UNEXPECTED_IMPORT ' + name);
    } };
  vm.runInNewContext(source, sandbox, { filename: sandbox.__filename });
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
test('safe projection v2 preserves both new counters without minting success or raising bounds',()=>{
  const result=projectFailure({stage:'FALLBACK_CONTROL',subject:'uring-mixed-quota',index:6,check:'Q146',completed:38,
    owner:{adapterState:{closed:{admission:admission(6,5,4,true)}}}});
  assert.equal(result.schema,'tablo/linux-safe-qualification-failure/v2');assert.equal(result.admission.ioUringSetupAttempts,5);
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
      if(name==='./server-qualification-diagnostic.cjs')return {projectFailure};
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
