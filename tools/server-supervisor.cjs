'use strict';

// Opt-in server fixtures only. The default SQLite verifier does not load this owner.
const { createHash, createHmac, randomBytes, timingSafeEqual } = require('node:crypto');
const { createReadStream } = require('node:fs');
const fs = require('node:fs/promises');
const net = require('node:net');
const path = require('node:path');
const { spawn } = require('node:child_process');
const { TextDecoder } = require('node:util');

const START = process.hrtime.bigint(); // Before declaration reads, directory creation or argument preparation.
const SECOND = 1_000_000_000n;
const RESERVE = SECOND;
const HEX = /^[0-9a-f]{64}$/;
const NONCE = /^[0-9a-f]{32}$/;
const utf8 = new TextDecoder('utf-8', { fatal: true });
const normalize = value => value.replaceAll('\\', '/');
const sha = bytes => createHash('sha256').update(bytes).digest('hex');
const fail = code => { throw new Error(code); };
const object = value => value !== null && typeof value === 'object' && !Array.isArray(value);
const integer = (value, minimum, maximum) => Number.isSafeInteger(value) && value >= minimum && value <= maximum;
const exactKeys = (value, keys) => object(value) && Object.keys(value).sort().join('\n') === [...keys].sort().join('\n');
function json(bytes) {
  if (bytes.length >= 3 && bytes[0] === 239 && bytes[1] === 187 && bytes[2] === 191) fail('JSON_BOM');
  const text = utf8.decode(bytes); let cursor = 0;
  const whitespace = () => { while (/\s/.test(text[cursor] ?? '') && cursor < text.length) cursor++; };
  const string = () => {
    const start = cursor++; if (text[start] !== '"') fail('JSON_STRING');
    while (cursor < text.length) {
      if (text[cursor] === '\\') { cursor += 2; continue; }
      if (text[cursor++] === '"') return JSON.parse(text.slice(start, cursor));
    }
    fail('JSON_STRING');
  };
  const value = depth => {
    if (depth > 32) fail('JSON_DEPTH'); whitespace();
    if (text[cursor] === '{') {
      cursor++; whitespace(); const keys = new Set();
      if (text[cursor] === '}') { cursor++; return; }
      while (cursor < text.length) {
        whitespace(); const key = string(); if (keys.has(key)) fail('JSON_DUPLICATE_KEY'); keys.add(key);
        whitespace(); if (text[cursor++] !== ':') fail('JSON_COLON'); value(depth + 1); whitespace();
        if (text[cursor] === '}') { cursor++; return; }
        if (text[cursor++] !== ',') fail('JSON_COMMA');
      }
    } else if (text[cursor] === '[') {
      cursor++; whitespace(); if (text[cursor] === ']') { cursor++; return; }
      while (cursor < text.length) {
        value(depth + 1); whitespace(); if (text[cursor] === ']') { cursor++; return; }
        if (text[cursor++] !== ',') fail('JSON_COMMA');
      }
    } else if (text[cursor] === '"') { string(); return; }
    else {
      const start = cursor; while (cursor < text.length && !/[\s,}\]]/.test(text[cursor])) cursor++;
      if (start === cursor) fail('JSON_VALUE'); JSON.parse(text.slice(start, cursor)); return;
    }
    fail('JSON_INCOMPLETE');
  };
  value(0); whitespace(); if (cursor !== text.length) fail('JSON_TRAILING');
  return JSON.parse(text);
}

async function regular(file) {
  const absolute = path.resolve(file);
  let cursor = path.parse(absolute).root;
  for (const component of absolute.slice(cursor.length).split(path.sep)) {
    cursor = path.join(cursor, component);
    const stat = await fs.lstat(cursor);
    if (stat.isSymbolicLink()) fail('LINKED_INPUT');
  }
  if (!(await fs.lstat(absolute)).isFile()) fail('NONREGULAR_INPUT');
  return absolute;
}

async function digest(file) {
  await regular(file);
  const hash = createHash('sha256');
  for await (const bytes of createReadStream(file)) hash.update(bytes);
  return hash.digest('hex');
}

async function pinned(tool) {
  if (!object(tool) || typeof tool.path !== 'string' || !HEX.test(tool.sha256)
      || !(await fs.stat(tool.path)).isFile()) fail('PIN_CHANGED');
  // Explicit executable pins may name a preinstalled toolchain junction. No unpinned input is followed.
  const hash = createHash('sha256');
  for await (const bytes of createReadStream(tool.path)) hash.update(bytes);
  if (hash.digest('hex') !== tool.sha256) fail('PIN_CHANGED');
}

async function readPlan(file, expected, maximum = 4 * 1024 * 1024) {
  if (!HEX.test(expected)) fail('PLAN_DIGEST');
  await regular(file);
  const stat = await fs.stat(file);
  if (stat.size > maximum) fail('PLAN_SIZE');
  const bytes = await fs.readFile(file);
  if (sha(bytes) !== expected) fail('PLAN_CHANGED');
  return json(bytes);
}

async function retain(file, value) {
  const handle = await fs.open(file, 'wx', 0o600);
  try { await handle.writeFile(JSON.stringify(value, null, 2) + '\n'); await handle.sync(); }
  finally { await handle.close(); }
}

function mac(key, direction, nonce, seq, kind, payload) {
  return createHmac('sha256', key).update(`tablo-i39-windows-adapter/1\n${direction}\n${nonce}\n${seq}\n${kind}\n${payload}`).digest('hex');
}

// The payload bytes, not a cross-language JSON reserialization, are authenticated.
class Frames {
  constructor(key, nonce, outbound, inbound, maximum = 64) {
    this.key = key; this.nonce = nonce; this.outbound = outbound; this.inbound = inbound;
    this.sent = 0; this.received = 0; this.maximum = maximum; this.pending = Buffer.alloc(0);
  }
  encode(kind, value) {
    const bytes = Buffer.from(JSON.stringify(value));
    if (!object(value) || bytes.length > 32768 || ++this.sent > this.maximum) fail('FRAME_LIMIT');
    const payload = bytes.toString('base64');
    const frame = { v: 1, nonce: this.nonce, seq: this.sent, kind, payload,
      mac: mac(this.key, this.outbound, this.nonce, this.sent, kind, payload) };
    const line = Buffer.from(JSON.stringify(frame) + '\n');
    if (line.length > 65536) fail('FRAME_SIZE');
    return line;
  }
  decode(line) {
    if (line.length === 0 || line.length > 65535 || line.includes(13)) fail('FRAME_ENCODING');
    const frame = json(line);
    if (!exactKeys(frame, ['v', 'nonce', 'seq', 'kind', 'payload', 'mac']) || frame.v !== 1
        || frame.nonce !== this.nonce || frame.seq !== this.received + 1 || frame.seq > this.maximum
        || typeof frame.kind !== 'string' || typeof frame.payload !== 'string' || !HEX.test(frame.mac)) fail('FRAME_SCHEMA');
    const expected = mac(this.key, this.inbound, this.nonce, frame.seq, frame.kind, frame.payload);
    if (!timingSafeEqual(Buffer.from(expected, 'hex'), Buffer.from(frame.mac, 'hex'))) fail('FRAME_MAC');
    const bytes = Buffer.from(frame.payload, 'base64');
    if (bytes.length > 32768 || bytes.toString('base64') !== frame.payload) fail('FRAME_PAYLOAD');
    const payload = json(bytes);
    if (!object(payload)) fail('FRAME_PAYLOAD');
    this.received = frame.seq;
    return { kind: frame.kind, payload };
  }
  push(bytes, consume) {
    this.pending = Buffer.concat([this.pending, bytes]);
    if (this.pending.length > 65536 && !this.pending.includes(10)) fail('FRAME_SIZE');
    for (let offset; (offset = this.pending.indexOf(10)) !== -1;) {
      const line = this.pending.subarray(0, offset);
      this.pending = this.pending.subarray(offset + 1);
      consume(this.decode(line));
    }
    if (this.pending.length > 65536) fail('FRAME_SIZE');
  }
}

