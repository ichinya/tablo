'use strict';

// Explicit hosted input preparation only; this never opens a database or starts a service.
const fs = require('node:fs/promises');
const path = require('node:path');
const { createHash } = require('node:crypto');
const { spawnSync } = require('node:child_process');
const api = require('./server-supervisor.cjs');
const sha = bytes => createHash('sha256').update(bytes).digest('hex');
const fail = () => { throw new Error('LINUX_PREPARATION_REFUSED'); };
const literal = value => typeof value === 'string' && /^\/[A-Za-z0-9_./+-]+$/.test(value)
  && path.posix.normalize(value) === value && value !== '/' && !value.includes('//');

// Deliberately a finite extension-directive reader, not a general INI parser.
// Repeated directives retain their order; duplicate resolved modules are refused later.
function extensionNames(bytes) {
  if (bytes.length > 1048576 || bytes.includes(0)) fail();
  const result = [];
  for (const line of bytes.toString('utf8').split(/\r?\n/)) {
    if (!/^\s*extension\s*=/i.test(line)) continue;
    const match = line.match(/^\s*extension\s*=\s*(?:"([A-Za-z0-9_./+-]+)"|([A-Za-z0-9_./+-]+))\s*(?:;[^\r\n]*)?$/i);
    if (!match) fail();
    const name = match[1] ?? match[2];
    if (!name.startsWith('/') && !/^[A-Za-z0-9_+-]+\.[A-Za-z0-9_.+-]+$/.test(name)) fail();
    result.push(name);
    if (result.length > 128) fail();
  }
  return result;
}

function iniNames(info) {
  if (!info || Object.keys(info).join(',') !== 'binary,loaded,scanned,extensionDirectory'
      || !literal(info.binary) || !literal(info.loaded) || !literal(info.extensionDirectory)
      || !(info.scanned === false || info.scanned === null || typeof info.scanned === 'string')) fail();
  const extra = typeof info.scanned === 'string' && info.scanned.trim() !== '' ? info.scanned.split(',').map(v => v.trim()) : [];
  const result = [info.loaded, ...extra];
  if (result.length > 128 || new Set(result).size !== result.length || !result.every(literal)) fail();
  return result;
}

async function pin(file) {
  if (!literal(file)) fail();
  await api.regular(file);
  return { path: file, sha256: sha(await fs.readFile(file)) };
}
async function selected(name) {
  const file = process.env[name];
  if (typeof file !== 'string') fail();
  return pin(file);
}
async function write(file, value) {
  await fs.writeFile(file, JSON.stringify(value, null, 2) + '\n', { flag: 'wx', mode: 0o600 });
  return pin(file);
}

async function phpInfo(php, cell, label, ini = null, scan = null) {
  const code = "echo json_encode(['binary'=>PHP_BINARY,'loaded'=>php_ini_loaded_file(),'scanned'=>php_ini_scanned_files(),'extensionDirectory'=>ini_get('extension_dir')],JSON_THROW_ON_ERROR);";
  const env = { PATH: '/usr/local/bin:/usr/bin:/bin',
    ...(scan !== null ? { PHP_INI_SCAN_DIR: scan } : process.env.PHP_INI_SCAN_DIR !== undefined ? { PHP_INI_SCAN_DIR: process.env.PHP_INI_SCAN_DIR } : {}) };
  const result = spawnSync(php.path, [...(ini === null ? [] : ['-c', ini]), '-d', 'auto_prepend_file=', '-d', 'auto_append_file=', '-d', 'opcache.preload=', '-r', code], {
    cwd: cell, env, shell: false, timeout: 8000, killSignal: 'SIGKILL', maxBuffer: 131072, encoding: null, stdio: ['ignore', 'pipe', 'pipe']
  });
  await write(cell + '/' + label + '.json', { pid: result.pid ?? null, nativeExit: result.status, signal: result.signal,
    error: result.error ? result.error.code ?? 'UNKNOWN' : null, stdoutSha256: sha(result.stdout ?? Buffer.alloc(0)),
    stderrSha256: sha(result.stderr ?? Buffer.alloc(0)), closure: !result.error && result.status !== null ? 'NATIVE_RETURN' : 'UNKNOWN' });
  if (result.error || result.status !== 0 || result.signal !== null || result.stderr.length !== 0) fail();
  const info = JSON.parse(result.stdout.toString('utf8'));
  if (info.binary !== php.path) fail();
  iniNames(info);
  return info;
}

