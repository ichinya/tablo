<?php
declare(strict_types=1);

namespace Tablo\Tools;

use RuntimeException;
use Tablo\JsonRecord;
use Tablo\SqlColumn;

/** Fixed test-only catalog slots. This module never opens a connection or executes a command.
 * @phpstan-type Fixture array{alias: string, engine: string, initial: string, catalog: string}
 */
final class ServerFixturePlan
{
    public const INITIAL_STATES = ['empty', 'ready', 'metadata-table', 'metadata-view', 'metadata-trigger', 'metadata-routine', 'metadata-event', 'metadata-limited', 'metadata-role'];
    /** @return list<string> */
    public static function slots(string $nonce, string $engine): array
    { return server_fixture_plan_slots($nonce, $engine); }

    /** @return array{writer: string, reader: string, migrator: string, deniedDdl: string} */
    public static function accounts(string $nonce, string $engine): array
    { return server_fixture_plan_accounts($nonce, $engine); }

    public static function metadataAccount(string $nonce, string $engine, string $catalog): string
    {
        if (!in_array($catalog, self::slots($nonce, $engine), strict: true)) { throw new RuntimeException('Owned metadata catalog refused.'); }
        return 'tm39_' . substr($catalog, offset: 5);
    }

    /** Every physical slot may occur once in a native case; reuse is a separate closed-case protocol.
     * @return list<Fixture> */
    public static function fixtures(array $declaration, string $nonce, string $selectedEngine, string $lane = 'main'): array
    { return server_fixture_plan_fixtures($declaration, $nonce, $selectedEngine, $lane); }

    /** A caller cannot substitute a broad directory, URI, symlink or a different nonce. */
    public static function runtime(string $directory, string $nonce): void
    { server_fixture_plan_runtime($directory, $nonce); }

    /** Pure lifecycle declaration: no daemon, credentials, application bootstrap or database open.
     * @param array{engine: string, cross: bool, pull: bool, output: string, diagnostic: bool} $options */
    public static function cell(string $root, array $options): array
    { return server_fixture_plan_cell($root, $options); }

    /** Select source names before any caller hashes or opens them.
     * @param list<string> $names
     * @return list<string> */
    public static function sourcePaths(array $names): array
    { return server_fixture_plan_source_paths($names); }
}

/** This pure composition step never probes even excluded or nonexistent paths.
 * @param list<string> $names
 * @return list<string> */
function server_fixture_plan_source_paths(array $names): array
{
    $paths = [];
    foreach ($names as $name) {
        if ($name === '' || str_contains($name, '..') || str_contains($name, '\\') || str_contains($name, ':') || str_contains($name, "\0") || str_starts_with($name, '/')) {
            throw new RuntimeException('Malformed source inventory path.');
        }
        if (preg_match('~\A(?:storage|artifacts|vendor|\.git)(?:/|\z)~D', $name) === 1
            || in_array(basename($name), ['.env', 'real.env'], strict: true)) { continue; }
        $paths[] = $name;
    }
    return $paths;
}

/** @param array{engine: string, cross: bool, pull: bool, output: string, diagnostic: bool} $options */
function server_fixture_plan_cell(string $root, array $options): array
{
    $engine = $options['engine']; $executionMode = $options['diagnostic'] ? 'diagnostic-only' : 'qualified';
    $nonce = server_fixture_plan_nonce($executionMode, getenv('TABLO_SERVER_CELL_NONCE'));
    server_fixture_plan_selection($nonce, $engine);
    if ($options['cross'] && [$engine, PHP_MAJOR_VERSION, PHP_MINOR_VERSION] !== ['mysql', 8, 5]) { throw new RuntimeException('Mandatory cross cell is MySQL/PHP8.5 only.'); }
    $output = $options['output'];
    if (str_contains($output, '..') || str_contains($output, '://') || !is_dir(dirname($output)) || is_link(dirname($output)) || file_exists($output)) {
        throw new RuntimeException('Fresh explicit cell plan output refused.');
    }
    $runtime = str_replace('\\', replace: '/', subject: dirname($output)) . '/tablo-server-' . $nonce;
    $pins = JsonRecord::decode(\Tablo\RequiredFile::bytes(file_get_contents($root . '/deploy/database-engines.json')));
    $engines = $options['cross'] ? ['mysql', 'mariadb'] : [$engine]; $services = [];
    foreach ($engines as $name) { $services[$name] = server_fixture_plan_service($nonce, $name, JsonRecord::checked($pins[$name] ?? null), $runtime); }
    return ['schema' => 'tablo/server-cell-start/v1', 'executionMode' => $executionMode, 'nonce' => $nonce, 'selectedEngine' => $engine, 'cross' => $options['cross'], 'allowPull' => $options['pull'],
        'sourceInput' => $root, 'source' => $runtime . '/source', 'runtime' => $runtime, 'ini' => $runtime . '/private/php.ini',
        'key' => $runtime . '/private/installation.bin', 'ca' => $runtime . '/private/tls/ca.pem', 'services' => $services,
        'limits' => ['catalogsPerEngine' => 8, 'originalCatalogCeiling' => 64, 'connections' => 8, 'children' => 11,
            'pullSeconds' => 300, 'serviceSeconds' => 180, 'authenticatedPdoSeconds' => 30, 'fixtureSeconds' => 30,
            'nativeSeconds' => 8, 'httpSeconds' => 20, 'cleanupSeconds' => 30, 'overallSeconds' => 1800],
        'allowServerCrash' => $options['cross'], 'sourceState' => 'DECLARATION_ONLY_NOT_EXECUTED',
        'observer' => server_fixture_plan_observer($root, $runtime, $nonce, $executionMode)] + server_fixture_plan_docker($runtime) + server_fixture_plan_inputs($root);
}

