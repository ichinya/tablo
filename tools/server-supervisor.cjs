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
const LINUX_POLICY = 'tgid-seccomp-ptrace-x64-v2';
const QUALIFICATION_KEYS = Object.freeze(['schema', 'policy', 'status', 'binarySha256', 'sourceSha256', 'recipeSha256', 'buildSha256',
  'controlsSourceSha256', 'controlsBinarySha256', 'runtimeControlsSha256', 'platformReceiptSha256', 'nonce', 'runtimes',
  'runtimeControls', 'platformReceipt', 'neutralControls', 'fallbackControlsSha256', 'fallbackControls']);
const FALLBACK_NAMES = Object.freeze(['uring-handled', 'uring-repeat', 'uring-arguments', 'uring-threads', 'uring-forkexec',
  'uring-mixed-security', 'uring-mixed-quota', 'uring-unhandled']);
const utf8 = new TextDecoder('utf-8', { fatal: true });
const normalize = value => value.replaceAll('\\', '/');
const sha = bytes => createHash('sha256').update(bytes).digest('hex');
// Refusal identity comes only from this owner, never from Error.message/stack.
const observerRefusals = new WeakMap();
const fail = code => { const error = new Error(code); observerRefusals.set(error, code); throw error; };
const object = value => value !== null && typeof value === 'object' && !Array.isArray(value);
const integer = (value, minimum, maximum) => Number.isSafeInteger(value) && value >= minimum && value <= maximum;
const exactKeys = (value, keys) => object(value) && Object.keys(value).sort().join('\n') === [...keys].sort().join('\n');
const OBSERVER_STAGES = Object.freeze(['arguments', 'preflight', 'admission', 'declaration', 'pins', 'linux-pins', 'root-layout',
  'listener', 'phase-admission', 'phase-layout', 'launch-description', 'adapter-plan', 'adapter-run', 'custody', 'phase-proof',
  'phase-retention', 'retention', 'settlement']);
const OBSERVER_REFUSALS = Object.freeze(['EXACT_ARGUMENTS', 'EXACT_MODE', 'NODE_VERSION', 'PLAN_DIGEST', 'PLAN_SIZE', 'PLAN_CHANGED',
  'EXECUTION_MODE_UNQUALIFIED', 'CELL_DECLARATION', 'OBSERVER_DECLARATION', 'PHASE_DECLARATION', 'CONTROL_DECLARATION', 'CONTROL_PHASE',
  'CONTROL_ENVIRONMENT', 'PIN_CHANGED', 'BOOTSTRAP_INI_DECLARATION', 'OWNER_PIN', 'UNQUALIFIED_PLATFORM', 'LINUX_BUILD_UNQUALIFIED',
  'LINUX_RUNTIME_UNQUALIFIED', 'LINUX_FOREIGN_QUALIFICATION', 'LINUX_FOREIGN_RUNTIME', 'LINUX_EVIDENCE_SCOPE', 'LINUX_CLIENT_UNQUALIFIED',
  'LINUX_FALLBACK_BINDING', 'LINUX_FALLBACK_COVERAGE', 'PRIOR_PHASE_UNSETTLED', 'OBSERVER_CLOSURE_UNKNOWN', 'CONTROLLER_INPUT_UNSETTLED',
  'CONTROLLER_NATIVE_FAILURE', 'CONTROLLER_CUSTODY', 'PREPARATION_PROOF', 'BASELINE_EXPORT', 'CELL_RESULT_MISSING', 'CUSTODY_LATE', 'CELL_FAILED']);
function observerFailure() { return { stage: 'arguments', phase: 'MISSING', first: null }; }
const PREPARE_STAGES = Object.freeze(['ARGUMENTS', 'DEPENDENCY_GUARD', 'LOAD', 'PLAN_BINDING', 'OBSERVER_BINDING', 'MATERIALIZE', 'AUTOLOAD',
  'ENVIRONMENT', 'CONTROLLER_DIRECTORY', 'EXECUTE', 'UNKNOWN']);
const PREPARE_CLASSES = Object.freeze(['RuntimeException', 'LogicException', 'InvalidArgumentException', 'JsonException', 'Error', 'TypeError',
  'ValueError', 'ParseError', 'ErrorException', 'UNKNOWN']);
const PREPARE_SOURCES = Object.freeze(['tools/server-cell.php', 'tools/ServerFixturePlan.php', 'tools/ServerFixtureCatalog.php', 'tools/reviewed-dependencies.php',
  'tools/ReviewedDependencyManifest.php', 'tools/ReviewedDependencyCatalog.php', 'tools/ReviewedDependencyEntry.php', 'tools/ReviewedDependencyCoverage.php',
  'tools/MagoSource.php', 'tools/MagoPin.php', 'app/RequiredFile.php', 'app/JsonRecord.php', 'app/JsonEncoding.php', 'app/SqlColumn.php',
  'app/DatabaseInteger.php', 'app/DatabaseFailure.php', 'app/bootstrap.php', 'tests/Support/TemporaryDirectory.php', 'tests/Support/Subprocess.php',
  'tests/Support/SubprocessReceipt.php', 'tests/Server/EngineVerificationTest.php']);
function capabilityDiagnostic(value) {
  if (!exactKeys(value, ['schema','reportedExit','stdout','stderr','category','missingExtension'])
      || Object.keys(value).join(',') !== 'schema,reportedExit,stdout,stderr,category,missingExtension'
      || value.schema !== 'tablo/php-capability-observation/v1'
      || !(value.reportedExit === null || value.reportedExit === 'UNKNOWN' || integer(value.reportedExit,-2147483648,2147483647))
      || !['READY','EMPTY','OTHER','UNKNOWN'].includes(value.stdout) || !['EMPTY','NONEMPTY','UNKNOWN'].includes(value.stderr)
      || !['MISSING_EXTENSION','MISSING_SOURCE','MISSING_INI','MISSING_LOADED_INI','INI_MISMATCH','TEMP_MISMATCH','READY_WRITE_FAILED','UNKNOWN'].includes(value.category)
      || (value.category === 'MISSING_EXTENSION' ? !['curl','openssl','pdo','pdo_mysql','pdo_sqlite','mbstring','dom','xml','xmlwriter'].includes(value.missingExtension) : value.missingExtension !== null)
      || (value.category !== 'UNKNOWN' && value.stdout !== 'OTHER')
      || (value.reportedExit === 0 && value.stdout === 'READY' && value.stderr === 'EMPTY')) fail('CAPABILITY_DIAGNOSTIC');
  return Object.freeze({schema:value.schema,reportedExit:value.reportedExit,stdout:value.stdout,stderr:value.stderr,category:value.category,missingExtension:value.missingExtension});
}
function prepareDiagnostic(value) {
  const v2 = value?.schema === 'tablo/server-prepare-failure/v2';
  const keys = ['schema', 'status', 'phase', 'rootCause', 'issueAccepted', 'stage', 'errorClass', 'source', 'line', ...(v2 ? ['capability'] : [])];
  if (!exactKeys(value, keys) || Object.keys(value).join(',') !== keys.join(',')
      || !['tablo/server-prepare-failure/v1','tablo/server-prepare-failure/v2'].includes(value.schema) || value.status !== 'failed' || value.phase !== 'prepare'
      || value.rootCause !== 'UNKNOWN' || value.issueAccepted !== false || !PREPARE_STAGES.includes(value.stage)
      || !PREPARE_CLASSES.includes(value.errorClass) || !(value.source === null && value.line === null
        || PREPARE_SOURCES.includes(value.source) && integer(value.line, 1, 1000000))) fail('PREPARE_DIAGNOSTIC');
  const result = { schema: value.schema, status: value.status, phase: value.phase, rootCause: value.rootCause,
    issueAccepted: value.issueAccepted, stage: value.stage, errorClass: value.errorClass, source: value.source, line: value.line,
    ...(v2 ? {capability:value.capability === null ? null : capabilityDiagnostic(value.capability)} : {}) };
  if (Buffer.byteLength(JSON.stringify(result)) > 2048) fail('PREPARE_DIAGNOSTIC');
  return Object.freeze(result);
}
function consolePrepareDiagnostic(value) {
  try { return prepareDiagnostic(value); } catch { return 'MISSING'; }
}
function latchObserverFailure(failure, error, owner) {
  if (failure.first) return;
  const stage = OBSERVER_STAGES.includes(failure.stage) ? failure.stage : 'UNKNOWN';
  const phase = ['prepare', 'baseline', 'engine', 'control', 'MISSING'].includes(failure.phase) ? failure.phase : 'UNKNOWN';
  const base = { schema: 'tablo/server-safe-observer-failure/v1', status: 'failed', rootCause: 'UNKNOWN', issueAccepted: false, stage, phase };
  try {
    const scalar = (value, valid) => value === undefined ? 'MISSING' : value === null ? null : valid(value) ? value : 'UNKNOWN';
    const prototype = object(error) ? Object.getPrototypeOf(error) : null;
    const errorClass = [[Error, 'Error'], [TypeError, 'TypeError'], [RangeError, 'RangeError'], [SyntaxError, 'SyntaxError']]
      .find(([type]) => prototype === type.prototype)?.[1] ?? 'UNKNOWN';
    const refusal = observerRefusals.get(error);
    failure.first = Object.freeze({ ...base, errorClass, refusal: OBSERVER_REFUSALS.includes(refusal) ? refusal : 'UNKNOWN',
      cancellation: scalar(owner?.cancelReason, value => ['deadline', 'owner-loss', 'protocol'].includes(value)),
      completedPhases: scalar(owner?.phaseResults?.length, value => integer(value, 0, 3)),
      adapterExit: scalar(owner?.adapterClose?.code, value => integer(value, -2147483648, 2147483647)),
      controllerExit: scalar(owner?.adapterState?.exited?.exitCode, value => integer(value, 0, 2147483647)),
      inputSettled: scalar(owner?.adapterState?.input?.settled, value => typeof value === 'boolean'),
      scopeObservedEmpty: scalar(owner?.adapterState?.closed?.scopeObservedEmpty, value => typeof value === 'boolean'),
      prepareDiagnostic: consolePrepareDiagnostic(owner?.phase?.kind === 'prepare' ? owner.phase.prepareDiagnostic : undefined) });
  } catch { failure.first = Object.freeze({ ...base, errorClass: 'UNKNOWN', refusal: 'UNKNOWN', prepareDiagnostic: 'MISSING' }); }
}
function emitObserverFailure(failure) {
  try {
    const bytes = JSON.stringify({ ...failure.first, prepareDiagnostic: consolePrepareDiagnostic(failure.first?.prepareDiagnostic) }) + '\n';
    if (!failure.first || Buffer.byteLength(bytes) > 4096) return false;
    process.stderr.write(bytes); return true;
  } catch { return false; }
}
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
    // PHP's existing encoder escapes slashes. This command admits only its exact
    // finite canonical representation, after authentication and duplicate checks.
    if (frame.kind === 'prepare-failure' && bytes.toString('utf8') !== JSON.stringify(payload).replaceAll('/', '\\/')) fail('PREPARE_CANONICAL');
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
          if (requestSeen) fail('CONTROL_REPLAY');
          requestSeen = true;
          const { result, finishing } = controllerRequest(owner, phase, kind, payload);
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

