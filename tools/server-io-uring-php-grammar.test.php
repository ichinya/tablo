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
    function sys_get_temp_dir(): string { return '/synthetic/' . ((grammar_state()['capability']['tempMismatch'] ?? false) === true ? 'different' : 'tmp'); }
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
    $build = ['schema' => 'tablo/linux-adapter-build/v1', 'policy' => 'tgid-seccomp-ptrace-x64-v2', 'target' => 'linux-x86_64',
        'sourceSha256' => $hash, 'recipeSha256' => $hash, 'binarySha256' => $hash, 'compilerImageDigest' => 'sha256:' . $hash,
        'compilerSha256' => $hash, 'linkerSha256' => $hash, 'libcArchiveSha256' => $hash];
    $qualified = ['schema' => 'tablo/linux-adapter-qualification/v3', 'policy' => 'tgid-seccomp-ptrace-x64-v2', 'status' => 'qualified',
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
    foreach (['"policy":"old",', '"pol\\u0069cy":"tgid-seccomp-ptrace-x64-v2",'] as $duplicate) {
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
    grammar_prepare_failures();
    // The only reads above were the two explicit in-memory receipt strings;
    // none of their reference paths can be opened by this isolated harness.
    echo 'IO_URING_PHP_GRAMMAR_OK ' . \Tablo\Tools\grammar_state()['checks'] . "\n";

    /** Extract only the actual pure producer helpers, never cell main or its dependencies. */
    function grammar_prepare_failures(): void
    {
        $bytes = file_get_contents(__DIR__ . '/server-cell.php', length: 262_145);
        if (!is_string($bytes)) { throw new RuntimeException('Required public producer source absent.'); }
        $selected = grammar_prepare_functions($bytes);
        grammar_expect(count($selected) === 11);
        // Only these inspected public helper bodies enter an isolated namespace;
        // no include, autoload, cell entry or source transformation is executed.
        // @mago-expect lint:no-eval
        eval('namespace Tablo\\Tools; use Tablo\\RequiredFile; use Tablo\\JsonRecord; use Tablo\\SqlColumn; use Throwable; use RuntimeException; use Closure;' . implode("\n", $selected));
        grammar_expect(!function_exists('Tablo\\Tools\\server_cell_prepare_client'));
        grammar_prepare_extraction($bytes);
        grammar_prepare_projection(); grammar_prepare_origins(); grammar_prepare_transport(); grammar_capability();
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
            'server_cell_capability', 'server_cell_capability_marker', 'server_cell_capability_observation', 'server_cell_capability_exit', 'server_cell_capability_projection', 'server_cell_capability_check'] as $name) {
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
        $project = grammar_prepare_callable('Tablo\\Tools\\server_cell_prepare_failure'); return $project;
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
        $sources = grammar_prepare_callable('Tablo\\Tools\\server_cell_prepare_sources');
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
        $roots = grammar_prepare_callable('Tablo\\Tools\\server_cell_prepare_roots');
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
        $rethrow = grammar_prepare_callable('Tablo\\Tools\\server_cell_prepare_rethrow');
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
        $capability = grammar_prepare_callable('Tablo\\Tools\\server_cell_capability');
        /** @var Closure(string): array{category: string, missingExtension: ?string} $marker */
        $marker = grammar_prepare_callable('Tablo\\Tools\\server_cell_capability_marker');
        $success = grammar_capability_case($capability, []); grammar_expect($success === ['error' => null, 'output' => "PRIVATE_PHP_READY\n"]);
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
        $primary = new RuntimeException('PRIVATE original'); $secondary = new TypeError('PRIVATE output');
        $failed = grammar_capability_case($capability, ['queryError' => $primary, 'markerError' => $secondary]);
        grammar_expect($failed['error'] === $primary && $failed['output'] === '');
        grammar_capability_markers($marker); grammar_capability_results();
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
        $observe = grammar_prepare_callable('Tablo\\Tools\\server_cell_capability_observation');
        /** @var Closure(array): ?array $project */
        $project = grammar_prepare_callable('Tablo\\Tools\\server_cell_capability_projection');
        $check = grammar_prepare_callable('Tablo\\Tools\\server_cell_capability_check');
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
        grammar_capability_transport($observe);
        grammar_expect(\Tablo\Tools\grammar_state()['effects'] === []);
    }

    /** @param Closure(array): array $observe */
    function grammar_capability_transport(Closure $observe): void
    {
        $capability = grammar_prepare_callable('Tablo\\Tools\\server_cell_capability');
        $case = grammar_capability_case($capability, ['missing' => 'curl']);
        $observation = $observe(['exit_code' => 1, 'stdout' => $case['output'], 'stderr' => '']);
        /** @var Closure(Throwable $error, string $checkpoint, array $roots, ?array $capability): array $prepare */
        $prepare = grammar_prepare_callable('Tablo\\Tools\\server_cell_prepare_failure');
        /** @var Closure(Throwable $error, array $failure, bool $ready, ?Closure $retain): never $rethrow */
        $rethrow = grammar_prepare_callable('Tablo\\Tools\\server_cell_prepare_rethrow');
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
}