/** Qualified cells receive one explicitly prepared nonce before qualification, never one adopted from a receipt. */
function server_fixture_plan_nonce(string $executionMode, string|false $supplied): string
{
    if ($executionMode === 'diagnostic-only') {
        if ($supplied !== false) { throw new RuntimeException('Diagnostic-only nonce override refused.'); }
        return bin2hex(random_bytes(16));
    }
    if ($executionMode !== 'qualified' || !is_string($supplied) || preg_match('/\A[0-9a-f]{32}\z/D', $supplied) !== 1) {
        throw new RuntimeException('Explicit fresh qualified cell nonce required.');
    }
    return $supplied;
}

/** The observer is an explicit opt-in tool; production and the SQLite verifier never select it. */
function server_fixture_plan_observer(string $root, string $runtime, string $nonce, string $executionMode): array
{
    $port = getenv('TABLO_TEST_OBSERVER_PORT');
    if (!is_string($port) || preg_match('/\A[0-9]{4,5}\z/D', $port) !== 1 || (int) $port < 1024 || (int) $port > 65_535 || $port === '8087') {
        throw new RuntimeException('Explicit local observer port required.');
    }
    $base = str_replace('\\', replace: '/', subject: dirname($runtime)) . '/tablo-observer-' . $nonce;
    $bootstrap = server_fixture_plan_bootstrap_ini();
    return ['schema' => 'tablo/server-observer/v1', 'root' => $base, 'host' => '127.0.0.1', 'port' => (int) $port,
        'authorityFile' => $base . '/private/controller.bin', 'adapterAuthorityFile' => $base . '/private/adapter.bin',
        'node' => server_fixture_plan_tool('TABLO_TEST_NODE_BIN'),
        'nodeOwner' => ['path' => $root . '/tools/server-supervisor.cjs', 'sha256' => \Tablo\RequiredFile::digest($root . '/tools/server-supervisor.cjs')],
        'powershell' => PHP_OS_FAMILY === 'Windows' ? server_fixture_plan_tool('TABLO_TEST_POWERSHELL_BIN') : null,
        'windowsAdapter' => PHP_OS_FAMILY === 'Windows' ? ['path' => $root . '/tools/server-supervisor-windows.ps1',
            'sha256' => \Tablo\RequiredFile::digest($root . '/tools/server-supervisor-windows.ps1')] : null,
        'maximumScopeProcesses' => 9, 'settlementSeconds' => 1, 'parentSeconds' => 1800,
        'phases' => server_fixture_plan_observer_phases($base),
        'processGraph' => ['owner' => 1, 'adapter' => 1, 'scope' => 9, 'maximumTotal' => 11,
            'baseline' => ['composer', 'composer-command-shell', 'sqlite-verifier', 'testo', 'endpoint-server', 'web-server', 'independent-web-server', 'session-request', 'password-cli'],
            'server' => ['cell-with-callable-verifier', 'testo', 'fixture', 'peer-fixture', 'helper', 'reserved']]] + $bootstrap
        + server_fixture_plan_linux_inputs($root, $executionMode, $nonce, $bootstrap['bootstrapIniFiles'] ?? null);
}

function server_fixture_plan_linux_inputs(string $root, string $executionMode, string $nonce = '', mixed $iniFiles = null): array
{
    if (!in_array($executionMode, ['diagnostic-only', 'qualified'], strict: true)) { throw new RuntimeException('Explicit execution mode required.'); }
    if ($executionMode === 'diagnostic-only' || PHP_OS_FAMILY === 'Windows') { return []; }
    if (PHP_OS_FAMILY !== 'Linux' || php_uname('m') !== 'x86_64') { throw new RuntimeException('Qualified Linux X64 adapter required.'); }
    $shell = server_fixture_plan_linux_shell();
    $binary = server_fixture_plan_linux_file('TABLO_SERVER_LINUX_ADAPTER');
    $build = server_fixture_plan_linux_file('TABLO_SERVER_LINUX_BUILD');
    $qualification = server_fixture_plan_linux_qualification($nonce);
    if (!is_executable($binary['path'])) { throw new RuntimeException('Executable Linux adapter required.'); }
    $sourcePath = server_fixture_plan_source_path($root, name: 'tools/server-supervisor-linux.c');
    $recipePath = server_fixture_plan_source_path($root, name: 'tools/server-supervisor-linux-build.sh');
    $source = ['path' => $sourcePath, 'sha256' => \Tablo\RequiredFile::digest($sourcePath)];
    $recipe = ['path' => $recipePath, 'sha256' => \Tablo\RequiredFile::digest($recipePath)];
    $inputs = ['linuxAdapter' => $binary, 'linuxAdapterSource' => $source, 'linuxAdapterRecipe' => $recipe, 'linuxAdapterBuild' => $build, 'linuxAdapterQualification' => $qualification];
    $controls = server_fixture_plan_source_path($root, name: 'tools/server-supervisor-linux-controls.c');
    server_fixture_plan_linux_provenance($inputs, $nonce, \Tablo\RequiredFile::digest($controls), server_fixture_plan_linux_runtimes($shell));
    return ['bootstrapExtensionFiles' => server_fixture_plan_php_modules($iniFiles), 'linuxShell' => $shell] + $inputs;
}