// Every end uses this owner's epoch. A containing end is never renewed by a child.
class Leases {
  constructor(parentEnd, now = () => process.hrtime.bigint()) {
    this.parentEnd = parentEnd; this.now = now; this.active = new Map(); this.next = 1; this.events = [];
  }
  end() { return [...this.active.values()].reduce((end, lease) => lease.end < end ? lease.end : end, this.parentEnd); }
  remaining() { return this.end() - this.now(); }
  admit(seconds, label) {
    if (typeof seconds !== 'number' || !Number.isFinite(seconds) || seconds <= 0 || seconds > 1800
        || typeof label !== 'string' || !/^[a-z0-9:/._-]{1,180}$/.test(label) || this.active.size >= 8 || this.next > 16384) fail('LEASE_DECLARATION');
    const started = this.now(); const proposed = started + BigInt(Math.floor(seconds * 1e9));
    const containing = this.end(); const end = proposed < containing ? proposed : containing;
    if (end - started <= RESERVE) fail('LEASE_TOO_LATE');
    const id = this.next++; this.active.set(id, { id, label, started, end });
    this.events.push({ kind: 'admitted', id, label, startedNanoseconds: started.toString(), endNanoseconds: end.toString() });
    return { id, remainingNanoseconds: (end - started).toString() };
  }
  finish(id, closure) {
    const lease = this.validateFinish(id, closure);
    this.events.push({ kind: 'closed', id, label: lease.label, closedNanoseconds: this.now().toString(), closureKnown: true });
    this.active.delete(id);
  }
  validateFinish(id, closure) {
    const lease = this.active.get(id);
    if (!lease || this.now() >= lease.end || closure !== true) fail('LEASE_CLOSURE_UNKNOWN_OR_LATE');
    return lease;
  }
  cancellationDue() { return this.now() >= this.end() - RESERVE; }
}

function environment(inherited) {
  const clean = {};
  for (const [name, value] of Object.entries(inherited)) {
    if (/^(systemroot|windir|path|pathext|comspec|userprofile|localappdata|appdata)$/i.test(name)) clean[name] = value;
  }
  return { ...clean, TABLO_DB_DRIVER: 'sqlite', TABLO_DB: ':memory:', TABLO_TOKEN_KEY_FILE: '', PHP_INI_SCAN_DIR: '' };
}

function declaration(plan, file) {
  if (plan.schema !== 'tablo/server-cell-start/v1' || !NONCE.test(plan.nonce) || !object(plan.observer)
      || plan.limits?.overallSeconds !== 1800 || plan.limits?.children !== 11 || plan.limits?.connections !== 8) fail('CELL_DECLARATION');
  const observer = plan.observer;
  const root = normalize(path.join(path.dirname(plan.runtime), 'tablo-observer-' + plan.nonce));
  if (observer.schema !== 'tablo/server-observer/v1' || observer.root !== root
      || observer.host !== '127.0.0.1' || !integer(observer.port, 1024, 65535) || observer.port === 8087
      || observer.authorityFile !== root + '/private/controller.bin' || observer.adapterAuthorityFile !== root + '/private/adapter.bin'
      || observer.maximumScopeProcesses !== 9 || observer.settlementSeconds !== 1
      || observer.parentSeconds !== 1800 || observer.processGraph?.maximumTotal !== 11
      || observer.processGraph?.owner !== 1 || observer.processGraph?.adapter !== 1
      || observer.processGraph?.scope !== 9 || normalize(path.resolve(file)) === normalize(plan.runtime)) fail('OBSERVER_DECLARATION');
  const phases = observer.phases;
  if (!Array.isArray(phases) || phases.length !== 3 || phases.map(phase => phase.kind).join(',') !== 'prepare,baseline,engine'
      || new Set([plan.nonce, ...phases.map(phase => phase.nonce)]).size !== 4) fail('PHASE_DECLARATION');
  for (const phase of phases) {
    if (!NONCE.test(phase.nonce) || phase.root !== root + '/phases/tablo-observer-' + phase.nonce
        || phase.authorityFile !== phase.root + '/private/controller.bin' || phase.adapterAuthorityFile !== phase.root + '/private/adapter.bin'
        || phase.controllerStdout !== phase.root + '/evidence/controller.stdout' || phase.controllerStderr !== phase.root + '/evidence/controller.stderr') fail('PHASE_DECLARATION');
  }
  return observer;
}

function controlDeclaration(plan, file) {
  if (plan.schema !== 'tablo/server-observer-control/v1' || !NONCE.test(plan.nonce) || ![8, 20].includes(plan.seconds)
      || !object(plan.observer) || !object(plan.launch) || plan.observer.root !== normalize(path.dirname(path.resolve(file))) + '/tablo-observer-' + plan.nonce
      || plan.observer.host !== '127.0.0.1' || !integer(plan.observer.port, 1024, 65535) || plan.observer.port === 8087
      || plan.observer.maximumScopeProcesses !== 8 || plan.observer.processGraph?.maximumTotal !== 10
      || plan.observer.processGraph?.owner !== 1 || plan.observer.processGraph?.adapter !== 1 || plan.observer.processGraph?.scope !== 8
      || plan.observer.settlementSeconds !== 1 || !Array.isArray(plan.observer.phases) || plan.observer.phases.length !== 1) fail('CONTROL_DECLARATION');
  const phase = plan.observer.phases[0]; const root = plan.observer.root + '/phases/tablo-observer-' + phase.nonce;
  if (phase.kind !== 'control' || !NONCE.test(phase.nonce) || phase.nonce === plan.nonce || phase.root !== root
      || phase.authorityFile !== root + '/private/controller.bin' || phase.adapterAuthorityFile !== root + '/private/adapter.bin'
      || phase.controllerStdout !== root + '/evidence/controller.stdout' || phase.controllerStderr !== root + '/evidence/controller.stderr') fail('CONTROL_PHASE');
  if (!Array.isArray(plan.launch.argv) || plan.launch.argv.length > 32 || plan.launch.argv.some(value => typeof value !== 'string')
      || !object(plan.launch.environment) || Object.entries(plan.launch.environment).some(([name, value]) =>
        typeof value !== 'string' || /^(TABLO_DB|TABLO_TOKEN_KEY|DOCKER_|NODE_OPTIONS|NODE_PATH|PHPRC|PHP_INI_SCAN_DIR)/i.test(name))) fail('CONTROL_ENVIRONMENT');
  return plan.observer;
}

