'use strict';

// Never-merge diagnostic: one fixed private input, one finite public projection.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');
const os = require('node:os');
const PHASES = Object.freeze(['setup', 'export', 'serverImport', 'writerOpen', 'verify', 'readerOpen', 'reverseExport', 'sqliteImport', 'finalVerify']);
const IDENTITY = 'Tablo\\Tests\\Server\\EngineTransferTest::completeNativeRoundTripOverEightMiBUsesAtMostTwoMiBOfPhpWorkingData';
const MAX_BYTES = 8192;
const PLAN_BYTES = 4 * 1024 * 1024; // Existing frozen plans include the complete locked vendor file inventory.
const MAX_NS = 30_000_000_000;
function refuse() { throw new Error('Finite cold profile refused.'); }
function object(value) { return value !== null && typeof value === 'object' && !Array.isArray(value); }
function keys(value, names) {
  if (!object(value) || Object.keys(value).length !== names.length || Object.keys(value).sort().join('|') !== [...names].sort().join('|')) refuse();
}
function ns(value) { return Number.isSafeInteger(value) && value >= 0 && value <= MAX_NS; }
function chain(file, directory = false) {
  const absolute = path.resolve(file);
  let cursor = path.parse(absolute).root;
  const parts = absolute.slice(cursor.length).split(path.sep).filter(Boolean);
  for (let index = 0; index < parts.length; index++) {
    cursor = path.join(cursor, parts[index]);
    const stat = fs.lstatSync(cursor);
    if (stat.isSymbolicLink() || (index < parts.length - 1 || directory ? !stat.isDirectory() : !stat.isFile())) refuse();
  }
  return absolute;
}
function bytes(file, maximum) {
  const absolute = chain(file);
  const before = fs.lstatSync(absolute);
  if (before.size < 1 || before.size > maximum) refuse();
  const fd = fs.openSync(absolute, fs.constants.O_RDONLY | (fs.constants.O_NOFOLLOW || 0));
  try {
    const stat = fs.fstatSync(fd);
    if (!stat.isFile() || stat.ino !== before.ino || stat.dev !== before.dev || stat.size !== before.size) refuse();
    const data = Buffer.alloc(maximum + 1);
    let count = 0;
    while (count < data.length) {
      const read = fs.readSync(fd, data, count, data.length - count, null);
      if (read === 0) break;
      count += read;
    }
    if (count !== stat.size || count > maximum) refuse();
    return data.subarray(0, count);
  } finally { fs.closeSync(fd); }
}
function json(file, maximum) { return JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(bytes(file, maximum))); }
function project(record, nonce) {
  keys(record, ['schema', 'nonce', 'case', 'status', 'rootCause', 'currentPhase', 'lastCompletedPhase', 'elapsedNs', 'phases', 'profileOverheadIncluded', 'originalActorAccepted', 'issueAccepted']);
  if (record.schema !== 'tablo/cold-transfer-phase/v1' || record.nonce !== nonce || record.case !== 'transfer-working-data'
      || !['partial', 'complete'].includes(record.status) || record.rootCause !== 'UNKNOWN'
      || record.profileOverheadIncluded !== true || record.originalActorAccepted !== false || record.issueAccepted !== false || !ns(record.elapsedNs)) refuse();
  keys(record.phases, PHASES);
  let completed = null;
  let current = null;
  let previous = 0;
  let stopped = false;
  const phases = {};
  for (const name of PHASES) {
    const stage = record.phases[name];
    keys(stage, ['startedNs', 'completedNs']);
    const start = stage.startedNs;
    const end = stage.completedNs;
    if (start === null) {
      if (end !== null) refuse();
      stopped = true;
    } else {
      if (stopped || !ns(start) || start !== previous || start > record.elapsedNs || (name === 'setup' && start !== 0)) refuse();
      if (end === null) { current = name; stopped = true; }
      else {
        if (!ns(end) || end < start || end > record.elapsedNs) refuse();
        completed = name; previous = end;
      }
    }
    phases[name] = { startedNs: start, completedNs: end };
  }
  if (record.phases.setup.startedNs !== 0 || record.currentPhase !== current || record.lastCompletedPhase !== completed
      || (record.status === 'partial' && current === null)
      || (record.status === 'complete' && (completed !== 'finalVerify' || current !== null))) refuse();
  return { status: record.status, currentPhase: current, lastCompletedPhase: completed, elapsedNs: record.elapsedNs, phases };
}
function unknown(observation) {
  return { schema: 'tablo/safe-cold-transfer-phase/v1', observation, status: 'UNKNOWN', rootCause: 'UNKNOWN',
    currentPhase: 'UNKNOWN', lastCompletedPhase: 'UNKNOWN', elapsedNs: null, phases: null,
    profileOverheadIncluded: true, originalActorAccepted: false, issueAccepted: false,
    timingSemantics: 'Last durable boundary; includes diagnostic overhead; not kill time or original actor acceptance.' };
}
function collect(cell, source) {
  const safe = unknown('NOTRUN_OR_UNAVAILABLE');
  try {
    cell = chain(cell, true); source = chain(source, true);
    const plan = json(path.join(cell, 'cell.json'), PLAN_BYTES);
    if (plan.schema !== 'tablo/server-cell-start/v1' || !/^[0-9a-f]{32}$/.test(plan.nonce) || plan.selectedEngine !== 'mysql'
        || plan.cross !== false || plan.executionMode !== 'qualified' || plan.sourceInput !== source.replaceAll('\\', '/')) refuse();
    const runtime = path.join(cell, 'tablo-server-' + plan.nonce);
    if (plan.runtime !== runtime.replaceAll('\\', '/')) refuse();
    chain(runtime, true);
    const started = json(path.join(runtime, 'evidence', 'started-cell.json'), PLAN_BYTES);
    if (started.schema !== 'tablo/server-cell/v1' || started.nonce !== plan.nonce || started.runtime !== plan.runtime
        || started.selectedEngine !== 'mysql' || started.sourceState !== 'STARTED_PENDING_NATIVE_VERIFICATION') refuse();
    const map = json(path.join(source, 'tools', 'server-native-tests.json'), 1048576);
    if (map.schema !== 'tablo/server-native-tests/v1' || !Array.isArray(map.cases?.main) || map.cases.main.length > 512) refuse();
    const matches = map.cases.main.map((entry, index) => ({ entry, index })).filter(item => item.entry.identity === IDENTITY);
    if (matches.length !== 1) refuse();
    const { entry, index } = matches[0];
    if (entry.path !== 'tests/Server/EngineTransferTest.php' || entry.deadlineSeconds !== 8 || entry.minimumAssertions !== 8
        || JSON.stringify(entry.dataRows) !== '[]' || JSON.stringify(entry.fixtures) !== '[{"alias":"transfer-working-data","engine":"selected","initial":"ready"}]') refuse();
    const actor = path.join(runtime, 'cases', 'main-' + index, 'mysql', 'tablo-server-' + plan.nonce);
    const manifest = json(path.join(actor, 'private', 'fixture-manifest.json'), 16384);
    keys(manifest, ['format', 'nonce', 'engine', 'cases', 'environment']);
    keys(manifest.cases, ['transfer-working-data']);
    if (manifest.format !== 1 || manifest.nonce !== plan.nonce || manifest.engine !== 'mysql' || !object(manifest.environment)
        || !new RegExp('^ti39_' + plan.nonce.slice(0, 16) + '_m_[0-9]{3}$').test(manifest.cases['transfer-working-data'])) refuse();
    const raw = bytes(path.join(actor, 'cases', 'transfer-working-data', 'cold-profile.json'), MAX_BYTES);
    const record = JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(raw));
    return { ...safe, observation: 'observed', nonce: plan.nonce, ...project(record, plan.nonce), profileSha256: crypto.createHash('sha256').update(raw).digest('hex') };
  } catch (error) {
    if (error?.code !== 'ENOENT') safe.observation = 'REFUSED';
    return safe;
  }
}
function selfTest() {
  const nonce = 'a'.repeat(32);
  function fixture() {
    return { schema: 'tablo/cold-transfer-phase/v1', nonce, case: 'transfer-working-data', status: 'partial', rootCause: 'UNKNOWN',
      currentPhase: 'setup', lastCompletedPhase: null, elapsedNs: 10,
      phases: Object.fromEntries(PHASES.map(name => [name, { startedNs: name === 'setup' ? 0 : null, completedNs: null }])),
      profileOverheadIncluded: true, originalActorAccepted: false, issueAccepted: false };
  }
  let count = 0;
  const accepted = fixture();
  assert.equal(project(accepted, nonce).currentPhase, 'setup'); count++;
  for (const change of [
    r => { r.secret = 'synthetic-secret'; }, r => { r.phases.setup.secret = 'synthetic-secret'; },
    r => { r['case|currentPhase'] = r.case; delete r.case; delete r.currentPhase; },
    r => { r.nonce = 'b'.repeat(32); }, r => { r.status = 'passed'; }, r => { r.rootCause = 'mysql'; },
    r => { r.currentPhase = 'raw/path'; }, r => { r.lastCompletedPhase = 'setup'; },
    r => { r.elapsedNs = -1; }, r => { r.elapsedNs = MAX_NS + 1; }, r => { r.elapsedNs = Infinity; }, r => { r.elapsedNs = 0.5; },
    r => { r.phases.setup.completedNs = 5; }, r => { r.phases.export.startedNs = 5; },
    r => { r.phases.setup.startedNs = null; }, r => { r.phases.setup.startedNs = 1; },
    r => { r.phases.export.completedNs = 4; }, r => { delete r.phases.verify; },
    r => { r.issueAccepted = true; }, r => { r.originalActorAccepted = true; }, r => { r.profileOverheadIncluded = false; },
    r => { r.status = 'complete'; }
  ]) {
    const value = fixture(); change(value); assert.throws(() => project(value, nonce)); count++;
  }
  const completed = fixture();
  let clock = 0;
  for (const name of PHASES) { completed.phases[name] = { startedNs: clock, completedNs: ++clock }; }
  completed.elapsedNs = clock; completed.currentPhase = null; completed.lastCompletedPhase = 'finalVerify'; completed.status = 'complete';
  assert.equal(project(completed, nonce).status, 'complete'); count++;
  completed.status = 'partial'; assert.throws(() => project(completed, nonce)); count++;
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'tablo-cold-profile-controls-'));
  const cell = path.join(root, 'cell'); const source = path.join(root, 'source');
  const runtime = path.join(cell, 'tablo-server-' + nonce);
  const actor = path.join(runtime, 'cases', 'main-0', 'mysql', 'tablo-server-' + nonce);
  const profile = path.join(actor, 'cases', 'transfer-working-data', 'cold-profile.json');
  function write(file, value) { fs.mkdirSync(path.dirname(file), { recursive: true }); fs.writeFileSync(file, JSON.stringify(value)); }
  const plan = { schema: 'tablo/server-cell-start/v1', nonce, selectedEngine: 'mysql', cross: false, executionMode: 'qualified', sourceInput: source.replaceAll('\\', '/'), runtime: runtime.replaceAll('\\', '/') };
  try {
    write(path.join(cell, 'cell.json'), plan);
    write(path.join(runtime, 'evidence', 'started-cell.json'), { ...plan, schema: 'tablo/server-cell/v1', sourceState: 'STARTED_PENDING_NATIVE_VERIFICATION' });
    write(path.join(source, 'tools', 'server-native-tests.json'), { schema: 'tablo/server-native-tests/v1', cases: { main: [{ identity: IDENTITY, path: 'tests/Server/EngineTransferTest.php', deadlineSeconds: 8, minimumAssertions: 8, dataRows: [], fixtures: [{ alias: 'transfer-working-data', engine: 'selected', initial: 'ready' }] }] } });
    write(path.join(actor, 'private', 'fixture-manifest.json'), { format: 1, nonce, engine: 'mysql', cases: { 'transfer-working-data': 'ti39_' + nonce.slice(0, 16) + '_m_001' }, environment: { password: 'synthetic-secret' } });
    write(profile, accepted);
    assert.equal(collect(cell, source).observation, 'observed'); count++;
    assert.equal(JSON.stringify(collect(cell, source)).includes('synthetic-secret'), false); count++;
    const cli = require('node:child_process').spawnSync(process.execPath, [__filename, '--cell=' + cell, '--source=' + source], { maxBuffer: 4096 });
    assert.equal(cli.status, 0); count++;
    assert.deepEqual(fs.readdirSync(cell).filter(name => name.startsWith('safe-')), ['safe-cold-profile.json']); count++;
    const publicBytes = fs.readFileSync(path.join(cell, 'safe-cold-profile.json'));
    assert.equal(publicBytes.includes('synthetic-secret'), false); count++;
    assert(publicBytes.length <= MAX_BYTES); count++;
    fs.writeFileSync(profile, 'x'.repeat(MAX_BYTES + 1)); assert.equal(collect(cell, source).observation, 'REFUSED'); count++;
    fs.writeFileSync(profile, '{'); assert.equal(collect(cell, source).observation, 'REFUSED'); count++;
    fs.unlinkSync(profile); assert.equal(collect(cell, source).status, 'UNKNOWN'); count++;
    fs.symlinkSync(path.join(cell, 'cell.json'), profile, 'file'); assert.equal(collect(cell, source).observation, 'REFUSED'); count++;
    fs.unlinkSync(profile); write(profile, accepted);
    write(path.join(cell, 'cell.json'), { ...plan, runtime: runtime + '/..' }); assert.equal(collect(cell, source).observation, 'REFUSED'); count++;
    write(path.join(cell, 'cell.json'), plan);
    write(path.join(runtime, 'evidence', 'started-cell.json'), { ...plan, schema: 'tablo/server-cell/v1', nonce: 'b'.repeat(32), sourceState: 'STARTED_PENDING_NATIVE_VERIFICATION' });
    assert.equal(collect(cell, source).observation, 'REFUSED'); count++;
    write(path.join(runtime, 'evidence', 'started-cell.json'), { ...plan, schema: 'tablo/server-cell/v1', sourceState: 'STARTED_PENDING_NATIVE_VERIFICATION' });
    write(path.join(actor, 'private', 'fixture-manifest.json'), { format: 1, nonce: 'b'.repeat(32), engine: 'mysql', cases: { 'transfer-working-data': 'ti39_' + nonce.slice(0, 16) + '_m_001' }, environment: {} });
    assert.equal(collect(cell, source).observation, 'REFUSED'); count++;
  } finally {
    if (!path.resolve(root).startsWith(path.resolve(os.tmpdir()) + path.sep) || !/^tablo-cold-profile-controls-[A-Za-z0-9]+$/.test(path.basename(root))) refuse();
    fs.rmSync(root, { recursive: true, force: true });
  }
  process.stdout.write(JSON.stringify({ schema: 'tablo/cold-profile-controls/v1', status: 'passed', controls: count, originalActorAccepted: false, issueAccepted: false }) + '\n');
}
if (require.main === module) {
  if (process.argv.length === 3 && process.argv[2] === '--self-test') selfTest();
  else {
    if (process.argv.length !== 4 || !process.argv[2].startsWith('--cell=') || !process.argv[3].startsWith('--source=')) refuse();
    const cell = path.resolve(process.argv[2].slice(7));
    const result = collect(cell, process.argv[3].slice(9));
    const output = path.join(chain(cell, true), 'safe-cold-profile.json');
    fs.writeFileSync(output, JSON.stringify(result) + '\n', { flag: 'wx', mode: 0o600 });
    process.stdout.write('Finite cold profile projection retained.\n');
  }
}
module.exports = { project, collect };