async function prepare(args) {
  if (process.platform !== 'linux' || process.arch !== 'x64' || process.version !== 'v24.13.0'
      || args.length !== 2 || !args[0].startsWith('--cell=') || !args[1].startsWith('--source=')) fail();
  const cell = args[0].slice(7), source = args[1].slice(9), nonce = process.env.TABLO_SERVER_CELL_NONCE;
  if (!literal(cell) || !literal(source) || !/^[0-9a-f]{32}$/.test(nonce ?? '')) fail();
  for (const dir of [cell, source]) {
    if (await fs.realpath(dir) !== dir || !(await fs.lstat(dir)).isDirectory()) fail();
  }
  const runtimes = { node: await pin(process.execPath), php: await selected('TABLO_SERVER_PREPARED_PHP'),
    composer: await selected('TABLO_TEST_COMPOSER_PHAR'), shell: await selected('TABLO_SERVER_LINUX_SHELL'),
    docker: await selected('TABLO_TEST_DOCKER_BIN') };
  // Acquire only the actual reported INIs into this fresh owned bootstrap, preserving bytes/order.
  // No application autoload, include or PHP package execution occurs in either query.
  const original = await phpInfo(runtimes.php, cell, 'php-original-input-query');
  const bootstrap = cell + '/php-bootstrap', scan = bootstrap + '/scan';
  await fs.mkdir(bootstrap, { mode: 0o700 }); await fs.mkdir(scan, { mode: 0o700 });
  const iniFiles = [], originals = [], extensionFiles = [], seen = new Set();
  for (const [index, reported] of iniNames(original).entries()) {
    const canonical = await fs.realpath(reported), frozen = await pin(canonical), bytes = await fs.readFile(canonical);
    if (sha(bytes) !== frozen.sha256) fail();
    const file = index === 0 ? bootstrap + '/php.ini' : scan + '/' + String(index).padStart(3, '0') + '.ini';
    await fs.writeFile(file, bytes, { flag: 'wx', mode: 0o600 });
    const owned = await pin(file); if (owned.sha256 !== frozen.sha256) fail();
    originals.push({ reported, canonical: frozen, owned }); iniFiles.push(owned);
    for (const name of extensionNames(bytes)) {
      const module = name.startsWith('/') ? name : original.extensionDirectory + '/' + name;
      if (seen.has(module) || extensionFiles.length >= 128) fail();
      seen.add(module); extensionFiles.push(await pin(module));
    }
  }
  const info = await phpInfo(runtimes.php, cell, 'php-owned-input-query', iniFiles[0].path, scan);
  if (JSON.stringify(iniNames(info)) !== JSON.stringify(iniFiles.map(v => v.path)) || info.extensionDirectory !== original.extensionDirectory) fail();
  const modules = await write(cell + '/php-bootstrap-modules.json', { schema: 'tablo/php-bootstrap-modules/v1', iniFiles, extensionFiles });
  const observer = { linuxAdapter: await selected('TABLO_SERVER_LINUX_ADAPTER'),
    linuxAdapterSource: await pin(source + '/tools/server-supervisor-linux.c'),
    linuxAdapterRecipe: await pin(source + '/tools/server-supervisor-linux-build.sh'),
    linuxAdapterBuild: await selected('TABLO_SERVER_LINUX_BUILD') };
  await api.linuxPins(observer, true); // Build provenance only; this cannot qualify a runtime.
  const platformReceipt = await write(cell + '/prepared-platform.json', { schema: 'tablo/linux-prepared-platform/v1', nonce,
    kind: 'host', context: await api.linuxContext(), runtimes, phpBootstrap: { iniFiles, extensionFiles }, originalIniFiles: originals, modules });
  const admissionFile = cell + '/platform-admission.json';
  await write(admissionFile, { schema: 'tablo/linux-platform-admission/v1', nonce, kind: 'host' });
  const qualificationPlan = await write(cell + '/qualification-plan.json', { schema: 'tablo/linux-admission-qualification-plan/v1',
    mode: 'qualify', nonce, root: cell + '/tablo-linux-' + nonce, observer,
    owner: await pin(source + '/tools/server-supervisor.cjs'), qualifier: await pin(source + '/tools/server-supervisor-linux-qualify.cjs'),
    controls: await pin(cell + '/compiler-out/server-supervisor-linux-controls'),
    controlsSource: await pin(source + '/tools/server-supervisor-linux-controls.c'), platformReceipt, admissionFile,
    runtimes, phpBootstrap: { iniFiles, extensionFiles }, outputQualification: cell + '/tablo-linux-' + nonce + '/qualification.json' });
  await write(cell + '/prepared-inputs.json', { schema: 'tablo/linux-hosted-inputs/v1', nonce, modules, qualificationPlan,
    qualification: { path: cell + '/tablo-linux-' + nonce + '/qualification.json' }, runtimes, observer,
    bootstrapIni: iniFiles[0].path, bootstrapIniScanDirectory: scan });
}

function registerTests() {
  const test = require('node:test'), assert = require('node:assert/strict');
  test('literal extension directives retain their declared order without suffix guessing', () => {
    assert.deepEqual(extensionNames(Buffer.from(';extension=ignored.so\nzend_extension=ignored.so\nextension=a.so\nextension="/prepared/b.so" ; finite\nextension=a.so\n')), ['a.so', '/prepared/b.so', 'a.so']);
    for (const value of ['extension=phar', 'extension=${MODULE}', 'extension="a.so" trailing', 'extension=../a.so', 'extension=a.so # ambiguous']) assert.throws(() => extensionNames(Buffer.from(value)));
  });
  test('prepared INI selection preserves loaded and scanned order and refuses malformed paths', () => {
    const info = { binary: '/prepared/php', loaded: '/prepared/php.ini', scanned: '/prepared/10.ini,\n/prepared/20.ini', extensionDirectory: '/prepared/modules' };
    assert.deepEqual(iniNames(info), ['/prepared/php.ini', '/prepared/10.ini', '/prepared/20.ini']);
    assert.deepEqual(iniNames({ ...info, scanned: false }), ['/prepared/php.ini']);
    for (const scanned of ['/prepared/../other.ini', '/prepared/10.ini,/prepared/10.ini', '/prepared/10.ini,', {}]) assert.throws(() => iniNames({ ...info, scanned }));
    assert.throws(() => iniNames({ ...info, extra: true }));
  });
}

module.exports = { extensionNames, iniNames };
if (require.main === module) {
  if (process.argv.length === 3 && process.argv[2] === '--self-test') registerTests();
  else prepare(process.argv.slice(2)).catch(() => { process.stderr.write('LINUX_PREPARATION_FAILED\n'); process.exitCode = 1; });
}