async function controllerLaunch(owner, file, hash) {
  const { plan, observer, phase } = owner; const runtime = plan.runtime;
  if (owner.control) {
    await pinned(plan.launch.executable);
    if (plan.launch.executable.path !== observer.node.path && plan.launch.executable.path !== plan.php?.path) fail('CONTROL_EXECUTABLE');
    if (plan.launch.input.mode === 'synthetic-file') {
      const source = normalize(path.dirname(path.resolve(file))) + '/synthetic-input-' + plan.nonce + '.bin';
      if (plan.syntheticInput !== source || plan.launch.input.path !== phase.root + '/private/input.bin'
          || !integer(plan.launch.input.bytes, 1, 1048576)) fail('CONTROL_INPUT_DECLARATION');
      const bytes = await fs.readFile(await regular(source));
      if (bytes.length !== plan.launch.input.bytes || sha(bytes) !== plan.launch.input.sha256) fail('CONTROL_INPUT_CHANGED');
      await fs.writeFile(plan.launch.input.path, bytes, { flag: 'wx', mode: 0o600 });
    }
    return { executable: plan.launch.executable, argv: plan.launch.argv, cwd: phase.root + '/cwd',
      environment: { ...environment(process.env), ...plan.launch.environment, TABLO_SERVER_OBSERVER_FILE: phase.root + '/private/controller.json', TABLO_SERVER_OBSERVER_SHA256: '' },
      stdout: phase.controllerStdout, stderr: phase.controllerStderr, input: plan.launch.input };
  }
  let argv = ['-c', observer.bootstrapIni, normalize(path.join(plan.sourceInput, 'tools/server-cell.php')), '--execute=' + normalize(path.resolve(file)), '--sha256=' + hash];
  const cwd = phase.root + '/cwd'; let env = { ...environment(process.env), PHP_INI_SCAN_DIR: observer.bootstrapIniScanDirectory };
  if (phase.kind !== 'prepare') {
    const started = runtime + '/evidence/started-cell.json'; const startedSha = await digest(started);
    env = { ...environment(process.env), TABLO_TEST_SOURCE_ROOT: plan.source, TABLO_TEST_RUN_NONCE: plan.nonce,
      TABLO_TEST_DB_DRIVER: plan.selectedEngine, PHPRC: plan.ini, PHP_INI_SCAN_DIR: '',
      TEMP: runtime + '/tmp', TMP: runtime + '/tmp', TMPDIR: runtime + '/tmp',
      TABLO_SESSION_EVIDENCE_DIRECTORY: runtime + '/evidence/closure',
      COMPOSER_HOME: runtime + '/composer-home', COMPOSER_CACHE_DIR: runtime + '/composer-cache',
      COMPOSER_DISABLE_NETWORK: '1', COMPOSER_NO_INTERACTION: '1', COMPOSER_PROCESS_TIMEOUT: '1800', LEKALO_BIN: plan.lekalo.path,
      TABLO_TEST_COMPOSER_PHAR: plan.composer.path, TABLO_TEST_COMPOSER_SHA256: plan.composer.sha256,
      TABLO_TEST_CELL_PLAN: started, TABLO_TEST_CELL_PLAN_SHA256: startedSha };
    if (phase.kind === 'baseline') {
      await pinned(plan.composer);
      delete env.TABLO_TEST_DB_DRIVER;
      const prefix = runtime + '/tmp/tablo-server-verify-' + phase.nonce.slice(0, 16);
      await fs.mkdir(prefix, { mode: 0o700 });
      owner.baselineDirectory = prefix + '/composer-verify'; env.TABLO_VERIFY_NATIVE_DIRECTORY = owner.baselineDirectory;
      argv = ['-c', plan.ini, plan.composer.path, '--working-dir=' + plan.source, '--no-interaction', '--no-plugins', 'verify'];
    } else {
      const proof = owner.phaseResults.find(result => result.kind === 'baseline'); if (!proof) fail('BASELINE_PHASE_MISSING');
      env.TABLO_TEST_BASELINE_DIRECTORY = owner.baselineDirectory;
      env.TABLO_TEST_BASELINE_PHASE_FILE = proof.path; env.TABLO_TEST_BASELINE_PHASE_SHA256 = proof.sha256;
      argv = ['-c', plan.ini, plan.source + '/tools/server-cell.php', '--continue=' + started, '--sha256=' + startedSha];
    }
  }
  return { executable: plan.php, argv, cwd, environment: { ...env,
      TABLO_SERVER_OBSERVER_FILE: phase.root + '/private/controller.json', TABLO_SERVER_OBSERVER_SHA256: '' },
    stdout: phase.controllerStdout, stderr: phase.controllerStderr,
    input: { mode: 'none', path: null, sha256: null, bytes: 0 } };
}

async function listen(owner) {
  const { observer, leases } = owner;
  let connections = 0; owner.connections = 0;
  const server = net.createServer(socket => {
    if (++connections > 8) { socket.destroy(); owner.cancel('protocol'); return; }
    owner.connections = connections;
    const phase = owner.phase;
    if (!phase) { socket.destroy(); owner.cancel('protocol'); return; }
    const frames = new Frames(phase.authority, phase.nonce, 'owner-to-controller', 'controller-to-owner', 4096);
    let requestSeen = false;
    socket.setTimeout(1000, () => { socket.destroy(); owner.cancel('protocol'); });
    socket.on('error', () => owner.cancel('protocol'));
    socket.on('close', () => { connections--; owner.connections = connections; });
    socket.on('data', bytes => {
      try {
        frames.push(bytes, ({ kind, payload }) => {
          if (requestSeen || payload.sequence !== phase.sequence + 1 || phase.sequence >= 49152) fail('CONTROL_REPLAY');
          requestSeen = true; phase.sequence++;
          let result; let finishing = null;
          if (kind === 'begin' && exactKeys(payload, ['sequence', 'seconds', 'label'])) {
            result = leases.admit(payload.seconds, payload.label);
          } else if (kind === 'finish' && exactKeys(payload, ['sequence', 'id', 'closureKnown'])) {
            leases.validateFinish(payload.id, payload.closureKnown); finishing = payload.id; result = { closed: true };
          } else if (kind === 'remaining' && exactKeys(payload, ['sequence'])) {
            const remaining = leases.remaining(); if (remaining <= RESERVE) fail('PARENT_EXPIRED');
            result = { remainingNanoseconds: remaining.toString() };
          } else fail('CONTROL_COMMAND');
          socket.end(frames.encode('accepted', { sequence: phase.sequence, ...result }), error => {
            try {
              if (error) fail('CONTROL_ACK_WRITE');
              if (finishing !== null) leases.finish(finishing, true);
            } catch { owner.cancel('protocol'); }
          });
        });
      } catch { socket.destroy(); owner.cancel('protocol'); }
    });
    socket.on('end', () => { if (!requestSeen || frames.pending.length !== 0) owner.cancel('protocol'); });
  });
  owner.server = server;
  await new Promise((resolve, reject) => { server.once('error', reject); server.listen({ host: observer.host, port: observer.port, exclusive: true }, resolve); });
  const address = server.address();
  if (!object(address) || address.address !== observer.host || address.port !== observer.port) fail('CONTROL_LISTENER_IDENTITY');
}

function phaseProcessLimit(control, kind) { return !control && kind === 'baseline' ? 9 : 8; }

