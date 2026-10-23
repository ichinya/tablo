<?php
declare(strict_types=1);

// Pure actual-function grammar tests. No autoload or product fixture bootstrap.
namespace Tablo\Tools {
    /** @return array{inputs: array<string, string>, reads: list<string>, effects: list<string>, checks: int, helpers: bool, helperChecks: int, requests: list<array>, sendMode: string, capability: array} */
    function &grammar_state(): array
    {
        static $state = ['inputs' => [], 'reads' => [], 'effects' => [], 'checks' => 0,
            'helpers' => true, 'helperChecks' => 0, 'requests' => [], 'sendMode' => 'accepted', 'capability' => []];
        return $state;
    }
    function grammar_tripwire(string $effect): never
    {
        $state = &grammar_state();
        $state['effects'][] = $effect;
        throw new class($effect) extends \LogicException {};
    }
    function file_get_contents(string $filename, mixed $use_include_path = false, mixed $context = null, int $offset = 0, ?int $length = null): string|false
    {
        $state = &grammar_state();
        if ($use_include_path !== false || $context !== null || $offset !== 0 || $length !== 16_385
            || !array_key_exists($filename, $state['inputs'])) { grammar_tripwire('Unexpected grammar input access.'); }
        $state['reads'][] = $filename;
        return substr($state['inputs'][$filename], offset: 0, length: $length);
    }
    function is_file(string $filename): bool { grammar_tripwire('Unexpected grammar metadata access.'); }
    function is_link(string $filename): bool { grammar_tripwire('Unexpected grammar link access.'); }
    function realpath(string $path): string|false { grammar_tripwire('Unexpected grammar realpath access.'); }
    function hash_file(string $algorithm, string $filename): string|false { grammar_tripwire('Unexpected grammar hash access.'); }
    /** @param-out list<string> $output
     * @param-out int $result_code */
    function exec(string $command, ?array &$output = null, ?int &$result_code = null): string|false
    { grammar_tripwire('Unexpected grammar command.'); }
    /** @param string|list<string> $command
     * @param array<int, mixed> $descriptor_spec
     * @param-out array<int, resource> $pipes
     * @param null|array<string, string> $env_vars
     * @param null|array<string, mixed> $options
     * @return resource|false */
    // Match the native signature so source analysis of other owners remains accurate.
    // @mago-expect lint:excessive-parameter-list
    function proc_open(array|string $command, array $descriptor_spec, mixed &$pipes, ?string $cwd = null, ?array $env_vars = null, ?array $options = null): mixed
    { grammar_tripwire('Unexpected grammar process.'); }
}

namespace Tablo\Tests\CapabilityGrammar {
    use function Tablo\Tools\grammar_state;
    use function Tablo\Tools\grammar_tripwire;

    // Match the builtin signature and independently latch any autoload request.
    // @mago-expect lint:no-boolean-flag-parameter
    function class_exists(string $class, bool $autoload = true): bool
    {
        if ($autoload || !in_array($class, ['Tablo\\RequiredFile', 'Tablo\\JsonRecord', 'Tablo\\JsonEncoding', 'Tablo\\SqlColumn'], strict: true)) { grammar_tripwire('Unexpected helper bootstrap.'); }
        $state = &grammar_state(); $state['helperChecks']++; return $state['helpers'];
    }
    function server_cell_observer_request(string $kind, array $payload): array
    {
        $state = &grammar_state();
        if ($kind !== 'prepare-failure' || array_keys($payload) !== ['diagnostic']) { grammar_tripwire('Unexpected observer command.'); }
        $state['requests'][] = $payload;
        if ($state['sendMode'] === 'broken') { throw new \RuntimeException('PRIVATE send failure'); }
        return $state['sendMode'] === 'accepted' ? ['sequence' => 1, 'recorded' => true] : ['sequence' => 1, 'recorded' => false];
    }
    function extension_loaded(string $extension): bool
    {
        $state = &grammar_state();
        if (!in_array($extension, ['curl','openssl','pdo','pdo_mysql','pdo_sqlite','mbstring','dom','xml','xmlwriter'], strict: true)) { grammar_tripwire('Unexpected extension query.'); }
        /** @var mixed $error Only the injected Throwable is thrown. */
        $error = $state['capability']['queryError'] ?? null;
        if ($error instanceof \Throwable) { throw $error; }
        return ($state['capability']['missing'] ?? null) !== $extension;
    }
    function getenv(string $name): string|false
    {
        $state = &grammar_state();
        $key = match ($name) { 'TABLO_TEST_SOURCE_ROOT' => 'source', 'PHPRC' => 'ini', default => null };
        if ($key === null) { grammar_tripwire('Unexpected environment query.'); }
        /** @var mixed $value The closed seam can model absence without an environment read. */
        $value = $state['capability'][$key] ?? null; return is_string($value) ? $value : false;
    }
    function php_ini_loaded_file(): string|false
    {
        /** @var mixed $value No real PHP configuration is read. */
        $value = grammar_state()['capability']['loaded'] ?? null; return is_string($value) ? $value : false;
    }
    function php_ini_scanned_files(): string|false
    {
        $state = &grammar_state();
        /** @var mixed $queries The counter is validated before arithmetic. */
        $queries = $state['capability']['scanQueries'] ?? 0;
        if (!is_int($queries)) { grammar_tripwire('Invalid synthetic query counter.'); }
        $state['capability']['scanQueries'] = $queries + 1;
        /** @var mixed $value Closed seam; no configuration file or directory is inspected. */
        $value = grammar_state()['capability']['scanned'] ?? false;
        if ($value instanceof \Throwable) { throw $value; }
        return is_string($value) ? $value : false;
    }
    function sys_get_temp_dir(): string { return '/synthetic/' . ((grammar_state()['capability']['tempMismatch'] ?? false) === true ? 'different' : 'tmp'); }
    /** @param-out array<array-key, mixed> $matches */
    function preg_match(string $pattern, string $subject, ?array &$matches = null, int $flags = 0, int $offset = 0): int|false
    {
        /** @var mixed $fault Only the UTF-8 classifier check has an injected parser failure. */
        $fault = grammar_state()['capability']['regexFault'] ?? null;
        if ($pattern === '//u') {
            if ($fault instanceof \Throwable) { throw $fault; }
            if ($fault === false) { $matches = []; return false; }
        }
        return \preg_match($pattern, $subject, $matches, $flags, $offset);
    }
    function fwrite(mixed $stream, string $data, ?int $length = null): int|false
    {
        $state = &grammar_state();
        if ($stream !== STDOUT || $length !== null || ($data !== "PRIVATE_PHP_READY\n" && !str_starts_with($data, 'TABLO_CAPABILITY_FAILURE '))) { grammar_tripwire('Unexpected output effect.'); }
        if ($data === "PRIVATE_PHP_READY\n" && ($state['capability']['readyWriteFails'] ?? false) === true) { return false; }
        /** @var mixed $error Marker output failure is independently injected. */
        $error = $state['capability']['markerError'] ?? null;
        if (str_starts_with($data, 'TABLO_CAPABILITY_FAILURE ') && $error instanceof \Throwable) { throw $error; }
        /** @var mixed $output A malformed seam must trip the outside effect latch. */
        $output = $state['capability']['output'] ?? null;
        if (!is_array($output)) { grammar_tripwire('Missing closed output seam.'); }
        $output[] = $data; $state['capability']['output'] = $output; return strlen($data);
    }
}

namespace Tablo\Tests\PrivateIniGrammar {
    /** @return array{platform: string, files: array<string, array{digest: string, regular: bool, canonical: string|false}>, links: array<string, bool>, inspected: list<string>, writes: list<array{path: string, bytes: string}>} */
    function &state(): array
    {
        static $value = ['platform' => 'Linux', 'files' => [], 'links' => [], 'inspected' => [], 'writes' => []]; return $value;
    }
    /** @return array{digest: string, regular: bool, canonical: string|false} */
    function selected(string $path): array
    {
        $state = &state();
        if (!array_key_exists($path, $state['files'])) { \Tablo\Tools\grammar_tripwire('Foreign private INI file inspection.'); }
        $state['inspected'][] = $path; return $state['files'][$path];
    }
    // Closed digest stand-in for the exact extracted producer; no physical file access.
    // @mago-expect lint:file-name
    final class RequiredFile {
        public static function digest(string $path): string { return selected($path)['digest']; }
    }
    function is_file(string $path): bool { return selected($path)['regular']; }
    function realpath(string $path): string|false { return selected($path)['canonical']; }
    function is_link(string $path): bool
    {
        $state = &state();
        if (!array_key_exists($path, $state['links'])) { \Tablo\Tools\grammar_tripwire('Foreign private INI ancestor inspection.'); }
        $state['inspected'][] = $path; return $state['links'][$path];
    }
    function ini_get(string $option): string|false
    {
        if (state()['platform'] !== 'Windows' || $option !== 'extension_dir') { \Tablo\Tools\grammar_tripwire('Ambient Linux INI query.'); }
        return 'C:/synthetic/extensions';
    }
    function getenv(string $name): string|false { \Tablo\Tools\grammar_tripwire('Private INI environment discovery.'); }
    function is_dir(string $path): bool
    {
        if (state()['platform'] !== 'Windows' || $path !== 'C:/synthetic/extensions') { \Tablo\Tools\grammar_tripwire('Guessed Linux extension directory.'); }
        return true;
    }
    /** @return list<string> */
    function get_loaded_extensions(): array
    {
        if (state()['platform'] !== 'Windows') { \Tablo\Tools\grammar_tripwire('Ambient Linux extension registry.'); }
        return ['JSON', 'Zend OPcache', 'PDO_MYSQL', 'MISSING'];
    }
    function server_cell_private_write(string $path, string $bytes): void
    {
        if ($path !== '/synthetic/private/php.ini') { \Tablo\Tools\grammar_tripwire('Foreign private INI write.'); }
        $state = &state(); $state['writes'][] = ['path' => $path, 'bytes' => $bytes];
    }
}

namespace Tablo\Tests\ScanEnvironmentGrammar {
    const PHP_OS_FAMILY = 'Linux';
    // Only synthetic absence is available; the extracted helper cannot read the host environment.
    function getenv(string $name): false { return false; }
    /** @return list<string> */
    function &directories(): array { static $value = []; return $value; }
    function server_cell_directory(string $path): void { $value = &directories(); $value[] = $path; }
    function file_get_contents(string $path): never { throw new \RuntimeException('SYNTHETIC_INVENTORY_STOP'); }
}

namespace Tablo\Tests\EngineReportGrammar {
    /** @return array<string, mixed> */
    function &state(): array { static $value = []; return $value; }
    function server_cell_availability_failure(\Throwable $error, ?string $engine, string $stage): array
    {
        $value = &state(); $value['originCalls'] = true;
        if (($value['originFails'] ?? false) === true) { throw new \RuntimeException('PRIVATE secondary origin'); }
        if ($engine !== null || $stage !== 'unknown') { \Tablo\Tools\grammar_tripwire('Unexpected engine diagnostic classification.'); }
        return ['schema' => 'tablo/server-availability-failure/v1', 'engine' => null, 'stage' => 'unknown', 'type' => 'runtime',
            'category' => null, 'sqlState' => null, 'nativeCode' => null, 'nativeReason' => 'unknown', 'source' => 'tools/server-cell.php', 'line' => 17];
    }
    function server_cell_private_write(string $path, string $bytes): void
    {
        if ($path !== '/synthetic/cell/safe-engine-failure.json') { \Tablo\Tools\grammar_tripwire('Foreign engine report path.'); }
        $value = &state();
        if (($value['writeFails'] ?? false) === true || array_key_exists('written', $value)) { throw new \RuntimeException('PRIVATE secondary write'); }
        $value['written'] = $bytes;
    }
}

namespace Tablo\Tests\AvailabilityReportGrammar {
    /** @return array<string, mixed> */
    function &state(): array { static $value = []; return $value; }
    function server_cell_private_write(string $path, string $bytes): void
    {
        if ($path !== '/synthetic/cell/safe-availability-failure.json') { \Tablo\Tools\grammar_tripwire('Foreign availability report path.'); }
        $value = &state();
        if (($value['writeFails'] ?? false) === true || array_key_exists('written', $value)) { throw new \RuntimeException('PRIVATE secondary write'); }
        $value['written'] = $bytes;
    }
}

namespace Tablo\Tests\InitializeReportGrammar {
    /** @return array<string, mixed> */
    function &state(): array { static $value = []; return $value; }
    function server_cell_private_write(string $path, string $bytes): void
    {
        if ($path !== '/synthetic/cell/safe-initialize-failure.json') { \Tablo\Tools\grammar_tripwire('Foreign initialization report path.'); }
        $value = &state();
        if (($value['writeFails'] ?? false) === true || array_key_exists('written', $value)) { throw new \RuntimeException('PRIVATE secondary write'); }
        $value['written'] = $bytes;
    }
}

namespace Tablo\Tests\DiagnosticEffects {
    /** Any unqualified effect API imported into an extracted helper must latch before it can be caught. */
    function deny(mixed ...$arguments): never
    {
        unset($arguments); \Tablo\Tools\grammar_tripwire('Diagnostic effect attempted.');
    }
}

namespace {
    require __DIR__ . '/../app/JsonRecord.php';
    require __DIR__ . '/../app/SqlColumn.php';
    require __DIR__ . '/../app/RequiredFile.php';
    require __DIR__ . '/../app/JsonEncoding.php';
    require __DIR__ . '/ServerFixturePlan.php';