/** The authenticated channel grants no new capability for a failure projection. */
function controllerRequest(owner, phase, kind, payload) {
  const { leases } = owner;
  if (owner.phase !== phase || payload.sequence !== phase.sequence + 1 || phase.sequence >= 49152) fail('CONTROL_REPLAY');
  phase.sequence++;
  let result; let finishing = null;
  if (kind === 'begin' && exactKeys(payload, ['sequence', 'seconds', 'label'])) {
    result = leases.admit(payload.seconds, payload.label);
  } else if (kind === 'finish' && exactKeys(payload, ['sequence', 'id', 'closureKnown'])) {
    leases.validateFinish(payload.id, payload.closureKnown); finishing = payload.id; result = { closed: true };
  } else if (kind === 'remaining' && exactKeys(payload, ['sequence'])) {
    const remaining = leases.remaining(); if (remaining <= RESERVE) fail('PARENT_EXPIRED');
    result = { remainingNanoseconds: remaining.toString() };
  } else if (kind === 'prepare-failure' && exactKeys(payload, ['sequence', 'cellNonce', 'diagnostic'])
      && Object.keys(payload).join(',') === 'sequence,cellNonce,diagnostic') {
    if (owner.control !== false || owner.plan.executionMode !== 'qualified' || phase.kind !== 'prepare'
        || !NONCE.test(phase.nonce) || !NONCE.test(owner.plan.nonce) || payload.cellNonce !== owner.plan.nonce
        || phase.prepareDiagnosticOpen !== true || owner.cancelReason !== null || leases.remaining() <= RESERVE
        || phase.prepareDiagnostic !== undefined) fail('PREPARE_CONTEXT');
    phase.prepareDiagnostic = prepareDiagnostic(payload.diagnostic); result = { recorded: true };
  } else fail('CONTROL_COMMAND');
  return { result, finishing };
}

function phaseProcessLimit(control, kind) { return !control && kind === 'baseline' ? 9 : 8; }
function executionAdmission(plan) {
  if (plan.executionMode !== 'qualified') fail('EXECUTION_MODE_UNQUALIFIED');
}

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
  return { schema: windows ? 'tablo/server-observer-windows-plan/v1' : 'tablo/server-observer-linux-plan/v1',
    nonce: phase.nonce, root: phase.root, planId: phase.nonce + (windows ? '/windows-controller/1' : '/linux-controller/1'),
    authorityFile: phase.adapterAuthorityFile, authorityLength: 32,
    adapter: windows ? observer.windowsAdapter : observer.linuxAdapter, powershell: observer.powershell ?? null,
    ...(!windows ? { linux: { policy: LINUX_POLICY, unit: 'process/TGID', source: observer.linuxAdapterSource,
      recipe: observer.linuxAdapterRecipe, build: observer.linuxAdapterBuild,
      deadlineNanoseconds: leases.end().toString(), ownerMonotonicNanoseconds: process.hrtime.bigint().toString() } } : {}),
    parentNodePid: process.pid, launch, launchCanonicalBase64: canonical.toString('base64'), launchSha256: sha(canonical),
    job: { name: null, logicalId: phase.nonce + '/job/1', killOnClose: true, breakaway: false, atomicJobListRequired: true, activeProcessLimit: phaseProcessLimit(owner.control, phase.kind) },
    limits: { maximumFrameBytes: 65536, maximumPayloadBytes: 32768, maximumFrames: 64, maximumParentSeconds: 1800, maximumControllers: 1, cancellationExitCode: 124 },
    initialRemainingNanoseconds: leases.remaining().toString() };
}

function adapterState(plan) {
  return { planId: plan.planId, ready: null, started: null, input: null, exited: null, closed: null, failed: null, notStarted: null };
}