async function adapterPlan(owner, launch) {
  const { plan, observer, phase, leases } = owner;
  await fs.writeFile(phase.authorityFile, phase.authority, { flag: 'wx', mode: 0o600 });
  await fs.writeFile(phase.adapterAuthorityFile, phase.adapterAuthority, { flag: 'wx', mode: 0o600 });
  const controller = { schema: 'tablo/server-observer-controller/v1', nonce: phase.nonce, cellNonce: plan.nonce, phase: phase.kind,
    host: observer.host, port: observer.port, authorityFile: phase.authorityFile, ownerPid: process.pid, maximumFrameBytes: 65536 };
  const controllerFile = phase.root + '/private/controller.json';
  await retain(controllerFile, controller);
  await fs.writeFile(phase.root + '/private/controller.sequence', '0\n', { flag: 'wx', mode: 0o600 });
  launch.environment.TABLO_SERVER_OBSERVER_SHA256 = await digest(controllerFile);
  const canonical = Buffer.from(JSON.stringify(launch));
  const windows = process.platform === 'win32';
  return { schema: windows ? 'tablo/server-observer-windows-plan/v1' : 'tablo/server-observer-posix-plan/v1',
    nonce: phase.nonce, root: phase.root, planId: phase.nonce + (windows ? '/windows-controller/1' : '/posix-controller/1'),
    authorityFile: phase.adapterAuthorityFile, authorityLength: 32,
    adapter: windows ? observer.windowsAdapter : observer.nodeOwner, powershell: observer.powershell ?? null,
    parentNodePid: process.pid, launch, launchCanonicalBase64: canonical.toString('base64'), launchSha256: sha(canonical),
    job: { name: null, logicalId: phase.nonce + '/job/1', killOnClose: true, breakaway: false, atomicJobListRequired: true, activeProcessLimit: phaseProcessLimit(owner.control, phase.kind) },
    limits: { maximumFrameBytes: 65536, maximumPayloadBytes: 32768, maximumFrames: 64, maximumParentSeconds: 1800, maximumControllers: 1, cancellationExitCode: 124 },
    initialRemainingNanoseconds: leases.remaining().toString() };
}

function adapterState(plan) {
  return { planId: plan.planId, ready: null, started: null, input: null, exited: null, closed: null, failed: null, notStarted: null };
}

function acceptEvent(state, kind, payload, plan, child, expectedPlanHash) {
  if (!Object.hasOwn(state, kind) || kind === 'planId' || state[kind] !== null || payload.planId !== plan.planId) fail('ADAPTER_EVENT_ORDER');
  if (process.platform === 'win32') {
    const fields = {
      ready: ['planId', 'adapterPid', 'adapterProcessCreationFileTime', 'platform', 'planSha256', 'jobLogicalId', 'atomicJobListSupported', 'capabilityReady'],
      started: ['planId', 'controllerPid', 'controllerCreationFileTime', 'actualImagePath', 'actualImageSha256', 'jobLogicalId', 'assignedAtCreation', 'jobActiveProcesses', 'nativeProcessHandleRetained', 'nativeThreadHandleClosed'],
      input: ['planId', 'mode', 'declaredBytes', 'writtenBytes', 'settled', 'stdinClosed', 'errorCode'],
      exited: ['planId', 'controllerPid', 'controllerCreationFileTime', 'rootExitObserved', 'waitResult', 'windowsExitUnsigned', 'windowsExitSigned', 'terminationRequested', 'terminationAccepted', 'posixSignal'],
      closed: ['planId', 'controllerPid', 'controllerCreationFileTime', 'rootExitObserved', 'nativeProcessHandleClosed', 'nativeThreadHandleClosed', 'jobActiveProcesses', 'jobHandleClosed', 'inputSettled', 'stdinClosed', 'stdout', 'stderr', 'custodySettled', 'descendantStatuses', 'jobAccounting'],
      failed: ['planId', 'phase', 'code', 'controllerStarted', 'rootExitObserved', 'closureKnown', 'nestedPhpClosure'],
      notStarted: ['planId', 'controllerStarted', 'reason', 'closed']
    };
    if (!exactKeys(payload, fields[kind] ?? [])) fail('ADAPTER_EVENT_FIELDS');
  }
  if (kind === 'ready') {
    if (state.started || payload.adapterPid !== child.pid || payload.capabilityReady !== true || payload.planSha256 !== expectedPlanHash
        || (process.platform === 'win32' && (payload.platform !== 'Windows' || payload.atomicJobListSupported !== true
          || payload.jobLogicalId !== plan.job.logicalId))) fail('ADAPTER_READY');
  } else if (kind === 'started') {
    if (!state.ready || !integer(payload.controllerPid, 1, 0x7fffffff)
        || payload.actualImageSha256 !== plan.launch.executable.sha256
        || normalize(payload.actualImagePath).toLowerCase() !== normalize(plan.launch.executable.path).toLowerCase()
        || (process.platform === 'win32' && (payload.assignedAtCreation !== true || payload.nativeProcessHandleRetained !== true
          || payload.nativeThreadHandleClosed !== true || payload.jobLogicalId !== plan.job.logicalId
          || !/^[1-9][0-9]{0,19}$/.test(payload.controllerCreationFileTime)
          || !integer(payload.jobActiveProcesses, 0, plan.job.activeProcessLimit)))) fail('ADAPTER_STARTED');
  } else if (kind === 'exited' || kind === 'closed') {
    if (!state.started || payload.controllerPid !== state.started.controllerPid || payload.rootExitObserved !== true
        || (process.platform === 'win32' && payload.controllerCreationFileTime !== state.started.controllerCreationFileTime)) fail('ADAPTER_NATIVE_IDENTITY');
    if (kind === 'closed' && (!state.exited || payload.custodySettled !== true || payload.inputSettled !== true || payload.stdinClosed !== true)) fail('ADAPTER_CLOSURE');
    if (kind === 'closed' && process.platform === 'win32') {
      const accounting = payload.jobAccounting;
      if (!exactKeys(accounting, ['configuredLimit', 'maximumAssociatedObserved', 'finalAssociated', 'totalProcesses', 'totalLimitTerminated'])
          || accounting.configuredLimit !== plan.job.activeProcessLimit || accounting.finalAssociated !== 0
          || !integer(accounting.maximumAssociatedObserved, state.started.jobActiveProcesses, 0xffffffff)
          || !integer(accounting.totalProcesses, accounting.maximumAssociatedObserved, 0xffffffff)
          || !integer(accounting.totalLimitTerminated, 0, accounting.totalProcesses)) fail('ADAPTER_JOB_ACCOUNTING');
    }
    if (kind === 'exited' && process.platform === 'win32' && (payload.waitResult !== 'signaled' || payload.posixSignal !== null
        || !integer(payload.windowsExitUnsigned, 0, 0xffffffff) || payload.windowsExitSigned !== (payload.windowsExitUnsigned | 0)
        || typeof payload.terminationRequested !== 'boolean' || typeof payload.terminationAccepted !== 'boolean')) fail('ADAPTER_NATIVE_STATUS');
  } else if (kind === 'input' && !state.started) fail('ADAPTER_INPUT_ORDER');
  state[kind] = payload;
}