/** @return array{path: string, sha256: string} */
function server_fixture_plan_linux_shell(): array
{
    $shell = server_fixture_plan_linux_file('TABLO_SERVER_LINUX_SHELL');
    $canonical = realpath($shell['path']);
    if ($canonical === false || str_replace('\\', replace: '/', subject: $canonical) !== $shell['path'] || !is_executable($shell['path'])) {
        throw new RuntimeException('Canonical regular Linux command shell required.');
    }
    return $shell;
}

/** @return array{path: string, sha256: string} */
function server_fixture_plan_linux_file(string $selector): array
{
    $file = server_fixture_plan_tool($selector); $digest = getenv($selector . '_SHA256');
    if (!is_string($digest) || preg_match('/\A[0-9a-f]{64}\z/D', $digest) !== 1 || !hash_equals($file['sha256'], $digest)) { throw new RuntimeException('Exact Linux adapter input digest required.'); }
    return $file;
}

/** The selected qualification may be opened only inside its explicit nonce root.
 * @return array{path: string, sha256: string} */
function server_fixture_plan_linux_qualification(string $nonce): array
{
    $path = getenv('TABLO_SERVER_LINUX_QUALIFICATION');
    if (!is_string($path) || preg_match('/\A[0-9a-f]{32}\z/D', $nonce) !== 1
        || basename(dirname($path)) !== 'tablo-linux-' . $nonce || basename($path) !== 'qualification.json') {
        throw new RuntimeException('Explicit nonce-owned qualification required.');
    }
    server_fixture_plan_linux_regular($path);
    return server_fixture_plan_linux_file('TABLO_SERVER_LINUX_QUALIFICATION');
}

/** Only the explicitly selected prepared manifest is read; no configuration discovery or module load.
 * @return list<array{path: string, sha256: string}> */
function server_fixture_plan_php_modules(mixed $iniFiles): array
{
    $path = getenv('TABLO_SERVER_PHP_BOOTSTRAP_MODULES');
    if (!is_string($path)) { throw new RuntimeException('Prepared PHP bootstrap module manifest required.'); }
    server_fixture_plan_linux_regular($path);
    $selected = server_fixture_plan_linux_file('TABLO_SERVER_PHP_BOOTSTRAP_MODULES');
    $bytes = \Tablo\RequiredFile::bytes(file_get_contents($selected['path'], length: 131_073));
    $extensions = server_fixture_plan_php_module_decode($bytes, $iniFiles);
    foreach ($extensions as $pin) {
        server_fixture_plan_linux_regular($pin['path']);
        if (!hash_equals($pin['sha256'], \Tablo\RequiredFile::digest($pin['path']))) { throw new RuntimeException('Prepared PHP extension file changed.'); }
    }
    return $extensions;
}

/** Preserve JSON object/list distinctions, including an empty extension list.
 * @return list<array{path: string, sha256: string}> */
function server_fixture_plan_php_module_decode(string $bytes, mixed $iniFiles): array
{
    if (strlen($bytes) > 131_072) { throw new RuntimeException('PHP bootstrap module manifest envelope exceeded.'); }
    try {
        /** @var mixed $decoded Native JSON values need explicit object/list admission. */
        $decoded = json_decode($bytes, associative: false, depth: 16, flags: JSON_THROW_ON_ERROR);
    } catch (\JsonException $discarded) { unset($discarded); throw new RuntimeException('Malformed prepared PHP module JSON.'); }
    if (!$decoded instanceof \stdClass) { throw new RuntimeException('Prepared PHP module JSON object required.'); }
    $record = get_object_vars($decoded);
    foreach (['iniFiles', 'extensionFiles'] as $field) {
        /** @var mixed $entries The decoded list must not be an empty JSON object. */
        $entries = $record[$field] ?? null;
        if (!is_array($entries) || count($entries) > 128) { throw new RuntimeException('Finite prepared PHP module JSON lists required.'); }
        $pins = [];
        /** @var mixed $entry Each array item must be a JSON pin object. */
        foreach ($entries as $entry) {
            if (!$entry instanceof \stdClass) { throw new RuntimeException('Prepared PHP module JSON pin object required.'); }
            $pins[] = get_object_vars($entry);
        }
        $record[$field] = $pins;
    }
    return server_fixture_plan_php_module_record($record, $iniFiles);
}

/** Prepared module selection is not a PHP or Composer capability proof.
 * @return list<array{path: string, sha256: string}> */
function server_fixture_plan_php_module_record(array $record, mixed $iniFiles): array
{
    if (array_keys($record) !== ['schema', 'iniFiles', 'extensionFiles'] || ($record['schema'] ?? null) !== 'tablo/php-bootstrap-modules/v1') {
        throw new RuntimeException('Exact prepared PHP module manifest required.');
    }
    $expected = server_fixture_plan_php_module_pins($iniFiles);
    $actual = server_fixture_plan_php_module_pins($record['iniFiles'] ?? null);
    if ($expected === [] || \Tablo\JsonEncoding::encode($actual) !== \Tablo\JsonEncoding::encode($expected)) {
        throw new RuntimeException('Prepared PHP INI pins differ from selected bootstrap.');
    }
    return server_fixture_plan_php_module_pins($record['extensionFiles'] ?? null);
}