    function grammar_expect(mixed $condition): void
    {
        if ($condition !== true) { throw new RuntimeException('Pure grammar assertion failed.'); }
        $state = &\Tablo\Tools\grammar_state();
        $state['checks']++;
    }
    function grammar_refused(Closure $operation): void
    {
        $refused = false;
        try { $operation(); } catch (RuntimeException|JsonException) { $refused = true; }
        finally { grammar_expect(\Tablo\Tools\grammar_state()['effects'] === []); }
        grammar_expect($refused);
    }
    $hash = str_repeat('a', times: 64); $nonce = str_repeat('b', times: 32);
    $root = '/synthetic/unopened/tablo-linux-' . $nonce;
    $reference = static fn (string $file): array => ['path' => $root . '/' . $file, 'sha256' => $hash];
    $runtimes = [];
    foreach (['node','php','composer','shell','docker'] as $name) { $runtimes[$name] = ['path' => '/synthetic/unopened/' . $name, 'sha256' => $hash]; }
    $inputs = ['linuxAdapter' => $reference('binary'), 'linuxAdapterSource' => $reference('source'),
        'linuxAdapterRecipe' => $reference('recipe'), 'linuxAdapterBuild' => $reference('build.json'),
        'linuxAdapterQualification' => $reference('qualification.json')];
    $build = ['schema' => 'tablo/linux-adapter-build/v1', 'policy' => 'tgid-seccomp-ptrace-x64-v3', 'target' => 'linux-x86_64',
        'sourceSha256' => $hash, 'recipeSha256' => $hash, 'binarySha256' => $hash, 'compilerImageDigest' => 'sha256:' . $hash,
        'compilerSha256' => $hash, 'linkerSha256' => $hash, 'libcArchiveSha256' => $hash];
    $qualified = ['schema' => 'tablo/linux-adapter-qualification/v3', 'policy' => 'tgid-seccomp-ptrace-x64-v3', 'status' => 'qualified',
        'binarySha256' => $hash, 'sourceSha256' => $hash, 'recipeSha256' => $hash, 'buildSha256' => $hash,
        'controlsSourceSha256' => $hash, 'controlsBinarySha256' => $hash, 'runtimeControlsSha256' => $hash, 'platformReceiptSha256' => $hash,
        'nonce' => $nonce, 'runtimes' => $runtimes, 'runtimeControls' => $reference('runtime-controls.json'),
        'platformReceipt' => $reference('platform.json'), 'neutralControls' => $reference('neutral-controls.json'),
        'fallbackControlsSha256' => $hash, 'fallbackControls' => $reference('fallback-controls.json')];
    $run = static function (array|string $b, array|string $q) use ($inputs, $nonce, $hash, $runtimes): void {
        $state = &\Tablo\Tools\grammar_state();
        $state['inputs'] = [$inputs['linuxAdapterBuild']['path'] => is_string($b) ? $b : json_encode($b, flags: JSON_THROW_ON_ERROR),
            $inputs['linuxAdapterQualification']['path'] => is_string($q) ? $q : json_encode($q, flags: JSON_THROW_ON_ERROR)];
        $state['reads'] = [];
        \Tablo\Tools\server_fixture_plan_linux_provenance($inputs, $nonce, $hash, $runtimes);
        grammar_expect($state['effects'] === []);
        grammar_expect($state['reads'] === [$inputs['linuxAdapterBuild']['path'], $inputs['linuxAdapterQualification']['path']]);
    };
    $run($build, $qualified);
    $rawBuild = json_encode($build, flags: JSON_THROW_ON_ERROR);
    $rawQualified = json_encode($qualified, flags: JSON_THROW_ON_ERROR);
    $run($rawBuild, $rawQualified);
    // Escape-equivalent unique names retain their exact decoded order.
    $run($rawBuild, str_replace('"schema":', replace: '"sch\\u0065ma":', subject: $rawQualified));
    foreach (['"schema":"old",', '"schema":"tablo/linux-adapter-qualification/v3",',
        '"sch\\u0065ma":"old",', '"\\u0073chema":"old",'] as $duplicate) {
        grammar_refused(static fn () => $run($rawBuild, '{' . $duplicate . substr($rawQualified, offset: 1)));
    }
    foreach (['"policy":"old",', '"pol\\u0069cy":"tgid-seccomp-ptrace-x64-v3",'] as $duplicate) {
        grammar_refused(static fn () => $run($rawBuild, str_replace('"policy":', $duplicate . '"policy":', $rawQualified)));
    }
    foreach (['"path":"foreign",', '"pa\\u0074h":"foreign",',
        '"sha256":"' . $hash . '",', '"sha\\u003256":"wrong",'] as $duplicate) {
        $key = str_starts_with($duplicate, '"pa') ? 'path' : 'sha256';
        grammar_refused(static fn () => $run($rawBuild, str_replace('"' . $key . '":', $duplicate . '"' . $key . '":', $rawQualified)));
    }
    grammar_refused(static fn () => $run('{"schema":"old",' . substr($rawBuild, offset: 1), $rawQualified));
    // Arbitrary depth, arrays, literal UTF-8, surrogate pairs and escaped punctuation.
    foreach (['{"x":[{"path":0,"pa\\u0074h":1}]}', '{"é":0,"\\u00e9":0}',
        '{"😀":0,"\\ud83d\\ude00":1}', '{"a\"b":0,"a\\u0022b":1}',
        '{"x":{"sha256":0,"sha256":0}}'] as $raw) {
        grammar_refused(static fn () => \Tablo\Tools\server_fixture_plan_linux_unique_names($raw));
    }
    \Tablo\Tools\server_fixture_plan_linux_unique_names('{"x":[{"path":0},{"path":1}],"text":"{},\"path\":", "0":0,"00":0}');
    grammar_expect(\Tablo\Tools\grammar_state()['effects'] === []);
    $run($rawBuild, $rawQualified . str_repeat(' ', times: max(0, 16_384 - strlen($rawQualified))));
    grammar_refused(static fn () => $run($rawBuild, $rawQualified . str_repeat(' ', times: max(0, 16_385 - strlen($rawQualified)))));
    foreach (['{', '{"schema":', $rawQualified . 'false', "\xef\xbb\xbf" . $rawQualified] as $raw) {
        grammar_refused(static fn () => $run($rawBuild, $raw));
    }
    foreach (['schema' => 'tablo/linux-adapter-qualification/v2', 'policy' => 'tgid-seccomp-ptrace-x64-v1', 'status' => 'failed',
        'nonce' => str_repeat('c', times: 32), 'binarySha256' => str_repeat('d', times: 64),
        'sourceSha256' => str_repeat('d', times: 64), 'recipeSha256' => str_repeat('d', times: 64),
        'buildSha256' => str_repeat('d', times: 64), 'controlsSourceSha256' => str_repeat('d', times: 64),
        'fallbackControlsSha256' => str_repeat('d', times: 64)] as $key => $value) {
        grammar_refused(static fn () => $run($build, array_replace($qualified, [$key => $value])));
    }
    foreach (['policy' => 'tgid-seccomp-ptrace-x64-v1', 'binarySha256' => str_repeat('d', times: 64),
        'sourceSha256' => str_repeat('d', times: 64), 'compilerImageDigest' => 'latest'] as $key => $value) {
        grammar_refused(static fn () => $run(array_replace($build, [$key => $value]), $qualified));
    }
    $previousPolicy = substr($build['policy'], offset: 0, length: -1) . '2';
    grammar_refused(static fn () => $run(array_replace($build, ['policy' => $previousPolicy]), $qualified));
    grammar_refused(static fn () => $run($build, array_replace($qualified, ['policy' => $previousPolicy])));
    grammar_refused(static fn () => $run($build, array_reverse($qualified, preserve_keys: true)));
    grammar_refused(static fn () => $run($build, $qualified + ['extra' => 0]));
    foreach (array_keys($qualified) as $key) {
        $missing = $qualified; unset($missing[$key]);
        grammar_refused(static fn () => $run($build, $missing));
    }
    foreach (['runtimeControls' => 'runtime-controls.json', 'platformReceipt' => 'platform.json',
        'neutralControls' => 'neutral-controls.json', 'fallbackControls' => 'fallback-controls.json'] as $name => $file) {
        $original = $reference($file);
        foreach ([$root . '-foreign/proof.json', $root . '/wrong.json', $root . '/nested/' . $file,
            '/synthetic/../foreign', '/synthetic//foreign', "/synthetic/\0foreign", 'relative', 'C:/foreign'] as $path) {
            $changed = $qualified; $changed[$name]['path'] = $path;
            grammar_refused(static fn () => $run($build, $changed));
        }
        foreach ([array_reverse($original, preserve_keys: true), $original + ['extra' => 0],
            ['path' => $original['path'], 'sha256' => strtoupper($hash)]] as $bad) {
            $changed = $qualified; $changed[$name] = $bad;
            grammar_refused(static fn () => $run($build, $changed));
        }
    }
    foreach (['node','php','composer','shell','docker'] as $name) {
        $changed = $qualified; $changed['runtimes'][$name]['sha256'] = str_repeat('d', times: 64);
        grammar_refused(static fn () => $run($build, $changed));
    }
    $changed = $qualified; $changed['runtimes'] = array_reverse($runtimes, preserve_keys: true);
    grammar_refused(static fn () => $run($build, $changed));
    grammar_prepare_failures(); grammar_private_ini(); grammar_runtime_diagnostics(); grammar_availability_diagnostics(); grammar_initialization_diagnostics();
    // The only reads above were the two explicit in-memory receipt strings;
    // none of their reference paths can be opened by this isolated harness.
    echo 'IO_URING_PHP_GRAMMAR_OK ' . \Tablo\Tools\grammar_state()['checks'] . "\n";

    /** Extract only the actual pure producer helpers, never cell main or its dependencies. */
    function grammar_private_ini(): void
    {
        $cell = file_get_contents(__DIR__ . '/server-cell.php', length: 262_145);
        $plan = file_get_contents(__DIR__ . '/ServerFixturePlan.php', length: 262_145);
        if (!is_string($cell) || !is_string($plan)) { throw new RuntimeException('Required public INI sources absent.'); }
        grammar_scan_environment($cell);
        $bodies = array_merge(grammar_ini_functions($cell, ['server_cell_private_ini', 'server_cell_private_ini_linux', 'server_cell_private_ini_legacy']),
            grammar_ini_functions($plan, ['server_fixture_plan_php_module_pins', 'server_fixture_plan_linux_reference', 'server_fixture_plan_linux_path', 'server_fixture_plan_linux_regular']));
        foreach (['Linux', 'Windows'] as $platform) {
            // Exact bodies and fixed platform only; every physical operation is a closed seam.
            // @mago-expect lint:no-eval
            eval('namespace Tablo\\Tests\\Ini' . $platform . '; const PHP_OS_FAMILY = "' . $platform . '"; use Tablo\\JsonRecord; use Tablo\\SqlColumn; use Tablo\\Tests\\PrivateIniGrammar\\RequiredFile; use RuntimeException; use function Tablo\\Tests\\PrivateIniGrammar\\is_file, Tablo\\Tests\\PrivateIniGrammar\\is_link, Tablo\\Tests\\PrivateIniGrammar\\realpath, Tablo\\Tests\\PrivateIniGrammar\\ini_get, Tablo\\Tests\\PrivateIniGrammar\\is_dir, Tablo\\Tests\\PrivateIniGrammar\\get_loaded_extensions, Tablo\\Tests\\PrivateIniGrammar\\getenv, Tablo\\Tests\\PrivateIniGrammar\\server_cell_private_write; use function Tablo\\Tools\\file_get_contents, Tablo\\Tools\\hash_file, Tablo\\Tools\\exec, Tablo\\Tools\\proc_open;' . implode("\n", $bodies));
        }
        $run = grammar_prepare_callable('Tablo\\Tests\\IniLinux\\server_cell_private_ini');
        $pins = [['path' => '/opt/frozen/tokenizer-build42.bin', 'sha256' => str_repeat('a', times: 64)],
            ['path' => '/opt/frozen/dependencies/fileinfo-helper.so', 'sha256' => str_repeat('b', times: 64)]];
        foreach ([$pins, array_reverse($pins), []] as $ordered) {
            grammar_ini_setup($ordered); $run(grammar_ini_plan($ordered));
            $expected = ''; foreach ($ordered as $pin) { $expected .= 'extension="' . $pin['path'] . '"' . "\n"; }
            grammar_expect(grammar_ini_bytes() === $expected . grammar_ini_settings());
            grammar_expect(\Tablo\Tools\grammar_state()['effects'] === []);
        }
        $maximum = array_map(static fn (int $index): array => ['path' => '/opt/frozen/p' . $index, 'sha256' => str_repeat('a', times: 64)], range(0, end: 127));
        grammar_ini_setup($maximum); $run(grammar_ini_plan($maximum));
        grammar_expect(substr_count(grammar_ini_bytes(), needle: 'extension="') === 128); grammar_expect(\Tablo\Tools\grammar_state()['effects'] === []);
        grammar_ini_invalid($run, $pins); grammar_ini_physical($run, $pins); grammar_ini_windows();
    }

    function grammar_runtime_diagnostics(): void
    {
        $cell = \Tablo\RequiredFile::bytes(file_get_contents(__DIR__ . '/server-cell.php', length: 262_145));
        $bodies = grammar_ini_functions($cell, ['server_cell_docker_operation', 'server_cell_engine_failure', 'server_cell_report_engine_failure']);
        // Exact bodies only; every origin/public-file effect is closed in the namespace above.
        // @mago-expect lint:no-eval
        eval('namespace Tablo\\Tests\\EngineReportGrammar; use RuntimeException, Throwable;' . implode("\n", $bodies));
        grammar_docker_operations(); grammar_engine_reports();
    }

    function grammar_availability_diagnostics(): void
    {
        $cell = \Tablo\RequiredFile::bytes(file_get_contents(__DIR__ . '/server-cell.php', length: 262_145));
        $bodies = grammar_ini_functions($cell, ['server_cell_require_availability', 'server_cell_availability_observation', 'server_cell_availability_marker', 'server_cell_failure_marker',
            'server_cell_availability_record', 'server_cell_availability_details', 'server_cell_availability_location', 'server_cell_availability_sources', 'server_cell_report_availability_failure', 'server_cell_capability_exit', 'server_cell_prepare_sources']);
        // Only exact helpers are evaluated; publication is a closed, exclusive in-memory seam.
        // @mago-expect lint:no-eval
        eval('namespace Tablo\\Tests\\AvailabilityReportGrammar; use RuntimeException, Throwable;' . grammar_diagnostic_imports() . implode("\n", $bodies));
        /** @var Closure(array): ?array $parse */
        $parse = grammar_prepare_callable('Tablo\\Tests\\AvailabilityReportGrammar\\server_cell_availability_marker');
        $base = ['schema' => 'tablo/server-availability-failure/v1', 'engine' => 'mariadb', 'stage' => 'connection', 'type' => 'database',
            'category' => 'unavailable', 'sqlState' => 'HY000', 'nativeCode' => 2002, 'nativeReason' => 'connection-refused', 'source' => 'app/DatabaseFailure.php', 'line' => 17];
        $child = grammar_availability_child($base); grammar_expect($parse($child) === $base);
        grammar_diagnostic_effect_control($bodies, $child, kind: 'availability');
        $crlf = $child; $crlf['stderr'] = str_replace("\n", replace: "\r\n", subject: $child['stderr']); grammar_expect($parse($crlf) === $base);
        grammar_availability_invalid($parse, $base, $child); grammar_availability_valid($parse, $base);
        grammar_availability_reports($child); grammar_expect(\Tablo\Tools\grammar_state()['effects'] === []);
    }

