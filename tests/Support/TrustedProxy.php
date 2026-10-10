<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

/** Owned HTTP edge: its outbound socket and observed inbound socket are real. */
final class TrustedProxy
{
    public readonly TemporaryDirectory $directory;
    public readonly TestServer $server;

    public function __construct(string $backend, string $source, bool $internal = false)
    {
        $this->directory = new TemporaryDirectory('tablo-proxy-');
        try {
            $this->server = new TestServer($this->directory, dirname(__DIR__) . '/fixtures/trusted-proxy-router.php',
                environment: ['TABLO_FIXTURE_BACKEND' => $backend, 'TABLO_FIXTURE_SOURCE' => $source,
                    'TABLO_FIXTURE_INTERNAL' => $internal ? '1' : '0',
                    'TABLO_FIXTURE_OBSERVATIONS' => $this->directory->path . '/observations.jsonl']);
        } catch (\Throwable $error) {
            $this->directory->close();
            throw $error;
        }
    }

    public function observations(): array
    {
        return array_map(static fn (string $line): array => json_decode($line, associative: true, flags: JSON_THROW_ON_ERROR),
            file($this->directory->path . '/observations.jsonl', FILE_IGNORE_NEW_LINES));
    }

    public function close(): void
    {
        $this->server->close();
        $this->directory->close();
    }
}