/** Pure shape/path checks preserve the explicit load order; no mentioned path is opened here.
 * @return list<array{path: string, sha256: string}> */
function server_fixture_plan_php_module_pins(mixed $value): array
{
    if (!is_array($value) || !array_is_list($value) || count($value) > 128) { throw new RuntimeException('Finite ordered PHP module pins required.'); }
    $pins = []; $seen = [];
    /** @var mixed $entry Each untrusted element needs its complete pin validation. */
    foreach ($value as $entry) {
        $pin = server_fixture_plan_linux_reference($entry);
        if (array_key_exists($pin['path'], $seen) || str_contains($pin['path'], '"')) { throw new RuntimeException('Unique literal PHP bootstrap paths required.'); }
        $seen[$pin['path']] = true; $pins[] = $pin;
    }
    return $pins;
}

/** Inspect only the positively selected regular input, after lexical path admission. */
function server_fixture_plan_linux_regular(string $path): void
{
    server_fixture_plan_linux_path($path);
    $cursor = '';
    foreach (explode('/', ltrim($path, characters: '/')) as $part) {
        $cursor .= '/' . $part;
        if (is_link($cursor)) { throw new RuntimeException('Linked qualification input ancestor refused.'); }
    }
    if (!is_file($path) || realpath($path) !== $path) { throw new RuntimeException('Canonical regular qualification input required.'); }
}

/** @param array{path: string, sha256: string} $shell
 * @return array<string, array{path: string, sha256: string}> */
function server_fixture_plan_linux_runtimes(array $shell): array
{
    return ['node' => server_fixture_plan_tool('TABLO_TEST_NODE_BIN'),
        'php' => ['path' => str_replace('\\', replace: '/', subject: PHP_BINARY), 'sha256' => \Tablo\RequiredFile::digest(PHP_BINARY)],
        'composer' => server_fixture_plan_tool('TABLO_TEST_COMPOSER_PHAR'), 'shell' => $shell, 'docker' => server_fixture_plan_tool('TABLO_TEST_DOCKER_BIN')];
}

/** Structural binding only; actual proof/reference reads belong to the already armed native owner.
 * @param array{linuxAdapter: array{path: string, sha256: string}, linuxAdapterSource: array{path: string, sha256: string}, linuxAdapterRecipe: array{path: string, sha256: string}, linuxAdapterBuild: array{path: string, sha256: string}, linuxAdapterQualification: array{path: string, sha256: string}} $inputs
 * @param array<string, array{path: string, sha256: string}> $runtimes */
function server_fixture_plan_linux_provenance(array $inputs, string $nonce, string $controlsSourceSha256, array $runtimes): void
{
    $binary = $inputs['linuxAdapter']; $source = $inputs['linuxAdapterSource']; $recipe = $inputs['linuxAdapterRecipe'];
    $build = $inputs['linuxAdapterBuild']; $qualification = $inputs['linuxAdapterQualification'];
    $b = server_fixture_plan_linux_receipt($build, schema: 'tablo/linux-adapter-build/v1', keys: ['schema', 'policy', 'target', 'sourceSha256', 'recipeSha256', 'binarySha256', 'compilerImageDigest', 'compilerSha256', 'linkerSha256', 'libcArchiveSha256']);
    $q = server_fixture_plan_linux_receipt($qualification, schema: 'tablo/linux-adapter-qualification/v3', keys: ['schema', 'policy', 'status', 'binarySha256', 'sourceSha256', 'recipeSha256', 'buildSha256', 'controlsSourceSha256', 'controlsBinarySha256', 'runtimeControlsSha256', 'platformReceiptSha256', 'nonce', 'runtimes', 'runtimeControls', 'platformReceipt', 'neutralControls', 'fallbackControlsSha256', 'fallbackControls']);
    if ([$b['target'] ?? null, $q['status'] ?? null, $q['nonce'] ?? null, $q['controlsSourceSha256'] ?? null] !== ['linux-x86_64', 'qualified', $nonce, $controlsSourceSha256]
        || [$b['sourceSha256'] ?? null, $b['recipeSha256'] ?? null, $b['binarySha256'] ?? null] !== [$source['sha256'], $recipe['sha256'], $binary['sha256']]
        || [$q['sourceSha256'] ?? null, $q['recipeSha256'] ?? null, $q['binarySha256'] ?? null, $q['buildSha256'] ?? null] !== [$source['sha256'], $recipe['sha256'], $binary['sha256'], $build['sha256']]
        || preg_match('/\Asha256:[0-9a-f]{64}\z/D', SqlColumn::string($b, 'compilerImageDigest')) !== 1) { throw new RuntimeException('Linux adapter build/qualification identity mismatch.'); }
    server_fixture_plan_linux_runtime_binding($q, $runtimes);
    server_fixture_plan_linux_references($q, dirname($qualification['path']));
}