    function grammar_initialization_diagnostics(): void
    {
        $cell = \Tablo\RequiredFile::bytes(file_get_contents(__DIR__ . '/server-cell.php', length: 262_145));
        $bodies = grammar_ini_functions($cell, ['server_cell_require_initialization', 'server_cell_initialize_observation', 'server_cell_initialize_marker', 'server_cell_failure_marker',
            'server_cell_initialize_record', 'server_cell_initialize_progress', 'server_cell_initialize_location', 'server_cell_initialize_sources', 'server_cell_report_initialize_failure',
            'server_cell_availability_record', 'server_cell_availability_details', 'server_cell_availability_location', 'server_cell_availability_sources', 'server_cell_capability_exit', 'server_cell_prepare_sources']);
        // The same exact envelope parser runs in both namespaces; all publication effects are closed.
        // @mago-expect lint:no-eval
        eval('namespace Tablo\\Tests\\InitializeReportGrammar; use RuntimeException, Throwable;' . grammar_diagnostic_imports() . implode("\n", $bodies));
        /** @var Closure(array): ?array $parse */
        $parse = grammar_prepare_callable('Tablo\\Tests\\InitializeReportGrammar\\server_cell_initialize_marker');
        $base = ['schema' => 'tablo/server-initialize-failure/v1', 'engine' => 'mariadb', 'stage' => 'migration', 'type' => 'database',
            'category' => 'unavailable', 'sqlState' => 'HY000', 'nativeCode' => 2002, 'nativeReason' => 'connection-refused', 'source' => 'app/ServerMigrationStep.php', 'line' => 17,
            'point' => 'before-ddl', 'step' => 0];
        $child = grammar_availability_child($base); grammar_expect($parse($child) === $base);
        grammar_diagnostic_effect_control($bodies, $child, kind: 'initialize');
        $crlf = $child; $crlf['stderr'] = str_replace("\n", replace: "\r\n", subject: $child['stderr']); grammar_expect($parse($crlf) === $base);
        grammar_availability_invalid($parse, $base, $child); grammar_availability_valid($parse, $base);
        grammar_initialize_progress($parse, $base); grammar_initialize_reports($child);
        grammar_initialize_rejections($base, $child);
        grammar_expect(\Tablo\Tools\grammar_state()['effects'] === []);
    }

    /** @param Closure(array): ?array $parse */
    function grammar_initialize_progress(Closure $parse, array $base): void
    {
        foreach (['point', 'step'] as $key) {
            $bad = $base; unset($bad[$key]); grammar_expect($parse(grammar_availability_child($bad)) === null);
            foreach (['PRIVATE', [], true, 1.5] as $value) { $bad = $base; $bad[$key] = $value; grammar_expect($parse(grammar_availability_child($bad)) === null); }
        }
        foreach ([-1, 18, '0'] as $step) { $bad = $base; $bad['step'] = $step; grammar_expect($parse(grammar_availability_child($bad)) === null); }
        grammar_initialize_values($parse, $base);
        /** @var Closure(): list<string> $sources */
        $sources = grammar_prepare_callable('Tablo\\Tests\\InitializeReportGrammar\\server_cell_initialize_sources');
        foreach ($sources() as $source) { $valid = $base; $valid['source'] = $source; grammar_expect($parse(grammar_availability_child($valid)) === $valid); }
        /** @var Closure(array, string): ?array $shared */
        $shared = grammar_prepare_callable('Tablo\\Tests\\InitializeReportGrammar\\server_cell_failure_marker');
        foreach (['unknown', 'availability'] as $kind) { grammar_expect($shared(grammar_availability_child($base), $kind) === null); }
    }

    /** @param Closure(array): ?array $parse */
    function grammar_initialize_values(Closure $parse, array $base): void
    {
        foreach ([null, 0, 10, 17] as $step) {
            foreach ([null, 'before-ddl', 'after-ddl', 'after-journal', 'before-publication', 'after-publication'] as $point) {
                $valid = array_replace($base, ['step' => $step, 'point' => $point]); grammar_expect($parse(grammar_availability_child($valid)) === $valid);
            }
        }
        foreach (['bootstrap', 'input', 'fixture', 'connection', 'objects', 'migration', 'admission', 'unknown'] as $stage) {
            $valid = $base; $valid['stage'] = $stage; grammar_expect($parse(grammar_availability_child($valid)) === $valid);
        }
    }

    function grammar_initialize_reports(array $child): void
    {
        /** @var Closure(array): ?array $observe */
        $observe = grammar_prepare_callable('Tablo\\Tests\\InitializeReportGrammar\\server_cell_initialize_observation');
        $guard = grammar_prepare_callable('Tablo\\Tests\\InitializeReportGrammar\\server_cell_require_initialization');
        $report = grammar_prepare_callable('Tablo\\Tests\\InitializeReportGrammar\\server_cell_report_initialize_failure');
        $root = '/synthetic/cell/tablo-server-' . str_repeat('a', times: 32) . '/source';
        $state = &\Tablo\Tests\InitializeReportGrammar\state(); $state = [];
        $success = ['exit_code' => 0, 'stdout' => 'PRIVATE arbitrary success output', 'stderr' => ''];
        $guard($success, $root); grammar_expect($observe($success) === null); grammar_expect(\Tablo\Tests\InitializeReportGrammar\state() === []);
        grammar_initialize_exit_values($observe);
        foreach ([$child, [], ['exit_code' => 0, 'stdout' => 'PRIVATE', 'stderr' => 'PRIVATE'], ['exit_code' => 2, 'stdout' => '', 'stderr' => '']] as $failure) {
            grammar_expect(!str_contains(json_encode($observe($failure), JSON_THROW_ON_ERROR), 'PRIVATE'));
            foreach ([[], ['writeFails' => true]] as $fault) {
                $state = $fault; $caught = null;
                try { $guard($failure, $root); } catch (RuntimeException $error) { $caught = $error; }
                grammar_expect($caught?->getMessage() === 'Native fixture preparation failed or its outcome is unknown.');
            }
        }
        $state = []; $report($child, $root);
        /** @var mixed $first The writer stores exactly the attempted bytes, never a synthesized record. */
        $first = \Tablo\Tests\InitializeReportGrammar\state()['written'] ?? null;
        grammar_expect(is_string($first) && strlen($first) < 2_048); $report([], $root);
        grammar_expect((\Tablo\Tests\InitializeReportGrammar\state()['written'] ?? null) === $first);
        foreach (['/foreign', '/synthetic/cell/source', '/synthetic/tablo-server-invalid/source'] as $foreign) {
            $state = []; $report($child, $foreign); grammar_expect(\Tablo\Tests\InitializeReportGrammar\state() === []);
        }
    }

    /** @param Closure(array): ?array $observe */
    function grammar_initialize_exit_values(Closure $observe): void
    {
        foreach ([[null, null], ['1', 'UNKNOWN'], [true, 'UNKNOWN'], [[], 'UNKNOWN'], [0, 0], [1, 1], [-2_147_483_648, -2_147_483_648],
            [2_147_483_647, 2_147_483_647], [-2_147_483_649, 'UNKNOWN'], [2_147_483_648, 'UNKNOWN']] as [$value, $expected]) {
            $observation = $observe(['exit_code' => $value, 'stdout' => '', 'stderr' => 'PRIVATE']) ?? throw new RuntimeException('Expected rejected child observation.');
            grammar_expect(array_key_exists('reportedExit', $observation)); grammar_expect(($observation['reportedExit'] ?? null) === $expected);
        }
        $missing = $observe([]) ?? throw new RuntimeException('Expected absent-exit observation.'); grammar_expect(array_key_exists('reportedExit', $missing));
        grammar_expect(($missing['reportedExit'] ?? null) === 'UNKNOWN');
    }

    /** Independent reachable-owner regression cases, rather than deriving completeness from the allowlist itself.
     * @param array{exit_code: int, stdout: string, stderr: string} $child */
    function grammar_initialize_rejections(array $base, array $child): void
    {
        /** @var Closure(array): ?array $observe */
        $observe = grammar_prepare_callable('Tablo\\Tests\\InitializeReportGrammar\\server_cell_initialize_observation');
        $accepted = $observe($child) ?? throw new RuntimeException('Expected rejected-child observation.');
        grammar_expect(($accepted['schema'] ?? null) === 'tablo/server-initialize-observation/v2');
        grammar_expect(array_key_exists('rejection', $accepted)); grammar_expect(($accepted['rejection'] ?? null) === null);
        foreach (['ServerColumnAdmission', 'ServerIndexAdmission', 'ServerForeignAdmission', 'ServerCheckAdmission',
            'ServerSessionSettings', 'KeyFilePath', 'KeyFileRead', 'VaultKeyFile', 'ServerExpression', 'ServerExpressionTokens',
            'ServerExpressionOperator', 'ServerExpressionAtom', 'RegexCapture'] as $owner) {
            $valid = $base; $valid['source'] = 'app/' . $owner . '.php';
            $result = $observe(grammar_availability_child($valid)) ?? throw new RuntimeException('Expected direct-owner observation.');
            grammar_expect(($result['diagnostic'] ?? null) === $valid); grammar_expect(array_key_exists('rejection', $result));
            grammar_expect(($result['rejection'] ?? null) === null);
        }
        foreach ([[], array_replace($child, ['exit_code' => null]), array_replace($child, ['exit_code' => '1']), array_replace($child, ['stdout' => 'PRIVATE'])] as $bad) {
            grammar_initialize_rejection($bad, expected: 'CHILD_SHAPE');
        }
        grammar_initialize_rejection(array_replace($child, ['stderr' => str_repeat('x', times: 1_025)]), expected: 'OVERSIZE');
        grammar_initialize_rejection(array_replace($child, ['stderr' => 'PRIVATE']), expected: 'FOOTER_MISSING');
        foreach (['PRIVATE', '[]', '{'] as $prefix) {
            grammar_initialize_rejection(array_replace($child, ['stderr' => $prefix . "\nNative server cell failed.\n"]), expected: 'JSON_INVALID');
        }
        grammar_initialize_rejection(grammar_availability_child(array_replace($base, ['schema' => 'PRIVATE'])), expected: 'SCHEMA_INVALID');
        grammar_initialize_rejection(grammar_availability_child(array_replace($base, ['step' => '0'])), expected: 'SCHEMA_INVALID');
        grammar_initialize_rejection(grammar_availability_child(array_replace($base, ['nativeCode' => '2002'])), expected: 'SCHEMA_INVALID');
        grammar_initialize_rejection(grammar_availability_child(array_replace($base, ['source' => 'app/PRIVATE.php'])), expected: 'ORIGIN_INVALID');
        grammar_initialize_rejection(array_replace($child, ['stderr' => ' ' . $child['stderr']]), expected: 'CANONICAL_INVALID');
    }

    function grammar_initialize_rejection(array $child, string $expected): void
    {
        /** @var Closure(array): ?array $observe */
        $observe = grammar_prepare_callable('Tablo\\Tests\\InitializeReportGrammar\\server_cell_initialize_observation');
        $record = $observe($child) ?? throw new RuntimeException('Expected failed-child observation.');
        grammar_expect(array_key_exists('rejection', $record)); grammar_expect(($record['rejection'] ?? null) === $expected);
        grammar_expect(($record['diagnostic'] ?? null) === null); grammar_expect(!str_contains(json_encode($record, JSON_THROW_ON_ERROR), 'PRIVATE'));
    }

    /** Fixed aliases close the known unqualified filesystem, process, environment and runtime-query effect APIs. */
    function grammar_diagnostic_imports(): string
    {
        return implode('', array_map(static fn (string $name): string => 'use function Tablo\\Tests\\DiagnosticEffects\\deny as ' . $name . ';',
            ['file_get_contents', 'file_put_contents', 'file_exists', 'is_file', 'is_dir', 'is_link', 'realpath', 'hash_file', 'readlink', 'fopen', 'mkdir',
                'scandir', 'glob', 'unlink', 'rename', 'copy', 'exec', 'proc_open', 'getenv', 'extension_loaded', 'php_ini_loaded_file', 'php_ini_scanned_files', 'sys_get_temp_dir']));
    }

    /** Mutate only the extracted parser: an ignored metadata attempt is swallowed by its real catch but remains latched.
     * @param list<string> $bodies */
    function grammar_diagnostic_effect_control(array $bodies, array $child, string $kind): void
    {
        $mutated = []; $changed = 0;
        foreach ($bodies as $body) {
            if (str_starts_with($body, 'function server_cell_failure_marker(')) {
                grammar_expect(substr_count($body, needle: "    try {\n") === 1);
                $body = str_replace("    try {\n", replace: "    try {\n        is_file('/synthetic/unopened');\n", subject: $body); $changed++;
            }
            $mutated[] = $body;
        }
        grammar_expect($changed === 1); grammar_expect(in_array($kind, ['availability', 'initialize'], strict: true));
        $namespace = $kind === 'availability' ? 'AvailabilityEffectControl' : 'InitializeEffectControl';
        // Fixed namespace choice, exact bodies and one authored ignored-call mutation; all effect aliases are denial seams.
        // @mago-expect lint:no-eval
        eval('namespace Tablo\\Tests\\' . $namespace . '; use RuntimeException, Throwable;' . grammar_diagnostic_imports() . implode("\n", $mutated));
        /** @var Closure(array, string): ?array $parse */
        $parse = grammar_prepare_callable('Tablo\\Tests\\' . $namespace . '\\server_cell_failure_marker');
        grammar_expect($parse($child, $kind) === null);
        $state = &\Tablo\Tools\grammar_state(); grammar_expect($state['effects'] === ['Diagnostic effect attempted.']); $state['effects'] = [];
        if ($kind === 'initialize') {
            /** @var Closure(array): ?array $observe */
            $observe = grammar_prepare_callable('Tablo\\Tests\\' . $namespace . '\\server_cell_initialize_observation');
            $record = $observe($child) ?? throw new RuntimeException('Expected internally rejected observation.');
            grammar_expect(($record['rejection'] ?? null) === 'INTERNAL_UNKNOWN');
            grammar_expect($state['effects'] === ['Diagnostic effect attempted.']); $state['effects'] = [];
        }
    }

    /** @param Closure(array): ?array $parse
     * @param array{exit_code: int, stdout: string, stderr: string} $child */
    function grammar_availability_invalid(Closure $parse, array $base, array $child): void
    {
        foreach (['schema', 'engine', 'stage', 'type', 'category', 'sqlState', 'nativeCode', 'nativeReason', 'source', 'line'] as $key) {
            $bad = $base; unset($bad[$key]); grammar_expect($parse(grammar_availability_child($bad)) === null);
            foreach (['PRIVATE', [], true, 1.5] as $value) { $bad = $base; $bad[$key] = $value; grammar_expect($parse(grammar_availability_child($bad)) === null); }
        }
        foreach ([['extra' => 'PRIVATE'], ['nativeCode' => -1], ['nativeCode' => 65_536], ['nativeCode' => '2002'], ['sqlState' => 'hy000'],
            ['line' => 0], ['line' => 1_000_001], ['line' => '17'], ['source' => null], ['line' => null], ['source' => '/private/secret.php'],
            ['source' => 'app/../private.php'], ['type' => 'runtime'], ['type' => 'pdo']] as $change) {
            grammar_expect($parse(grammar_availability_child(array_replace($base, $change))) === null);
        }
        $json = json_encode($base, JSON_THROW_ON_ERROR);
        foreach ([' ' . $json, $json . ' ', str_replace('"engine":', replace: '"schema":"PRIVATE","engine":', subject: $json),
            str_replace('"schema"', replace: '"sch\\u0065ma"', subject: $json), json_encode(array_reverse($base, preserve_keys: true), JSON_THROW_ON_ERROR),
            '[]', 'null', '{', "\xff", $json . "\nPRIVATE", str_repeat('x', times: 1_025)] as $bad) {
            $changed = $child; $changed['stderr'] = $bad . "\nNative server cell failed.\n"; grammar_expect($parse($changed) === null);
        }
        foreach (['', "PRIVATE\n", $child['stderr'] . "\n", "PRIVATE\n" . $child['stderr'], $child['stderr'] . "\0"] as $bad) {
            $changed = $child; $changed['stderr'] = $bad; grammar_expect($parse($changed) === null);
        }
        foreach ([['exit_code' => 0], ['exit_code' => '1'], ['exit_code' => null], ['stdout' => 'PRIVATE'], ['stderr' => []]] as $change) {
            grammar_expect($parse(array_replace($child, $change)) === null);
        }
    }

