<?php
declare(strict_types=1);

// Pure actual-function grammar tests. No autoload or product fixture bootstrap.
namespace Tablo\Tools {
    /** @return array{inputs: array<string, string>, reads: list<string>, effects: list<string>, checks: int} */
    function &grammar_state(): array
    {
        static $state = ['inputs' => [], 'reads' => [], 'effects' => [], 'checks' => 0];
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

namespace {
    require __DIR__ . '/../app/JsonRecord.php';
    require __DIR__ . '/../app/SqlColumn.php';
    require __DIR__ . '/../app/RequiredFile.php';
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
    // The only reads above were the two explicit in-memory receipt strings;
    // none of their reference paths can be opened by this isolated harness.
    echo 'IO_URING_PHP_GRAMMAR_OK ' . \Tablo\Tools\grammar_state()['checks'] . "\n";
}