/** @param array<string, array{path: string, sha256: string}> $runtimes */
function server_fixture_plan_linux_runtime_binding(array $qualification, array $runtimes): void
{
    $actual = JsonRecord::checked($qualification['runtimes'] ?? null);
    if (array_keys($actual) !== ['node', 'php', 'composer', 'shell', 'docker']) { throw new RuntimeException('Exact qualification runtimes required.'); }
    foreach (array_keys($actual) as $name) {
        if (server_fixture_plan_linux_reference($actual[$name] ?? null) !== ($runtimes[$name] ?? null)) {
            throw new RuntimeException('Current selected qualification runtime differs.');
        }
    }
}

/** No path mentioned by a qualification reference is opened by declaration. */
function server_fixture_plan_linux_references(array $qualification, string $root): void
{
    server_fixture_plan_linux_path($root);
    foreach (['runtimeControls' => 'runtime-controls.json', 'platformReceipt' => 'platform.json',
        'neutralControls' => 'neutral-controls.json', 'fallbackControls' => 'fallback-controls.json'] as $name => $file) {
        $reference = server_fixture_plan_linux_reference($qualification[$name] ?? null);
        if ($reference['path'] !== $root . '/' . $file
            || ($name !== 'neutralControls' && $reference['sha256'] !== ($qualification[$name . 'Sha256'] ?? null))) {
            throw new RuntimeException('Nonce-owned qualification reference required.');
        }
    }
}

/** @return array{path: string, sha256: string} */
function server_fixture_plan_linux_reference(mixed $value): array
{
    $pin = JsonRecord::checked($value);
    if (array_keys($pin) !== ['path', 'sha256']) { throw new RuntimeException('Exact qualification reference required.'); }
    $path = SqlColumn::string($pin, 'path'); $digest = SqlColumn::string($pin, 'sha256');
    server_fixture_plan_linux_path($path);
    if (preg_match('/\A[0-9a-f]{64}\z/D', $digest) !== 1) { throw new RuntimeException('Qualification reference digest refused.'); }
    return ['path' => $path, 'sha256' => $digest];
}

/** Lexical validation only: do not stat or open foreign reference values. */
function server_fixture_plan_linux_path(string $path): void
{
    if (strlen($path) > 4096 || !str_starts_with($path, '/') || str_contains($path, '\\')
        || preg_match('/[\x00-\x1f\x7f]/', $path) === 1) { throw new RuntimeException('Finite canonical qualification path required.'); }
    foreach (array_slice(explode('/', $path), offset: 1) as $component) {
        if (in_array($component, ['', '.', '..'], strict: true)) { throw new RuntimeException('Canonical qualification path components required.'); }
    }
}

/** @param array{path: string, sha256: string} $file
 * @param list<string> $keys */
function server_fixture_plan_linux_receipt(array $file, string $schema, array $keys): array
{
    $bytes = \Tablo\RequiredFile::bytes(file_get_contents($file['path'], length: 16_385));
    if (strlen($bytes) > 16_384) { throw new RuntimeException('Linux adapter receipt envelope exceeded.'); }
    server_fixture_plan_linux_unique_names($bytes);
    $record = JsonRecord::decode($bytes);
    if (array_keys($record) !== $keys || [$record['schema'] ?? null, $record['policy'] ?? null] !== [$schema, 'tgid-seccomp-ptrace-x64-v3']) { throw new RuntimeException('Linux adapter receipt shape refused.'); }
    foreach ($keys as $key) {
        if (!str_ends_with($key, 'Sha256')) { continue; }
        if (preg_match('/\A[0-9a-f]{64}\z/D', SqlColumn::string($record, $key)) !== 1) { throw new RuntimeException('Linux adapter receipt hash refused.'); }
    }
    return $record;
}

/** Scan bounded raw tokens before associative decoding can collapse object names.
 * Full JSON syntax and ordered receipt validation remain with the existing decoder. */
function server_fixture_plan_linux_unique_names(string $bytes): void
{
    $matches = [];
    if (preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}\[\]:,]/s', $bytes, $matches) === false) {
        throw new RuntimeException('Linux adapter receipt token scan refused.');
    }
    /** @var list<array{object: bool, key: bool, seen: array<string, true>}> $stack */
    $stack = [];
    foreach ($matches[0] ?? [] as $lexeme) {
        if (in_array($lexeme, ['{', '['], strict: true)) {
            $stack[] = ['object' => $lexeme === '{', 'key' => true, 'seen' => []];
            continue;
        }
        if (in_array($lexeme, ['}', ']'], strict: true)) { array_pop($stack); continue; }
        $index = array_key_last($stack);
        $frame = $index === null ? null : ($stack[$index] ?? null);
        if ($index === null || $frame === null || !$frame['object']) { continue; }
        if ($lexeme === ',') { $frame['key'] = true; $stack[$index] = $frame; continue; }
        if ($lexeme[0] !== '"' || !$frame['key']) { continue; }
        server_fixture_plan_linux_unique_name($lexeme, $frame['seen']);
        $frame['key'] = false;
        $stack[$index] = $frame;
    }
}