async function linuxContext() {
  return { kernel: require('node:os').release(), arch: process.arch, uid: process.getuid(), gid: process.getgid(),
    mountNamespace: await fs.readlink('/proc/self/ns/mnt'), userNamespace: await fs.readlink('/proc/self/ns/user'),
    pidNamespace: await fs.readlink('/proc/self/ns/pid') };
}
function qualificationSelection(q, selected, file) {
  if (!selected || !NONCE.test(selected.nonce) || q.nonce !== selected.nonce
      || !exactKeys(q.runtimes, ['node', 'php', 'composer', 'shell', 'docker'])
      || Object.keys(q.runtimes).join(',') !== 'node,php,composer,shell,docker') fail('LINUX_FOREIGN_QUALIFICATION');
  const qroot = path.dirname(file), parent = path.dirname(qroot);
  if (path.basename(qroot) !== 'tablo-linux-' + selected.nonce || path.basename(file) !== 'qualification.json'
      || q.runtimeControls?.path !== qroot + '/runtime-controls.json' || q.platformReceipt?.path !== qroot + '/platform.json'
      || q.neutralControls?.path !== qroot + '/neutral-controls.json'
      || q.fallbackControls?.path !== qroot + '/fallback-controls.json') fail('LINUX_EVIDENCE_SCOPE');
  for (const name of Object.keys(q.runtimes)) {
    const tool = selected.runtimes[name];
    if (!exactKeys(q.runtimes[name], ['path', 'sha256']) || !tool || q.runtimes[name].path !== tool.path
        || q.runtimes[name].sha256 !== tool.sha256) fail('LINUX_FOREIGN_RUNTIME');
  }
  return { qroot, parent };
}
async function linuxPins(observer, control = false, selected = null) {
  if (process.platform !== 'linux' || process.arch !== 'x64') fail('LINUX_ABI_UNQUALIFIED');
  for (const name of ['linuxAdapter', 'linuxAdapterSource', 'linuxAdapterRecipe', 'linuxAdapterBuild']) await pinned(observer[name]);
  const b = await readPlan(observer.linuxAdapterBuild.path, observer.linuxAdapterBuild.sha256, 16384);
  if (!exactKeys(b, ['schema', 'policy', 'target', 'sourceSha256', 'recipeSha256', 'binarySha256', 'compilerImageDigest',
    'compilerSha256', 'linkerSha256', 'libcArchiveSha256']) || b.schema !== 'tablo/linux-adapter-build/v1'
      || b.policy !== LINUX_POLICY || b.target !== 'linux-x86_64' || b.sourceSha256 !== observer.linuxAdapterSource.sha256
      || b.recipeSha256 !== observer.linuxAdapterRecipe.sha256 || b.binarySha256 !== observer.linuxAdapter.sha256
      || !/^sha256:[0-9a-f]{64}$/.test(b.compilerImageDigest)
      || ![b.compilerSha256, b.linkerSha256, b.libcArchiveSha256].every(value => HEX.test(value))) fail('LINUX_BUILD_UNQUALIFIED');
  if (!control) {
    const pin = observer.linuxAdapterQualification;
    await pinned(pin);
    const q = await readPlan(pin.path, pin.sha256, 16384);
    if (!object(q) || Object.keys(q).join(',') !== QUALIFICATION_KEYS.join(',')
        || q.schema !== 'tablo/linux-adapter-qualification/v3' || q.policy !== LINUX_POLICY || q.status !== 'qualified'
        || q.binarySha256 !== observer.linuxAdapter.sha256 || q.sourceSha256 !== observer.linuxAdapterSource.sha256
        || q.recipeSha256 !== observer.linuxAdapterRecipe.sha256 || q.buildSha256 !== observer.linuxAdapterBuild.sha256
        || ![q.controlsSourceSha256, q.controlsBinarySha256, q.runtimeControlsSha256, q.platformReceiptSha256, q.fallbackControlsSha256].every(value => HEX.test(value))) fail('LINUX_RUNTIME_UNQUALIFIED');
    const { qroot, parent } = qualificationSelection(q, selected, pin.path);
    for (const name of Object.keys(q.runtimes)) {
      const tool = selected.runtimes[name];
      await regular(tool.path); await pinned(tool);
    }
    for (const evidence of [q.runtimeControls, q.platformReceipt, q.neutralControls, q.fallbackControls]) {
      if (!exactKeys(evidence, ['path', 'sha256']) || Object.keys(evidence).join(',') !== 'path,sha256') fail('LINUX_EVIDENCE_PIN');
      await regular(evidence.path); await pinned(evidence);
    }
    if (q.runtimeControls.sha256 !== q.runtimeControlsSha256 || q.platformReceipt.sha256 !== q.platformReceiptSha256
        || q.fallbackControls.sha256 !== q.fallbackControlsSha256) fail('LINUX_EVIDENCE_CHANGED');
    const runtime = await readPlan(q.runtimeControls.path, q.runtimeControls.sha256);
    const platform = await readPlan(q.platformReceipt.path, q.platformReceipt.sha256);
    const neutral = await readPlan(q.neutralControls.path, q.neutralControls.sha256);
    const fallback = await readPlan(q.fallbackControls.path, q.fallbackControls.sha256);
    const sourceDirectory = path.dirname(observer.linuxAdapterSource.path);
    if (q.controlsSourceSha256 !== await digest(path.join(sourceDirectory, 'server-supervisor-linux-controls.c'))
        || q.controlsBinarySha256 !== await digest(path.join(path.dirname(observer.linuxAdapter.path), 'server-supervisor-linux-controls'))
        || runtime.owner?.sha256 !== await digest(__filename)
        || runtime.qualifier?.sha256 !== await digest(path.join(sourceDirectory, 'server-supervisor-linux-qualify.cjs'))) fail('LINUX_FOREIGN_CONTROL_SOURCE');
    if (platform.schema !== 'tablo/linux-qualified-platform/v1' || platform.nonce !== selected.nonce
        || JSON.stringify(platform.context) !== JSON.stringify(await linuxContext())
        || JSON.stringify(platform.runtimes) !== JSON.stringify(q.runtimes)
        || runtime.status !== 'passed' || runtime.nonce !== selected.nonce || JSON.stringify(runtime.runtimes) !== JSON.stringify(q.runtimes)
        || neutral.status !== 'passed' || neutral.nonce !== selected.nonce || neutral.count !== 30
        || neutral.controlsSourceSha256 !== q.controlsSourceSha256 || neutral.controlsBinarySha256 !== q.controlsBinarySha256
        || runtime.witness?.stdout !== 'WITNESS_CLOSED 3 3 SIGKILL ECHILD\n' || runtime.witness.status?.code !== 0
        || runtime.witness.status?.signal !== null || runtime.witness.expired !== false || runtime.witness.overflow !== false
        || !Array.isArray(runtime.clients) || runtime.clients.map(row => row.label).join(',') !== 'node,php,composer,shell,docker') fail('LINUX_EVIDENCE_UNQUALIFIED');
    if (!selected.phpBootstrap || !Array.isArray(selected.phpBootstrap.iniFiles) || !Array.isArray(selected.phpBootstrap.extensionFiles)
        || JSON.stringify(runtime.phpBootstrap) !== JSON.stringify(selected.phpBootstrap)
        || JSON.stringify(platform.phpBootstrap) !== JSON.stringify(selected.phpBootstrap)) fail('LINUX_COMPOSER_BOOTSTRAP_UNQUALIFIED');
    for (const tool of [...selected.phpBootstrap.iniFiles, ...selected.phpBootstrap.extensionFiles]) { await regular(tool.path); await pinned(tool); }
    if (path.dirname(platform.preparedReceipt.path) !== parent || path.resolve(platform.preparedReceipt.path) !== platform.preparedReceipt.path
        || platform.admission.path !== parent + '/platform-admission.json') fail('LINUX_PLATFORM_SCOPE');
    await regular(platform.preparedReceipt.path); await pinned(platform.preparedReceipt);
    const prepared = await readPlan(platform.preparedReceipt.path, platform.preparedReceipt.sha256);
    if (prepared.schema !== 'tablo/linux-prepared-platform/v1' || prepared.nonce !== selected.nonce) fail('LINUX_PLATFORM_UNQUALIFIED');
    await regular(platform.admission.path); await pinned(platform.admission);
    const admission = await readPlan(platform.admission.path, platform.admission.sha256);
    if (admission.schema !== 'tablo/linux-platform-admission/v1' || admission.nonce !== selected.nonce
        || !['host', 'container'].includes(admission.kind) || admission.kind === 'container'
          && (!/^[0-9a-f]{64}$/.test(admission.containerId) || admission.containerName !== prepared.containerName
            || admission.ownedConfigValidated !== true)) fail('LINUX_PLATFORM_UNQUALIFIED');
    await linuxControlEvidence(neutral, fallback, q, qroot, platform, runtime, selected,
      path.join(path.dirname(observer.linuxAdapter.path), 'server-supervisor-linux-controls'));
    for (const row of runtime.clients) {
      if (row.receipt.path !== qroot + '/client-' + row.label + '/result.json') fail('LINUX_CLIENT_SCOPE');
      await regular(row.receipt.path); await pinned(row.receipt);
      const result = await readPlan(row.receipt.path, row.receipt.sha256);
      const events = result.events, close = result.adapterClose;
      const executable = q.runtimes[row.label === 'composer' ? 'php' : row.label];
      if (result.label !== row.label || close?.code !== 0 || close.signal !== null || close.closeObserved !== true
          || JSON.stringify(row.executable) !== JSON.stringify(executable)
          || !linuxReceiptIdentity(events, q, 8, q.nonce + '/client/' + row.label, executable)
          || close.stderrBytes !== 0 || events?.ready?.helperSha256 !== q.binarySha256 || events.ready.sourceSha256 !== q.sourceSha256
          || events.exited?.exitCode !== 0 || events.exited.posixSignal !== null || events.input?.sourceEofObserved !== true
          || !linuxAdmission(events.closed?.admission, 8) || events.closed.admission.failed !== false
          || events.closed.admission.deniedAdmissions !== events.closed.admission.ioUringSetupAttempts
          || events.closed.admission.ioUringSetupAttempts !== events.closed.admission.ioUringSetupEpermWrites
          || row.label === 'node' && events.closed.admission.ioUringSetupAttempts === 0
          || !linuxClosedEvents(events, close)) fail('LINUX_CLIENT_UNQUALIFIED');
    }
  }
}

