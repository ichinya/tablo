<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use PDO;
use Tablo\DatabaseConfiguration;
use Tablo\DatabaseConnectionFactory;
use Tablo\ServerSchema;
use Tablo\TokenVault;

/** All connections use the selected real service and the exact manifest-owned catalog. */
final class EngineFixture
{
    /** @var array<string, string> */
    private readonly array $environment;
    public readonly TokenVault $vault;
    private ?PDO $connection = null;

    public function __construct(public readonly string $case, ?string $peerEngine = null)
    {
        $this->environment = EngineSchedule::environment($case, $peerEngine);
        $key = FixtureValue::string($this->environment['TABLO_TOKEN_KEY_FILE'] ?? null);
        // TokenVault honors the global override; refuse a foreign inherited path before it can read.
        if (!in_array(getenv('TABLO_TOKEN_KEY_FILE'), [false, '', $key], strict: true)) { throw new \RuntimeException('Fixture key environment refused.'); }
        $this->vault = new TokenVault($key, external: true);
    }

    public function database(): PDO
    {
        $this->connection ??= $this->independent();
        return $this->connection;
    }

    public function independent(string $role = 'writer'): PDO
    {
        return DatabaseConnectionFactory::open(DatabaseConfiguration::fromEnvironment($role, $this->environment), vault: $this->vault);
    }

    public function maintenance(): PDO
    {
        return DatabaseConnectionFactory::open(DatabaseConfiguration::fromEnvironment('migrator', $this->environment), mode: 'maintenance');
    }

    /** @return array<string, string> */
    public function environment(): array { return $this->environment + ['TABLO_TEST_ENGINE_CASE' => $this->case]; }

    public static function forWeb(array $environment): ?self
    {
        $case = array_key_exists('TABLO_TEST_ENGINE_CASE', $environment) ? FixtureValue::string($environment['TABLO_TEST_ENGINE_CASE']) : null;
        return $case === null ? null : new self($case);
    }

    /** The original isolated SQLite WebFixture remains available without any selected server. */
    public static function sqliteWeb(string $path): PDO
    {
        $db = new PDO('sqlite:' . $path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $db->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
        return $db;
    }

    public function scalar(string $sql, ?PDO $connection = null): int
    {
        $rows = ServerSchema::rows($connection ?? $this->database(), $sql);
        $row = FixtureValue::row($rows[0] ?? null);
        return \Tablo\DatabaseInteger::checked(array_values($row)[0] ?? null);
    }

    /** A real native refusal, retaining an unknown code as an error rather than zero. */
    public function nativeFailure(string $sql, array $values = []): int
    {
        try {
            $statement = \Tablo\SqlStatement::checked($this->database()->prepare($sql));
            try { $statement->execute($values); } finally { $statement->closeCursor(); }
        } catch (\PDOException $error) { return FixtureValue::integer($error->errorInfo[1] ?? null); }
        throw new \RuntimeException('Expected native database refusal was absent.');
    }

    public function close(): void { $this->connection = null; }
}