async function runAdapter(owner, plan) {
  const windows = process.platform === 'win32'; const file = owner.phase.root + '/private/adapter.json';
  await retain(file, plan); const planHash = await digest(file);
  const executable = windows ? plan.powershell.path : process.execPath;
  const argv = windows ? ['-NoProfile', '-NonInteractive', '-File', plan.adapter.path, '-PlanFile', file, '-PlanSha256', planHash]
    : [__filename, '--scope-plan=' + file, '--sha256=' + planHash];
  const child = spawn(executable, argv, { cwd: owner.phase.root + '/cwd', env: environment(process.env), windowsHide: true, stdio: ['pipe', 'pipe', 'pipe'] });
  owner.adapter = child;
  const frames = new Frames(owner.phase.adapterAuthority, plan.nonce, 'node-to-adapter', 'adapter-to-node');
  const state = adapterState(plan); owner.adapterState = state;
  let stderr = Buffer.alloc(0); let stdout = Buffer.alloc(0); let spawnError = false; let stderrTruncated = false;
  const send = (kind, payload) => { if (!child.stdin.destroyed) child.stdin.write(frames.encode(kind, { planId: plan.planId, ...payload })); };
  owner.send = send;
  child.stdin.on('error', () => owner.cancel('protocol'));
  child.on('error', () => { spawnError = true; owner.cancel('protocol'); });
  child.stderr.on('data', bytes => {
    if (stderr.length + bytes.length > 65536) { stderrTruncated = true; owner.cancel('protocol'); }
    stderr = Buffer.concat([stderr, bytes.subarray(0, 65536 - stderr.length)]);
  });
  child.stdout.on('data', bytes => {
    try {
      if (stdout.length + bytes.length > 64 * 65536) fail('ADAPTER_STDOUT_LIMIT');
      stdout = Buffer.concat([stdout, bytes]);
      frames.push(bytes, ({ kind, payload }) => {
        acceptEvent(state, kind, payload, plan, child, planHash);
        if (kind === 'ready') {
          if (owner.cancelReason) send('cancel', { reason: owner.cancelReason });
          else send('launch', { launchSha256: plan.launchSha256 });
        }
        if (kind === 'closed') send('shutdown', {});
        if (kind === 'failed' || kind === 'notStarted') owner.cancel('protocol');
      });
    } catch { owner.cancel('protocol'); }
  });
  const close = await new Promise(resolve => {
    child.once('close', (code, signal) => resolve({ code, signal, closeObserved: true }));
    owner.expire = () => {
      child.stdin.end();
      if (windows) child.kill(); // Adapter death closes its own kill-on-close Job.
      // POSIX guard owns its registered group and receives EOF. No parent PID adoption or scan.
      child.unref(); child.stdout.destroy(); child.stderr.destroy();
      resolve({ code: null, signal: null, closeObserved: false });
    };
  });
  owner.expire = null;
  owner.adapterClose = { pid: child.pid ?? null, ...close, spawnError, stdoutTrailingBytes: frames.pending.length,
    stdoutBytes: stdout.length, stdoutSha256: sha(stdout), stderrBytes: stderr.length, stderrSha256: sha(stderr), stderrTruncated };
  await fs.writeFile(owner.phase.root + '/evidence/adapter.stdout', stdout, { flag: 'wx', mode: 0o600 });
  await fs.writeFile(owner.phase.root + '/evidence/adapter.stderr', stderr, { flag: 'wx', mode: 0o600 });
  await retain(owner.phase.root + '/evidence/adapter-events.json', state);
  return state;
}

async function verifyCustody(owner, state) {
  if (owner.cancelReason || owner.leases.active.size !== 0 || owner.adapterClose?.code !== 0
      || owner.adapterClose.signal !== null || owner.adapterClose.spawnError || owner.adapterClose.stdoutTrailingBytes !== 0
      || owner.adapterClose.stderrBytes !== 0
      || !state.closed || !state.exited || !state.input) fail('OBSERVER_CLOSURE_UNKNOWN');
  const windows = process.platform === 'win32';
  if (state.input.mode !== 'none' || state.input.declaredBytes !== 0 || state.input.writtenBytes !== 0 || state.input.settled !== true
      || state.input.stdinClosed !== true || state.input.errorCode !== null) fail('CONTROLLER_INPUT_UNSETTLED');
  if (windows ? state.exited.windowsExitUnsigned !== 0 || state.closed.jobActiveProcesses !== 0
      || state.closed.nativeProcessHandleClosed !== true || state.closed.nativeThreadHandleClosed !== true || state.closed.jobHandleClosed !== true
    : state.exited.exitCode !== 0 || state.exited.posixSignal !== null || state.closed.scopeObservedEmpty !== true) fail('CONTROLLER_NATIVE_FAILURE');
  for (const name of ['stdout', 'stderr']) {
    const item = state.closed[name]; const expected = owner.phase.root + '/evidence/controller.' + name;
    if (!object(item) || typeof item.path !== 'string' || normalize(item.path) !== expected || item.closed !== true || !integer(item.bytes, 0, Number.MAX_SAFE_INTEGER)
        || (await fs.stat(expected)).size !== item.bytes || await digest(expected) !== item.sha256) fail('CONTROLLER_CUSTODY');
  }
  return { controller: state.exited, adapterClose: owner.adapterClose, streams: { stdout: state.closed.stdout, stderr: state.closed.stderr } };
}

async function verifyPhase(owner, custody) {
  const kind = owner.phase.kind; const runtime = owner.plan.runtime;
  if (owner.control) return { custody, qualification: 'NEUTRAL_CONTROL_ONLY_NO_ENGINE_QUALIFICATION' };
  if (kind === 'prepare') {
    const file = runtime + '/evidence/started-cell.json'; const plan = json(await fs.readFile(await regular(file)));
    if (plan.schema !== 'tablo/server-cell/v1' || plan.nonce !== owner.plan.nonce || plan.sourceState !== 'STARTED_PENDING_NATIVE_VERIFICATION') fail('PREPARATION_PROOF');
    return { startedCell: file, startedCellSha256: await digest(file), serviceAdmission: 'ACTUAL_NATIVE_ADMISSION_SELECTED_PDO_PENDING', custody };
  }
  if (kind === 'baseline') {
    const file = owner.baselineDirectory + '/native.json'; const bytes = await fs.readFile(await regular(file));
    const record = json(bytes); if (record.schema !== 'tablo/verify-native-export/v1') fail('BASELINE_EXPORT');
    return { baselineDirectory: owner.baselineDirectory, nativeExport: file, nativeExportSha256: sha(bytes), custody,
      qualification: 'ACTUAL_COMPOSER_CONTROLLER0_SAME_CHILD_EXPORT_FULL_JUNIT_VALIDATION_REQUIRED_BY_ENGINE_PHASE' };
  }
  const resultFile = owner.plan.runtime + '/evidence/cell-result.json';
  const result = json(await fs.readFile(await regular(resultFile)));
  if (result.schema !== 'tablo/server-cell-result/v1' || result.status !== 'passed' || result.nonce !== owner.plan.nonce
      || result.ownedServicesClosed !== true || result.observerClosureKnown !== true) fail('CELL_RESULT_MISSING');
  if (owner.leases.remaining() <= 0n) fail('CUSTODY_LATE');
  return { result: resultFile, sha256: await digest(resultFile), nestedPhpClosure: 'PROVEN_BY_CELL_RECEIPT_AUDIT', custody };
}