    /** @param Closure(array): ?array $parse */
    function grammar_availability_valid(Closure $parse, array $base): void
    {
        /** @var Closure(): list<string> $sources */
        $sources = grammar_prepare_callable('Tablo\\Tests\\AvailabilityReportGrammar\\server_cell_availability_sources');
        foreach ($sources() as $source) { $valid = $base; $valid['source'] = $source; grammar_expect($parse(grammar_availability_child($valid)) === $valid); }
        foreach ([['nativeCode' => 0], ['nativeCode' => 65_535], ['source' => null, 'line' => null], ['type' => 'pdo', 'category' => null],
            ['engine' => null], ['engine' => 'mysql'], ['line' => 1_000_000]] as $change) {
            $valid = array_replace($base, $change); grammar_expect($parse(grammar_availability_child($valid)) === $valid);
        }
    }

    /** @return array{exit_code: int, stdout: string, stderr: string} */
    function grammar_availability_child(array $record): array
    { return ['exit_code' => 1, 'stdout' => '', 'stderr' => json_encode($record, JSON_THROW_ON_ERROR) . "\nNative server cell failed.\n"]; }

    function grammar_availability_reports(array $child): void
    {
        /** @var Closure(array): ?array $observe */
        $observe = grammar_prepare_callable('Tablo\\Tests\\AvailabilityReportGrammar\\server_cell_availability_observation');
        $guard = grammar_prepare_callable('Tablo\\Tests\\AvailabilityReportGrammar\\server_cell_require_availability');
        $report = grammar_prepare_callable('Tablo\\Tests\\AvailabilityReportGrammar\\server_cell_report_availability_failure');
        $root = '/synthetic/cell/tablo-server-' . str_repeat('a', times: 32) . '/source';
        $success = ['exit_code' => 0, 'stdout' => 'PRIVATE success', 'stderr' => ''];
        $state = &\Tablo\Tests\AvailabilityReportGrammar\state(); $state = [];
        $guard($success, $root); grammar_expect($observe($success) === null); grammar_expect(\Tablo\Tests\AvailabilityReportGrammar\state() === []);
        foreach ([$child, ['exit_code' => 0, 'stdout' => 'PRIVATE', 'stderr' => 'PRIVATE'], ['exit_code' => 2, 'stdout' => '', 'stderr' => ''],
            [], ['exit_code' => null, 'stdout' => null, 'stderr' => null], ['exit_code' => 'PRIVATE', 'stdout' => [], 'stderr' => []]] as $failure) {
            $value = $observe($failure); grammar_expect(is_array($value));
            grammar_expect(!str_contains(json_encode($value, JSON_THROW_ON_ERROR), 'PRIVATE'));
            foreach ([[], ['writeFails' => true]] as $fault) {
                $state = $fault; $caught = null;
                try { $guard($failure, $root); } catch (RuntimeException $error) { $caught = $error; }
                grammar_expect($caught?->getMessage() === 'Actual fresh TLS PDO availability failed.');
            }
        }
        grammar_expect($observe([]) === ['schema' => 'tablo/server-availability-observation/v1', 'status' => 'failed', 'rootCause' => 'UNKNOWN',
            'issueAccepted' => false, 'reportedExit' => 'UNKNOWN', 'stdout' => 'UNKNOWN', 'stderr' => 'UNKNOWN', 'diagnostic' => null]);
        $state = []; $report($child, $root);
        /** @var mixed $first The closed publication seam records the actual attempted bytes. */
        $first = \Tablo\Tests\AvailabilityReportGrammar\state()['written'] ?? null;
        grammar_expect(is_string($first) && strlen($first) < 2_048); $report([], $root); grammar_expect((\Tablo\Tests\AvailabilityReportGrammar\state()['written'] ?? null) === $first);
        foreach (['/foreign', '/synthetic/cell/source', '/synthetic/tablo-server-invalid/source'] as $foreign) {
            $state = []; $report($child, $foreign); grammar_expect(\Tablo\Tests\AvailabilityReportGrammar\state() === []);
        }
    }

    function grammar_docker_operations(): void
    {
        /** @var Closure(array): string $classify */
        $classify = grammar_prepare_callable('Tablo\\Tests\\EngineReportGrammar\\server_cell_docker_operation');
        $families = [];
        foreach (['context' => ['inspect'], 'image' => ['inspect'], 'container' => ['inspect'], 'volume' => ['inspect', 'create', 'rm'], 'network' => ['inspect', 'create', 'rm']] as $command => $actions) {
            foreach ($actions as $action) {
                $expected = $command . '-' . $action; $families[] = $expected;
                grammar_expect($classify([$command, $action, 'PRIVATE']) === $expected);
            }
            grammar_expect($classify([$command, 'PRIVATE']) === 'unknown');
        }
        foreach (['inspect', 'create', 'start', 'exec', 'cp', 'stop', 'rm', 'pull', 'save', 'load', 'info', 'version'] as $command) {
            $families[] = $command; grammar_expect($classify([$command, 'PRIVATE']) === $command);
        }
        foreach ([[], ['PRIVATE'], [false], [null], [1], ['image', []]] as $arguments) { grammar_expect($classify($arguments) === 'unknown'); }
        grammar_expect(\Tablo\Tools\grammar_state()['effects'] === []);
        $families[] = 'unknown'; echo 'DOCKER_FAMILY_COMPATIBILITY ' . json_encode($families, JSON_THROW_ON_ERROR) . "\n";
    }

    function grammar_engine_reports(): void
    {
        /** @var Closure(Throwable): array $project */
        $project = grammar_prepare_callable('Tablo\\Tests\\EngineReportGrammar\\server_cell_engine_failure');
        $report = grammar_prepare_callable('Tablo\\Tests\\EngineReportGrammar\\server_cell_report_engine_failure');
        $root = '/synthetic/cell/tablo-server-' . str_repeat('a', times: 32) . '/source';
        $primary = new RuntimeException('PRIVATE message /private/filename ' . str_repeat('x', times: 8_192));
        $state = &\Tablo\Tests\EngineReportGrammar\state(); $state = [];
        $value = $project($primary);
        grammar_expect($value === ['schema' => 'tablo/server-engine-failure/v1', 'status' => 'failed', 'phase' => 'engine', 'rootCause' => 'UNKNOWN',
            'issueAccepted' => false, 'engine' => null, 'stage' => 'unknown', 'type' => 'runtime', 'category' => null, 'sqlState' => null,
            'nativeCode' => null, 'nativeReason' => 'unknown', 'source' => 'tools/server-cell.php', 'line' => 17]);
        $report($primary, $root);
        /** @var mixed $first The closed reporter mutates its namespace state. */
        $first = \Tablo\Tests\EngineReportGrammar\state()['written'] ?? null;
        grammar_expect(is_string($first) && strlen($first) < 1_024 && !str_contains($first, 'PRIVATE') && !str_contains($first, '/private/'));
        $report(new TypeError('PRIVATE later'), $root); grammar_expect((\Tablo\Tests\EngineReportGrammar\state()['written'] ?? null) === $first);
        foreach ([['originFails' => true], ['writeFails' => true]] as $fault) { $state = $fault; $report($primary, $root); grammar_expect(!array_key_exists('written', \Tablo\Tests\EngineReportGrammar\state())); }
        foreach (['/foreign', '/synthetic/cell/source', '/synthetic/tablo-server-invalid/source'] as $foreign) {
            $state = []; $report($primary, $foreign); grammar_expect(\Tablo\Tests\EngineReportGrammar\state() === []);
        }
        grammar_expect(\Tablo\Tools\grammar_state()['effects'] === []);
    }

    function grammar_scan_environment(string $cell): void
    {
        $body = grammar_ini_functions($cell, ['server_cell_ini_scan_directory', 'server_cell_start_environment', 'server_cell_materialize']);
        // Only the uniquely extracted producer is evaluated, with a closed getenv seam.
        // @mago-expect lint:no-eval
        eval('namespace Tablo\\Tests\\ScanEnvironmentGrammar; use Tablo\\JsonRecord, Tablo\\SqlColumn, Tablo\\RequiredFile; use RuntimeException;' . implode("\n", $body));
        /** @var Closure(array): array<string, string> $start */
        $start = grammar_prepare_callable('Tablo\\Tests\\ScanEnvironmentGrammar\\server_cell_start_environment');
        $environment = $start([
            'runtime' => '/synthetic/runtime', 'source' => '/synthetic/runtime/source', 'nonce' => str_repeat('a', times: 32),
            'selectedEngine' => 'mysql', 'ini' => '/synthetic/runtime/private/php.ini',
            'composer' => ['path' => '/synthetic/composer', 'sha256' => str_repeat('b', times: 64)], 'lekalo' => ['path' => '/synthetic/lekalo']]);
        grammar_expect(\Tablo\SqlColumn::string($environment, 'PHP_INI_SCAN_DIR') === '/synthetic/runtime/private/php-ini-scan');
        grammar_expect(\Tablo\SqlColumn::string($environment, 'PHPRC') === '/synthetic/runtime/private/php.ini');
        grammar_expect(\Tablo\Tools\grammar_state()['effects'] === []);
        $materialize = grammar_prepare_callable('Tablo\\Tests\\ScanEnvironmentGrammar\\server_cell_materialize');
        $nonce = str_repeat('a', times: 32); $runtime = '/synthetic/tablo-server-' . $nonce;
        $caught = null;
        try { $materialize(['runtime' => $runtime, 'nonce' => $nonce, 'sourceInput' => '/synthetic/input', 'source' => $runtime . '/source',
            'sourceManifest' => ['path' => '/synthetic/inventory.json']]); } catch (RuntimeException $error) { $caught = $error; }
        grammar_expect($caught?->getMessage() === 'SYNTHETIC_INVENTORY_STOP');
        grammar_expect(\Tablo\Tests\ScanEnvironmentGrammar\directories() === [$runtime, ...array_map(static fn (string $name): string => $runtime . '/' . $name,
            ['private', 'private/tls', 'private/docker-config', 'private/php-ini-scan', 'tmp', 'sessions', 'composer-home', 'composer-cache', 'evidence', 'evidence/closure', 'source'])]);
        grammar_expect(\Tablo\Tools\grammar_state()['effects'] === []);
        grammar_scan_paths($cell, $start, $materialize);
    }

    function grammar_scan_paths(string $cell, Closure $start, Closure $materialize): void
    {
        $body = grammar_ini_functions($cell, ['server_cell_ini_scan_directory']);
        // The exact pure helper is checked with a fixed Windows platform, without any filesystem seam.
        // @mago-expect lint:no-eval
        eval('namespace Tablo\\Tests\\ScanWindowsGrammar; const PHP_OS_FAMILY = "Windows"; use RuntimeException;' . implode("\n", $body));
        /** @var Closure(string): string $windows */
        $windows = grammar_prepare_callable('Tablo\\Tests\\ScanWindowsGrammar\\server_cell_ini_scan_directory');
        grammar_expect($windows('C:/synthetic/runtime') === 'C:/synthetic/runtime/private/php-ini-scan');
        foreach (['', 'C:/synthetic/a;b/runtime', ";C:/synthetic/runtime", "C:/synthetic/runtime\0"] as $runtime) {
            grammar_refused(static fn () => $windows($runtime));
        }
        $before = \Tablo\Tests\ScanEnvironmentGrammar\directories();
        foreach (['', '/synthetic/a:b/runtime', ':/synthetic/runtime', "/synthetic/runtime\0"] as $runtime) {
            grammar_refused(static fn () => $start(['runtime' => $runtime]));
            grammar_refused(static fn () => $materialize(['runtime' => $runtime, 'nonce' => str_repeat('a', times: 32)]));
            grammar_expect(\Tablo\Tests\ScanEnvironmentGrammar\directories() === $before);
        }
    }

    /** @param list<string> $names
     * @return list<string> */
    function grammar_ini_functions(string $source, array $names): array
    {
        if (strlen($source) > 262_144) { throw new RuntimeException('Public INI source bound exceeded.'); }
        $bodies = [];
        foreach ($names as $name) {
            $marker = "\nfunction " . $name . '('; $at = strpos($source, $marker);
            if ($at === false || strpos($source, $marker, offset: $at + strlen($marker)) !== false) { throw new RuntimeException('Unique INI helper required.'); }
            $close = strpos($source, needle: "\n}", offset: $at + 1);
            if ($close === false) { throw new RuntimeException('Exact INI helper boundary absent.'); }
            $bodies[] = substr($source, offset: $at + 1, length: $close + 1 - $at);
        }
        return $bodies;
    }

    /** @param list<array{path: string, sha256: string}> $pins */
    function grammar_ini_setup(array $pins): void
    {
        $state = &\Tablo\Tests\PrivateIniGrammar\state(); $state = ['platform' => 'Linux', 'files' => [], 'links' => [], 'inspected' => [], 'writes' => []];
        foreach ($pins as $pin) {
            $state['files'][$pin['path']] = ['digest' => $pin['sha256'], 'regular' => true, 'canonical' => $pin['path']];
            $cursor = ''; foreach (explode('/', ltrim($pin['path'], characters: '/')) as $part) { $cursor .= '/' . $part; $state['links'][$cursor] = false; }
        }
    }

    function grammar_ini_plan(mixed $pins): array
    { return ['runtime' => '/synthetic/runtime', 'ini' => '/synthetic/private/php.ini', 'ca' => '/synthetic/ca.pem', 'observer' => ['bootstrapExtensionFiles' => $pins]]; }
    function grammar_ini_settings(): string
    { return "display_errors=stderr\nlog_errors=0\nzend.exception_ignore_args=1\nerror_reporting=E_ALL\nmemory_limit=256M\nopcache.enable_cli=0\nsys_temp_dir=\"/synthetic/runtime/tmp\"\nsession.save_path=\"/synthetic/runtime/sessions\"\nopenssl.cafile=\"/synthetic/ca.pem\"\n"; }
    function grammar_ini_bytes(): string
    {
        $writes = \Tablo\Tests\PrivateIniGrammar\state()['writes']; grammar_expect(count($writes) === 1);
        if (count($writes) !== 1) { throw new RuntimeException('Exactly one closed INI write required.'); }
        return $writes[0]['bytes'];
    }