// These validators stay in the pinned owner that admits actual product work.
function linuxAdmission(a, configuredLimit) {
  return exactKeys(a, ['configuredLimit', 'liveTgids', 'pendingReservations', 'peakCommitted', 'processBirths', 'processReservations',
    'failedCreations', 'deniedAdmissions', 'clone3Fallbacks', 'threadBirths', 'terminalWaits', 'execRetiredIdentities', 'waitEchild', 'failed',
    'ioUringSetupAttempts', 'ioUringSetupEpermWrites'])
    && a.configuredLimit === configuredLimit && a.liveTgids === 0 && a.pendingReservations === 0
    && integer(a.peakCommitted, 1, a.configuredLimit) && integer(a.processBirths, 1, 0xffffffff)
    && integer(a.processReservations, a.processBirths - 1, 0xffffffff) && integer(a.failedCreations, 0, 0xffffffff)
    && integer(a.deniedAdmissions, 0, 2147483647) && integer(a.ioUringSetupAttempts, 0, a.deniedAdmissions)
    && integer(a.ioUringSetupEpermWrites, 0, a.ioUringSetupAttempts)
    && a.peakCommitted <= a.processBirths + a.failedCreations
    && a.processReservations <= a.processBirths - 1 + a.failedCreations
    && integer(a.clone3Fallbacks, 0, 0xffffffff) && integer(a.threadBirths, 0, 0xffffffff)
    && integer(a.execRetiredIdentities, 0, a.threadBirths) && integer(a.terminalWaits, 1, 0xffffffff)
    && a.terminalWaits + a.execRetiredIdentities === a.processBirths + a.threadBirths
    && a.waitEchild === true && typeof a.failed === 'boolean';
}
function linuxClosedEvents(events, close) {
  const input = events?.input, closed = events?.closed;
  return close?.closeObserved === true && close.signal === null && close.spawnError === false
    && close.protocolFailed === false && close.stdoutTrailingBytes === 0 && close.stderrBytes === 0
    && close.nativeProtocol === 'private-inherited-pipes/v2'
    && events?.ready?.policy === LINUX_POLICY && events.started && events.exited?.rootExitObserved === true
    && events.exited.terminationRequested === false && input?.settled === true && input.stdinClosed === true
    && input.errorCode === null && input.sourceEofObserved === true
    && integer(input.declaredBytes, 0, 1048576) && input.writtenBytes === input.declaredBytes
    && closed?.scopeObservedEmpty === true && closed.rootExitObserved === true && closed.inputSettled === true
    && closed.stdinClosed === true && closed.custodySettled === true
    && closed.descendantStatuses === 'TERMINAL_WAIT_REGISTRY'
    && closed.stdout?.closed === true && closed.stderr?.closed === true;
}
function linuxReceiptIdentity(events, q, limit, planId, executable) {
  const ready = events?.ready, started = events?.started;
  return events?.planId === planId && ['ready','started','input','exited','closed'].every(kind => events[kind]?.planId === planId)
    && ready.policy === LINUX_POLICY && ready.helperSha256 === q.binarySha256 && ready.sourceSha256 === q.sourceSha256
    && ready.recipeSha256 === q.recipeSha256 && ready.buildSha256 === q.buildSha256
    && ready.configuredLimit === limit && ready.limitUnit === 'process/TGID'
    && started.actualImagePath === executable.path && started.actualImageSha256 === executable.sha256;
}
async function linuxControlEvidence(neutral, fallback, q, qroot, platform, runtime, selected, controlsPath) {
  const original = [
    ['limit8',8,8,'ok'], ['limit9',9,8,'ok'], ['overflow8',8,8,'denied'], ['overflow9',9,8,'denied'],
    ['threads',8,8,'ok'], ['threadfork',8,8,'ok'], ['threadexec',8,8,'exit23'], ['concurrent',8,8,'denied'],
    ['vfork',8,8,'ok'], ['spawn',8,8,'ok'], ['short',8,8,'ok'], ['failedfork',8,8,'ok'], ['clone3',8,8,'ok'],
    ['untraced',8,8,'denied'], ['filter',8,8,'denied'], ['namespace',8,8,'denied'], ['ptrace',8,8,'denied'],
    ['x32',8,8,'sigsys'], ['i386',8,8,'sigsys'], ['stdin0',8,8,'ok'], ['stdin65536',8,8,'ok'],
    ['exit23',8,8,'exit23'], ['signal',8,8,'signal'], ['rootfirst',8,8,'cancelled'],
    ['owner-eof',8,8,'cancelled'], ['deadline',8,8,'cancelled'], ['deadline',8,20,'cancelled']
  ];
  if (!Array.isArray(neutral.outcomes) || neutral.outcomes.length !== 30 || neutral.externalNamespaceDenial !== false
      || neutral.nativeNamespaceLatchCoverage !== true) fail('LINUX_NEUTRAL_COVERAGE');
  for (const [index, [mode, limit, seconds, expectation]] of original.entries()) {
    const row = neutral.outcomes[index], a = row?.events?.closed?.admission;
    if (row?.mode !== mode || row.limit !== limit || row.seconds !== seconds || row.expectation !== expectation
        || row.status !== 'passed' || !linuxAdmission(a, limit)
        || !linuxReceiptIdentity(row.events, q, limit, q.nonce + '/linux-controller/' + index, { path: controlsPath, sha256: q.controlsBinarySha256 })
        || row.adapterClose?.closeObserved !== true
        || row.adapterClose.nativeProtocol !== 'private-inherited-pipes/v2' || row.adapterClose.protocolFailed !== false
        || row.adapterClose.signal !== null || row.adapterClose.stderrBytes !== 0
        || row.events.closed.scopeObservedEmpty !== true || row.events.closed.custodySettled !== true
        || (mode.startsWith('limit') || mode.startsWith('overflow')) && a.peakCommitted !== limit
        || mode === 'threads' && a.threadBirths < 4 || mode === 'failedfork' && a.failedCreations < 1) fail('LINUX_NEUTRAL_COVERAGE');
    if (['ok','exit23','signal'].includes(expectation)) {
      if (row.adapterClose.code !== 0 || a.failed !== false || a.deniedAdmissions !== 0
          || row.events.exited?.exitCode !== (expectation === 'ok' ? 0 : expectation === 'exit23' ? 23 : null)
          || row.events.exited.posixSignal !== (expectation === 'signal' ? 10 : null)) fail('LINUX_NEUTRAL_COVERAGE');
    } else if (row.adapterClose.code !== 125 || a.failed !== true
        || expectation === 'denied' && (a.deniedAdmissions === 0 || row.events.exited?.exitCode !== 0)
        || expectation === 'sigsys' && row.events.exited?.posixSignal !== 31) fail('LINUX_NEUTRAL_COVERAGE');
  }
  for (const [index, kind] of ['partial-input','unresponsive-input','invalid-capture'].entries()) {
    const row = neutral.outcomes[index + 27];
    if (row?.kind !== kind || row.status !== 'passed' || row.expectedFailure !== true
        || row.nativeStatus?.code !== 125 || row.nativeStatus.signal !== null || !Array.isArray(row.seen)) fail('LINUX_NEUTRAL_BOUNDARY');
    if (kind === 'invalid-capture') { if (row.seen.length !== 0) fail('LINUX_NEUTRAL_BOUNDARY'); }
    else {
      const input = row.seen.find(item => item.event === 'INPUT'), closed = row.seen.find(item => item.event === 'CLOSED');
      if (JSON.stringify(input?.values) !== JSON.stringify([65536,kind === 'partial-input' ? 1 : 0,1,0])
          || closed?.values?.length !== 15 || closed.values[0] !== 0 || closed.values[1] !== 0
          || closed.values[11] !== 1 || closed.values[12] !== 1) fail('LINUX_NEUTRAL_BOUNDARY');
    }
  }
  const keys = ['schema','policy','status','nonce','sourceSha256','recipeSha256','binarySha256','buildSha256',
    'controlsSourceSha256','controlsBinarySha256','owner','qualifier','platformReceipt','runtimes','count','outcomes'];
  if (!object(fallback) || Object.keys(fallback).join(',') !== keys.join(',')
      || fallback.schema !== 'tablo/linux-io-uring-fallback-controls/v1' || fallback.policy !== LINUX_POLICY
      || fallback.status !== 'passed' || fallback.nonce !== selected.nonce || fallback.count !== 8
      || !Array.isArray(fallback.outcomes) || fallback.outcomes.length !== 8
      || JSON.stringify(fallback.runtimes) !== JSON.stringify(q.runtimes)
      || JSON.stringify(fallback.owner) !== JSON.stringify(runtime.owner)
      || JSON.stringify(fallback.qualifier) !== JSON.stringify(runtime.qualifier)
      || JSON.stringify(fallback.platformReceipt) !== JSON.stringify(platform.preparedReceipt)) fail('LINUX_FALLBACK_BINDING');
  for (const pin of [fallback.owner, fallback.qualifier, fallback.platformReceipt]) {
    if (!exactKeys(pin, ['path','sha256']) || Object.keys(pin).join(',') !== 'path,sha256') fail('LINUX_FALLBACK_BINDING');
  }
  for (const key of ['sourceSha256','recipeSha256','binarySha256','buildSha256','controlsSourceSha256','controlsBinarySha256']) {
    if (fallback[key] !== q[key]) fail('LINUX_FALLBACK_BINDING');
  }
  const sourceDirectory = path.dirname(runtime.owner.path);
  if (runtime.owner.path !== __filename || runtime.qualifier.path !== path.join(sourceDirectory, 'server-supervisor-linux-qualify.cjs')) fail('LINUX_FALLBACK_OWNER');
  await regular(runtime.owner.path); await pinned(runtime.owner); await regular(runtime.qualifier.path); await pinned(runtime.qualifier);
  await regular(sourceDirectory + '/server-supervisor-linux-controls.c');
  for (const [index, name] of FALLBACK_NAMES.entries()) {
    const item = fallback.outcomes[index];
    if (!exactKeys(item, ['name','receipt']) || Object.keys(item).join(',') !== 'name,receipt' || item.name !== name
        || !exactKeys(item.receipt, ['path','sha256']) || Object.keys(item.receipt).join(',') !== 'path,sha256'
        || item.receipt.path !== qroot + '/fallback-' + index + '/result.json') fail('LINUX_FALLBACK_SCOPE');
    await regular(item.receipt.path); await pinned(item.receipt);
    const row = await readPlan(item.receipt.path, item.receipt.sha256), a = row.events?.closed?.admission;
    const count = ['uring-repeat','uring-arguments','uring-threads'].includes(name) ? 4 : 1;
    const mixed = name === 'uring-mixed-security' || name === 'uring-mixed-quota';
    if (row.name !== name || row.status !== 'passed' || !linuxAdmission(a, 8) || !linuxClosedEvents(row.events, row.adapterClose)
        || !linuxReceiptIdentity(row.events, q, 8, q.nonce + '/fallback/' + index, { path: controlsPath, sha256: q.controlsBinarySha256 })
        || a.ioUringSetupAttempts !== count || a.ioUringSetupEpermWrites !== count
        || row.adapterClose.code !== (mixed ? 125 : 0) || a.failed !== mixed
        || a.deniedAdmissions !== count + (name === 'uring-mixed-security' ? 8 : name === 'uring-mixed-quota' ? 1 : 0)
        || row.events.exited.exitCode !== (name === 'uring-unhandled' ? 23 : 0) || row.events.exited.posixSignal !== null
        || name === 'uring-mixed-quota' && (a.peakCommitted !== 8 || a.processBirths !== 8)
        || name === 'uring-threads' && (a.threadBirths < 4 || a.processBirths !== 1 || a.processReservations !== 0)
        || name === 'uring-forkexec' && (a.processBirths !== 2 || a.processReservations !== 1)
        || ['uring-handled','uring-repeat','uring-arguments','uring-unhandled'].includes(name)
          && (a.processBirths !== 1 || a.processReservations !== 0)) fail('LINUX_FALLBACK_COVERAGE');
    const capture = row.events.closed.stdout;
    if (capture.path !== qroot + '/fallback-' + index + '/evidence/controller.stdout'
        || !integer(capture.bytes, 1, 65536)) fail('LINUX_FALLBACK_CAPTURE');
    await regular(capture.path); await pinned(capture);
    if ((await fs.stat(capture.path)).size !== capture.bytes
        || (await fs.readFile(capture.path, 'utf8')) !== 'IO_URING_EPERM ' + name + '\n') fail('LINUX_FALLBACK_CAPTURE');
  }
}