async function supervise(file, hash, control = false) {
  if (process.version !== 'v24.13.0') fail('NODE_VERSION');
  const leases = new Leases(START + 1800n * SECOND);
  const plan = await readPlan(file, hash); const observer = control ? controlDeclaration(plan, file) : declaration(plan, file);
  if (control) leases.parentEnd = START + BigInt(plan.seconds) * SECOND;
  await pinned(observer.node); await pinned(observer.nodeOwner); if (!control || plan.php) await pinned(plan.php);
  if (!control && (!Array.isArray(observer.bootstrapIniFiles) || observer.bootstrapIniFiles.length < 1
      || observer.bootstrapIniFiles.length > 128 || typeof observer.bootstrapIniScanDirectory !== 'string'
      || observer.bootstrapIni !== observer.bootstrapIniFiles[0].path)) fail('BOOTSTRAP_INI_DECLARATION');
  if (!control) for (const ini of observer.bootstrapIniFiles) await pinned(ini);
  if (normalize(observer.node.path).toLowerCase() !== normalize(process.execPath).toLowerCase()
      || normalize(observer.nodeOwner.path) !== normalize(__filename)) fail('OWNER_PIN');
  if (process.platform === 'win32') { await pinned(observer.powershell); await pinned(observer.windowsAdapter); }
  else if (process.platform !== 'linux') fail('UNQUALIFIED_PLATFORM');
  const parent = path.dirname(observer.root); await regular(path.join(parent, path.basename(file)));
  await fs.mkdir(observer.root, { mode: 0o700 });
  for (const directory of ['private', 'evidence', 'phases']) await fs.mkdir(observer.root + '/' + directory, { mode: 0o700 });
  const owner = { plan, observer, leases, control, cancelReason: null, phaseResults: [] };
  owner.cancel = reason => {
    if (!owner.cancelReason) {
      owner.cancelReason = reason;
      try { owner.send?.('cancel', { reason }); } catch { owner.controlDeliveryKnown = false; }
    }
  };
  const timer = setInterval(() => {
    if (leases.cancellationDue()) owner.cancel('deadline');
    if (leases.remaining() <= 0n) owner.expire?.();
  }, 10);
  const lost = () => owner.cancel('owner-loss');
  process.once('SIGINT', lost); process.once('SIGTERM', lost);
  let verdict = { schema: control ? 'tablo/server-observer-control-result/v1' : 'tablo/server-observer-result/v1', nonce: plan.nonce,
    status: 'failed', qualification: control ? 'NEUTRAL_CONTROL_ONLY' : 'ENGINE_CELL', nestedPhpClosure: 'UNKNOWN' };
  try {
    await listen(owner);
    for (const declaration of observer.phases) {
      if (owner.cancelReason || owner.connections !== 0 || leases.active.size !== 0) fail('PRIOR_PHASE_UNSETTLED');
      owner.phase = { ...declaration, authority: randomBytes(32), adapterAuthority: randomBytes(32), sequence: 0 };
      await fs.mkdir(owner.phase.root, { mode: 0o700 });
      for (const directory of ['private', 'evidence', 'cwd']) await fs.mkdir(owner.phase.root + '/' + directory, { mode: 0o700 });
      const adapter = await adapterPlan(owner, await controllerLaunch(owner, file, hash));
      const state = await runAdapter(owner, adapter);
      const custody = await verifyPhase(owner, await verifyCustody(owner, state));
      const proof = owner.phase.root + '/evidence/phase-result.json';
      await retain(proof, { schema: 'tablo/server-observer-phase/v1', status: 'passed', cellNonce: plan.nonce, phaseNonce: owner.phase.nonce,
        kind: owner.phase.kind, custody, adapterClose: owner.adapterClose });
      owner.phaseResults.push({ kind: owner.phase.kind, path: proof, sha256: await digest(proof) });
    }
    verdict = { ...verdict, status: 'passed', phases: owner.phaseResults,
      nestedPhpClosure: control ? 'NOT_APPLICABLE_NODE_CONTROL' : 'PROVEN_BY_CELL_RECEIPT_AUDIT' };
  } finally {
    owner.server?.close();
    // The parent deadline stays armed until final custody has been written.
    try {
      await retain(observer.root + '/evidence/leases.json', { parentStartedNanoseconds: START.toString(), parentEndNanoseconds: leases.parentEnd.toString(),
        events: leases.events, active: [...leases.active.values()].map(value => ({ id: value.id, label: value.label,
          startedNanoseconds: value.started.toString(), endNanoseconds: value.end.toString() })) });
      await retain(observer.root + '/evidence/observer-result.json', { ...verdict, cancellation: owner.cancelReason,
        adapterClose: owner.adapterClose ?? null, elapsedNanoseconds: (process.hrtime.bigint() - START).toString(),
        bound: 'OBSERVED_QUALIFIED_HOST_PROCESS_SCOPE_NOT_UNIVERSAL_KERNEL_IO_GUARANTEE' });
    } finally { clearInterval(timer); process.removeListener('SIGINT', lost); process.removeListener('SIGTERM', lost); }
  }
  if (verdict.status !== 'passed' || leases.remaining() <= 0n) fail('CELL_FAILED');
}

// Same physical owner, separate trusted POSIX guard. Its stdin EOF is owner loss.
// Only a group created by this exact guard is eligible for group signalling.
async function settleOwnedGroup(end, probe, cancel, now = () => process.hrtime.bigint(), pause = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds))) {
  let cancellationRequested = false;
  while (now() < end) {
    try { probe(); } catch (error) { if (error.code === 'ESRCH') return now() < end; throw error; }
    // Reaping unexpected descendants establishes custody, never ordinary success.
    if (!cancellationRequested) { cancel(); cancellationRequested = true; }
    const remaining = end - now();
    if (remaining <= 0n) break;
    await pause(Number(remaining < 5_000_000n ? remaining : 5_000_000n) / 1e6);
  }
  return false;
}

