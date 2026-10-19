'use strict';
// Source authoring does not authorize execution. Native/client qualification is
// opt-in through one pinned finite plan. No sockets, services or installation input.
const fs = require('node:fs/promises');
const { createHash, randomBytes } = require('node:crypto');
const { spawn } = require('node:child_process');
const assert = require('node:assert/strict');
const path = require('node:path');
const api = require('./server-supervisor.cjs');
const sha = bytes => createHash('sha256').update(bytes).digest('hex');
const pin = async path => ({ path, sha256: sha(await fs.readFile(path)) });
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));

const { projectFailure } = require('./server-qualification-diagnostic.cjs');
const progress = { stage: 'SETUP', subject: null, index: null, check: null, completed: 0 };
let safeFailurePath = null, firstFailure = null;
function check(id, assertion, ...args) { return checked(progress, id, assertion, ...args); }
function checked(state, id, assertion, ...args) { state.check = id; return assertion(...args); }
function attempt(stage, subject = null, index = null) {
  Object.assign(progress, { stage, subject, index, check: null, owner: null, nativeStatus: null });
}

async function main() {
  check('Q001', assert.equal, process.platform, 'linux'); check('Q002', assert.equal, process.arch, 'x64'); check('Q003', assert.equal, process.version, 'v24.13.0');
  check('Q004', assert.equal, process.argv.length, 4); check('Q005', assert.match, process.argv[2], /^--plan=\//); check('Q006', assert.match, process.argv[3], /^--sha256=[0-9a-f]{64}$/);
  const planFile = process.argv[2].slice(7), spec = await api.readPlan(planFile, process.argv[3].slice(9));
  check('Q007', assert.equal, spec.schema, 'tablo/linux-admission-qualification-plan/v1'); check('Q008', assert.match, spec.nonce, /^[0-9a-f]{32}$/);
  check('Q009', assert, ['neutral-only', 'neutral-diagnostic', 'qualify'].includes(spec.mode));
  if (spec.mode === 'qualify') {
    check('Q010', assert.deepEqual, Object.keys(spec.runtimes).sort(), ['composer','docker','node','php','shell']);
    spec.runtimes = Object.fromEntries(['node','php','composer','shell','docker'].map(name => [name,
      { path: spec.runtimes[name].path, sha256: spec.runtimes[name].sha256 }]));
    check('Q011', assert.deepEqual, Object.keys(spec.phpBootstrap).sort(), ['extensionFiles','iniFiles']);
    check('Q012', assert, Array.isArray(spec.phpBootstrap.iniFiles) && spec.phpBootstrap.iniFiles.length >= 1 && spec.phpBootstrap.iniFiles.length <= 128);
    check('Q013', assert, Array.isArray(spec.phpBootstrap.extensionFiles) && spec.phpBootstrap.extensionFiles.length <= 128);
    for (const tool of [...spec.phpBootstrap.iniFiles, ...spec.phpBootstrap.extensionFiles]) {
      await api.regular(tool.path); await api.pinned(tool);
    }
  }
  const nonce = spec.nonce, base = path.dirname(planFile) + '/tablo-linux-' + nonce;
  safeFailurePath = path.dirname(planFile) + '/safe-qualification.json';
  check('Q014', assert.equal, spec.root, base); check('Q015', assert.equal, spec.outputQualification, base + '/qualification.json');
  for (const item of [spec.owner, spec.qualifier, spec.controls, spec.controlsSource, spec.platformReceipt]) {
    await api.regular(item.path); await api.pinned(item);
  }
  check('Q016', assert.equal, spec.owner.path, __dirname + '/server-supervisor.cjs'); check('Q017', assert.equal, spec.qualifier.path, __filename);
  const prepared = await api.readPlan(spec.platformReceipt.path, spec.platformReceipt.sha256);
  check('Q018', assert.equal, prepared.schema, 'tablo/linux-prepared-platform/v1'); check('Q019', assert.equal, prepared.nonce, spec.nonce);
  check('Q020', assert.equal, spec.admissionFile, path.dirname(planFile) + '/platform-admission.json');
  await api.regular(spec.admissionFile); spec.admission = await pin(spec.admissionFile);
  const admission = await api.readPlan(spec.admission.path, spec.admission.sha256);
  check('Q021', assert.equal, admission.schema, 'tablo/linux-platform-admission/v1'); check('Q022', assert.equal, admission.nonce, spec.nonce);
  check('Q023', assert, ['host', 'container'].includes(admission.kind));
  if (admission.kind === 'container') { check('Q024', assert.match, admission.containerId, /^[0-9a-f]{64}$/); check('Q025', assert.equal, admission.containerName, prepared.containerName); check('Q026', assert.equal, admission.ownedConfigValidated, true); }
  await fs.mkdir(base, { mode: 0o700 });
  const observer = spec.observer; await api.linuxPins(observer, true);
  const controls = spec.controls;
  const identity = { schema: 'tablo/linux-neutral-platform/v1', nonce, node: await pin(process.execPath), nodeVersion: process.version,
    owner: spec.owner, qualifier: spec.qualifier, controls, controlsSource: spec.controlsSource, ...observer,
    kernel: require('node:os').release(), platformReceipt: spec.platformReceipt, qualification: 'PENDING' };
  await api.retain(base + '/identity.json', identity);
  const parentEnd = process.hrtime.bigint() + 1800_000_000_000n, outcomes = [];
  const diagnostic = spec.mode === 'neutral-diagnostic';
  let namespaceProbe = null, externalNamespaceDenial = false;
  if (diagnostic) {
    attempt('NAMESPACE_PROBE', 'namespace', 0);
    // A genuine untraced invocation of the same pinned one-syscall control, in
    // this same sandbox. EPERM is reported by that control only after checking
    // the exact errno. This is external-policy evidence, never native coverage.
    const issued = process.hrtime.bigint(), end = issued + 8_000_000_000n; check('Q027', assert, end < parentEnd);
    const child = spawn(spec.controls.path, ['namespace'], {
      cwd: base, env: { PATH: '/usr/local/bin:/usr/bin:/bin' }, stdio: ['ignore', 'pipe', 'pipe']
    });
    let stdout = '', stderr = '', expired = false, overflow = false, spawnError = null;
    child.on('error', error => { spawnError = String(error); });
    child.stdout.on('data', b => { if (Buffer.byteLength(stdout) + b.length > 1024) overflow = true; else stdout += b.toString(); });
    child.stderr.on('data', b => { if (Buffer.byteLength(stderr) + b.length > 4096) overflow = true; else stderr += b.toString(); });
    let finish;
    const terminal = new Promise(resolve => {
      finish = resolve; child.once('close', (code, signal) => resolve({ code, signal, closeObserved: true }));
    });
    const timer = setInterval(() => {
      if (overflow || process.hrtime.bigint() >= end - 1_000_000_000n) { expired = true; child.kill('SIGKILL'); }
      if (process.hrtime.bigint() >= end) {
        child.unref(); child.stdout.destroy(); child.stderr.destroy();
        finish({ code: null, signal: null, closeObserved: false });
      }
    }, 10);
    let status;
    try { status = await terminal; progress.nativeStatus = status; }
    finally { clearInterval(timer); }
    progress.nativeStatus = { ...status, spawnError: spawnError !== null, deadlineExpired: expired, outputOverflow: overflow };
    namespaceProbe = { schema: 'tablo/linux-external-namespace-probe/v1', nonce, executable: spec.controls,
      argv: ['namespace'], traced: false, actualPid: child.pid, originalSeconds: 8, settlementSeconds: 1,
      status, stdout, stderr, expired, overflow, spawnError, nativeLatchCoverage: false };
    await api.retain(base + '/namespace-capability.json', namespaceProbe);
    check('Q028', assert.equal, expired, false); check('Q029', assert.equal, overflow, false); check('Q030', assert.equal, spawnError, null);
    check('Q031', assert.deepEqual, status, { code: 0, signal: null, closeObserved: true }); check('Q032', assert.equal, stderr, '');
    check('Q033', assert.equal, stdout, 'ESCAPE_DENIED_ROOT_ZERO\n');
  }
  let sentinel, sentinelBytes = '', sentinelError = null;
  const startSentinel = async () => {
    sentinelBytes = ''; sentinelError = null;
    sentinel = spawn(controls.path, ['sentinel'], { env: { PATH: '/usr/local/bin:/usr/bin:/bin' }, stdio: ['ignore', 'pipe', 'pipe'] });
    sentinel.on('error', error => { sentinelError = error; });
    sentinel.stdout.on('data', bytes => { sentinelBytes += bytes.toString(); if (sentinelBytes.length > 65536) sentinelError = Error('SENTINEL_OUTPUT'); });
    sentinel.stderr.on('data', () => { sentinelError = Error('SENTINEL_STDERR'); });
    for (let i = 0; i < 100 && !sentinelBytes.includes('SENTINEL_TICK\n') && !sentinelError; i++) await pause(10);
    check('Q034', assert.ifError, sentinelError); check('Q035', assert.match, sentinelBytes, /FOREIGN_COORDINATOR_OWNED_SENTINEL\nSENTINEL_TICK/);
  };
  const stopSentinel = async () => {
    if (!sentinel) return;
    if (sentinel.exitCode !== null || sentinel.signalCode !== null) { sentinel = null; return; }
    const close = new Promise(resolve => sentinel.once('close', (code, signal) => resolve({ code, signal })));
    check('Q036', assert.equal, sentinel.kill('SIGTERM'), true); const status = await close;
    check('Q037', assert.equal, status.signal, 'SIGTERM'); sentinel = null;
  };
  const cases = [
    ['limit8', 8, 8, 'ok'], ['limit9', 9, 8, 'ok'],
    ['overflow8', 8, 8, 'denied'], ['overflow9', 9, 8, 'denied'],
    ['threads', 8, 8, 'ok'], ['threadfork', 8, 8, 'ok'], ['threadexec', 8, 8, 'exit23'],
    ['concurrent', 8, 8, 'denied'], ['vfork', 8, 8, 'ok'], ['spawn', 8, 8, 'ok'],
    ['short', 8, 8, 'ok'], ['failedfork', 8, 8, 'ok'], ['clone3', 8, 8, 'ok'],
    ['untraced', 8, 8, 'denied'], ['filter', 8, 8, 'denied'], ['namespace', 8, 8, 'denied'], ['ptrace', 8, 8, 'denied'],
    ['x32', 8, 8, 'sigsys'], ['i386', 8, 8, 'sigsys'],
    ['stdin0', 8, 8, 'ok'], ['stdin65536', 8, 8, 'ok'], ['exit23', 8, 8, 'exit23'], ['signal', 8, 8, 'signal'],
    ['rootfirst', 8, 8, 'cancelled'], ['owner-eof', 8, 8, 'cancelled'], ['deadline', 8, 8, 'cancelled'], ['deadline', 8, 20, 'cancelled']
  ];
  const boundary = async (kind, index) => {
    attempt('INPUT_BOUNDARY', kind, index);
    const root = base + '/' + kind; await fs.mkdir(root, { mode: 0o700 });
    const out = await fs.open(root + '/stdout', 'wx', 0o600), err = await fs.open(root + '/stderr', 'wx', 0o600);
    const issued = process.hrtime.bigint(), end = issued + 8_000_000_000n;
    const child = spawn(observer.linuxAdapter.path, ['8', end.toString(), '65536', '--', controls.path, 'stdin65536'], {
      cwd: root, env: { PATH: '/usr/local/bin:/usr/bin:/bin' },
      stdio: ['pipe', 'pipe', 'pipe', kind === 'invalid-capture' ? 'pipe' : out.fd, err.fd, 'pipe']
    });
    const records = new api.LinuxRecords(), seen = [], faults = [];
    let raw = '', nativeStderr = '', forced = false;
    child.stdin.on('error', error => faults.push(String(error)));
    child.stdio[5].on('error', error => faults.push(String(error)));
    child.on('error', error => faults.push(String(error)));
    child.stderr.on('data', bytes => { nativeStderr += bytes; });
    child.stdout.on('data', bytes => {
      raw += bytes;
      try {
        records.push(bytes, (event, values) => {
          seen.push({ event, values });
          if (event === 'READY') {
            check('Q038', assert.equal, values[0], child.pid); check('Q039', assert, BigInt(values[2]) >= issued && BigInt(values[2]) <= process.hrtime.bigint());
            child.stdin.write('LAUNCH\n');
          }
          if (event === 'STARTED' && kind === 'partial-input') child.stdio[5].write(Buffer.from([0x5a]));
          if (event === 'CLOSED') child.stdin.write('SHUTDOWN\n');
        });
      } catch (error) { faults.push(String(error)); child.stdin.end(); }
    });
    const timer = setInterval(() => { if (process.hrtime.bigint() >= end) { forced = true; child.kill('SIGKILL'); } }, 10);
    let status;
    try { status = await new Promise(resolve => child.once('close', (code, signal) => resolve({ code, signal }))); }
    finally { clearInterval(timer); child.stdio[5].destroy(); await out.close(); await err.close(); }
    progress.nativeStatus = { ...status, closeObserved: true, deadlineExpired: forced, protocolFaultCount: faults.length };
    await fs.writeFile(root + '/native.stdout', raw, { flag: 'wx' });
    await fs.writeFile(root + '/native.stderr', nativeStderr, { flag: 'wx' });
    check('Q040', assert.equal, forced, false); check('Q041', assert.deepEqual, faults, []); check('Q042', assert.equal, status.code, 125); check('Q043', assert.equal, status.signal, null);
    check('Q044', assert.equal, nativeStderr, ''); check('Q045', assert.equal, records.pending.length, 0);
    if (kind === 'invalid-capture') check('Q046', assert.deepEqual, seen, []);
    else {
      const input = seen.find(record => record.event === 'INPUT'), close = seen.find(record => record.event === 'CLOSED');
      check('Q047', assert, input && close); check('Q048', assert.deepEqual, input.values, [65536, kind === 'partial-input' ? 1 : 0, 1, 0]);
      check('Q049', assert.equal, close.values[0], 0); check('Q050', assert.equal, close.values[1], 0); check('Q051', assert.equal, close.values[11], 1); check('Q052', assert.equal, close.values[12], 1);
    }
    const result = { kind, status: 'passed', expectedFailure: true, nativeStatus: status, seen };
    await fs.writeFile(root + '/result.json', JSON.stringify(result, null, 2) + '\n', { flag: 'wx' }); outcomes.push(result); progress.completed = outcomes.length;
  };
  try {
    for (let index = 0; index < cases.length; index++) {
      const [mode, limit, seconds, expectation] = cases[index];
      attempt('NEUTRAL_CONTROL', mode, index);
      // The foreign owned sentinel is present only with cap 8: owner1+adapter1+
      // scope8+sentinel1 <=11. Cap9 cells have no sentinel and remain <=11.
      if (limit === 8 && ['overflow8', 'owner-eof', 'deadline'].includes(mode)) await startSentinel();
      const before = sentinelBytes.length;
      const root = base + '/case-' + index; await fs.mkdir(root, { mode: 0o700 });
      for (const name of ['private', 'evidence', 'cwd']) await fs.mkdir(root + '/' + name, { mode: 0o700 });
      let input = { mode: 'none', path: null, sha256: null, bytes: 0 };
      if (mode === 'stdin65536') {
        const bytes = Buffer.alloc(65536, 0x5a), path = root + '/private/input.bin';
        await fs.writeFile(path, bytes, { flag: 'wx', mode: 0o600 }); input = { mode: 'synthetic-file', path, sha256: sha(bytes), bytes: bytes.length };
      }
      const issued = process.hrtime.bigint(), end = issued + BigInt(seconds) * 1_000_000_000n;
      check('Q053', assert, end < parentEnd);
      const owner = { phase: { root, adapterAuthority: randomBytes(32) }, cancelReason: null };
      owner.cancel = reason => { if (!owner.cancelReason) { owner.cancelReason = reason; owner.send?.('cancel', { reason }); } };
      const plan = { schema: 'tablo/server-observer-linux-plan/v1', nonce, planId: nonce + '/linux-controller/' + index, root,
        adapter: observer.linuxAdapter, linux: { policy: 'tgid-seccomp-ptrace-x64-v1', unit: 'process/TGID',
          source: observer.linuxAdapterSource, recipe: observer.linuxAdapterRecipe, build: observer.linuxAdapterBuild,
          ownerMonotonicNanoseconds: issued.toString(), deadlineNanoseconds: end.toString() }, job: { activeProcessLimit: limit },
        launch: { executable: controls, argv: [mode], cwd: root + '/cwd', environment: { PATH: '/usr/local/bin:/usr/bin:/bin' },
          stdout: root + '/evidence/controller.stdout', stderr: root + '/evidence/controller.stderr', input } };
      let injected = false;
      const timer = setInterval(() => {
        if (process.hrtime.bigint() >= end - 1_000_000_000n) owner.cancel('deadline');
        if (process.hrtime.bigint() >= end) owner.expire?.();
        if (mode === 'owner-eof' && owner.adapterState?.started && !injected) {
          injected = true;
          void (async () => {
            while (process.hrtime.bigint() < end - 1_000_000_000n) {
              const text = await fs.readFile(plan.launch.stdout, 'utf8');
              if (text.includes('OWNED_SETSID_DESCENDANT')) { owner.adapter.stdin.end(); return; }
              await pause(10);
            }
            owner.cancel('deadline');
          })().catch(() => owner.cancel('protocol'));
        }
      }, 10);
      let state; progress.owner = owner;
      try { state = await api.runLinuxAdapter(owner, plan); }
      finally { clearInterval(timer); }
      const capture = await fs.readFile(plan.launch.stdout, 'utf8');
      check('Q054', assert, !capture.includes('FORBIDDEN_'));
      check('Q055', assert, state.ready && state.started && state.exited && state.input && state.closed);
      check('Q056', assert.equal, state.closed.admission.waitEchild, true); check('Q057', assert.equal, state.closed.admission.liveTgids, 0);
      check('Q058', assert.equal, state.closed.admission.pendingReservations, 0); check('Q059', assert, state.closed.admission.peakCommitted <= limit);
      check('Q060', assert.equal, owner.adapterClose.signal, null); check('Q061', assert.equal, owner.adapterClose.stderrBytes, 0);
      let denialEvidence = null;
      if (diagnostic && mode === 'namespace' && state.closed.admission.deniedAdmissions === 0) {
        check('Q062', assert, namespaceProbe); check('Q063', assert.equal, namespaceProbe.nativeLatchCoverage, false);
        check('Q064', assert.equal, capture, 'ESCAPE_DENIED_ROOT_ZERO\n'); check('Q065', assert.equal, state.exited.exitCode, 0); check('Q066', assert.equal, state.exited.posixSignal, null);
        check('Q067', assert.equal, owner.adapterClose.code, 0); check('Q068', assert.equal, state.closed.admission.failed, false);
        check('Q069', assert.equal, state.closed.admission.processBirths, 1); check('Q070', assert.equal, state.closed.admission.processReservations, 0);
        check('Q071', assert.equal, state.closed.admission.threadBirths, 0); check('Q072', assert.equal, state.closed.admission.terminalWaits, 1);
        denialEvidence = 'EXTERNAL_EPERM_NOT_NATIVE_LATCH_COVERAGE'; externalNamespaceDenial = true;
      } else if (expectation === 'ok' || expectation === 'exit23' || expectation === 'signal') {
        check('Q073', assert.equal, owner.adapterClose.code, 0); check('Q074', assert.equal, state.closed.admission.failed, false);
        check('Q075', assert.equal, state.closed.admission.deniedAdmissions, 0);
        check('Q076', assert.equal, state.exited.exitCode, expectation === 'ok' ? 0 : expectation === 'exit23' ? 23 : null);
        check('Q077', assert.equal, state.exited.posixSignal, expectation === 'signal' ? 10 : null);
      } else {
        check('Q078', assert.equal, owner.adapterClose.code, 125); check('Q079', assert.equal, state.closed.admission.failed, true);
        if (expectation === 'denied') { check('Q080', assert, state.closed.admission.deniedAdmissions > 0); check('Q081', assert.equal, state.exited.exitCode, 0); }
        if (expectation === 'sigsys') check('Q082', assert.equal, state.exited.posixSignal, 31);
      }
      if (mode.startsWith('limit') || mode.startsWith('overflow')) check('Q083', assert.equal, state.closed.admission.peakCommitted, limit);
      if (mode === 'threads') check('Q084', assert, state.closed.admission.threadBirths >= 4);
      if (mode === 'failedfork') check('Q085', assert, state.closed.admission.failedCreations >= 1);
      if (sentinel) {
        for (let i = 0; i < 100 && sentinelBytes.length === before; i++) await pause(10);
        check('Q086', assert.ifError, sentinelError); check('Q087', assert, sentinelBytes.length > before); check('Q088', assert.equal, sentinel.exitCode, null);
        await fs.writeFile(root + '/evidence/foreign-sentinel.stdout', sentinelBytes, { flag: 'wx' }); await stopSentinel();
      }
      const result = { mode, limit, seconds, expectation, status: denialEvidence ? 'diagnostic-external-denial' : 'passed',
        denialEvidence, nativeLatchCoverage: denialEvidence ? false : null, adapterClose: owner.adapterClose, events: state };
      outcomes.push(result); progress.completed = outcomes.length;
      await fs.writeFile(root + '/result.json', JSON.stringify(result, null, 2) + '\n', { flag: 'wx' });
    }
    for (const [index, kind] of ['partial-input', 'unresponsive-input', 'invalid-capture'].entries()) await boundary(kind, index);
    await api.retain(base + '/neutral-controls.json', { status: diagnostic ? 'diagnostic-passed' : 'passed', nonce: spec.nonce, count: outcomes.length,
      externalNamespaceDenial, nativeNamespaceLatchCoverage: !externalNamespaceDenial, namespaceProbe,
      controlsSourceSha256: spec.controlsSource.sha256, controlsBinarySha256: controls.sha256, outcomes });
    await qualifyRemaining(spec, base, observer, controls, outcomes, parentEnd);
    await api.retain(base + '/neutral-result.json', { status: diagnostic ? 'diagnostic-passed' : 'passed', outcomes,
      qualification: diagnostic ? 'UNQUALIFIED_DIAGNOSTIC_ONLY' : spec.mode === 'qualify' ? 'QUALIFIED_BY_REQUIRED_CONTROLS' : 'NEUTRAL_ONLY_CLIENT_RUNTIME_NOTRUN' });
  } catch (error) {
    firstFailure ??= projectFailure(progress);
    await fs.writeFile(base + '/failed.json', JSON.stringify({ status: 'failed', completed: outcomes.length, error: String(error),
      qualification: 'UNQUALIFIED_NO_RETRY' }, null, 2) + '\n', { flag: 'wx' }); throw error;
  } finally { await stopSentinel(); }
}
async function qualifyRemaining(spec, base, observer, controls, outcomes, parentEnd) {
  attempt('TRACER_WITNESS', 'witness', 0);
  const root = base + '/tracer-death'; await fs.mkdir(root, { mode: 0o700 });
  const end = process.hrtime.bigint() + 8_000_000_000n; check('Q089', assert, end < parentEnd);
  const child = spawn(controls.path, ['witness', observer.linuxAdapter.path, controls.path, end.toString()], {
    cwd: root, env: { PATH: '/usr/local/bin:/usr/bin:/bin' }, stdio: ['ignore', 'pipe', 'pipe']
  });
  let stdout = Buffer.alloc(0), stderr = Buffer.alloc(0), overflow = false, expired = false, spawnError = null;
  child.on('error', error => { spawnError = String(error); });
  child.stdout.on('data', b => { if (stdout.length + b.length > 1024) overflow = true; else stdout = Buffer.concat([stdout, b]); });
  child.stderr.on('data', b => { if (stderr.length + b.length > 4096) overflow = true; else stderr = Buffer.concat([stderr, b]); });
  const timer = setInterval(() => { if (overflow || process.hrtime.bigint() >= end) { expired = true; child.kill('SIGKILL'); } }, 10);
  let status;
  try { status = await new Promise(resolve => child.once('close', (code, signal) => resolve({ code, signal }))); }
  finally { clearInterval(timer); }
  progress.nativeStatus = { ...status, closeObserved: true, spawnError: spawnError !== null, deadlineExpired: expired, outputOverflow: overflow };
  const witness = { status, stdout: stdout.toString(), stderr: stderr.toString(), overflow, expired, spawnError,
    processGraph: { node: 1, reaper: 1, native: 1, maximumScope: 8, maximumTotal: 11, foreignSentinel: 0 } };
  await api.retain(root + '/result.json', witness);
  check('Q090', assert.equal, expired, false); check('Q091', assert.equal, overflow, false); check('Q092', assert.equal, spawnError, null);
  check('Q093', assert.deepEqual, status, { code: 0, signal: null }); check('Q094', assert.equal, stderr.length, 0);
  check('Q095', assert.equal, stdout.toString(), 'WITNESS_CLOSED 3 3 SIGKILL ECHILD\n');
  const runtimeResults = { status: 'passed', nonce: spec.nonce, runtimes: spec.runtimes ?? null, phpBootstrap: spec.phpBootstrap ?? null,
    owner: spec.owner, qualifier: spec.qualifier, witness, clients: [] };
  if (spec.mode !== 'qualify') { await api.retain(base + '/runtime-controls.json', { ...runtimeResults, clients: 'NOTRUN' }); return; }
  check('Q096', assert.deepEqual, Object.keys(spec.runtimes).sort(), ['composer', 'docker', 'node', 'php', 'shell']);
  for (const tool of Object.values(spec.runtimes)) { await api.regular(tool.path); await api.pinned(tool); }
  check('Q097', assert.equal, spec.runtimes.node.path, process.execPath);
  const cases = [
    ['node', spec.runtimes.node, ['--eval', "const{Worker}=require('node:worker_threads');const{spawnSync}=require('node:child_process');const w=new Worker('process.exit(0)',{eval:true});const c=spawnSync(process.execPath,['--version'],{encoding:'utf8'});if(c.status!==0||c.signal)process.exit(91);w.on('exit',n=>{if(n)process.exit(92);process.stdout.write('NODE_WORKER_CHILD_OK '+process.version+'\\n')});"], /NODE_WORKER_CHILD_OK v24\.13\.0/],
    ['php', spec.runtimes.php, ['-n', '-r', "$p=proc_open([PHP_BINARY,'-n','-r','echo strlen(stream_get_contents(STDIN));'],[['pipe','r'],['pipe','w'],['pipe','w']],$s);if(!is_resource($p))exit(91);fclose($s[0]);$o=stream_get_contents($s[1]);$e=stream_get_contents($s[2]);fclose($s[1]);fclose($s[2]);$c=proc_close($p);if($c!==0||$o!=='0'||$e!=='')exit(92);echo 'PHP_PROC_EOF_OK '.PHP_VERSION;"], /PHP_PROC_EOF_OK (\d+)\.(\d+)\.(\d+)/],
    ['composer', spec.runtimes.php, ['-c', 'OWNED_COMPOSER_INI', spec.runtimes.composer.path, '--no-plugins', '--no-interaction', '--version'], /Composer version 2\./],
    ['shell', spec.runtimes.shell, ['-c', '"$1" exit0 & wait; printf SHELL_CHILD_OK', 'scope-shell', controls.path], /SHELL_CHILD_OK/],
    ['docker', spec.runtimes.docker, ['--version'], /^Docker version /]
  ];
  for (const [index, [label, executable, argv, expected]] of cases.entries()) {
    attempt('CLIENT_RUNTIME', label, index);
    const phaseRoot = base + '/client-' + label; await fs.mkdir(phaseRoot, { mode: 0o700 });
    for (const dir of ['private', 'evidence', 'cwd']) await fs.mkdir(phaseRoot + '/' + dir, { mode: 0o700 });
    if (label === 'composer') {
      const ini = phaseRoot + '/private/composer.ini';
      let bytes = '[PHP]\nauto_prepend_file=\nauto_append_file=\nopcache.preload=\n';
      for (const tool of spec.phpBootstrap.extensionFiles) {
        check('Q098', assert.match, tool.path, /^\/[a-zA-Z0-9_./+-]+$/); // Finite generated INI grammar, no guessed DLL or extension directory.
        bytes += 'extension=' + tool.path + '\n';
      }
      await fs.writeFile(ini, bytes, { flag: 'wx', mode: 0o600 }); argv[1] = ini;
    }
    const issued = process.hrtime.bigint(), deadline = issued + 8_000_000_000n; check('Q099', assert, deadline < parentEnd);
    const owner = { phase: { root: phaseRoot, adapterAuthority: randomBytes(32) }, cancelReason: null };
    owner.cancel = reason => { if (!owner.cancelReason) { owner.cancelReason = reason; owner.send?.('cancel', { reason }); } };
    const plan = { schema: 'tablo/server-observer-linux-plan/v1', nonce: spec.nonce, planId: spec.nonce + '/client/' + label,
      adapter: observer.linuxAdapter, linux: { policy: 'tgid-seccomp-ptrace-x64-v1', unit: 'process/TGID',
        source: observer.linuxAdapterSource, recipe: observer.linuxAdapterRecipe, build: observer.linuxAdapterBuild,
        deadlineNanoseconds: deadline.toString(), ownerMonotonicNanoseconds: issued.toString() }, job: { activeProcessLimit: 8 },
      launch: { executable, argv, cwd: phaseRoot + '/cwd', environment: { PATH: '/usr/local/bin:/usr/bin:/bin',
        HOME: phaseRoot + '/private', COMPOSER_HOME: phaseRoot + '/private', COMPOSER_DISABLE_NETWORK: '1',
        DOCKER_CONFIG: phaseRoot + '/private', PHP_INI_SCAN_DIR: '' }, stdout: phaseRoot + '/evidence/controller.stdout',
        stderr: phaseRoot + '/evidence/controller.stderr', input: { mode: 'none', path: null, bytes: 0, sha256: null } } };
    const timer = setInterval(() => { if (process.hrtime.bigint() >= deadline - 1_000_000_000n) owner.cancel('deadline'); if (process.hrtime.bigint() >= deadline) owner.expire?.(); }, 10);
    let events; progress.owner = owner;
    try { events = await api.runLinuxAdapter(owner, plan); } finally { clearInterval(timer); }
    await api.retain(phaseRoot + '/result.json', { label, adapterClose: owner.adapterClose, events });
    check('Q100', assert.equal, owner.cancelReason, null); check('Q101', assert.equal, owner.adapterClose.code, 0); check('Q102', assert.equal, owner.adapterClose.signal, null);
    check('Q103', assert.equal, owner.adapterClose.stderrBytes, 0); check('Q104', assert, events.closed && events.input && events.exited);
    check('Q105', assert.equal, events.exited.exitCode, 0); check('Q106', assert.equal, events.exited.posixSignal, null);
    check('Q107', assert.equal, events.closed.admission.failed, false); check('Q108', assert.equal, events.closed.admission.deniedAdmissions, 0);
    check('Q109', assert.equal, events.closed.admission.waitEchild, true); check('Q110', assert.equal, events.input.sourceEofObserved, true);
    const output = await fs.readFile(plan.launch.stdout, 'utf8'); check('Q111', assert.match, output, expected);
    let version = null;
    if (label === 'php') { const m = output.match(expected); check('Q112', assert.equal, Number(m[1]), 8); check('Q113', assert, Number(m[2]) >= 2); version = m.slice(1,4).join('.'); }
    runtimeResults.clients.push({ label, executable, argv, version, receipt: await pin(phaseRoot + '/result.json') });
  }
  attempt('PUBLICATION');
  await api.retain(base + '/runtime-controls.json', runtimeResults);
  await api.retain(base + '/platform.json', { schema: 'tablo/linux-qualified-platform/v1', nonce: spec.nonce,
    context: await api.linuxContext(), runtimes: spec.runtimes, phpBootstrap: spec.phpBootstrap,
    preparedReceipt: spec.platformReceipt, admission: spec.admission });
  const b = await api.readPlan(observer.linuxAdapterBuild.path, observer.linuxAdapterBuild.sha256);
  await api.retain(spec.outputQualification, { schema: 'tablo/linux-adapter-qualification/v2', policy: 'tgid-seccomp-ptrace-x64-v1', status: 'qualified',
    binarySha256: b.binarySha256, sourceSha256: b.sourceSha256, recipeSha256: b.recipeSha256, buildSha256: observer.linuxAdapterBuild.sha256,
    controlsSourceSha256: spec.controlsSource.sha256, controlsBinarySha256: controls.sha256,
    runtimeControlsSha256: (await pin(base + '/runtime-controls.json')).sha256, platformReceiptSha256: (await pin(base + '/platform.json')).sha256,
    nonce: spec.nonce, runtimes: spec.runtimes, runtimeControls: await pin(base + '/runtime-controls.json'), platformReceipt: await pin(base + '/platform.json'),
    neutralControls: await pin(base + '/neutral-controls.json') });
}

module.exports = { checked }; // Pure assertion instrumentation; importing never runs qualification.
if (require.main === module) main().catch(async () => {
  process.exitCode = 1;
  if (safeFailurePath) {
    try {
      await fs.writeFile(safeFailurePath, JSON.stringify(firstFailure ?? projectFailure(progress), null, 2) + '\n', { flag: 'wx', mode: 0o600 });
    } catch { process.stderr.write('SAFE_QUALIFICATION_RECORD_UNAVAILABLE\n'); }
  }
  process.stderr.write('LINUX_QUALIFICATION_FAILED\n');
});