// Node is the existing trusted owner, not a proxy process. Native has no key or
// JSON parser: its pipe ends are private inherited handles, closed before child
// exec. Node validates the finite native grammar and authenticates retained event
// payloads with the same Frames implementation used for the other owner channels.
const DENIAL_FIELDS = Object.freeze(['ptrace', 'seccomp', 'prctlSetSeccomp', 'prctlSetPtracer', 'prctlSetDumpable',
  'unshare', 'setns', 'ioUringSetup', 'cloneFlags', 'cloneLimit', 'forkLimit', 'vforkLimit', 'unknown',
  'epermRegisterWrites', 'eagainRegisterWrites']);
function linuxDenialDiagnostic(values, deniedCount) {
  if (!Array.isArray(values) || values.length !== DENIAL_FIELDS.length
      || !integer(deniedCount, 1, 2147483647) || values.some(value => !integer(value, 0, 2147483647))) fail('LINUX_DENIAL_DIAGNOSTIC');
  const decisions = values.slice(0, 13).reduce((sum, value) => sum + value, 0);
  const eperm = values.slice(0, 9).reduce((sum, value) => sum + value, 0) + values[12];
  const eagain = values[9] + values[10] + values[11];
  if (decisions !== deniedCount || values[13] > eperm || values[14] > eagain
      || values[13] + values[14] > deniedCount) fail('LINUX_DENIAL_DIAGNOSTIC');
  return Object.freeze(Object.fromEntries(DENIAL_FIELDS.map((key, index) => [key, values[index]])));
}
class LinuxRecords {
  constructor() {
    this.pending = Buffer.alloc(0); this.count = 0; this.total = 0;
    this.seen = new Set(); this.denials = null; this.denialDiagnostic = 'UNKNOWN'; this.closed = false;
  }
  push(bytes, receive) {
    try { this.read(bytes, receive); }
    catch (error) { this.denialDiagnostic = 'UNKNOWN'; throw error; }
  }
  read(bytes, receive) {
    this.total += bytes.length;
    if (this.total > 8192) fail('LINUX_RECORD_LIMIT');
    this.pending = Buffer.concat([this.pending, bytes]);
    let end;
    while ((end = this.pending.indexOf(10)) >= 0) {
      const line = this.pending.subarray(0, end); this.pending = this.pending.subarray(end + 1);
      if (++this.count > 8 || line.length > 512 || !/^[A-Z0-9a-z -]+$/.test(line.toString('ascii'))
          || [...line].some(value => value > 127)) fail('LINUX_RECORD_ENCODING');
      const [kind, ...values] = line.toString('ascii').split(' ');
      const lengths = { READY: 3, STARTED: 1, INPUT: 4, EXITED: 4, DENIALS: 15, CLOSED: 15 };
      if (values.length !== lengths[kind]) fail('LINUX_RECORD_FIELDS');
      if (this.denials && this.closed) fail('LINUX_DENIAL_ORDER');
      if (this.denials && !this.closed && kind !== 'CLOSED') fail('LINUX_DENIAL_ORDER');
      if (kind === 'DENIALS' && (this.denials || this.closed
          || !['READY', 'STARTED', 'INPUT', 'EXITED'].every(event => this.seen.has(event)))) fail('LINUX_DENIAL_ORDER');
      if (kind === 'READY') {
        if (!/^[1-9][0-9]{0,9}$/.test(values[0]) || values[1] !== LINUX_POLICY
            || !/^[1-9][0-9]{0,19}$/.test(values[2])) fail('LINUX_RECORD_POLICY');
        receive(kind, [Number(values[0]), values[1], values[2]]);
      } else {
        if (values.some(value => !/^(0|[1-9][0-9]{0,9}|-1)$/.test(value) || !Number.isSafeInteger(Number(value)))) fail('LINUX_RECORD_INTEGER');
        const numbers = values.map(Number);
        if (kind === 'DENIALS') {
          const sum = numbers.slice(0, 13).reduce((total, value) => total + value, 0);
          linuxDenialDiagnostic(numbers, sum); this.denials = numbers;
        } else if (kind === 'CLOSED') {
          if (this.closed || !['READY','STARTED','INPUT','EXITED'].every(event => this.seen.has(event))) fail('LINUX_CLOSE_ORDER');
          if (!integer(numbers[6], 0, 2147483647) || !integer(numbers[13], 0, numbers[6])
              || !integer(numbers[14], 0, numbers[13])) fail('LINUX_CLOSE_AUTHORITY');
          if (this.denials) this.denialDiagnostic = linuxDenialDiagnostic(this.denials, numbers[6]);
          this.closed = true;
        }
        receive(kind, numbers);
      }
      this.seen.add(kind);
    }
    if (this.pending.length > 512) fail('LINUX_RECORD_SIZE');
  }
}

