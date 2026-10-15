<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

/** Owned HTTP edge: its outbound socket and observed inbound socket are real.
 * @phpstan-type Observation array{peer: string, xff: string, outbound_source: string, ...} */
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

    /** @return list<Observation> */
    public function observations(): array
    {
        return array_map(self::observation(...), FixtureFiles::lines($this->directory->path . '/observations.jsonl', FILE_IGNORE_NEW_LINES));
    }

    /** @return Observation */
    public static function observation(string $line): array
    {
        $row = \Tablo\JsonRecord::decode($line, 512);
        $row['peer'] = \Tablo\SqlColumn::string($row, 'peer');
        $row['xff'] = \Tablo\SqlColumn::string($row, 'xff');
        $row['outbound_source'] = \Tablo\SqlColumn::string($row, 'outbound_source');
        return $row;
    }

    public function close(): void
    {
        $this->server->close();
        $this->directory->close();
    }
}
