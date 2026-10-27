<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use RuntimeException;

final class PasswordHelperEvidenceStart
{
    /** @return list<string> */
    public static function componentCommand(string $shell, string $control, TemporaryDirectory $directory, string $journal, string $caseId): array
    {
        $fixture = dirname(__DIR__) . '/fixtures/password-helper-observer-control.ps1';
        $argv = [$shell, '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $fixture,
            '-Control', $control, '-Journal', $journal, '-CaseId', $caseId];
        PasswordHelperEvidenceFiles::record($directory->path . '/' . $control . '.command.json', [
            'classification' => 'prospective component command; no helper/DB/credential execution', 'argv' => $argv,
            'cwd' => dirname(__DIR__, levels: 2), 'pins' => PasswordHelperEvidenceFiles::pins([$fixture,
                dirname(__DIR__) . '/fixtures/password-helper-fixture-observation.ps1',
                dirname(__DIR__) . '/fixtures/password-helper-input.ps1', PHP_BINARY, $shell])]);
        return $argv;
    }

    /** Only the synthetic controls use the single startup budget measured before launch.
     * @param array<string, string> $expectedCase */
    public static function awaitFixtureEntry(string $path, array $expectedCase, int $startedNs,
        PasswordHelperFixtureReadiness $output, float $timeout = 20): void
    { $output->awaitEntry($path, $expectedCase, $startedNs, $timeout); }

    public static function create(TemporaryDirectory $directory, PasswordHelperCase $case, ?string $sink): string
    {
        $root = $sink ?? getenv('TABLO_HELPER_DIAGNOSTICS_DIRECTORY');
        if ($root !== false && $root !== '' && !is_dir($root)) { throw new RuntimeException('Required helper evidence sink is unavailable'); }
        $path = ($root === false || $root === '' ? $directory->path : $root) . '/case-' . bin2hex(random_bytes(12));
        if (!mkdir($path, permissions: 0o700)) { throw new RuntimeException('Cannot create synthetic helper case'); }
        self::started($path, $case);
        PasswordHelperEvidenceFiles::record($path . '/input-manifest.json', $case->input);
        if ($case->expectedInput !== null) { PasswordHelperEvidenceFiles::bytes($path . '/expected-input.bin', $case->expectedInput); }
        return $path;
    }

    private static function started(string $path, PasswordHelperCase $case): void
    {
        $repo = dirname(__DIR__, levels: 2);
        $argv = $case->identity['phase'] === 'negative-control' ? $case->argv :
            \Tablo\Tests\Network\PasswordHelperCommand::forCase($case, $path);
        PasswordHelperEvidenceFiles::record($path . '/started.json', ['classification' => 'STARTED, closure UNKNOWN',
            'utc' => gmdate('c'), 'monotonic_ns' => hrtime(true), 'case' => $case->identity,
            'controller_pid' => getmypid(), 'direct_parent_pid' => getmypid(), 'direct_pid' => null,
            'argv' => $argv, 'cwd' => $repo, 'php_version' => PHP_VERSION, 'os' => PHP_OS_FAMILY,
            'observation' => ['fixture' => ['configured' => $case->identity['phase'] !== 'negative-control', 'state' => 'not-observed'],
                'native' => ['configured' => $case->identity['phase'] !== 'negative-control', 'state' => 'not-observed'],
                'limitation' => 'configuration is not emission; missing stages remain UNKNOWN'],
            'pins' => PasswordHelperEvidenceFiles::pins([PHP_BINARY, FixtureValue::at($case->argv, 0),
                $repo . '/composer.lock', $repo . '/lekalo.lock', $repo . '/tools/mago-pin.json',
                $repo . '/mago.toml', $repo . '/tools/admin-password.ps1', $repo . '/tests/fixtures/helper-diagnostic.php',
                $repo . '/tests/fixtures/password-helper-input.ps1', $repo . '/tests/fixtures/password-helper-fixture-observation.ps1',
                $repo . '/tests/Support/PasswordHelperFixtureObservation.php', $repo . '/tests/Network/PasswordHelperTest.php']),
            'nested_closure' => 'UNKNOWN; only native handle observations can establish exit']);
    }
}
