<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use RuntimeException;

final class SessionFixture
{
    public readonly WebFixture $web;
    public const START = 1_000_000;

    public function __construct(bool $allowPrivateNetwork = false)
    {
        $this->web = new WebFixture($allowPrivateNetwork, environment: ['TABLO_TEST_SESSION_CLOCK' => '1']);
        $this->clock(self::START);
    }

    public function clock(int $epoch): void
    {
        file_put_contents($this->web->directory->path . '/runtime-clock', (string) $epoch, LOCK_EX);
        SessionEvidence::record('clock', ['fixture' => $this->web->directory->path, 'epoch' => $epoch]);
    }

    public function sessionFile(string $jar = 'cookies'): string
    {
        $cookies = \Tablo\RequiredFile::bytes(file_get_contents($this->web->directory->path . '/' . $jar));
        $matches = [];
        if (preg_match('/\ttablo_session\t([a-zA-Z0-9,-]+)\s*$/m', $cookies, $matches) !== 1) { throw new RuntimeException('Owned session cookie missing.'); }
        return $this->web->directory->path . '/runtime/sessions/sess_' . FixtureValue::at($matches, 1);
    }

    /** @return array{authenticated_at: int, last_activity_at: int, session_observed_at: int} */
    public function timestamps(string $jar = 'cookies'): array
    {
        $bytes = \Tablo\RequiredFile::bytes(file_get_contents($this->sessionFile($jar)));
        $read = static function(string $field) use ($bytes): int {
            $matches = [];
            if (preg_match('/' . $field . '\|i:([0-9]+);/', $bytes, $matches) !== 1) { throw new RuntimeException('Owned session timestamp missing.'); }
            return (int) FixtureValue::at($matches, 1);
        };
        return ['authenticated_at' => $read('authenticated_at'), 'last_activity_at' => $read('last_activity_at'),
            'session_observed_at' => $read('session_observed_at')];
    }

    public function corrupt(string $field, mixed $value): void
    { $this->replace($field, $field . '|' . serialize($value)); }

    public function omit(string $field): void { $this->replace($field, ''); }

    private function replace(string $field, string $replacement): void
    {
        $file = $this->sessionFile();
        $bytes = \Tablo\RequiredFile::bytes(file_get_contents($file));
        $replaced = preg_replace('/' . $field . '\|i:[0-9]+;/', $replacement, $bytes);
        if ($replaced === null || $replaced === $bytes) { throw new RuntimeException('Owned corruption field missing.'); }
        file_put_contents($file, $replaced);
        SessionEvidence::record('timestamp-control', ['fixture' => $this->web->directory->path, 'field' => $field,
            'input' => $replacement, 'resultSha256' => hash('sha256', $replaced)]);
    }

    public function close(): void { $this->web->directory->cleanup($this->web->close(...)); }
}