    /** @param array{0: array{path: string, sha256: string}, 1: array{path: string, sha256: string}} $pins */
    function grammar_ini_invalid(Closure $run, array $pins): void
    {
        $bad = [null, ['foreign' => $pins[0]], [$pins[0], $pins[0]], [array_replace($pins[0], ['extra' => true])],
            [array_replace($pins[0], ['sha256' => 'PRIVATE'])], array_map(static fn (int $index): array => ['path' => '/foreign/p' . $index, 'sha256' => str_repeat('a', times: 64)], range(0, end: 128))];
        $hostile = new class implements JsonSerializable { public function jsonSerialize(): mixed { \Tablo\Tools\grammar_tripwire('Arbitrary INI pin conversion.'); } };
        $bad[] = [array_replace($pins[0], ['sha256' => $hostile])];
        foreach (['/foreign/../x.so', '/foreign/x".so', "/foreign/x\n.so", '/foreign/path space.so', '/foreign/semicolon;.so', '/foreign/ユ.so', 'relative.so'] as $path) {
            $bad[] = [$pins[0], ['path' => $path, 'sha256' => str_repeat('a', times: 64)]];
        }
        foreach ($bad as $selection) {
            grammar_ini_setup($pins); grammar_ini_refused($run, grammar_ini_plan($selection));
            grammar_expect(\Tablo\Tests\PrivateIniGrammar\state()['inspected'] === []);
        }
        foreach (['missing', 'empty'] as $change) {
            grammar_ini_setup($pins); $plan = grammar_ini_plan($pins); $plan['observer'] = [];
            if ($change === 'missing') { unset($plan['observer']); }
            grammar_ini_refused($run, $plan); grammar_expect(\Tablo\Tests\PrivateIniGrammar\state()['inspected'] === []);
        }
    }

    function grammar_ini_refused(Closure $run, array $plan): void
    {
        grammar_refused(static fn () => $run($plan));
        grammar_expect(\Tablo\Tests\PrivateIniGrammar\state()['writes'] === []); grammar_expect(\Tablo\Tools\grammar_state()['effects'] === []);
    }

    /** @param array{0: array{path: string, sha256: string}, 1: array{path: string, sha256: string}} $pins */
    function grammar_ini_physical(Closure $run, array $pins): void
    {
        foreach (['digest', 'regular', 'canonical', 'linked'] as $fault) {
            grammar_ini_setup($pins); $state = &\Tablo\Tests\PrivateIniGrammar\state(); $path = $pins[1]['path'];
            if ($fault === 'digest') { $state['files'][$path]['digest'] = str_repeat('c', times: 64); }
            if ($fault === 'regular') { $state['files'][$path]['regular'] = false; }
            if ($fault === 'canonical') { $state['files'][$path]['canonical'] = '/foreign/canonical'; }
            if ($fault === 'linked') { $state['links']['/opt/frozen'] = true; }
            grammar_ini_refused($run, grammar_ini_plan($pins)); grammar_expect($state['inspected'] !== []);
        }
    }

    function grammar_ini_windows(): void
    {
        grammar_ini_setup([]); $state = &\Tablo\Tests\PrivateIniGrammar\state(); $state['platform'] = 'Windows';
        foreach (['json' => true, 'pdo_mysql' => true, 'missing' => false] as $name => $regular) {
            $path = 'C:/synthetic/extensions/php_' . $name . '.dll'; $state['files'][$path] = ['digest' => '', 'regular' => $regular, 'canonical' => $path];
        }
        $run = grammar_prepare_callable('Tablo\\Tests\\IniWindows\\server_cell_private_ini'); $plan = grammar_ini_plan(null); unset($plan['observer']); $run($plan);
        grammar_expect(grammar_ini_bytes() === "extension_dir=\"C:/synthetic/extensions\"\nextension=\"C:/synthetic/extensions/php_json.dll\"\nextension=\"C:/synthetic/extensions/php_pdo_mysql.dll\"\n" . grammar_ini_settings());
        grammar_expect(\Tablo\Tools\grammar_state()['effects'] === []);
    }

    function grammar_prepare_failures(): void
    {
        $bytes = file_get_contents(__DIR__ . '/server-cell.php', length: 262_145);
        if (!is_string($bytes)) { throw new RuntimeException('Required public producer source absent.'); }
        $selected = grammar_prepare_functions($bytes);
        grammar_expect(count($selected) === 18);
        // Only these inspected public helper bodies enter an isolated namespace;
        // no include, autoload, cell entry or source transformation is executed.
        // @mago-expect lint:no-eval
        eval('namespace Tablo\\Tests\\CapabilityGrammar; use Tablo\\RequiredFile; use Tablo\\JsonRecord; use Tablo\\SqlColumn; use Throwable; use RuntimeException; use Closure; use function Tablo\\Tools\\file_get_contents, Tablo\\Tools\\is_file, Tablo\\Tools\\is_link, Tablo\\Tools\\realpath, Tablo\\Tools\\hash_file, Tablo\\Tools\\exec, Tablo\\Tools\\proc_open;' . implode("\n", $selected));
        grammar_expect(!function_exists('Tablo\\Tests\\CapabilityGrammar\\server_cell_prepare_client'));
        grammar_prepare_extraction($bytes);
        grammar_prepare_projection(); grammar_prepare_origins(); grammar_prepare_transport(); grammar_capability(); grammar_stderr(); grammar_bare_duplicate(); grammar_stderr_reasons(); grammar_stderr_prefixes();
    }

    /** The inspected top-level helpers have column-zero closing braces.
     * Refuse changed boundaries or duplicate declarations rather than parsing or
     * importing any other PHP. Substrings preserve their exact original bytes.
     * @return list<string> */
    function grammar_prepare_functions(string $bytes): array
    {
        if (strlen($bytes) > 262_144) { throw new RuntimeException('Public producer source exceeds extraction bound.'); }
        $selected = [];
        foreach (['server_cell_prepare_roots', 'server_cell_prepare_sources', 'server_cell_prepare_failure', 'server_cell_prepare_report', 'server_cell_prepare_rethrow',
            'server_cell_capability', 'server_cell_capability_marker', 'server_cell_capability_stderr_kind', 'server_cell_capability_stderr_shape', 'server_cell_capability_stderr_prefix', 'server_cell_capability_stderr_records', 'server_cell_capability_stderr_record', 'server_cell_capability_observation', 'server_cell_capability_exit', 'server_cell_capability_shape', 'server_cell_capability_stderr_reason_shape', 'server_cell_capability_projection', 'server_cell_capability_check'] as $name) {
            $marker = "\nfunction " . $name . '('; $position = strpos($bytes, $marker);
            if ($position === false || strpos($bytes, $marker, offset: $position + strlen($marker)) !== false) { throw new RuntimeException('Exact unique pure helper declaration required.'); }
            $start = $position + 1; $close = strpos($bytes, needle: "\n}", offset: $start);
            if ($close === false) { throw new RuntimeException('Exact pure helper boundary absent.'); }
            $end = $close + 2; $next = substr($bytes, offset: $end, length: 2);
            if ($next !== '' && !str_starts_with($next, "\n") && $next !== "\r\n") { throw new RuntimeException('Changed pure helper boundary refused.'); }
            $selected[] = substr($bytes, offset: $start, length: $end - $start);
        }
        return $selected;
    }

    function grammar_prepare_extraction(string $bytes): void
    {
        $lf = str_replace("\r\n", replace: "\n", subject: $bytes);
        $windows = str_replace("\n", replace: "\r\n", subject: $lf);
        grammar_expect(grammar_prepare_functions($lf) === array_map(static fn (string $body): string => str_replace("\r\n", replace: "\n", subject: $body), grammar_prepare_functions($windows)));
        foreach ([$bytes . "\nfunction server_cell_prepare_roots() {}", str_replace('function server_cell_prepare_roots(', replace: 'function missing_prepare_roots(', subject: $bytes),
            substr($bytes, offset: 0, length: (int) strpos($bytes, needle: 'function server_cell_prepare_rethrow(')), str_repeat('x', times: 262_145)] as $invalid) {
            grammar_refused(static fn () => grammar_prepare_functions($invalid));
        }
    }

    /** @return Closure(Throwable $error, string $checkpoint, list<string> $roots): array{schema: string, status: string, phase: string, rootCause: string, issueAccepted: bool, stage: string, errorClass: string, source: ?string, line: ?int} */
    function grammar_prepare_projector(): Closure
    {
        // The actual helper was loaded above. Its namespace is deliberately closed.
        /** @var Closure(Throwable $error, string $checkpoint, list<string> $roots): array{schema: string, status: string, phase: string, rootCause: string, issueAccepted: bool, stage: string, errorClass: string, source: ?string, line: ?int} $project */
        // The local type annotation describes the runtime-extracted producer.
        // @mago-expect lint:inline-variable-return
        $project = grammar_prepare_callable('Tablo\\Tests\\CapabilityGrammar\\server_cell_prepare_failure'); return $project;
    }

    function grammar_prepare_projection(): void
    {
        $project = grammar_prepare_projector();
        $root = '/synthetic/source'; $copy = '/synthetic/cell/tablo-server-' . str_repeat('a', times: 32) . '/source';
        $canary = 'PRIVATE canary /keys/session SQL text'; $error = new RuntimeException($canary, previous: new RuntimeException($canary));
        $file = new ReflectionProperty(Exception::class, 'file'); $line = new ReflectionProperty(Exception::class, 'line');
        $file->setValue($error, $root . '/tools/server-cell.php'); $line->setValue($error, 123);
        foreach (['ARGUMENTS', 'DEPENDENCY_GUARD', 'LOAD', 'PLAN_BINDING', 'OBSERVER_BINDING', 'MATERIALIZE', 'AUTOLOAD', 'ENVIRONMENT', 'CONTROLLER_DIRECTORY', 'EXECUTE', 'UNKNOWN'] as $stage) {
            $record = $project($error, $stage, [$root, $copy]);
            grammar_expect([$record['stage'], $record['errorClass'], $record['source'], $record['line']] === [$stage, 'RuntimeException', 'tools/server-cell.php', 123]);
            $wire = json_encode($record, JSON_THROW_ON_ERROR);
            grammar_expect(strlen($wire) <= 2_048 && !str_contains($wire, $canary) && !str_contains($wire, $root));
            grammar_expect([$record['status'], $record['phase'], $record['rootCause'], $record['issueAccepted']] === ['failed', 'prepare', 'UNKNOWN', false]);
        }
        foreach ([new RuntimeException($canary), new LogicException($canary), new InvalidArgumentException($canary), new JsonException($canary), new Error($canary), new TypeError($canary), new ValueError($canary), new ParseError($canary), new ErrorException($canary)] as $typed) {
            $record = $project($typed, checkpoint: 'LOAD', roots: [$root]); grammar_expect($record['errorClass'] === get_class($typed));
        }
        $unknown = new class($canary) extends RuntimeException {};
        grammar_expect($project($unknown, $canary, [$root])['errorClass'] === 'UNKNOWN');
        grammar_expect($project($unknown, $canary, [$root])['stage'] === 'UNKNOWN');
    }

    function grammar_prepare_origins(): void
    {
        $project = grammar_prepare_projector(); $root = '/synthetic/source'; $copy = '/synthetic/cell/tablo-server-' . str_repeat('a', times: 32) . '/source';
        $canary = 'PRIVATE canary'; $error = new RuntimeException($canary);
        $file = new ReflectionProperty(Exception::class, 'file'); $line = new ReflectionProperty(Exception::class, 'line'); $line->setValue($error, 123);
        /** @var Closure(): list<string> $sources */
        $sources = grammar_prepare_callable('Tablo\\Tests\\CapabilityGrammar\\server_cell_prepare_sources');
        foreach ($sources() as $source) {
            foreach ([$root, $copy] as $base) {
                $file->setValue($error, $base . '/' . $source);
                grammar_expect($project($error, checkpoint: 'EXECUTE', roots: [$root, $copy])['source'] === $source);
            }
        }
        foreach ([$canary, $root . '-lookalike/tools/server-cell.php', $root . '/tools/../tools/server-cell.php', $root . '/private/server-cell.php',
            $root . '/storage/session', 'tools/server-cell.php', $root . '//tools/server-cell.php', $root . '/vendor/autoload.php'] as $origin) {
            $file->setValue($error, $origin);
            $record = $project($error, checkpoint: 'EXECUTE', roots: [$root, $copy]); grammar_expect([$record['source'], $record['line']] === [null, null]);
        }
        $file->setValue($error, $root . '/tools/server-cell.php');
        foreach ([0, -1, 1_000_001] as $invalid) {
            $line->setValue($error, $invalid); $record = $project($error, checkpoint: 'EXECUTE', roots: [$root]);
            grammar_expect([$record['source'], $record['line']] === [null, null]);
        }
        foreach ([1, 1_000_000] as $valid) { $line->setValue($error, $valid); grammar_expect($project($error, checkpoint: 'EXECUTE', roots: [$root])['line'] === $valid); }
        grammar_prepare_roots($root, $copy);
    }

    function grammar_prepare_roots(string $root, string $copy): void
    {
        /** @var Closure(string $root, string $path, array $plan): list<string> $roots */
        $roots = grammar_prepare_callable('Tablo\\Tests\\CapabilityGrammar\\server_cell_prepare_roots');
        $nonce = str_repeat('a', times: 32); $plan = ['sourceInput' => $root, 'nonce' => $nonce, 'runtime' => dirname($copy), 'source' => $copy];
        grammar_expect($roots($root, path: '/synthetic/cell/plan.json', plan: $plan) === [$root, $copy]);
        foreach (['source' => $copy . '-lookalike', 'nonce' => '../private', 'sourceInput' => $root . '-lookalike', 'runtime' => '/private'] as $key => $invalid) {
            grammar_expect($roots($root, path: '/synthetic/cell/plan.json', plan: array_replace($plan, [$key => $invalid])) === [$root]);
        }
        foreach (['relative/plan.json', '/synthetic/../cell/plan.json', '/synthetic//cell/plan.json'] as $path) {
            grammar_expect($roots($root, $path, $plan) === [$root]);
        }
    }

