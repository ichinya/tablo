'use strict';
// Synthetic bytes through the production parser/projector; no native execution.
const test = require('node:test'), assert = require('node:assert/strict');
const { LinuxRecords, DENIAL_FIELDS } = require('./server-supervisor.cjs');
const { projectFailure } = require('./server-qualification-diagnostic.cjs');
const ready = 'READY 7 tgid-seccomp-ptrace-x64-v1 123\nSTARTED 8\n';
const input = 'INPUT 0 0 0 1\n', exited = 'EXITED 8 0 0 0\n';
const before = ready + input + exited;
const record = values => 'DENIALS ' + values.join(' ') + '\n';
const closed = (denied, failed = denied > 0 ? 1 : 0) => `CLOSED 0 0 2 2 1 0 ${denied} 1 7 9 0 1 ${failed}\n`;
function parse(bytes, fragmented = false) {
  const parser = new LinuxRecords(), seen = [];
  if (fragmented) for (const byte of Buffer.from(bytes)) parser.push(Buffer.from([byte]), (kind, values) => seen.push({ kind, values }));
  else parser.push(Buffer.from(bytes), (kind, values) => seen.push({ kind, values }));
  return { parser, seen };
}
function failure(parser, denied = 5) {
  return projectFailure({ stage: 'CLIENT_RUNTIME', subject: 'node', index: 0, check: 'Q101', completed: 30,
    owner: { nativeDenialDiagnostic: parser.denialDiagnostic, cancelReason: null,
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
