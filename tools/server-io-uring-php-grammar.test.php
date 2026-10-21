<?php
declare(strict_types=1);

// Pure actual-function grammar tests. No autoload or product fixture bootstrap.
namespace Tablo\Tools {
    /** @return array{inputs: array<string, string>, reads: list<string>, effects: list<string>, checks: int, helpers: bool, helperChecks: int, requests: list<array>, sendMode: string} */
    function &grammar_state(): array
    {
        static $state = ['inputs' => [], 'reads' => [], 'effects' => [], 'checks' => 0,
            'helpers' => true, 'helperChecks' => 0, 'requests' => [], 'sendMode' => 'accepted'];
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
        $bytes = file_get_contents(__DIR__ . '/server-cell.php');
        if (!is_string($bytes)) { throw new RuntimeException('Required public producer source absent.'); }
        $tokens = token_get_all($bytes); $selected = []; $count = count($tokens);
        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index] ?? null;
            if (!is_array($token) || $token[0] !== T_FUNCTION) { continue; }
            $name = $tokens[$index + 2] ?? null;
            if (!is_array($name) || !in_array($name[1], ['server_cell_prepare_roots', 'server_cell_prepare_sources', 'server_cell_prepare_failure', 'server_cell_prepare_report', 'server_cell_prepare_rethrow'], strict: true)) { continue; }
            $body = ''; $depth = 0; $opened = false;
            for (; $index < $count; $index++) {
                $current = $tokens[$index] ?? ''; $body .= is_array($current) ? $current[1] : $current;
                if ($current === '{') { $depth++; $opened = true; }
                if ($current === '}' && --$depth === 0 && $opened) { break; }
            }
            $selected[] = $body;
        }
        grammar_expect(count($selected) === 5);
        // Only these five inspected public helper bodies enter an isolated namespace;
        // no include, autoload, cell entry or source transformation is executed.
        // @mago-expect lint:no-eval
        eval('namespace Tablo\\Tools; use Tablo\\RequiredFile; use Tablo\\JsonRecord; use Tablo\\SqlColumn; use Throwable; use RuntimeException; use Closure;' . implode("\n", $selected));
        grammar_prepare_projection(); grammar_prepare_origins(); grammar_prepare_transport();
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
        /** @var Closure(Throwable $error, string $checkpoint, list<string> $roots, bool $ready, ?Closure $retain): never $rethrow */
        $rethrow = grammar_prepare_callable('Tablo\\Tools\\server_cell_prepare_rethrow');
        foreach ([false, true] as $ready) {
            foreach ([false, true] as $helpers) {
                foreach (['accepted', 'rejected', 'broken'] as $mode) {
                    $state = &\Tablo\Tools\grammar_state();
                    $state['helpers'] = $helpers; $state['sendMode'] = $mode; $state['requests'] = []; $state['helperChecks'] = 0;
                    $error = new RuntimeException('PRIVATE original');
                    $caught = null; $retained = 0;
                    try { $rethrow($error, checkpoint: 'EXECUTE', roots: ['/synthetic/source'], ready: $ready, retain: static function () use (&$retained): void { $retained++; throw new TypeError('PRIVATE retention'); }); }
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
}
