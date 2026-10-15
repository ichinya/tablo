<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;


final class PasswordHelperCommand
{
    /** @return list<string> */
    public static function forEvidence(\Tablo\Tests\Support\PasswordHelperEvidence $evidence): array
    {
        return self::forCase($evidence->case, $evidence->path);
    }

    /** @return list<string> */
    public static function forCase(\Tablo\Tests\Support\PasswordHelperCase $case, string $path): array
    {
        $identity = $case->identity;
        return [...self::forHost($identity['host'], $identity['encoding'], $identity['scenario'], $path . '/native-observation.jsonl'),
            '-FixtureObservationPath', $path . '/fixture-observation.jsonl', '-CaseId', basename($path),
            '-CasePhase', $identity['phase'], '-FixtureControl', $identity['phase'] === 'fixture-control' ? 'pre-helper-stall' : 'none'];
    }

    /** @return list<string> */
    public static function forHost(string $shell, string $encoding, string $scenario, ?string $observationPath = null): array
    {
        return [$shell, '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
                        dirname(__DIR__) . '/fixtures/password-helper-input.ps1', '-Helper',
                        dirname(__DIR__, levels: 2) . '/tools/admin-password.ps1', '-Php', PHP_BINARY,
                        '-HostEncoding', $encoding, '-Scenario', $scenario,
                        ...($observationPath === null ? [] : ['-ObservationPath', $observationPath])];
    }
}