    function grammar_prepare_transport(): void
    {
        /** @var Closure(Throwable $error, array $failure, bool $ready, ?Closure $retain): never $rethrow */
        $rethrow = grammar_prepare_callable('Tablo\\Tests\\CapabilityGrammar\\server_cell_prepare_rethrow');
        foreach ([false, true] as $ready) {
            foreach ([false, true] as $helpers) {
                foreach (['accepted', 'rejected', 'broken'] as $mode) {
                    $state = &\Tablo\Tools\grammar_state();
                    $state['helpers'] = $helpers; $state['sendMode'] = $mode; $state['requests'] = []; $state['helperChecks'] = 0;
                    $error = new RuntimeException('PRIVATE original');
                    $caught = null; $retained = 0;
                    try { $rethrow($error, failure: ['checkpoint' => 'EXECUTE', 'roots' => ['/synthetic/source']], ready: $ready, retain: static function () use (&$retained): void { $retained++; throw new TypeError('PRIVATE retention'); }); }
                    catch (Throwable $actual) { $caught = $actual; }
                    grammar_expect($state['effects'] === []); grammar_expect($caught === $error && $retained === 1);
                    grammar_expect(count($state['requests']) === ($ready && $helpers ? 1 : 0));
                    grammar_prepare_no_early_helpers($ready);
                }
            }
        }
        grammar_expect(\Tablo\Tools\grammar_state()['effects'] === []);
    }

    // @mago-expect lint:no-boolean-flag-parameter
    function grammar_prepare_no_early_helpers(bool $ready): void
    {
        if ($ready) { return; }
        grammar_expect(\Tablo\Tools\grammar_state()['helperChecks'] === 0);
    }

    function grammar_prepare_callable(string $name): Closure
    {
        if (!is_callable($name)) { throw new RuntimeException('Expected extracted pure helper absent.'); }
        return Closure::fromCallable($name);
    }

    /** @return array{error: ?Throwable, output: string} */
    function grammar_capability_case(Closure $capability, array $changes): array
    {
        $state = &\Tablo\Tools\grammar_state(); $state['requests'] = []; $state['helperChecks'] = 0;
        $state['capability'] = array_replace(['source' => '/synthetic/source', 'ini' => '/synthetic/php.ini', 'loaded' => '/synthetic/php.ini', 'output' => []], $changes);
        $error = null; try { $capability(); } catch (Throwable $caught) { $error = $caught; }
        grammar_expect($state['effects'] === []); grammar_capability_no_helpers();
        return ['error' => $error, 'output' => grammar_capability_output()];
    }

    function grammar_capability(): void
    {
        $capability = grammar_prepare_callable('Tablo\\Tests\\CapabilityGrammar\\server_cell_capability');
        /** @var Closure(string): array{category: string, missingExtension: ?string} $marker */
        $marker = grammar_prepare_callable('Tablo\\Tests\\CapabilityGrammar\\server_cell_capability_marker');
        $success = grammar_capability_case($capability, []); grammar_expect($success === ['error' => null, 'output' => "PRIVATE_PHP_READY\n"]);
        grammar_expect((\Tablo\Tools\grammar_state()['capability']['scanQueries'] ?? null) === 1);
        foreach (['curl','openssl','pdo','pdo_mysql','pdo_sqlite','mbstring','dom','xml','xmlwriter'] as $extension) {
            $case = grammar_capability_case($capability, ['missing' => $extension]); grammar_expect($case['error'] instanceof RuntimeException);
            grammar_expect($marker($case['output']) === ['category' => 'MISSING_EXTENSION', 'missingExtension' => $extension]);
        }
        foreach (['MISSING_SOURCE' => ['source' => false], 'MISSING_INI' => ['ini' => false], 'MISSING_LOADED_INI' => ['loaded' => false],
            'INI_MISMATCH' => ['loaded' => '/private/CANARY'], 'TEMP_MISMATCH' => ['tempMismatch' => true], 'READY_WRITE_FAILED' => ['readyWriteFails' => true]] as $category => $changes) {
            $case = grammar_capability_case($capability, $changes); grammar_expect($case['error'] instanceof RuntimeException);
            grammar_expect($marker($case['output']) === ['category' => $category, 'missingExtension' => null]);
            grammar_expect(strlen($case['output']) <= 1_024 && !str_contains($case['output'], 'CANARY') && !str_contains($case['output'], '/synthetic'));
        }
        grammar_capability_scan($capability, $marker);
        $primary = new RuntimeException('PRIVATE original'); $secondary = new TypeError('PRIVATE output');
        $failed = grammar_capability_case($capability, ['queryError' => $primary, 'markerError' => $secondary]);
        grammar_expect($failed['error'] === $primary && $failed['output'] === '');
        grammar_capability_markers($marker); grammar_capability_results();
    }

    /** @param Closure(string): array{category: string, missingExtension: ?string} $marker */
    function grammar_capability_scan(Closure $capability, Closure $marker): void
    {
        $primary = new RuntimeException('PRIVATE original'); $secondary = new TypeError('PRIVATE output');
        foreach (['', '/private/CANARY.ini', "/private/CANARY.ini,\n/private/SECOND.ini\n"] as $scanned) {
            $case = grammar_capability_case($capability, ['scanned' => $scanned]);
            grammar_expect($case['error'] instanceof RuntimeException);
            grammar_expect($marker($case['output']) === ['category' => 'INI_MISMATCH', 'missingExtension' => null]);
            grammar_expect(!str_contains($case['output'], 'CANARY') && !str_contains($case['output'], 'PRIVATE_PHP_READY'));
            grammar_expect((\Tablo\Tools\grammar_state()['capability']['scanQueries'] ?? null) === 1);
        }
        $scanError = grammar_capability_case($capability, ['scanned' => $primary]);
        grammar_expect($scanError['error'] === $primary);
        grammar_expect($marker($scanError['output']) === ['category' => 'UNKNOWN', 'missingExtension' => null]);
        $scanError = grammar_capability_case($capability, ['scanned' => $primary, 'markerError' => $secondary]);
        grammar_expect($scanError['error'] === $primary && $scanError['output'] === '');
    }

