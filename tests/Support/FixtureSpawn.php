<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use RuntimeException;

final class FixtureSpawn
{
    /** @return array<string, string> */
    public static function environment(array $overrides): array
    {
        $inherited = \Tablo\JsonRecord::checked(getenv());
        foreach (array_keys($inherited) as $name) {
            if (!is_string($name)) { continue; }
            if (!str_starts_with(strtoupper($name), 'TABLO_DB') && strtoupper($name) !== 'TABLO_TOKEN_KEY_FILE') { continue; }
            unset($inherited[$name]);
        }
        // Explicit empty defaults also prevent the project .env from supplying private configuration.
        $defaults = array_fill_keys(['TABLO_DB_NAME', 'TABLO_DB_HOST', 'TABLO_DB_PORT', 'TABLO_DB_SOCKET', 'TABLO_DB_USER', 'TABLO_DB_PASSWORD',
            'TABLO_DB_READ_USER', 'TABLO_DB_READ_PASSWORD', 'TABLO_DB_MIGRATE_USER', 'TABLO_DB_MIGRATE_PASSWORD', 'TABLO_DB_TLS',
            'TABLO_DB_TLS_CA', 'TABLO_DB_TLS_CERT', 'TABLO_DB_TLS_KEY', 'TABLO_TOKEN_KEY_FILE'], value: '');
        $defaults['TABLO_DB_DRIVER'] = 'sqlite';
        $defaults['TABLO_DB'] = ':memory:';
        $environment = array_map(FixtureValue::string(...), array_replace($inherited, $defaults, $overrides));
        $checked = [];
        foreach ($environment as $key => $value) {
            if (!is_string($key)) { throw new RuntimeException('Invalid fixture environment key'); }
            $checked[$key] = $value;
        }
        return $checked;
    }

    /**
     * @param list<string> $command
     * @param array<int, resource> $pipes
     * @param array{cwd: ?string, environment: array<string, string>|null} $options
     * @return resource|false
     */
    public static function append(array $command, string $stdout, ?string $stderr, array &$pipes, array $options = ['cwd' => null, 'environment' => null]): mixed
    {
        $cwd = $options['cwd']; $environment = $options['environment'];
        if ($cwd === '') { throw new RuntimeException('Empty fixture working directory'); }
        $output = fopen($stdout, mode: 'a');
        if ($output === false) { throw new RuntimeException('Cannot open fixture output'); }
        $error = null;
        try {
            if ($stderr !== null) {
                $error = fopen($stderr, mode: 'a');
                if ($error === false) { throw new RuntimeException('Cannot open fixture diagnostic output'); }
            }
            return proc_open($command, [0 => ['pipe', 'r'], 1 => $output, 2 => $error ?? ['pipe', 'w']],
                $pipes, $cwd, $environment, ['bypass_shell' => true]);
        } finally {
            fclose($output);
            if (is_resource($error)) { fclose($error); }
        }
    }
}
