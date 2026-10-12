<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use RuntimeException;

final class FixtureSpawn
{
    /** @return array<string, string> */
    public static function environment(array $overrides): array
    {
        $environment = array_map(FixtureValue::string(...), array_replace(\Tablo\JsonRecord::checked(getenv()), $overrides));
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
