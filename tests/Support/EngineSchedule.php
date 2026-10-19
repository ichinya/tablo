<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

/** The runner supplies a finite private manifest. Production variables are never a fallback. */
final class EngineSchedule
{
    /** @return array<string, string> */
    public static function environment(string $case, ?string $peerEngine = null): array
    {
        $directory = self::directory($peerEngine);
        $manifest = self::manifest($directory, $peerEngine);
        $cases = \Tablo\JsonRecord::checked($manifest['cases'] ?? null);
        $catalog = \Tablo\SqlColumn::string($cases, $case);
        $nonce = \Tablo\SqlColumn::string($manifest, 'nonce');
        if (preg_match('/\Ati39_' . substr($nonce, offset: 0, length: 16) . '_[ma]_[0-9]{3}\z/D', $catalog) !== 1) { throw new \RuntimeException('Fixture catalog ownership refused.'); }
        $values = \Tablo\JsonRecord::checked($manifest['environment'] ?? null);
        $environment = [];
        foreach (array_keys($values) as $name) {
            $name = (string) $name;
            if (!str_starts_with($name, 'TABLO_DB_')) { throw new \RuntimeException('Fixture environment refused.'); }
            $environment[$name] = \Tablo\SqlColumn::string($values, $name);
        }
        $environment['TABLO_DB_DRIVER'] = \Tablo\SqlColumn::string($manifest, 'engine');
        $environment['TABLO_DB_NAME'] = $catalog;
        $environment['TABLO_DB'] = '';
        $environment['TABLO_TOKEN_KEY_FILE'] = $directory . '/private/installation.bin';
        return $environment;
    }

    public static function directory(?string $peerEngine = null): string
    {
        $nonce = FixtureValue::string(getenv('TABLO_TEST_RUN_NONCE'));
        $variable = $peerEngine === null ? 'TABLO_TEST_RUNTIME_ROOT' : 'TABLO_TEST_CROSS_RUNTIME_ROOT';
        $directory = FixtureValue::string(getenv($variable));
        if ([preg_match('/\A[0-9a-f]{32}\z/D', $nonce), basename(str_replace('\\', replace: '/', subject: $directory))] !== [1, 'tablo-server-' . $nonce]) {
            throw new \RuntimeException('Fixture runtime ownership refused.');
        }
        return $directory;
    }

    public static function receipt(string $case, ?string $peerEngine = null): string
    {
        if (preg_match('/\A[a-z][a-z0-9-]{0,63}\z/D', $case) !== 1) { throw new \RuntimeException('Fixture case refused.'); }
        return self::directory($peerEngine) . '/private/' . $case . '.bootstrap.json';
    }

    /** Only the separately supervised cell may create this receipt after actual server restart. */
    public static function crashReceipt(string $case, ?string $peerEngine = null): array
    { return engine_schedule_crash_receipt($case, $peerEngine); }

    /** @return array */
    private static function manifest(string $directory, ?string $peerEngine): array
    {
        $path = $directory . '/private/fixture-manifest.json';
        $manifest = \Tablo\JsonRecord::decode(\Tablo\RequiredFile::bytes(file_get_contents($path)));
        $selected = getenv('TABLO_TEST_DB_DRIVER'); $engine = $peerEngine ?? $selected;
        if (!in_array($engine, ['mysql', 'mariadb'], strict: true)) { throw new \RuntimeException('Selected fixture engine unavailable.'); }
        if ($peerEngine !== null && $peerEngine === $selected) { throw new \RuntimeException('Distinct explicit peer engine required.'); }
        if ([\Tablo\SqlColumn::string($manifest, 'engine'), \Tablo\SqlColumn::string($manifest, 'nonce'), \Tablo\SqlColumn::integer($manifest, 'format')]
            !== [$engine, getenv('TABLO_TEST_RUN_NONCE'), 1]) { throw new \RuntimeException('Selected fixture engine unavailable.'); }
        return $manifest;
    }
}

function engine_schedule_crash_receipt(string $case, ?string $peerEngine): array
{
    $path = substr(EngineSchedule::receipt($case, $peerEngine), offset: 0, length: -strlen('.bootstrap.json')) . '.server-crash.json';
    if (is_link($path)) { throw new \RuntimeException('Linked server-crash evidence refused.'); }
    $bytes = \Tablo\RequiredFile::bytes(file_get_contents($path, length: 16_385));
    if (strlen($bytes) > 16_384) { throw new \RuntimeException('Server-crash evidence envelope exceeded.'); }
    $record = \Tablo\JsonRecord::decode($bytes);
    $environment = EngineSchedule::environment($case, $peerEngine);
    if ([$record['schema'] ?? null, $record['nonce'] ?? null, $record['case'] ?? null, $record['engine'] ?? null, $record['catalog'] ?? null]
        !== ['tablo/server-crash/v1', getenv('TABLO_TEST_RUN_NONCE'), $case, \Tablo\SqlColumn::string($environment, 'TABLO_DB_DRIVER'), \Tablo\SqlColumn::string($environment, 'TABLO_DB_NAME')]) {
        throw new \RuntimeException('Exact server-crash evidence ownership refused.');
    }
    return $record;
}