async function posixScope(file, expected) {
  const plan = await readPlan(file, expected); if (process.platform !== 'linux' || plan.schema !== 'tablo/server-observer-posix-plan/v1') fail('SCOPE_PLATFORM');
  await pinned(plan.adapter); await pinned(plan.launch.executable);
  const canonical = Buffer.from(plan.launchCanonicalBase64, 'base64');
  if (sha(canonical) !== plan.launchSha256 || JSON.stringify(json(canonical)) !== JSON.stringify(plan.launch)) fail('SCOPE_LAUNCH_CHANGED');
  const key = await fs.readFile(await regular(plan.authorityFile)); if (key.length !== 32) fail('SCOPE_AUTHORITY');
  const input = plan.launch.input; let inputBytes = Buffer.alloc(0);
  if (input.mode === 'synthetic-file') {
    if (input.path !== plan.root + '/private/input.bin' || !integer(input.bytes, 1, 1048576)) fail('SCOPE_INPUT_DECLARATION');
    inputBytes = await fs.readFile(await regular(input.path));
    if (inputBytes.length !== input.bytes || sha(inputBytes) !== input.sha256) fail('SCOPE_INPUT_CHANGED');
  } else if (input.mode !== 'none' || input.bytes !== 0 || input.path !== null || input.sha256 !== null) fail('SCOPE_INPUT_DECLARATION');
  const frames = new Frames(key, plan.nonce, 'adapter-to-node', 'node-to-adapter');
  let child = null; let cancelled = false; let launched = false; let shutdown = false; let finished = false; let rootExitObserved = false;
  const emit = (kind, value) => process.stdout.write(frames.encode(kind, { planId: plan.planId, ...value }));
  const cancel = () => {
    cancelled = true;
    if (child && !finished) { try { process.kill(-child.pid, 'SIGKILL'); } catch (error) { if (error.code !== 'ESRCH') process.exitCode = 1; } }
    if (!launched && !finished) {
      finished = true; process.exitCode = 1;
      emit('notStarted', { controllerStarted: false, reason: 'cancelled-before-launch', closed: true });
      process.stdin.destroy(); clearInterval(timer);
    }
  };
  const limit = BigInt(plan.initialRemainingNanoseconds); const end = START + limit;
  const timer = setInterval(() => { if (process.hrtime.bigint() >= end) cancel(); }, 10);
  const launch = async () => {
    if (launched || cancelled) fail('SCOPE_LAUNCH_ORDER'); launched = true;
    const out = await fs.open(plan.launch.stdout, 'wx', 0o600); const err = await fs.open(plan.launch.stderr, 'wx', 0o600);
    try {
      if (cancelled) fail('SCOPE_CANCELLED_BEFORE_START');
      child = spawn(plan.launch.executable.path, plan.launch.argv, { cwd: plan.launch.cwd,
        env: plan.launch.environment, detached: true, stdio: ['pipe', out.fd, err.fd] });
      const nativeClose = new Promise(resolve => child.once('close', (code, signal) => resolve({ code, signal })));
      child.stdin.on('error', () => cancel());
      child.once('error', () => cancel());
      await new Promise((resolve, reject) => { child.once('spawn', resolve); child.once('error', reject); });
      emit('started', { controllerPid: child.pid, actualImagePath: plan.launch.executable.path,
        actualImageSha256: plan.launch.executable.sha256, processGroup: child.pid, ownedGroupCreated: true });
      const delivered = new Promise(resolve => {
        let errorCode = null; let writtenBytes = null;
        child.stdin.once('close', () => {
          emit('input', { mode: input.mode, declaredBytes: inputBytes.length, writtenBytes, settled: true, stdinClosed: true, errorCode });
          inputBytes.fill(0); resolve();
        });
        child.stdin.end(inputBytes, error => {
          if (error) { errorCode = 'INPUT_WRITE_FAILED'; cancel(); }
          else writtenBytes = inputBytes.length;
        });
      });
      const status = await nativeClose;
      rootExitObserved = true;
      emit('exited', { controllerPid: child.pid, rootExitObserved: true, exitCode: status.code, posixSignal: status.signal, terminationRequested: cancelled });
      await delivered;
      // Init reaping is asynchronous; retain the original inherited end/reserve.
      // Only the exact group created by this guard is eligible for this probe.
      if (!await settleOwnedGroup(end, () => process.kill(-child.pid, 0), cancel)) fail('SCOPE_DESCENDANTS_UNKNOWN');
      await out.sync(); await err.sync(); await out.close(); await err.close();
      const stream = async file => ({ path: file, bytes: (await fs.stat(file)).size, sha256: await digest(file), closed: true });
      const stdout = await stream(plan.launch.stdout), stderr = await stream(plan.launch.stderr);
      if (process.hrtime.bigint() >= end) fail('SCOPE_CUSTODY_LATE');
      emit('closed', { controllerPid: child.pid, rootExitObserved: true, scopeObservedEmpty: true, inputSettled: true,
        stdinClosed: true, stdout, stderr,
        custodySettled: true, descendantStatuses: 'NOT_COLLECTED_BY_ADAPTER' });
      finished = true;
      if (cancelled) process.exitCode = 1;
    } catch { cancel(); emit('failed', { phase: 'scope', code: 'SCOPE_UNSETTLED', controllerStarted: Boolean(child?.pid),
      rootExitObserved, closureKnown: false, nestedPhpClosure: 'UNKNOWN_UNLESS_ACTUAL_RECEIPT' }); process.exitCode = 1; }
    finally {
      await out.close().catch(() => {}); await err.close().catch(() => {});
      if (shutdown || cancelled || process.exitCode) { clearInterval(timer); process.stdin.destroy(); }
    }
  };
  process.stdin.on('data', bytes => {
    try {
      frames.push(bytes, ({ kind, payload }) => {
        if (payload.planId !== plan.planId) fail('SCOPE_PLAN_ID');
        if (kind === 'launch' && exactKeys(payload, ['planId', 'launchSha256']) && payload.launchSha256 === plan.launchSha256) void launch().catch(() => { cancel(); process.exitCode = 1; });
        else if (kind === 'cancel' && ['deadline', 'owner-loss', 'protocol', 'shutdown'].includes(payload.reason)) cancel();
        else if (kind === 'shutdown' && exactKeys(payload, ['planId'])) { shutdown = true; if (!finished) cancel(); process.stdin.destroy(); }
        else fail('SCOPE_COMMAND');
      });
    } catch { cancel(); process.exitCode = 1; process.stdin.destroy(); }
  });
  process.stdin.on('end', () => { if (!finished) cancel(); });
  process.stdout.on('error', () => { cancel(); process.exitCode = 1; });
  process.stdin.on('close', () => { if (!finished) cancel(); if (!launched || finished) clearInterval(timer); });
  process.once('SIGINT', cancel); process.once('SIGTERM', cancel);
  emit('ready', { adapterPid: process.pid, platform: 'Linux', planSha256: expected, capabilityReady: true, ownedGroupGuardReady: true });
}