/** @param array<string, true> $seen */
function server_fixture_plan_linux_unique_name(string $lexeme, array &$seen): void
{
    /** @var mixed $name Raw JSON string tokens need explicit string admission. */
    $name = json_decode($lexeme, flags: JSON_THROW_ON_ERROR);
    if (!is_string($name)) { throw new RuntimeException('Linux adapter receipt name refused.'); }
    // Prefix prevents PHP numeric-key conversion from changing name identity.
    $key = 'name:' . $name;
    if (array_key_exists($key, $seen)) { throw new RuntimeException('Duplicate Linux adapter receipt name refused.'); }
    $seen[$key] = true;
}

/** Each adapter has exactly one controller and its own never-reused protocol authority. */
function server_fixture_plan_observer_phases(string $base): array
{
    $phases = [];
    foreach (['prepare', 'baseline', 'engine'] as $kind) {
        $nonce = bin2hex(random_bytes(16)); $root = $base . '/phases/tablo-observer-' . $nonce;
        $phases[] = ['kind' => $kind, 'nonce' => $nonce, 'root' => $root,
            'authorityFile' => $root . '/private/controller.bin', 'adapterAuthorityFile' => $root . '/private/adapter.bin',
            'controllerStdout' => $root . '/evidence/controller.stdout', 'controllerStderr' => $root . '/evidence/controller.stderr'];
    }
    return $phases;
}

function server_fixture_plan_bootstrap_ini(): array
{
    $ini = php_ini_loaded_file(); $scanned = php_ini_scanned_files(); $files = []; $directories = [];
    if (!is_string($ini) || !is_file($ini) || is_link($ini)) { throw new RuntimeException('Declared bootstrap PHP ini required.'); }
    $extra = is_string($scanned) && trim($scanned) !== '' ? explode(',', $scanned) : [];
    foreach ([$ini, ...$extra] as $file) {
        $file = trim($file);
        $file = str_replace('\\', replace: '/', subject: $file);
        if (!is_file($file) || is_link($file)) { throw new RuntimeException('Regular bootstrap ini input required.'); }
        $files[] = ['path' => $file, 'sha256' => \Tablo\RequiredFile::digest($file)];
        if ($file !== str_replace('\\', replace: '/', subject: $ini)) { $directories[dirname($file)] = true; }
    }
    return ['bootstrapIni' => str_replace('\\', replace: '/', subject: $ini), 'bootstrapIniFiles' => $files,
        'bootstrapIniScanDirectory' => implode(PATH_SEPARATOR, array_keys($directories))];
}

function server_fixture_plan_inputs(string $root): array
{
    return ['docker' => server_fixture_plan_tool('TABLO_TEST_DOCKER_BIN'), 'composer' => server_fixture_plan_tool('TABLO_TEST_COMPOSER_PHAR'),
        'lekalo' => server_fixture_plan_tool('LEKALO_BIN'), 'php' => ['path' => PHP_BINARY, 'sha256' => \Tablo\RequiredFile::digest(PHP_BINARY)],
        'sourceManifest' => server_fixture_plan_source_manifest($root), 'vendorReferences' => server_fixture_plan_vendor($root),
        'vendorFiles' => server_fixture_plan_vendor_files($root . '/vendor'), 'magoFiles' => server_fixture_plan_mago_files($root)];
}

/** The declaration freezes only regular locked vendor code; a linked ancestor is refused before traversal.
 * @return list<array{path: string, sha256: string}> */
function server_fixture_plan_vendor_files(string $directory, string $prefix = ''): array
{
    if (!is_dir($directory) || is_link($directory)) { throw new RuntimeException('Regular locked vendor directory required.'); }
    $files = [];
    foreach (new \DirectoryIterator($directory) as $entry) {
        if ($entry->isDot()) { continue; }
        if ($entry->isLink()) { throw new RuntimeException('Linked locked vendor code refused.'); }
        $name = $prefix . $entry->getFilename();
        if ($entry->isDir()) { $files = [...$files, ...server_fixture_plan_vendor_files($entry->getPathname(), $name . '/')]; continue; }
        if (!$entry->isFile()) { throw new RuntimeException('Nonregular locked vendor code refused.'); }
        $files[] = ['path' => $name, 'sha256' => \Tablo\RequiredFile::digest($entry->getPathname())];
    }
    usort($files, static fn (array $left, array $right): int => strcmp($left['path'], $right['path']));
    return $files;
}

/** Explicit installer acquisition precedes the cell; verification and copying never download a tool. */
function server_fixture_plan_mago_files(string $root): array
{
    foreach (['MagoPlatform', 'MagoPinData', 'MagoPin'] as $name) { require_once __DIR__ . '/' . $name . '.php'; }
    $pin = MagoPin::selected($root); $files = [];
    foreach (['archive', 'binary'] as $kind) {
        $path = SqlColumn::string($pin, $kind . '_path'); $digest = SqlColumn::string($pin, $kind . '_sha256'); MagoPin::digest($path, $digest);
        $files[] = ['path' => substr($path, offset: strlen($root) + 1), 'sha256' => $digest];
    }
    return $files;
}