async function runLinuxAdapter(owner, plan) {
  if (process.platform !== 'linux' || process.arch !== 'x64' || plan.linux.policy !== LINUX_POLICY
      || plan.linux.unit !== 'process/TGID' || ![8, 9].includes(plan.job.activeProcessLimit)
      || !/^[1-9][0-9]{0,19}$/.test(plan.linux.deadlineNanoseconds)) fail('LINUX_UNQUALIFIED');
  const file = owner.phase.root + '/private/adapter.json';
  await retain(file, plan); const planHash = await digest(file);
  await pinned(plan.adapter); await pinned(plan.launch.executable);
  const input = plan.launch.input;
  let inputFile;
  if (input.mode === 'none' && input.path === null && input.sha256 === null && input.bytes === 0) {
    inputFile = owner.phase.root + '/private/input.empty'; await fs.writeFile(inputFile, Buffer.alloc(0), { flag: 'wx', mode: 0o600 });
  } else if (input.mode === 'synthetic-file' && input.path === owner.phase.root + '/private/input.bin'
      && integer(input.bytes, 1, 1048576) && await digest(input.path) === input.sha256 && (await fs.stat(input.path)).size === input.bytes) inputFile = input.path;
  else fail('LINUX_INPUT_DECLARATION');
  const handles = [];
  try {
    for (const name of ['stdout', 'stderr']) handles.push(await fs.open(plan.launch[name], 'wx', 0o600));
    handles.push(await fs.open(await regular(inputFile), 'r'));
    for (const handle of handles) if (!(await handle.stat()).isFile()) fail('LINUX_NONREGULAR_CAPTURE_OR_INPUT');
    const child = spawn(plan.adapter.path, [String(plan.job.activeProcessLimit), plan.linux.deadlineNanoseconds,
      String(input.bytes), '--', plan.launch.executable.path, ...plan.launch.argv], {
      cwd: plan.launch.cwd, env: plan.launch.environment, stdio: ['pipe', 'pipe', 'pipe', ...handles.map(handle => handle.fd)]
    });
    owner.adapter = child;
    const state = adapterState(plan); owner.adapterState = state;
    const records = new LinuxRecords(); const frames = new Frames(owner.phase.adapterAuthority, plan.nonce, 'adapter-to-node', 'node-to-adapter');
    owner.nativeDenialDiagnostic = 'UNKNOWN'; // Never an admission/event field.
    let stdout = Buffer.alloc(0), stderr = Buffer.alloc(0), authenticated = Buffer.alloc(0);
    let spawnError = false, stderrTruncated = false, protocolFailed = false, closedRecord = null, commands = 0;
    const send = (kind, value = {}) => {
      if (value.planId && value.planId !== plan.planId) fail('LINUX_COMMAND_ID');
      const command = { launch: 'LAUNCH\n', cancel: 'CANCEL\n', shutdown: 'SHUTDOWN\n' }[kind];
      if (!command || ++commands > 64) fail('LINUX_COMMAND_LIMIT');
      if (!child.stdin.destroyed) child.stdin.write(command);
    };
    owner.send = send;
    const emit = (kind, value) => {
      const payload = { planId: plan.planId, ...value };
      acceptEvent(state, kind, payload, plan, child, planHash);
      authenticated = Buffer.concat([authenticated, frames.encode(kind, payload)]);
    };
    const receive = (kind, n) => {
      if (kind === 'READY') {
        emit('ready', { adapterPid: n[0], platform: 'LinuxX64', planSha256: planHash, capabilityReady: true,
          policy: n[1], limitUnit: 'process/TGID', configuredLimit: plan.job.activeProcessLimit,
          helperSha256: plan.adapter.sha256, sourceSha256: plan.linux.source.sha256, recipeSha256: plan.linux.recipe.sha256, buildSha256: plan.linux.build.sha256,
          clockDomain: 'CLOCK_MONOTONIC', monotonicNanoseconds: n[2] });
        if (owner.cancelReason) send('cancel'); else send('launch');
      } else if (kind === 'STARTED') emit('started', { controllerPid: n[0], actualImagePath: plan.launch.executable.path,
        actualImageSha256: plan.launch.executable.sha256, preExecGate: true, pidfdRetained: true });
      else if (kind === 'INPUT') {
        if (![0, 1].includes(n[2]) || ![0, 1].includes(n[3])) fail('LINUX_INPUT_FLAG');
        emit('input', { mode: input.mode, declaredBytes: n[0], writtenBytes: n[1], settled: true, stdinClosed: true,
          errorCode: n[2] === 0 ? null : 'INPUT_WRITE_FAILED', sourceEofObserved: n[3] === 1 });
      } else if (kind === 'EXITED') {
        if (![0, 1].includes(n[3])) fail('LINUX_EXIT_FLAG');
        emit('exited', { controllerPid: n[0], rootExitObserved: true, exitCode: n[1] === -1 ? null : n[1],
          posixSignal: n[2] === 0 ? null : n[2], terminationRequested: n[3] === 1 });
      } else if (kind === 'CLOSED') {
        if (!state.exited || !state.input || closedRecord || n.some(value => value < 0)
            || n[11] !== 1 || ![0, 1].includes(n[12])) fail('LINUX_CLOSE_RECORD');
        closedRecord = { configuredLimit: plan.job.activeProcessLimit, liveTgids: n[0], pendingReservations: n[1], peakCommitted: n[2],
          processBirths: n[3], processReservations: n[4], failedCreations: n[5], deniedAdmissions: n[6], clone3Fallbacks: n[7],
          threadBirths: n[8], terminalWaits: n[9], execRetiredIdentities: n[10], waitEchild: true, failed: n[12] === 1,
          ioUringSetupAttempts: n[13], ioUringSetupEpermWrites: n[14] };
        send('shutdown');
      }
    };
    child.stdin.on('error', () => owner.cancel('protocol'));
    child.once('error', () => { spawnError = true; owner.cancel('protocol'); });
    child.stderr.on('data', bytes => {
      if (stderr.length + bytes.length > 65536) { stderrTruncated = true; owner.cancel('protocol'); }
      stderr = Buffer.concat([stderr, bytes.subarray(0, 65536 - stderr.length)]);
    });
    child.stdout.on('data', bytes => {
      try {
        if (stdout.length + bytes.length > 8192) fail('LINUX_OUTPUT_LIMIT');
        stdout = Buffer.concat([stdout, bytes]); records.push(bytes, receive);
        owner.nativeDenialDiagnostic = protocolFailed ? 'UNKNOWN' : records.denialDiagnostic;
      } catch { owner.nativeDenialDiagnostic = 'UNKNOWN'; protocolFailed = true; owner.cancel('protocol'); child.stdin.end(); }
    });
    const close = await new Promise(resolve => {
      child.once('close', (code, signal) => resolve({ code, signal, closeObserved: true }));
      owner.expire = () => {
        child.stdin.end(); child.kill('SIGKILL'); // EXITKILL kills the owned traced tree, including stopped threads.
        child.unref(); child.stdout.destroy(); child.stderr.destroy();
        resolve({ code: null, signal: null, closeObserved: false });
      };
    });
    owner.expire = null;
    if (!close.closeObserved || records.pending.length || protocolFailed) owner.nativeDenialDiagnostic = 'UNKNOWN';
    for (let i = 0; i < handles.length; i++) if (i < 2) await handles[i].sync();
    for (const handle of handles) await handle.close();
    handles.length = 0;
    if (closedRecord && close.closeObserved && records.pending.length === 0 && !protocolFailed) {
      const capture = async name => ({ path: plan.launch[name], bytes: (await fs.stat(plan.launch[name])).size,
        sha256: await digest(plan.launch[name]), closed: true });
      emit('closed', { controllerPid: state.started.controllerPid, rootExitObserved: true, scopeObservedEmpty: true,
        inputSettled: true, stdinClosed: true, stdout: await capture('stdout'), stderr: await capture('stderr'),
        custodySettled: true, descendantStatuses: 'TERMINAL_WAIT_REGISTRY', admission: closedRecord });
    }
    owner.adapterClose = { pid: child.pid ?? null, ...close, spawnError, stdoutTrailingBytes: records.pending.length,
      stdoutBytes: stdout.length, stdoutSha256: sha(stdout), stderrBytes: stderr.length, stderrSha256: sha(stderr), stderrTruncated,
      nativeProtocol: 'private-inherited-pipes/v2', protocolFailed };
    await fs.writeFile(owner.phase.root + '/evidence/adapter.stdout', stdout, { flag: 'wx', mode: 0o600 });
    await fs.writeFile(owner.phase.root + '/evidence/adapter.stderr', stderr, { flag: 'wx', mode: 0o600 });
    await fs.writeFile(owner.phase.root + '/evidence/adapter-authenticated.ndjson', authenticated, { flag: 'wx', mode: 0o600 });
    await retain(owner.phase.root + '/evidence/adapter-events.json', state);
    return state;
  } finally { for (const handle of handles) await handle.close().catch(() => {}); }
}

function acceptEvent(state, kind, payload, plan, child, expectedPlanHash) {
  if (!Object.hasOwn(state, kind) || kind === 'planId' || state[kind] !== null || payload.planId !== plan.planId) fail('ADAPTER_EVENT_ORDER');
  const linux = plan.schema === 'tablo/server-observer-linux-plan/v1';
  const windows = !linux && process.platform === 'win32';
  if (linux) {
    const fields = {
      ready: ['planId', 'adapterPid', 'platform', 'planSha256', 'capabilityReady', 'policy', 'limitUnit', 'configuredLimit', 'helperSha256', 'sourceSha256', 'recipeSha256', 'buildSha256', 'clockDomain', 'monotonicNanoseconds'],
      started: ['planId', 'controllerPid', 'actualImagePath', 'actualImageSha256', 'preExecGate', 'pidfdRetained'],
      input: ['planId', 'mode', 'declaredBytes', 'writtenBytes', 'settled', 'stdinClosed', 'errorCode', 'sourceEofObserved'],
      exited: ['planId', 'controllerPid', 'rootExitObserved', 'exitCode', 'posixSignal', 'terminationRequested'],
      closed: ['planId', 'controllerPid', 'rootExitObserved', 'scopeObservedEmpty', 'inputSettled', 'stdinClosed', 'stdout', 'stderr', 'custodySettled', 'descendantStatuses', 'admission'],
      failed: ['planId', 'phase', 'code', 'controllerStarted', 'rootExitObserved', 'closureKnown', 'nestedPhpClosure'],
      notStarted: ['planId', 'controllerStarted', 'reason', 'closed']
    };
    if (!exactKeys(payload, fields[kind] ?? [])) fail('ADAPTER_EVENT_FIELDS');
    if (kind === 'ready' && (payload.platform !== 'LinuxX64' || payload.policy !== LINUX_POLICY
        || payload.limitUnit !== 'process/TGID' || payload.configuredLimit !== plan.job.activeProcessLimit
        || payload.helperSha256 !== plan.adapter.sha256 || payload.sourceSha256 !== plan.linux.source.sha256
        || payload.recipeSha256 !== plan.linux.recipe.sha256 || payload.buildSha256 !== plan.linux.build.sha256
        || payload.clockDomain !== 'CLOCK_MONOTONIC' || !/^[1-9][0-9]{0,19}$/.test(payload.monotonicNanoseconds)
        || BigInt(payload.monotonicNanoseconds) < BigInt(plan.linux.ownerMonotonicNanoseconds)
        || BigInt(payload.monotonicNanoseconds) > process.hrtime.bigint()
        || BigInt(payload.monotonicNanoseconds) >= BigInt(plan.linux.deadlineNanoseconds) - RESERVE)) fail('ADAPTER_LINUX_CAPABILITY');
    if (kind === 'started' && (payload.preExecGate !== true || payload.pidfdRetained !== true)) fail('ADAPTER_LINUX_START');
    if (kind === 'exited' && ((!integer(payload.exitCode, 0, 255) || payload.posixSignal !== null)
        && (payload.exitCode !== null || !integer(payload.posixSignal, 1, 64))
        || typeof payload.terminationRequested !== 'boolean')) fail('ADAPTER_NATIVE_STATUS');
    if (kind === 'input' && (!exactKeys(plan.launch.input, ['mode', 'path', 'sha256', 'bytes'])
        || payload.mode !== plan.launch.input.mode || payload.declaredBytes !== plan.launch.input.bytes
        || !integer(payload.writtenBytes, 0, payload.declaredBytes) || payload.settled !== true || payload.stdinClosed !== true
        || ![null, 'INPUT_WRITE_FAILED'].includes(payload.errorCode) || typeof payload.sourceEofObserved !== 'boolean'
        || payload.errorCode === null && (payload.writtenBytes !== payload.declaredBytes || payload.sourceEofObserved !== true))) fail('ADAPTER_INPUT');
    if (kind === 'closed') {
      const a = payload.admission;
      if (!state.input || !linuxAdmission(a, plan.job.activeProcessLimit)
          || payload.scopeObservedEmpty !== true || payload.descendantStatuses !== 'TERMINAL_WAIT_REGISTRY') fail('ADAPTER_LINUX_ACCOUNTING');
    }
  } else if (windows) {
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
        || (windows && (payload.platform !== 'Windows' || payload.atomicJobListSupported !== true
          || payload.jobLogicalId !== plan.job.logicalId))) fail('ADAPTER_READY');
  } else if (kind === 'started') {
    if (!state.ready || !integer(payload.controllerPid, 1, 0x7fffffff)
        || payload.actualImageSha256 !== plan.launch.executable.sha256
        || normalize(payload.actualImagePath).toLowerCase() !== normalize(plan.launch.executable.path).toLowerCase()
        || (windows && (payload.assignedAtCreation !== true || payload.nativeProcessHandleRetained !== true
          || payload.nativeThreadHandleClosed !== true || payload.jobLogicalId !== plan.job.logicalId
          || !/^[1-9][0-9]{0,19}$/.test(payload.controllerCreationFileTime)
          || !integer(payload.jobActiveProcesses, 0, plan.job.activeProcessLimit)))) fail('ADAPTER_STARTED');
  } else if (kind === 'exited' || kind === 'closed') {
    if (!state.started || payload.controllerPid !== state.started.controllerPid || payload.rootExitObserved !== true
        || (windows && payload.controllerCreationFileTime !== state.started.controllerCreationFileTime)) fail('ADAPTER_NATIVE_IDENTITY');
    if (kind === 'closed' && (!state.exited || payload.custodySettled !== true || payload.inputSettled !== true || payload.stdinClosed !== true)) fail('ADAPTER_CLOSURE');
    if (kind === 'closed' && windows) {
      const accounting = payload.jobAccounting;
      if (!exactKeys(accounting, ['configuredLimit', 'maximumAssociatedObserved', 'finalAssociated', 'totalProcesses', 'totalLimitTerminated'])
          || accounting.configuredLimit !== plan.job.activeProcessLimit || accounting.finalAssociated !== 0
          || !integer(accounting.maximumAssociatedObserved, state.started.jobActiveProcesses, 0xffffffff)
          || !integer(accounting.totalProcesses, accounting.maximumAssociatedObserved, 0xffffffff)
          || !integer(accounting.totalLimitTerminated, 0, accounting.totalProcesses)) fail('ADAPTER_JOB_ACCOUNTING');
    }
    if (kind === 'exited' && windows && (payload.waitResult !== 'signaled' || payload.posixSignal !== null
        || !integer(payload.windowsExitUnsigned, 0, 0xffffffff) || payload.windowsExitSigned !== (payload.windowsExitUnsigned | 0)
        || typeof payload.terminationRequested !== 'boolean' || typeof payload.terminationAccepted !== 'boolean')) fail('ADAPTER_NATIVE_STATUS');
  } else if (kind === 'input' && !state.started) fail('ADAPTER_INPUT_ORDER');
  state[kind] = payload;
}

