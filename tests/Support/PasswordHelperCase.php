<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use Tablo\Tests\Network\PasswordHelperCommand;

/** Explicit allowlist of synthetic helper and direct diagnostic fixture commands. */
final class PasswordHelperCase
{
    /** @param list<string> $argv
     * @param array{host: string, encoding: string, scenario: string, phase: string} $identity
     * @param list<string> $secrets
     * @param array<string, mixed> $input */
    private function __construct(public readonly array $argv, public readonly array $identity,
        #[\SensitiveParameter] public readonly array $secrets, public readonly array $input,
        #[\SensitiveParameter] public readonly ?string $expectedInput = null) {}

    public static function helper(string $host, string $encoding, string $scenario, string $phase): self
    {
        $password = match ($scenario) {
            'min' => str_repeat('x', times: 12), 'max' => str_repeat('x', times: 72),
            'short' => str_repeat('x', times: 11), 'long' => str_repeat('x', times: 73),
            'encodingFailure' => null, default => "  \u{0436}\u{4e2d}\u{1f642}-unicode-  ",
        };
        $input = ['classification' => 'expected recipe, not observed PHP stdin', 'lineEnding' => 'LF',
            'scenario' => $scenario, 'encoding' => 'strict UTF8 without BOM',
            'utf16Units' => $scenario === 'encodingFailure' ? [0xd800] : null,
            'observedBytes' => null, 'observedClassification' => 'UNKNOWN'];
        return new self(PasswordHelperCommand::forHost($host, $encoding, $scenario),
            ['host' => $host, 'encoding' => $encoding, 'scenario' => $scenario, 'phase' => $phase],
            $password === null ? [] : [$password], $input,
            $password === null ? null : $password . "\n" . $password . "\n");
    }

    public static function diagnostic(string $mode, string $gate = '', #[\SensitiveParameter] string $secret = ''): self
    {
        if (!in_array($mode, ['ok', 'nonzero', 'silent', 'metadata-live', 'malformed', 'private'], strict: true)) {
            throw new \RuntimeException('Unregistered synthetic diagnostic mode');
        }
        return new self([PHP_BINARY, dirname(__DIR__) . '/fixtures/helper-diagnostic.php', $mode, $gate],
            ['host' => PHP_BINARY, 'encoding' => 'not-applicable', 'scenario' => $mode, 'phase' => 'negative-control'],
            $secret === '' ? [] : [$secret], ['classification' => 'no diagnostic stdin', 'observedBytes' => 0]);
    }

    public static function fixtureStall(string $host): self
    {
        return new self(PasswordHelperCommand::forHost($host, 'default', 'unicode'),
            ['host' => $host, 'encoding' => 'default', 'scenario' => 'unicode', 'phase' => 'fixture-control'],
            [], ['classification' => 'deliberate pre-helper stall; no credential input generated', 'observedBytes' => null]);
    }

    public static function missing(TemporaryDirectory $directory): self
    {
        return new self([$directory->path . '/absent-synthetic-executable'],
            ['host' => 'absent synthetic executable', 'encoding' => 'not-applicable', 'scenario' => 'missing', 'phase' => 'negative-control'],
            [], ['classification' => 'no input; spawn or nonzero remains actual native outcome']);
    }
}