function server_fixture_plan_service(string $nonce, string $engine, array $pin, string $runtime): array
{
    $name = 'tablo-i39-' . substr($nonce, offset: 0, length: 12) . '-' . $engine;
    return ['reference' => SqlColumn::string($pin, 'image'), 'configDigest' => SqlColumn::string($pin, 'config'), 'container' => $name,
        'containerId' => null, 'imageId' => null, 'volume' => $name . '-data', 'network' => $name . '-net', 'networkId' => null,
        'socket' => '/tmp/' . $name . '.sock', 'port' => $engine === 'mysql' ? 46_384 : 46_385,
        'passwordFile' => $runtime . '/private/' . $engine . '-roles.json', 'rootPasswordFile' => $runtime . '/private/' . $engine . '-root-password',
        'rootOptionsFile' => $runtime . '/private/' . $engine . '-root-client.cnf',
        'entrypointPath' => $engine === 'mysql' ? '/entrypoint.sh' : '/usr/local/bin/docker-entrypoint.sh',
        'entrypointSha256' => $engine === 'mysql' ? '7553dad15849bdc727c4830ffe3af358fd33167876338f606f703169bc61b0a0' : '149a6972d8c07d9542835cb63fb8c8b4723fe40ecb19e974efc8e559e7d58c61',
        'declaredVolumes' => $engine === 'mysql' ? [] : ['/var/lib/mysql'], 'expectedVersionPrefix' => SqlColumn::string($pin, 'imageLabel')];
}

/** @return array{path: string, sha256: string} */
function server_fixture_plan_tool(string $name): array
{
    $path = getenv($name);
    if (!is_string($path) || $path === '' || !is_file($path) || is_link($path)) { throw new RuntimeException('Explicit native cell executable absent.'); }
    return ['path' => str_replace('\\', replace: '/', subject: $path), 'sha256' => \Tablo\RequiredFile::digest($path)];
}

/** Caller supplies a finite git-owned source inventory; real .env/storage/private files are never inferred.
 * @return array{path: string, sha256: string, count: int} */
function server_fixture_plan_source_manifest(string $root): array
{
    $path = getenv('TABLO_TEST_SOURCE_MANIFEST'); $expected = getenv('TABLO_TEST_SOURCE_MANIFEST_SHA256');
    if (!is_string($path) || !is_string($expected) || is_link($path) || !hash_equals($expected, \Tablo\RequiredFile::digest($path))) {
        throw new RuntimeException('Frozen explicit source inventory absent or changed.');
    }
    $record = JsonRecord::decode(\Tablo\RequiredFile::bytes(file_get_contents($path)), 64);
    $files = JsonRecord::checked($record['files'] ?? null); $seen = [];
    foreach (array_keys($files) as $index) {
        $file = JsonRecord::checked($files[$index] ?? null); $name = SqlColumn::string($file, 'path');
        server_fixture_plan_source_file($root, $name, SqlColumn::string($file, 'sha256'));
        if (array_key_exists($name, $seen)) { throw new RuntimeException('Repeated native source path.'); }
        $seen[$name] = true;
    }
    if (count($seen) < 994) { throw new RuntimeException('Finite source inventory is incomplete.'); }
    return ['path' => $path, 'sha256' => $expected, 'count' => count($seen)];
}

function server_fixture_plan_source_file(string $root, string $name, string $digest): void
{
    $path = server_fixture_plan_source_path($root, $name);
    if (!hash_equals($digest, \Tablo\RequiredFile::digest($path))) { throw new RuntimeException('Frozen physical source changed.'); }
}

function server_fixture_plan_source_path(string $root, string $name): string
{
    if ($name === '' || str_contains($name, '..') || str_contains($name, '\\') || str_contains($name, ':') || str_contains($name, "\0") || str_starts_with($name, '/')
        || preg_match('~\A(?:storage|artifacts|vendor|\.git)(?:/|\z)~D', $name) === 1 || preg_match('~(?:\A|/)\.env\z~D', $name) === 1) {
        throw new RuntimeException('Native source inventory contains an installation or unsafe path.');
    }
    if (basename($name) === 'real.env') { throw new RuntimeException('Private environment source admission refused.'); }
    $cursor = $root;
    foreach (explode('/', $name) as $component) {
        $cursor .= '/' . $component;
        if (is_link($cursor)) { throw new RuntimeException('Linked native source component refused.'); }
    }
    $path = $root . '/' . $name;
    if (!is_file($path)) { throw new RuntimeException('Regular native source file required.'); }
    return $path;
}

/** Actual locked installed source references are required; this never installs or accesses a package network. */
function server_fixture_plan_vendor(string $root): array
{
    $installed = JsonRecord::decode(\Tablo\RequiredFile::bytes(file_get_contents($root . '/vendor/composer/installed.json')), 64);
    $packages = JsonRecord::checked($installed['packages'] ?? null); $expected = ['testo/testo' => '5ac7a5c47cabd2abc6a20d193775b3d9648748ff',
        'testo/bridge-symfony-console' => '65a8435b8a219b18b2cc78bd0a75fd0dd8b549c8']; $seen = [];
    foreach (array_keys($packages) as $index) {
        $package = JsonRecord::checked($packages[$index] ?? null); $name = SqlColumn::string($package, 'name');
        if (!array_key_exists($name, $expected)) { continue; }
        $source = JsonRecord::checked($package['source'] ?? null); $reference = SqlColumn::string($source, 'reference');
        if ($reference !== $expected[$name]) { throw new RuntimeException('Locked native test source reference changed.'); }
        $seen[$name] = $reference;
    }
    if (count($seen) !== 2) { throw new RuntimeException('Locked native test source references missing.'); }
    return $seen;
}

