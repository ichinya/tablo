<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use RuntimeException;

final class PasswordHelperEvidenceStart
{
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
            \Tablo\Tests\Network\PasswordHelperCommand::forHost($case->identity['host'], $case->identity['encoding'], $case->identity['scenario'], $path . '/native-observation.jsonl');
        PasswordHelperEvidenceFiles::record($path . '/started.json', ['classification' => 'STARTED, closure UNKNOWN',
            'utc' => gmdate('c'), 'monotonic_ns' => hrtime(true), 'case' => $case->identity,
            'controller_pid' => getmypid(), 'direct_parent_pid' => getmypid(), 'direct_pid' => null,
            'argv' => $argv, 'cwd' => $repo, 'php_version' => PHP_VERSION, 'os' => PHP_OS_FAMILY,
            'pins' => PasswordHelperEvidenceFiles::pins([PHP_BINARY, FixtureValue::at($case->argv, 0),
                $repo . '/composer.lock', $repo . '/lekalo.lock', $repo . '/tools/mago-pin.json',
                $repo . '/mago.toml', $repo . '/tools/admin-password.ps1', $repo . '/tests/fixtures/helper-diagnostic.php',
                $repo . '/tests/fixtures/password-helper-input.ps1', $repo . '/tests/Network/PasswordHelperTest.php']),
            'nested_closure' => 'UNKNOWN; only native handle observations can establish exit']);
    }
}