async function runAdapter(owner, plan) {
  if (process.platform === 'linux') return runLinuxAdapter(owner, plan);
  if (process.platform !== 'win32') fail('UNQUALIFIED_PLATFORM');
  const windows = true; const file = owner.phase.root + '/private/adapter.json';
  await retain(file, plan); const planHash = await digest(file);
  const executable = plan.powershell.path;
  const argv = ['-NoProfile', '-NonInteractive', '-File', plan.adapter.path, '-PlanFile', file, '-PlanSha256', planHash];
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
  const input = owner.control ? owner.plan.launch.input : { mode: 'none', bytes: 0 };
  if (state.input.mode !== input.mode || state.input.declaredBytes !== input.bytes || state.input.writtenBytes !== input.bytes || state.input.settled !== true
      || state.input.stdinClosed !== true || state.input.errorCode !== null
      || !windows && state.input.sourceEofObserved !== true) fail('CONTROLLER_INPUT_UNSETTLED');
  if (windows ? state.exited.windowsExitUnsigned !== 0 || state.closed.jobActiveProcesses !== 0
      || state.closed.nativeProcessHandleClosed !== true || state.closed.nativeThreadHandleClosed !== true || state.closed.jobHandleClosed !== true
    : state.exited.exitCode !== 0 || state.exited.posixSignal !== null || state.closed.scopeObservedEmpty !== true
      || !linuxAdmission(state.closed.admission, owner.plan.job?.activeProcessLimit ?? phaseProcessLimit(owner.control, owner.phase.kind))
      || state.closed.admission.failed !== false
      || state.closed.admission.deniedAdmissions !== state.closed.admission.ioUringSetupAttempts
      || state.closed.admission.ioUringSetupAttempts !== state.closed.admission.ioUringSetupEpermWrites
      || !linuxClosedEvents(state, owner.adapterClose)) fail('CONTROLLER_NATIVE_FAILURE');
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

async function supervise(file, hash, control = false, failure = observerFailure()) {
  let owner;
  try {
  failure.stage = 'preflight';
  if (process.version !== 'v24.13.0') fail('NODE_VERSION');
  const leases = new Leases(START + 1800n * SECOND);
  const plan = await readPlan(file, hash);
  failure.stage = 'admission';
  executionAdmission(plan); // Before pins, directories, listeners or child/resource creation.
  failure.stage = 'declaration';
  const observer = control ? controlDeclaration(plan, file) : declaration(plan, file);
  if (control) leases.parentEnd = START + BigInt(plan.seconds) * SECOND;
  failure.stage = 'pins';
  await pinned(observer.node); await pinned(observer.nodeOwner); if (!control || plan.php) await pinned(plan.php);
  if (!control && (!Array.isArray(observer.bootstrapIniFiles) || observer.bootstrapIniFiles.length < 1
      || observer.bootstrapIniFiles.length > 128 || typeof observer.bootstrapIniScanDirectory !== 'string'
      || observer.bootstrapIni !== observer.bootstrapIniFiles[0].path)) fail('BOOTSTRAP_INI_DECLARATION');
  if (!control) for (const ini of observer.bootstrapIniFiles) await pinned(ini);
  if (normalize(observer.node.path).toLowerCase() !== normalize(process.execPath).toLowerCase()
      || normalize(observer.nodeOwner.path) !== normalize(__filename)) fail('OWNER_PIN');
  if (process.platform === 'win32') { await pinned(observer.powershell); await pinned(observer.windowsAdapter); }
  else if (process.platform === 'linux' && process.arch === 'x64') { failure.stage = 'linux-pins'; await linuxPins(observer, control,
    { nonce: plan.nonce, runtimes: { node: observer.node, php: plan.php, composer: plan.composer, shell: observer.linuxShell, docker: plan.docker },
      phpBootstrap: { iniFiles: observer.bootstrapIniFiles, extensionFiles: observer.bootstrapExtensionFiles } }); }
  else fail('UNQUALIFIED_PLATFORM');
  failure.stage = 'root-layout';
  const parent = path.dirname(observer.root); await regular(path.join(parent, path.basename(file)));
  await fs.mkdir(observer.root, { mode: 0o700 });
  for (const directory of ['private', 'evidence', 'phases']) await fs.mkdir(observer.root + '/' + directory, { mode: 0o700 });
  owner = { plan, observer, leases, control, cancelReason: null, phaseResults: [] };
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
  let failed = false;
  try {
    failure.stage = 'listener';
    await listen(owner);
    for (const declaration of observer.phases) {
      failure.stage = 'phase-admission'; failure.phase = declaration.kind;
      if (owner.cancelReason || owner.connections !== 0 || leases.active.size !== 0) fail('PRIOR_PHASE_UNSETTLED');
      owner.phase = { ...declaration, authority: randomBytes(32), adapterAuthority: randomBytes(32), sequence: 0,
        prepareDiagnosticOpen: declaration.kind === 'prepare' && !control };
      failure.stage = 'phase-layout';
      await fs.mkdir(owner.phase.root, { mode: 0o700 });
      for (const directory of ['private', 'evidence', 'cwd']) await fs.mkdir(owner.phase.root + '/' + directory, { mode: 0o700 });
      failure.stage = 'launch-description';
      const launch = await controllerLaunch(owner, file, hash);
      failure.stage = 'adapter-plan';
      const adapter = await adapterPlan(owner, launch);
      failure.stage = 'adapter-run';
      let state;
      try { state = await runAdapter(owner, adapter); }
      finally { owner.phase.prepareDiagnosticOpen = false; }
      failure.stage = 'custody';
      const verifiedCustody = await verifyCustody(owner, state);
      failure.stage = 'phase-proof';
      const custody = await verifyPhase(owner, verifiedCustody);
      failure.stage = 'phase-retention';
      const proof = owner.phase.root + '/evidence/phase-result.json';
      await retain(proof, { schema: 'tablo/server-observer-phase/v1', status: 'passed', cellNonce: plan.nonce, phaseNonce: owner.phase.nonce,
        kind: owner.phase.kind, custody, adapterClose: owner.adapterClose });
      owner.phaseResults.push({ kind: owner.phase.kind, path: proof, sha256: await digest(proof) });
    }
    verdict = { ...verdict, status: 'passed', phases: owner.phaseResults,
      nestedPhpClosure: control ? 'NOT_APPLICABLE_NODE_CONTROL' : 'PROVEN_BY_CELL_RECEIPT_AUDIT' };
  } catch (error) {
    failed = true; latchObserverFailure(failure, error, owner); throw error;
  } finally {
    // The parent deadline stays armed until final custody has been written.
    try {
      failure.stage = 'retention';
      owner.server?.close();
      await retain(observer.root + '/evidence/leases.json', { parentStartedNanoseconds: START.toString(), parentEndNanoseconds: leases.parentEnd.toString(),
        events: leases.events, active: [...leases.active.values()].map(value => ({ id: value.id, label: value.label,
          startedNanoseconds: value.started.toString(), endNanoseconds: value.end.toString() })) });
      await retain(observer.root + '/evidence/observer-result.json', { ...verdict, cancellation: owner.cancelReason,
        adapterClose: owner.adapterClose ?? null, elapsedNanoseconds: (process.hrtime.bigint() - START).toString(),
        bound: 'OBSERVED_QUALIFIED_HOST_PROCESS_SCOPE_NOT_UNIVERSAL_KERNEL_IO_GUARANTEE' });
    } catch (error) {
      if (!failed) { failed = true; latchObserverFailure(failure, error, owner); throw error; }
    } finally {
      try { clearInterval(timer); process.removeListener('SIGINT', lost); process.removeListener('SIGTERM', lost); }
      catch (error) { if (!failed) { latchObserverFailure(failure, error, owner); throw error; } }
    }
  }
  failure.stage = 'settlement';
  if (verdict.status !== 'passed' || leases.remaining() <= 0n) fail('CELL_FAILED');
  } catch (error) { latchObserverFailure(failure, error, owner); throw error; }
}

// Executable neutral tests live in this same approved owner. They do not run on import.
// Native owner-loss/input/deadline controls are separate finite runtime sublanes.
function registerTests() {
  const test = require('node:test'); const assert = require('node:assert/strict');
  test('Linux qualification: foreign cell, runtime pins and evidence paths cannot qualify', () => {
    const nonce = 'a'.repeat(32), hash = 'b'.repeat(64), root = '/scope/tablo-linux-' + nonce;
    const runtimes = Object.fromEntries(['node','php','composer','shell','docker'].map(name => [name, { path: '/tools/' + name, sha256: hash }]));
    const selected = { nonce, runtimes }, q = { nonce, runtimes, runtimeControls: { path: root + '/runtime-controls.json' },
      platformReceipt: { path: root + '/platform.json' }, neutralControls: { path: root + '/neutral-controls.json' },
      fallbackControls: { path: root + '/fallback-controls.json' } };
    assert.doesNotThrow(() => qualificationSelection(q, selected, root + '/qualification.json'));
    assert.throws(() => qualificationSelection({ ...q, nonce: 'c'.repeat(32) }, selected, root + '/qualification.json'), /FOREIGN/);
    assert.throws(() => qualificationSelection({ ...q, runtimeControls: { path: '/scope/other/runtime-controls.json' } }, selected, root + '/qualification.json'), /SCOPE/);
    assert.throws(() => qualificationSelection({ ...q, runtimes: { ...runtimes, php: { ...runtimes.php, sha256: 'c'.repeat(64) } } }, selected, root + '/qualification.json'), /FOREIGN_RUNTIME/);
    assert.throws(() => qualificationSelection({ ...q, runtimes: { ...runtimes, docker: { ...runtimes.docker, path: '/foreign/docker' } } }, selected, root + '/qualification.json'), /FOREIGN_RUNTIME/);
  });
  test('server observer: only explicit qualified execution crosses the side-effect boundary', () => {
    assert.doesNotThrow(() => executionAdmission({ executionMode: 'qualified' }));
    for (const executionMode of [undefined, null, 'diagnostic-only', 'QUALIFIED', '', false, 'unknown']) {
      assert.throws(() => executionAdmission({ executionMode }), /EXECUTION_MODE_UNQUALIFIED/);
    }
  });
  test('Linux admission: finite records preserve decimal clocks and reject malformed native input', () => {
    const r = new LinuxRecords(), seen = [];
    const line = Buffer.from('READY 7 ' + LINUX_POLICY + ' 9007199254740993123\n');
    r.push(line.subarray(0, 9), (...v) => seen.push(v)); assert.equal(seen.length, 0);
    r.push(line.subarray(9), (...v) => seen.push(v)); assert.equal(seen[0][1][2], '9007199254740993123');
    for (const bad of ['READY 7 wrong 123\n', 'INPUT 0 0 0 1 extra\n', 'EXITED 2 0.1 0 0\n', 'CLOSED 0 0\n',
      'INPUT 0 0 0 1\r\n', 'STARTED 00\n', 'STARTED 9007199254740993\n']) {
      assert.throws(() => new LinuxRecords().push(Buffer.from(bad), () => {}), /LINUX_RECORD/);
    }
    assert.throws(() => new LinuxRecords().push(Buffer.alloc(8193), () => {}), /LIMIT/);
    assert.throws(() => new LinuxRecords().push(Buffer.from('STARTED 2\n'.repeat(9)), () => {}), /ENCODING/);
  });
  test('Linux admission: exact event fields reconcile process, thread and native terminal identities', () => {
    const hash = 'b'.repeat(64), planId = 'synthetic/linux-controller/1';
    const plan = { schema: 'tablo/server-observer-linux-plan/v1', planId,
      adapter: { sha256: hash }, linux: { source: { sha256: hash }, recipe: { sha256: hash }, build: { sha256: hash },
        ownerMonotonicNanoseconds: '1', deadlineNanoseconds: (process.hrtime.bigint() + 8n * SECOND).toString() },
      job: { activeProcessLimit: 8 }, launch: { executable: { path: '/synthetic/controller', sha256: hash },
        input: { mode: 'none', bytes: 0, path: null, sha256: null } } };
    const ready = { planId, adapterPid: 4, platform: 'LinuxX64', planSha256: hash, capabilityReady: true,
      policy: LINUX_POLICY, limitUnit: 'process/TGID', configuredLimit: 8, helperSha256: hash, sourceSha256: hash,
      recipeSha256: hash, buildSha256: hash, clockDomain: 'CLOCK_MONOTONIC', monotonicNanoseconds: process.hrtime.bigint().toString() };
    const state = adapterState(plan);
    acceptEvent(state, 'ready', ready, plan, { pid: 4 }, hash);
    assert.throws(() => acceptEvent(adapterState(plan), 'ready', { ...ready, limitUnit: 'threads' }, plan, { pid: 4 }, hash), /CAPABILITY/);
    assert.throws(() => acceptEvent(adapterState(plan), 'ready', { ...ready, extra: 1 }, plan, { pid: 4 }, hash), /FIELDS/);
    acceptEvent(state, 'started', { planId, controllerPid: 5, actualImagePath: '/synthetic/controller', actualImageSha256: hash,
      preExecGate: true, pidfdRetained: true }, plan, {}, hash);
    acceptEvent(state, 'input', { planId, mode: 'none', declaredBytes: 0, writtenBytes: 0, settled: true, stdinClosed: true, errorCode: null, sourceEofObserved: true }, plan, {}, hash);
    acceptEvent(state, 'exited', { planId, controllerPid: 5, rootExitObserved: true, exitCode: null, posixSignal: 10,
      terminationRequested: false }, plan, {}, hash);
    const admission = { configuredLimit: 8, liveTgids: 0, pendingReservations: 0, peakCommitted: 8, processBirths: 8,
      processReservations: 8, failedCreations: 1, deniedAdmissions: 0, clone3Fallbacks: 4, threadBirths: 4,
      terminalWaits: 11, execRetiredIdentities: 1, waitEchild: true, failed: false, ioUringSetupAttempts: 0, ioUringSetupEpermWrites: 0 };
    const closed = { planId, controllerPid: 5, rootExitObserved: true, scopeObservedEmpty: true, inputSettled: true,
      stdinClosed: true, stdout: {}, stderr: {}, custodySettled: true, descendantStatuses: 'TERMINAL_WAIT_REGISTRY', admission };
    for (const change of [{ pendingReservations: 1 }, { peakCommitted: 9 }, { terminalWaits: 12 }, { waitEchild: false },
      { configuredLimit: 9 }, { unknown: 0 }]) {
      assert.throws(() => acceptEvent({ ...state }, 'closed', { ...closed, admission: { ...admission, ...change } }, plan, {}, hash), /ACCOUNTING/);
    }
    assert.doesNotThrow(() => acceptEvent(state, 'closed', closed, plan, {}, hash));
    assert.throws(() => acceptEvent(state, 'closed', closed, plan, {}, hash), /ORDER/);
    assert.throws(() => acceptEvent({ ...state, exited: null }, 'exited', { ...state.exited, posixSignal: null }, plan, {}, hash), /STATUS/);
  });
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
  const failure = observerFailure();
  try {
  if (arguments_.length === 1 && arguments_[0] === '--self-test') { registerTests(); return; }
  if (arguments_.length !== 2 || !arguments_[1].startsWith('--sha256=')) fail('EXACT_ARGUMENTS');
  const expected = arguments_[1].slice(9);
  if (arguments_[0].startsWith('--execute=')) await supervise(arguments_[0].slice(10), expected, false, failure);
  else if (arguments_[0].startsWith('--control-plan=')) await supervise(arguments_[0].slice(15), expected, true, failure);
  else fail('EXACT_MODE');
  } catch (error) { latchObserverFailure(failure, error); emitObserverFailure(failure); throw error; }
}

module.exports = { Frames, Leases, LinuxRecords, DENIAL_FIELDS, linuxDenialDiagnostic, declaration, environment, acceptEvent, runLinuxAdapter, linuxPins, linuxContext, readPlan, regular, pinned, retain, verifyCustody };
if (require.main === module) main(process.argv.slice(2)).catch(() => {
  process.exitCode = 1;
  try { process.stderr.write('SERVER_OBSERVER_FAILED\n'); } catch { /* Original failure remains exit1. */ }
});