/** @return list<string> */
function server_fixture_plan_slots(string $nonce, string $engine): array
{
    server_fixture_plan_selection($nonce, $engine);
    $names = [];
    for ($index = 0; $index < 8; $index++) { $names[] = 'ti39_' . substr($nonce, offset: 0, length: 16) . '_' . ($engine === 'mysql' ? 'm' : 'a') . '_' . str_pad((string) $index, length: 3, pad_string: '0', pad_type: STR_PAD_LEFT); }
    return $names;
}

/** @return array{writer: string, reader: string, migrator: string, deniedDdl: string} */
function server_fixture_plan_accounts(string $nonce, string $engine): array
{
    server_fixture_plan_selection($nonce, $engine);
    $prefix = 'i39' . substr($nonce, offset: 0, length: 12);
    return ['writer' => $prefix . 'w', 'reader' => $prefix . 'r', 'migrator' => $prefix . 'm', 'deniedDdl' => $prefix . 'd'];
}

/** @return list<\Tablo\Tools\ServerFixturePlan::Fixture> */
function server_fixture_plan_fixtures(array $declaration, string $nonce, string $selectedEngine, string $lane): array
{
    server_fixture_plan_selection($nonce, $selectedEngine);
    if (!in_array($lane, ['main', 'cross'], strict: true) || ($lane === 'cross' && $selectedEngine !== 'mysql')) { throw new RuntimeException('Explicit native fixture lane refused.'); }
    $input = JsonRecord::checked($declaration['fixtures'] ?? null);
    if (!array_is_list($input)) { throw new RuntimeException('Required native fixture declaration absent.'); }
    $fixtures = []; $aliases = []; $counts = ['mysql' => 0, 'mariadb' => 0];
    foreach (array_keys($input) as $index) {
        $record = server_fixture_plan_fixture(JsonRecord::checked($input[$index] ?? null), $selectedEngine, $lane);
        $alias = $record['alias']; $engine = $record['engine']; $key = $engine . ':' . $alias;
        if (array_key_exists($key, $aliases)) { throw new RuntimeException('Repeated native fixture alias.'); }
        $position = $counts[$engine] ?? throw new RuntimeException('Selected fixture engine absent.');
        $catalog = server_fixture_plan_slots($nonce, $engine)[$position] ?? throw new RuntimeException('Finite native catalog-slot budget exceeded.');
        $counts[$engine] = $position + 1; $aliases[$key] = true; $fixtures[] = $record + ['catalog' => $catalog];
    }
    return $fixtures;
}

function server_fixture_plan_runtime(string $directory, string $nonce): void
{
    server_fixture_plan_selection($nonce, engine: 'mysql');
    if (str_contains($directory, "\0") || str_contains($directory, '://') || is_link($directory)
        || basename(str_replace('\\', replace: '/', subject: $directory)) !== 'tablo-server-' . $nonce || !is_dir($directory)) {
        throw new RuntimeException('Owned native runtime directory refused.');
    }
}

function server_fixture_plan_selection(string $nonce, string $engine): void
{
    if (preg_match('/\A[0-9a-f]{32}\z/D', $nonce) !== 1 || !in_array($engine, ['mysql', 'mariadb'], strict: true)) { throw new RuntimeException('Explicit native fixture selection refused.'); }
}

/** @return array{alias: string, engine: string, initial: string} */
function server_fixture_plan_fixture(array $record, string $selectedEngine, string $lane): array
{
    if (array_keys($record) !== ['alias', 'engine', 'initial']) { throw new RuntimeException('Unreviewed native fixture fields.'); }
    $alias = SqlColumn::string($record, 'alias'); $engine = SqlColumn::string($record, 'engine'); $initial = SqlColumn::string($record, 'initial');
    if ($lane === 'main' && $engine !== 'selected') { throw new RuntimeException('Main lane cannot silently start a peer engine.'); }
    if ($engine === 'selected') { $engine = $selectedEngine; }
    if (!in_array($engine, ['mysql', 'mariadb'], strict: true) || preg_match('/\A[a-z][a-z0-9-]{0,63}\z/D', $alias) !== 1
        || !in_array($initial, ServerFixturePlan::INITIAL_STATES, strict: true)) {
        throw new RuntimeException('Native fixture alias, engine or initial state refused.');
    }
    return ['alias' => $alias, 'engine' => $engine, 'initial' => $initial];
}

/** Explicit read-only context provenance is declared; only the fresh private config is used for later operations. */
function server_fixture_plan_docker(string $runtime): array
{
    $selection = match (PHP_OS_FAMILY) {
        'Windows' => ['desktop-linux', 'npipe:////./pipe/dockerDesktopLinuxEngine', 'USERPROFILE'],
        'Linux' => ['default', 'unix:///var/run/docker.sock', 'HOME'],
        default => throw new RuntimeException('Supported local Docker platform required.'),
    };
    $profile = getenv($selection[2]);
    if (!is_string($profile) || $profile === '') { throw new RuntimeException('Explicit Docker context profile absent.'); }
    return ['dockerPlatform' => PHP_OS_FAMILY, 'dockerContext' => $selection[0], 'dockerEndpoint' => $selection[1],
        'dockerContextConfig' => rtrim(str_replace('\\', replace: '/', subject: $profile), characters: '/') . '/.docker',
        'dockerConfig' => $runtime . '/private/docker-config', 'dockerAdmission' => null];
}
