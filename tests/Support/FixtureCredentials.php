<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

/** Deterministic generated credentials shared by isolated tests and their transports. */
final class FixtureCredentials
{
    /** Shared synthetic administrator input; preserves the existing public fixture recipe. */
    public static function syntheticPassword(): string
    { return self::value(label: 'fixture-password'); }

    public static function value(string $label): string
    {
        return hash('sha256', 'tablo-test-fixture:' . $label);
    }

    public static function invalidHeader(string $label): string
    {
        return self::value($label) . "\r\n" . self::value('second-line');
    }

    public static function confirmationInput(string $label): string
    {
        $value = self::value($label);
        return $value . "\n" . $value . "\n";
    }
}