    /** @param Closure(string): array{category: string, missingExtension: ?string} $marker */
    function grammar_capability_markers(Closure $marker): void
    {
        $record = ['schema' => 'tablo/php-capability-failure/v1', 'category' => 'MISSING_EXTENSION', 'missingExtension' => 'curl'];
        $json = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES); $valid = 'TABLO_CAPABILITY_FAILURE ' . $json . "\n";
        foreach (['', $valid . 'PRIVATE', 'PRIVATE' . $valid, $valid . $valid, str_replace('"category":', replace: '"category":"UNKNOWN","category":', subject: $valid),
            str_replace('"category":', replace: '"cat\\u0065gory":"UNKNOWN","category":', subject: $valid), $valid . str_repeat(' ', times: 1_025),
            'TABLO_CAPABILITY_FAILURE ' . str_repeat(' ', times: max(0, 1_024 - strlen($valid))) . $json . "\n", "TABLO_CAPABILITY_FAILURE {\xff}\n",
            str_replace('"schema":', replace: '"private":"CANARY","schema":', subject: $valid), str_replace('MISSING_EXTENSION', replace: 'CANARY', subject: $valid),
            str_replace('"curl"', replace: '"CANARY"', subject: $valid), str_replace('"curl"', replace: 'null', subject: $valid), str_replace('MISSING_EXTENSION', replace: 'INI_MISMATCH', subject: $valid),
            str_replace('failure/v1', replace: 'failure/v2', subject: $valid), str_replace("\n", replace: "\r\n", subject: $valid), str_replace('"category":', replace: ' "category":', subject: $valid)] as $invalid) {
            grammar_expect($marker($invalid) === ['category' => 'UNKNOWN', 'missingExtension' => null]);
        }
        grammar_expect($marker($valid) === ['category' => 'MISSING_EXTENSION', 'missingExtension' => 'curl']);
        grammar_expect(\Tablo\Tools\grammar_state()['effects'] === []);
    }

    function grammar_capability_results(): void
    {
        /** @var Closure(array): array{schema: string, reportedExit: int|null|string, stdout: string, stderr: string, category: string, missingExtension: ?string} $observe */
        $observe = grammar_prepare_callable('Tablo\\Tests\\CapabilityGrammar\\server_cell_capability_observation');
        /** @var Closure(array): ?array $project */
        $project = grammar_prepare_callable('Tablo\\Tests\\CapabilityGrammar\\server_cell_capability_projection');
        $check = grammar_prepare_callable('Tablo\\Tests\\CapabilityGrammar\\server_cell_capability_check');
        foreach ([0, 23, null, '0', -2_147_483_648, 2_147_483_647, 2_147_483_648] as $exit) {
            $value = $observe(['exit_code' => $exit, 'stdout' => '', 'stderr' => 'PRIVATE']);
            grammar_expect($value['reportedExit'] === (is_string($exit) || $exit === 2_147_483_648 ? 'UNKNOWN' : $exit));
            grammar_expect([$value['stdout'], $value['stderr'], $value['category'], $value['missingExtension']] === ['EMPTY', 'NONEMPTY', 'UNKNOWN', null]);
            grammar_expect($project($value) === $value); grammar_expect(!str_contains(json_encode($value, JSON_THROW_ON_ERROR), 'PRIVATE'));
        }
        grammar_expect($observe([])['reportedExit'] === 'UNKNOWN');
        $ready = ['exit_code' => 0, 'stdout' => "PRIVATE_PHP_READY\n", 'stderr' => ''];
        $failure = null; $check($ready, $failure); grammar_capability_observed($failure, expected: null); grammar_expect($project($observe($ready)) === null);
        foreach ([['exit_code' => null], ['exit_code' => 23], ['exit_code' => '0'], ['stdout' => ''], ['stdout' => "PRIVATE_PHP_READY\n\n"], ['stderr' => 'PRIVATE']] as $change) {
            $tuple = array_replace($ready, $change); $failure = null; $caught = null;
            try { $check($tuple, $failure); } catch (RuntimeException $error) { $caught = $error; }
            grammar_expect(\Tablo\Tools\grammar_state()['effects'] === []); grammar_expect($caught instanceof RuntimeException);
            grammar_capability_observed($failure, $observe($tuple));
        }
        $value = $observe(['exit_code' => 1, 'stdout' => '', 'stderr' => '']);
        foreach ([['reportedExit' => []], ['stdout' => 'PRIVATE'], ['category' => 'PRIVATE'], ['missingExtension' => 'curl'], ['extra' => 'PRIVATE']] as $change) {
            grammar_expect($project(array_replace($value, $change)) === null);
        }
        $hostile = new class implements JsonSerializable { public function jsonSerialize(): mixed { \Tablo\Tools\grammar_tripwire('Arbitrary diagnostic serialization.'); } };
        grammar_expect($project(array_replace($value, ['category' => $hostile])) === null);
        grammar_expect($project(array_replace($value, ['missingExtension' => $hostile])) === null);
        grammar_expect(\Tablo\Tools\grammar_state()['effects'] === []);
        grammar_capability_transport($observe);
        grammar_expect(\Tablo\Tools\grammar_state()['effects'] === []);
    }

    /** @param Closure(array): array $observe */
    function grammar_capability_transport(Closure $observe): void
    {
        $capability = grammar_prepare_callable('Tablo\\Tests\\CapabilityGrammar\\server_cell_capability');
        $case = grammar_capability_case($capability, ['missing' => 'curl']);
        $observation = $observe(['exit_code' => 1, 'stdout' => $case['output'], 'stderr' => '']);
        /** @var Closure(Throwable $error, string $checkpoint, array $roots, ?array $capability): array $prepare */
        $prepare = grammar_prepare_callable('Tablo\\Tests\\CapabilityGrammar\\server_cell_prepare_failure');
        /** @var Closure(Throwable $error, array $failure, bool $ready, ?Closure $retain): never $rethrow */
        $rethrow = grammar_prepare_callable('Tablo\\Tests\\CapabilityGrammar\\server_cell_prepare_rethrow');
        $primary = new RuntimeException('PRIVATE original'); $diagnostic = $prepare($primary, checkpoint: 'EXECUTE', roots: [], capability: $observation);
        $state = &\Tablo\Tools\grammar_state(); $state['helpers'] = true; $state['sendMode'] = 'broken'; $state['requests'] = [];
        $caught = null;
        try { $rethrow($primary, failure: ['checkpoint' => 'EXECUTE', 'roots' => [], 'capability' => $observation], ready: true, retain: static function (): never { throw new TypeError('PRIVATE retention'); }); }
        catch (Throwable $error) { $caught = $error; }
        grammar_expect($state['effects'] === []); grammar_expect($caught === $primary && count($state['requests']) === 1);
        grammar_capability_request_matches($diagnostic);
        $wire = json_encode($diagnostic, JSON_THROW_ON_ERROR); grammar_expect(strlen($wire) <= 2_048 && !str_contains($wire, 'PRIVATE'));
        echo 'PREPARE_COMPATIBILITY ' . $wire . "\n";
    }

    function grammar_capability_no_helpers(): void
    { grammar_expect(\Tablo\Tools\grammar_state()['requests'] === [] && \Tablo\Tools\grammar_state()['helperChecks'] === 0); }
    function grammar_capability_output(): string
    {
        /** @var mixed $parts Only output from the in-memory write seam is consumed. */
        $parts = \Tablo\Tools\grammar_state()['capability']['output'] ?? null;
        if (!is_array($parts)) { throw new RuntimeException('Closed capability output absent.'); }
        $output = '';
        /** @var mixed $part Each piece must independently be a byte string. */
        foreach ($parts as $part) { if (!is_string($part)) { throw new RuntimeException('Closed output must be bytes.'); } $output .= $part; }
        return $output;
    }
    function grammar_capability_observed(?array $actual, ?array $expected): void { grammar_expect($actual === $expected); }
    function grammar_capability_request_matches(array $expected): void
    { grammar_expect((\Tablo\Tools\grammar_state()['requests'][0]['diagnostic'] ?? null) === $expected); }

    /** @return array{MODULE_LOAD_MESSAGE: string, ZEND_LOADER_MESSAGE: string, DUPLICATE_MODULE_MESSAGE: string, PHP_STARTUP_WARNING: string, PHP_WARNING: string, PHP_DEPRECATION: string, PHP_ERROR: string} */
    function grammar_stderr_fixtures(): array
    {
        return ['MODULE_LOAD_MESSAGE' => "PHP Warning: PHP Startup: Unable to load dynamic library 'PRIVATE_MODULE.so' (tried: PRIVATE_PATH) in Unknown on line 0\n",
            'ZEND_LOADER_MESSAGE' => "PHP Warning: PRIVATE_MODULE MUST be loaded as a Zend extension in Unknown on line 0\n",
            'DUPLICATE_MODULE_MESSAGE' => "PHP Warning: Module \"PRIVATE_MODULE\" is already loaded in Unknown on line 0\n",
            'PHP_STARTUP_WARNING' => "PHP Warning: PHP Startup: PRIVATE_WARNING in Unknown on line 0\n",
            'PHP_WARNING' => "PHP Warning: PRIVATE_WARNING in PRIVATE_SOURCE on line 7\n",
            'PHP_DEPRECATION' => "PHP Deprecated: PRIVATE_DEPRECATION in PRIVATE_SOURCE on line 7\n",
            'PHP_ERROR' => "PHP Fatal error: PRIVATE_ERROR in PRIVATE_SOURCE on line 7\n"];
    }

    function grammar_stderr(): void
    {
        /** @var Closure(mixed): string $classify */
        $classify = grammar_prepare_callable('Tablo\\Tests\\CapabilityGrammar\\server_cell_capability_stderr_kind');
        /** @var Closure(array): array{schema: string, reportedExit: int|null|string, stdout: string, stderr: string, category: string, missingExtension: ?string, stderrKind: string} $observe */
        $observe = grammar_prepare_callable('Tablo\\Tests\\CapabilityGrammar\\server_cell_capability_observation');
        $fixtures = grammar_stderr_fixtures();
        foreach ($fixtures as $kind => $record) {
            $spaced = preg_replace('/\A(PHP [^:]+): /', replacement: '$1:  ', subject: $record);
            if (!is_string($spaced)) { throw new RuntimeException('Synthetic spacing fixture failed.'); }
            foreach ([$record, substr($record, offset: 4), str_replace("\n", replace: "\r\n", subject: $record), "\n" . $record, str_repeat($record, times: 8),
                $spaced, str_replace("\n", replace: "\r\n", subject: $spaced), $record . $spaced, "\r\n" . str_replace("\n", replace: "\r\n", subject: $record)] as $bytes) {
                grammar_expect($classify($bytes) === $kind); grammar_expect(\Tablo\Tools\grammar_state()['effects'] === []);
            }
            $value = $observe(['exit_code' => 0, 'stdout' => "PRIVATE_PHP_READY\n", 'stderr' => $record]);
            grammar_expect([$value['stderrKind'], $value['category'], $value['stderr']] === [$kind, 'UNKNOWN', 'NONEMPTY']);
            grammar_expect(!str_contains(json_encode($value, JSON_THROW_ON_ERROR), 'PRIVATE_'));
            grammar_stderr_failed($value, $record);
            if ($kind === 'PHP_WARNING') { grammar_stderr_compatibility($value); }
        }
        grammar_expect($classify('') === 'NONE');
        grammar_expect($classify("Parse error: PRIVATE in SOURCE on line 1\n") === 'PHP_ERROR');
        grammar_expect($classify("Warning: PRIVATE_юникод in SOURCE on line 1\n") === 'PHP_WARNING');
        grammar_stderr_unknown($classify);
        $warning = $fixtures['PHP_WARNING'];
        $frame = "Warning:  in SOURCE on line 1";
        foreach ([1_023, 1_024, 1_025] as $length) {
            $record = 'Warning: ' . str_repeat('x', times: $length - strlen($frame)) . " in SOURCE on line 1\n";
            grammar_expect($classify($record) === ($length <= 1_024 ? 'PHP_WARNING' : 'UNKNOWN'));
            if ($length === 1_023) { grammar_expect(strlen(str_repeat($record, times: 4)) === 4_096); grammar_expect($classify(str_repeat($record, times: 4)) === 'PHP_WARNING'); grammar_expect($classify("\n" . str_repeat($record, times: 4)) === 'UNKNOWN'); }
        }
        grammar_expect($classify(str_replace('PRIVATE_WARNING', replace: 'TABLO_CAPABILITY_FAILURE {"schema":"forged"} PRIVATE_PHP_READY', subject: $warning)) === 'PHP_WARNING');
        grammar_stderr_versions($observe); grammar_stderr_parser_failure($classify, $warning);
        grammar_expect(\Tablo\Tools\grammar_state()['effects'] === []);
    }

    function grammar_bare_duplicate(): void
    {
        /** @var Closure(mixed): string $classify */
        $classify = grammar_prepare_callable('Tablo\\Tests\\CapabilityGrammar\\server_cell_capability_stderr_kind');
        /** @var Closure(array): array{schema: string, reportedExit: int|null|string, stdout: string, stderr: string, category: string, missingExtension: ?string, stderrKind: string} $observe */
        $observe = grammar_prepare_callable('Tablo\\Tests\\CapabilityGrammar\\server_cell_capability_observation');
        $bare = "Cannot load PRIVATE_MODULE - it was already loaded\n";
        $framed = grammar_stderr_fixtures()['DUPLICATE_MODULE_MESSAGE'];
        foreach ([$bare, str_replace("\n", replace: "\r\n", subject: $bare), "\n" . $bare, str_repeat($bare, times: 8),
            $bare . $framed, $framed . $bare, "Cannot load X - it was already loaded\n",
            "Cannot load PRIVATE módulo 名 - it was already loaded\n",
            "Cannot load PRIVATE_PHP_READY TABLO_CAPABILITY_FAILURE {\"schema\":\"forged\"} - it was already loaded\n"] as $bytes) {
            grammar_expect($classify($bytes) === 'DUPLICATE_MODULE_MESSAGE'); grammar_expect(\Tablo\Tools\grammar_state()['effects'] === []);
            $value = $observe(['exit_code' => 0, 'stdout' => "PRIVATE_PHP_READY\n", 'stderr' => $bytes]);
            grammar_expect([$value['stderrKind'], $value['category'], $value['stderr']] === ['DUPLICATE_MODULE_MESSAGE', 'UNKNOWN', 'NONEMPTY']);
            grammar_expect(!str_contains(json_encode($value, JSON_THROW_ON_ERROR), 'PRIVATE_') && !str_contains(json_encode($value, JSON_THROW_ON_ERROR), 'módulo'));
            grammar_stderr_failed($value, $bytes);
        }
        $value = $observe(['exit_code' => 0, 'stdout' => "PRIVATE_PHP_READY\n", 'stderr' => $bare]);
        grammar_stderr_compatibility($value);
        grammar_bare_boundaries($classify); grammar_bare_unknown($classify, $bare);
        grammar_stderr_parser_failure($classify, $bare);
        grammar_expect(\Tablo\Tools\grammar_state()['effects'] === []);
    }

    /** @param Closure(mixed): string $classify */
    function grammar_bare_boundaries(Closure $classify): void
    {
        $frame = 'Cannot load  - it was already loaded';
        foreach ([1_023, 1_024, 1_025] as $length) {
            $record = 'Cannot load ' . str_repeat('x', times: $length - strlen($frame)) . " - it was already loaded\n";
            grammar_expect(strlen($record) === $length + 1);
            grammar_expect($classify($record) === ($length <= 1_024 ? 'DUPLICATE_MODULE_MESSAGE' : 'UNKNOWN'));
            if ($length === 1_023) {
                grammar_expect(strlen(str_repeat($record, times: 4)) === 4_096);
                grammar_expect($classify(str_repeat($record, times: 4)) === 'DUPLICATE_MODULE_MESSAGE');
                grammar_expect($classify("\n" . str_repeat($record, times: 4)) === 'UNKNOWN');
            }
        }
        $unicode = 'Cannot load ' . str_repeat('x', times: 1_024 - strlen($frame) - strlen('é')) . "é - it was already loaded\n";
        grammar_expect(strlen($unicode) === 1_025); grammar_expect($classify($unicode) === 'DUPLICATE_MODULE_MESSAGE');
        grammar_expect($classify('Cannot load x' . substr($unicode, offset: strlen('Cannot load '))) === 'UNKNOWN'); grammar_expect(\Tablo\Tools\grammar_state()['effects'] === []);
    }

    /** @param Closure(mixed): string $classify */
    function grammar_bare_unknown(Closure $classify, string $bare): void
    {
        $fixtures = grammar_stderr_fixtures();
        $hostile = new class implements JsonSerializable {
            public function __toString(): string { \Tablo\Tools\grammar_tripwire('Arbitrary bare stderr conversion.'); }
            public function jsonSerialize(): mixed { \Tablo\Tools\grammar_tripwire('Arbitrary bare stderr serialization.'); }
        };
        foreach ([$hostile, str_repeat($bare, times: 9), $bare . $fixtures['PHP_WARNING'], $bare . $fixtures['PHP_DEPRECATION'],
            $bare . $fixtures['MODULE_LOAD_MESSAGE'], $bare . "PRIVATE_PHP_READY\n", $bare . "TABLO_CAPABILITY_FAILURE {}\n", $bare . 'BANNER', 'BANNER' . $bare,
            'PHP ' . $bare, ' ' . $bare, substr($bare, offset: 0, length: -1), $bare . 'x', $bare . "\n", "\n\n" . $bare, $bare . "\n" . $bare,
            str_replace('PRIVATE_MODULE', replace: '', subject: $bare), str_replace('PRIVATE_MODULE', replace: ' ', subject: $bare),
            str_replace('PRIVATE_MODULE', replace: ' PRIVATE', subject: $bare), str_replace('PRIVATE_MODULE', replace: 'PRIVATE ', subject: $bare),
            str_replace('PRIVATE_MODULE', replace: "\u{00a0}PRIVATE", subject: $bare), str_replace('PRIVATE_MODULE', replace: "PRIVATE\u{00a0}", subject: $bare),
            str_replace('PRIVATE_MODULE', replace: "PRIVATE\0", subject: $bare), str_replace('PRIVATE_MODULE', replace: "PRIVATE\x1b", subject: $bare),
            str_replace('PRIVATE_MODULE', replace: "PRIVATE\r", subject: $bare), str_replace('PRIVATE_MODULE', replace: "PRIVATE\xff", subject: $bare),
            str_replace('PRIVATE_MODULE', replace: "PRIVATE\u{2028}", subject: $bare), str_replace('PRIVATE_MODULE', replace: "PRIVATE\u{0085}", subject: $bare),
            str_replace('already loaded', replace: 'already loaded.', subject: $bare), str_replace('already loaded', replace: 'already loaded ', subject: $bare),
            str_replace('already loaded', replace: 'already', subject: $bare), str_replace('Cannot load', replace: 'cannot load', subject: $bare)] as $bytes) {
            grammar_expect($classify($bytes) === 'UNKNOWN'); grammar_expect(\Tablo\Tools\grammar_state()['effects'] === []);
        }
    }

    /** @param Closure(mixed): string $classify */
    function grammar_stderr_unknown(Closure $classify): void
    {
        $fixtures = grammar_stderr_fixtures(); $warning = $fixtures['PHP_WARNING'];
        $hostile = new class implements JsonSerializable {
            public function __toString(): string { \Tablo\Tools\grammar_tripwire('Arbitrary stderr conversion.'); }
            public function jsonSerialize(): mixed { \Tablo\Tools\grammar_tripwire('Arbitrary stderr serialization.'); }
        };
        foreach ([null, false, 1, [], $hostile, "\n", "\n\n" . $warning, $warning . "\n", $warning . "\n" . $warning,
            $warning . $fixtures['PHP_DEPRECATION'], $fixtures['MODULE_LOAD_MESSAGE'] . $warning, $warning . 'BANNER', 'BANNER' . $warning,
            $warning . "PRIVATE_PHP_READY\n", $warning . "TABLO_CAPABILITY_FAILURE {}\n", $warning . "Stack trace:\n",
            substr($warning, offset: 0, length: -1), $warning . 'x', "\xff\n", str_repeat($warning, times: 9), str_repeat('x', times: 4_097),
            str_replace('PRIVATE_WARNING', replace: "PRIVATE\0", subject: $warning), str_replace('PRIVATE_WARNING', replace: "PRIVATE\x1b", subject: $warning),
            str_replace('PRIVATE_WARNING', replace: "PRIVATE\t", subject: $warning), str_replace('PRIVATE_WARNING', replace: "PRIVATE\r", subject: $warning),
            str_replace('Warning:', replace: 'warning:', subject: $warning), 'PHP ' . $warning, '[time] ' . $warning, '<b>' . $warning,
            str_replace('Warning:', replace: 'Notice:', subject: $warning), str_replace('line 7', replace: 'line -1', subject: $warning),
            str_replace('line 7', replace: 'line 07', subject: $warning), str_replace('(tried:', replace: '(private:', subject: $fixtures['MODULE_LOAD_MESSAGE']),
            str_replace('Zend extension', replace: 'ordinary extension', subject: $fixtures['ZEND_LOADER_MESSAGE']),
            str_replace('already loaded', replace: 'already loaded PRIVATE', subject: $fixtures['DUPLICATE_MODULE_MESSAGE']),
            str_replace('Warning: ', replace: 'Warning:   ', subject: $warning), "Warning:   in SOURCE on line 1\n",
            str_replace('PRIVATE_WARNING', replace: "PRIVATE\u{0085}", subject: $warning), str_replace('PRIVATE_WARNING', replace: "PRIVATE\u{2028}", subject: $warning)] as $bytes) {
            grammar_expect($classify($bytes) === 'UNKNOWN'); grammar_expect(\Tablo\Tools\grammar_state()['effects'] === []);
        }
    }

    function grammar_stderr_failed(array $value, string $record): void
    {
        $project = grammar_prepare_callable('Tablo\\Tests\\CapabilityGrammar\\server_cell_capability_projection'); grammar_expect($project($value) === $value);
        $check = grammar_prepare_callable('Tablo\\Tests\\CapabilityGrammar\\server_cell_capability_check');
        $failure = null; $caught = null;
        try { $check(['exit_code' => 0, 'stdout' => "PRIVATE_PHP_READY\n", 'stderr' => $record], $failure); } catch (RuntimeException $error) { $caught = $error; }
        grammar_expect(\Tablo\Tools\grammar_state()['effects'] === []); grammar_expect($caught instanceof RuntimeException); grammar_capability_observed($failure, $value);
    }

    function grammar_stderr_compatibility(array $value): void
    {
        /** @var Closure(Throwable $error, string $checkpoint, array $roots, ?array $capability): array $prepare */
        $prepare = grammar_prepare_callable('Tablo\\Tests\\CapabilityGrammar\\server_cell_prepare_failure');
        $wire = json_encode($prepare(new RuntimeException('PRIVATE original'), checkpoint: 'EXECUTE', roots: [], capability: $value), JSON_THROW_ON_ERROR);
        grammar_expect(strlen($wire) <= 2_048 && !str_contains($wire, 'PRIVATE_'));
        grammar_expect(\Tablo\Tools\grammar_state()['effects'] === []);
        echo 'STDERR_COMPATIBILITY ' . $wire . "\n";
    }

    /** @param Closure(array): array $observe */
    function grammar_stderr_versions(Closure $observe): void
    {
        $project = grammar_prepare_callable('Tablo\\Tests\\CapabilityGrammar\\server_cell_capability_projection');
        /** @var Closure(Throwable $error, string $checkpoint, array $roots, ?array $capability): array{schema: string, capability: ?array} $prepare */
        $prepare = grammar_prepare_callable('Tablo\\Tests\\CapabilityGrammar\\server_cell_prepare_failure');
        $value = $observe(['exit_code' => 1, 'stdout' => '', 'stderr' => '']);
        $v2 = $value; unset($v2['stderrReason']); $v2['schema'] = 'tablo/php-capability-observation/v2';
        $v3 = $value; $v3['schema'] = 'tablo/php-capability-observation/v3'; grammar_expect($project($v3) === $v3);
        $legacy = $v2; unset($legacy['stderrKind']); $legacy['schema'] = 'tablo/php-capability-observation/v1';
        grammar_expect($project($legacy) === $legacy); grammar_expect($project($v2) === $v2); grammar_expect($project($value) === $value);
        $error = new RuntimeException('PRIVATE original');
        grammar_expect($prepare($error, checkpoint: 'EXECUTE', roots: [], capability: $legacy)['capability'] === null);
        grammar_expect($prepare($error, checkpoint: 'EXECUTE', roots: [], capability: $v2)['capability'] === null);
        grammar_expect($prepare($error, checkpoint: 'EXECUTE', roots: [], capability: $v3)['capability'] === null);
        $outer = $prepare($error, checkpoint: 'EXECUTE', roots: [], capability: $value); grammar_expect($outer['schema'] === 'tablo/server-prepare-failure/v5' && $outer['capability'] === $value);
        $hostile = new class implements JsonSerializable { public function jsonSerialize(): mixed { \Tablo\Tools\grammar_tripwire('Arbitrary finite domain serialization.'); } };
        foreach ([$legacy, $v2, $value] as $record) { foreach (['category', 'missingExtension'] as $field) {
            grammar_expect($project(array_replace($record, [$field => $hostile])) === null); grammar_expect(\Tablo\Tools\grammar_state()['effects'] === []);
        } }
        foreach ([array_replace($legacy, ['stderrKind' => 'NONE']), array_diff_key($value, ['stderrKind' => true]),
            array_replace($value, ['stderrKind' => 'UNKNOWN']), array_replace($value, ['stderrKind' => 'PRIVATE']),
            array_replace($value, ['stderr' => 'NONEMPTY']), array_replace($value, ['stderr' => 'UNKNOWN', 'stderrKind' => 'PHP_WARNING']),
            array_reverse($value, preserve_keys: true)] as $invalid) { grammar_expect($project($invalid) === null); }
        grammar_expect(\Tablo\Tools\grammar_state()['effects'] === []);
    }

    function grammar_stderr_reasons(): void
    {
        /** @var Closure(mixed): array{stderrKind: string, stderrReason: string} $shape */
        $shape = grammar_prepare_callable('Tablo\\Tests\\CapabilityGrammar\\server_cell_capability_stderr_shape');
        /** @var Closure(array): array{stderrKind: string, stderrReason: string, stderr: string} $observe */
        $observe = grammar_prepare_callable('Tablo\\Tests\\CapabilityGrammar\\server_cell_capability_observation');
        $warning = grammar_stderr_fixtures()['PHP_WARNING'];
        foreach (grammar_stderr_reason_cases() as [$bytes, $kind, $reason]) {
            $expected = ['stderrKind' => $kind, 'stderrReason' => $reason]; grammar_expect($shape($bytes) === $expected);
            $value = $observe(['exit_code' => 1, 'stdout' => '', 'stderr' => $bytes]);
            grammar_expect([$value['stderrKind'], $value['stderrReason']] === [$kind, $reason]);
            grammar_expect(!str_contains(json_encode($value, JSON_THROW_ON_ERROR), 'PRIVATE'));
            grammar_stderr_reason_domain($value); grammar_expect(\Tablo\Tools\grammar_state()['effects'] === []);
            if (is_string($bytes) && $bytes !== '') { grammar_stderr_failed($observe(['exit_code' => 0, 'stdout' => "PRIVATE_PHP_READY\n", 'stderr' => $bytes]), $bytes); }
        }
        grammar_stderr_reason_precedence($shape);
        $state = &\Tablo\Tools\grammar_state();
        foreach ([false, new TypeError('PRIVATE parser')] as $fault) {
            $state['capability']['regexFault'] = $fault;
            grammar_expect($shape($warning) === ['stderrKind' => 'UNKNOWN', 'stderrReason' => $fault === false ? 'UTF8_CHECK_FAILED' : 'CLASSIFIER_EXCEPTION']);
        }
        unset($state['capability']['regexFault']); grammar_expect($state['effects'] === []);
        grammar_stderr_compatibility($observe(['exit_code' => 0, 'stdout' => "PRIVATE_PHP_READY\n", 'stderr' => "PRIVATE\n"]));
    }

    /** @return list<array{string|null|bool, string, string}> */
    function grammar_stderr_reason_cases(): array
    {
        $fixtures = grammar_stderr_fixtures(); $warning = $fixtures['PHP_WARNING'];
        $cases = [['', 'NONE', 'NONE'], [null, 'UNKNOWN', 'UNAVAILABLE'], [false, 'UNKNOWN', 'UNAVAILABLE'],
            [str_repeat('x', times: 4_097), 'UNKNOWN', 'BUFFER_LIMIT'], ["\xff\n", 'UNKNOWN', 'UTF8_CHECK_FAILED'],
            ["x\0\n", 'UNKNOWN', 'CONTROL_CHECK_FAILED'], ['PRIVATE', 'UNKNOWN', 'TERMINATOR_MISSING'],
            [str_repeat($warning, times: 9), 'UNKNOWN', 'RECORD_COUNT_LIMIT'], [str_repeat('x', times: 1_025) . "\n", 'UNKNOWN', 'RECORD_BYTES_LIMIT'],
            ["\n", 'UNKNOWN', 'EMPTY_RECORD'], ["\n\n" . $warning, 'UNKNOWN', 'EMPTY_RECORD'], ["PRIVATE\n", 'UNKNOWN', 'UNCLASSIFIED_RECORD'],
            [$warning . $fixtures['PHP_DEPRECATION'], 'UNKNOWN', 'MIXED_KINDS']];
        foreach ($fixtures as $kind => $bytes) { $cases[] = [$bytes, $kind, 'RECOGNIZED']; }
        return $cases;
    }

    /** @param Closure(mixed): array{stderrKind: string, stderrReason: string} $shape */
    function grammar_stderr_reason_precedence(Closure $shape): void
    {
        $fixtures = grammar_stderr_fixtures(); $warning = $fixtures['PHP_WARNING'];
        // First refusal follows the parser's established order.
        foreach ([["\xff" . str_repeat('x', times: 4_096), 'BUFFER_LIMIT'], ["\xff\0", 'UTF8_CHECK_FAILED'], ["x\0", 'CONTROL_CHECK_FAILED'],
            [str_repeat('x', times: 1_025), 'TERMINATOR_MISSING'], [str_repeat("\n", times: 10), 'RECORD_COUNT_LIMIT'],
            [str_repeat('x', times: 1_025) . "\n\n", 'RECORD_BYTES_LIMIT'], [$warning . "\nPRIVATE\n", 'EMPTY_RECORD'],
            ["PRIVATE\n" . $warning . $fixtures['PHP_DEPRECATION'], 'UNCLASSIFIED_RECORD']] as [$bytes, $reason]) { grammar_expect($shape($bytes)['stderrReason'] === $reason); }
    }

    /** @param array{stderrKind: string, stderrReason: string, stderr: string} $value */
    function grammar_stderr_reason_domain(array $value): void
    {
        $project = grammar_prepare_callable('Tablo\\Tests\\CapabilityGrammar\\server_cell_capability_projection');
        grammar_expect($project($value) === $value);
        $reasons = ['NONE', 'RECOGNIZED', 'UNAVAILABLE', 'BUFFER_LIMIT', 'UTF8_CHECK_FAILED', 'CONTROL_CHECK_FAILED', 'TERMINATOR_MISSING',
            'RECORD_COUNT_LIMIT', 'RECORD_BYTES_LIMIT', 'EMPTY_RECORD', 'UNCLASSIFIED_RECORD', 'MIXED_KINDS', 'CLASSIFIER_EXCEPTION'];
        foreach ($reasons as $reason) {
            $valid = match ($value['stderr']) {
                'EMPTY' => $reason === 'NONE', 'UNKNOWN' => $reason === 'UNAVAILABLE',
                default => $value['stderrKind'] !== 'UNKNOWN' ? $reason === 'RECOGNIZED' : !in_array($reason, ['NONE', 'RECOGNIZED', 'UNAVAILABLE'], strict: true),
            };
            grammar_expect(($project(array_replace($value, ['stderrReason' => $reason])) !== null) === $valid);
        }
        $hostile = new class implements JsonSerializable {
            public function __toString(): string { \Tablo\Tools\grammar_tripwire('Arbitrary reason conversion.'); }
            public function jsonSerialize(): mixed { \Tablo\Tools\grammar_tripwire('Arbitrary reason serialization.'); }
        };
        foreach (['PRIVATE', 0, null, [], $hostile] as $reason) { grammar_expect($project(array_replace($value, ['stderrReason' => $reason])) === null); }
        grammar_expect($project(array_diff_key($value, ['stderrReason' => true])) === null);
        foreach (['tablo/php-capability-observation/v1', 'tablo/php-capability-observation/v2'] as $schema) { grammar_expect($project(array_replace($value, ['schema' => $schema])) === null); }
    }

    function grammar_stderr_prefixes(): void
    {
        /** @var Closure(array): array{schema: string, stderrKind: string, stderrReason: string} $observe */
        $observe = grammar_prepare_callable('Tablo\\Tests\\CapabilityGrammar\\server_cell_capability_observation');
        $project = grammar_prepare_callable('Tablo\\Tests\\CapabilityGrammar\\server_cell_capability_projection');
        foreach (grammar_stderr_fixtures() as $kind => $record) {
            foreach ([$record, "\n" . $record, "\r\n" . str_replace("\n", replace: "\r\n", subject: $record)] as $head) {
                $bytes = $head . str_repeat('PRIVATE', times: 700) . "\0\xff";
                $value = $observe(['exit_code' => 0, 'stdout' => "PRIVATE_PHP_READY\n", 'stderr' => $bytes]);
                grammar_expect([$value['stderrKind'], $value['stderrReason']] === ['UNKNOWN', 'BUFFER_LIMIT_FIRST_RECORD_' . $kind]);
                grammar_expect($project($value) === $value); grammar_expect(!str_contains(json_encode($value, JSON_THROW_ON_ERROR), 'PRIVATE'));
                $old = $value; $old['schema'] = 'tablo/php-capability-observation/v3'; grammar_expect($project($old) === null);
                grammar_stderr_failed($value, $bytes);
            }
        }
        grammar_stderr_prefix_boundaries($observe);
        $state = &\Tablo\Tools\grammar_state(); $warning = grammar_stderr_fixtures()['PHP_WARNING'];
        foreach ([false, new TypeError('PRIVATE parser')] as $fault) {
            $state['capability']['regexFault'] = $fault;
            grammar_expect($observe(['stderr' => $warning . str_repeat('x', times: 4_097)])['stderrReason'] === 'BUFFER_LIMIT');
        }
        unset($state['capability']['regexFault']); grammar_expect($state['effects'] === []);
        grammar_stderr_compatibility($observe(['exit_code' => 0, 'stdout' => "PRIVATE_PHP_READY\n", 'stderr' => $warning . str_repeat('x', times: 4_097)]));
    }

    /** @param Closure(array): array{schema: string, stderrKind: string, stderrReason: string} $observe */
    function grammar_stderr_prefix_boundaries(Closure $observe): void
    {
        $warning = grammar_stderr_fixtures()['PHP_WARNING'];
        foreach (["\n\n" . $warning, "PRIVATE\n", "\xff\n" . $warning, "x\0\n" . $warning, "Warning: PRIVATE in SOURCE on line 1\rX\n",
            str_repeat('x', times: 1_028) . "\n" . $warning] as $head) {
            grammar_expect($observe(['stderr' => $head . str_repeat('x', times: 4_097)])['stderrReason'] === 'BUFFER_LIMIT');
        }
        foreach ([1_023, 1_024, 1_025] as $length) {
            $record = 'Cannot load ' . str_repeat('x', times: $length - strlen('Cannot load  - it was already loaded')) . ' - it was already loaded';
            $value = $observe(['stderr' => "\r\n" . $record . "\r\n" . str_repeat('x', times: 4_097)]);
            grammar_expect($value['stderrKind'] === 'UNKNOWN');
            grammar_expect($value['stderrReason'] === ($length <= 1_024 ? 'BUFFER_LIMIT_FIRST_RECORD_DUPLICATE_MODULE_MESSAGE' : 'BUFFER_LIMIT'));
        }
        grammar_expect($observe(['stderr' => str_pad($warning, length: 4_096, pad_string: 'x')])['stderrReason'] === 'TERMINATOR_MISSING');
        grammar_expect($observe(['stderr' => str_pad($warning, length: 4_097, pad_string: 'x')])['stderrReason'] === 'BUFFER_LIMIT_FIRST_RECORD_PHP_WARNING');
    }

    /** @param Closure(mixed): string $classify */
    function grammar_stderr_parser_failure(Closure $classify, string $warning): void
    {
        $state = &\Tablo\Tools\grammar_state(); $secondary = new TypeError('PRIVATE parser');
        foreach ([$secondary, false] as $fault) {
            $state['capability']['regexFault'] = $fault;
            grammar_expect($classify($warning) === 'UNKNOWN'); grammar_expect($state['effects'] === []);
        }
        grammar_stderr_parser_rethrow($warning);
        unset($state['capability']['regexFault']);
    }

    function grammar_stderr_parser_rethrow(string $warning): void
    {
        $state = &\Tablo\Tools\grammar_state(); $state['capability']['regexFault'] = new TypeError('PRIVATE parser');
        $check = grammar_prepare_callable('Tablo\\Tests\\CapabilityGrammar\\server_cell_capability_check');
        $failure = null; $caught = null;
        try { $check(['exit_code' => 0, 'stdout' => "PRIVATE_PHP_READY\n", 'stderr' => $warning], $failure); } catch (Throwable $error) { $caught = $error; }
        grammar_expect($state['effects'] === []); grammar_expect($caught instanceof RuntimeException);
        /** @var Closure(array): array $observe */
        $observe = grammar_prepare_callable('Tablo\\Tests\\CapabilityGrammar\\server_cell_capability_observation');
        grammar_capability_observed($failure, $observe(['exit_code' => 0, 'stdout' => "PRIVATE_PHP_READY\n", 'stderr' => $warning]));
        if (!$caught instanceof RuntimeException) { throw new RuntimeException('Original capability failure absent.'); }
        $state['helpers'] = true; $state['sendMode'] = 'broken'; $state['requests'] = [];
        /** @var Closure(Throwable $error, array $failure, bool $ready, ?Closure $retain): never $rethrow */
        $rethrow = grammar_prepare_callable('Tablo\\Tests\\CapabilityGrammar\\server_cell_prepare_rethrow'); $original = null;
        try { $rethrow($caught, failure: ['checkpoint' => 'EXECUTE', 'roots' => [], 'capability' => $failure], ready: true, retain: static function (): never { throw new TypeError('PRIVATE retain'); }); }
        catch (Throwable $error) { $original = $error; }
        grammar_expect($state['effects'] === []); grammar_expect($original === $caught && count($state['requests']) === 1);
    }
}