// Executable neutral tests live in this same approved owner. They do not run on import.
// Native owner-loss/input/deadline controls are separate finite runtime sublanes.
function registerTests() {
  const test = require('node:test'); const assert = require('node:assert/strict');
  test('server observer: containing leases keep one reserve and reject late closure', () => {
    let now = 0n; const leases = new Leases(30n * SECOND, () => now);
    const outer = leases.admit(8, 'case/main/0'); now = SECOND;
    const inner = leases.admit(8, 'native/docker');
    assert.equal(leases.end(), 8n * SECOND); assert.equal(inner.remainingNanoseconds, '7000000000');
    now = 7n * SECOND; assert.equal(leases.cancellationDue(), true);
    leases.finish(inner.id, true); assert.equal(leases.end(), 8n * SECOND);
    now = 8n * SECOND; assert.throws(() => leases.finish(outer.id, true), /LATE/);
    assert.throws(() => leases.admit(8, 'native/docker'), /TOO_LATE/);
  });
  test('server observer: unknown closure cannot release a lease or become exit zero', async () => {
    const leases = new Leases(30n * SECOND, () => 0n); const lease = leases.admit(8, 'case/main/0');
    for (const unknown of [false, null, undefined, 0, 'true']) assert.throws(() => leases.finish(lease.id, unknown), /UNKNOWN/);
    assert.equal(leases.active.size, 1); leases.finish(lease.id, true); assert.equal(leases.active.size, 0);
    assert.throws(() => leases.finish(lease.id, true), /UNKNOWN/);
    const missingGroup = () => { throw Object.assign(new Error('absent'), { code: 'ESRCH' }); };
    let clock = 0n, cancels = 0, probes = 0;
    const now = () => clock;
    const pause = async milliseconds => { clock += BigInt(Math.round(milliseconds * 1e6)); };
    assert.equal(await settleOwnedGroup(10_000_000n, missingGroup, () => cancels++, now, pause), true);
    assert.equal(cancels, 0);
    const eventuallyReaped = () => { if (++probes >= 3) missingGroup(); };
    assert.equal(await settleOwnedGroup(20_000_000n, eventuallyReaped, () => cancels++, now, pause), true);
    assert.equal(cancels, 1); // An ordinary root with leftovers remains cancelled after reaping.
    assert.equal(clock, 10_000_000n);
    const originalEnd = clock + 7_000_000n;
    assert.equal(await settleOwnedGroup(originalEnd, () => {}, () => cancels++, now, pause), false);
    assert.equal(clock, originalEnd); // No fresh timeout or settlement grace.
    let expiredProbes = 0;
    assert.equal(await settleOwnedGroup(originalEnd, () => expiredProbes++, () => cancels++, now, pause), false);
    assert.equal(expiredProbes, 0);
    await assert.rejects(settleOwnedGroup(originalEnd + 1n, () => { throw Object.assign(new Error('denied'), { code: 'EPERM' }); }, () => cancels++, now, pause), { code: 'EPERM' });
    const probeEnd = clock + 1n;
    assert.equal(await settleOwnedGroup(probeEnd, () => { clock = probeEnd; missingGroup(); }, () => cancels++, now, pause), false);
  });
  test('server observer: authenticated framing refuses replay, direction changes and changed bytes', () => {
    const key = Buffer.alloc(32, 7); const nonce = 'a'.repeat(32);
    const sender = new Frames(key, nonce, 'node-to-adapter', 'adapter-to-node');
    const receiver = () => new Frames(key, nonce, 'adapter-to-node', 'node-to-adapter');
    const frame = sender.encode('launch', { planId: nonce + '/windows-controller/1', launchSha256: 'b'.repeat(64) });
    const target = receiver(); const decoded = [];
    target.push(frame.subarray(0, 7), value => decoded.push(value)); assert.equal(decoded.length, 0);
    target.push(frame.subarray(7), value => decoded.push(value)); assert.equal(decoded[0].kind, 'launch');
    assert.throws(() => target.push(frame, () => {}), /SCHEMA/);
    const modified = JSON.parse(frame); modified.payload = Buffer.from('{"planId":"changed"}').toString('base64');
    assert.throws(() => receiver().push(Buffer.from(JSON.stringify(modified) + '\n'), () => {}), /MAC/);
    assert.throws(() => receiver().push(Buffer.from(frame.toString().replace('\n', '\r\n')), () => {}), /ENCODING/);
    assert.throws(() => new Frames(key, nonce, 'node-to-adapter', 'adapter-to-node').push(frame, () => {}), /MAC/);
    assert.throws(() => receiver().push(Buffer.alloc(65537, 1), () => {}), /SIZE/);
    assert.throws(() => receiver().push(Buffer.from(frame.toString().replace('"v":1', '"v":0,"v":1')), () => {}), /DUPLICATE/);
    assert.throws(() => receiver().push(Buffer.concat([Buffer.from([239, 187, 191]), frame]), () => {}), /BOM/);
    if (process.platform === 'win32') {
      const plan = { planId: 'synthetic', job: { logicalId: 'synthetic/job', activeProcessLimit: 8 },
        launch: { executable: { path: 'C:/synthetic/node.exe', sha256: 'b'.repeat(64) } } };
      const started = { controllerPid: 1, controllerCreationFileTime: '1', jobActiveProcesses: 8 };
      const state = () => ({ ...adapterState(plan), started, exited: {} });
      const closed = { planId: plan.planId, controllerPid: 1, controllerCreationFileTime: '1', rootExitObserved: true,
        nativeProcessHandleClosed: true, nativeThreadHandleClosed: true, jobActiveProcesses: 0, jobHandleClosed: true,
        inputSettled: true, stdinClosed: true, stdout: {}, stderr: {}, custodySettled: true,
        descendantStatuses: 'NOT_COLLECTED_BY_ADAPTER', jobAccounting: { configuredLimit: 8, maximumAssociatedObserved: 9,
          finalAssociated: 0, totalProcesses: 9, totalLimitTerminated: 1 } };
      assert.doesNotThrow(() => acceptEvent(state(), 'closed', closed, plan, {}, ''));
      for (const change of [{ configuredLimit: 9 }, { finalAssociated: 1 }, { totalLimitTerminated: null }, { maximumAssociatedObserved: null }]) {
        assert.throws(() => acceptEvent(state(), 'closed', { ...closed, jobAccounting: { ...closed.jobAccounting, ...change } }, plan, {}, ''), /ACCOUNTING/);
      }
    }
  });
  test('server observer: the original Composer baseline admits nine managed actors while other phases stay eight', () => {
    const nonce = 'a'.repeat(32), runtime = 'C:/synthetic/tablo-server-' + nonce;
    const root = 'C:/synthetic/tablo-observer-' + nonce;
    const phases = ['prepare', 'baseline', 'engine'].map((kind, i) => {
      const child = String(i + 1).repeat(32), phaseRoot = root + '/phases/tablo-observer-' + child;
      return { kind, nonce: child, root: phaseRoot, authorityFile: phaseRoot + '/private/controller.bin',
        adapterAuthorityFile: phaseRoot + '/private/adapter.bin', controllerStdout: phaseRoot + '/evidence/controller.stdout', controllerStderr: phaseRoot + '/evidence/controller.stderr' };
    });
    const plan = { schema: 'tablo/server-cell-start/v1', nonce, runtime, limits: { overallSeconds: 1800, children: 11, connections: 8 },
      observer: { schema: 'tablo/server-observer/v1', root, host: '127.0.0.1', port: 46000, authorityFile: root + '/private/controller.bin',
        adapterAuthorityFile: root + '/private/adapter.bin', maximumScopeProcesses: 9, settlementSeconds: 1, parentSeconds: 1800,
        processGraph: { maximumTotal: 11, owner: 1, adapter: 1, scope: 9 }, phases } };
    assert.equal(declaration(plan, 'C:/synthetic/plan.json'), plan.observer);
    for (const kind of ['prepare', 'baseline', 'engine']) {
      assert.equal(phaseProcessLimit(false, kind), kind === 'baseline' ? 9 : 8);
      assert.equal(phaseProcessLimit(true, kind), 8);
    }
    for (const change of [{ children: 10 }, { children: 12 }, { connections: 9 }]) {
      assert.throws(() => declaration({ ...plan, limits: { ...plan.limits, ...change } }, 'C:/synthetic/plan.json'), /DECLARATION/);
    }
    for (const change of [{ maximumScopeProcesses: 8 }, { maximumScopeProcesses: 10 }, { processGraph: { ...plan.observer.processGraph, scope: 10 } }]) {
      assert.throws(() => declaration({ ...plan, observer: { ...plan.observer, ...change } }, 'C:/synthetic/plan.json'), /DECLARATION/);
    }
  });
  test('server observer: inherited installation, Docker and key authority is excluded', () => {
    const clean = environment({ PATH: '/synthetic/tools', TABLO_DB: 'private', TABLO_DB_PASSWORD: 'private',
      TABLO_TOKEN_KEY_FILE: 'private', DOCKER_HOST: 'remote', PHPRC: 'private', HOME: 'private', NODE_OPTIONS: '--inspect' });
    assert.deepEqual(clean, { PATH: '/synthetic/tools', TABLO_DB_DRIVER: 'sqlite', TABLO_DB: ':memory:', TABLO_TOKEN_KEY_FILE: '', PHP_INI_SCAN_DIR: '' });
  });
}

async function main(arguments_) {
  if (arguments_.length === 1 && arguments_[0] === '--self-test') { registerTests(); return; }
  if (arguments_.length !== 2 || !arguments_[1].startsWith('--sha256=')) fail('EXACT_ARGUMENTS');
  const expected = arguments_[1].slice(9);
  if (arguments_[0].startsWith('--execute=')) await supervise(arguments_[0].slice(10), expected);
  else if (arguments_[0].startsWith('--control-plan=')) await supervise(arguments_[0].slice(15), expected, true);
  else if (arguments_[0].startsWith('--scope-plan=')) await posixScope(arguments_[0].slice(13), expected);
  else fail('EXACT_MODE');
}

module.exports = { Frames, Leases, declaration, environment, acceptEvent };
if (require.main === module) main(process.argv.slice(2)).catch(() => { process.stderr.write('SERVER_OBSERVER_FAILED\n'); process.exitCode = 1; });
