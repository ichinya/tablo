<?php
declare(strict_types=1);

namespace Tablo\Tests\Server;

use Tablo\Tests\Support\EngineFixture;

/** Real selected PDO commits first; the test then injects the lost acknowledgement at its boundary. */
final class EngineReadApiCommitPdo extends \PDO
{
    public bool $loseAcknowledgement = false;
    public bool $committedWithoutAcknowledgement = false;
    public bool $failRollback = false;
    public int $rollbackAttempts = 0;
    public ?\PDOException $prepareFailure = null;
    public ?\Throwable $queryFailure = null;

    public function __construct(EngineFixture $fixture, string $role = 'writer')
    {
        $configuration = $role === 'metadata' ? \Tablo\DatabaseConfiguration::fromEnvironment(environment: $fixture->environment())->metadata()
            : \Tablo\DatabaseConfiguration::fromEnvironment($role, $fixture->environment());
        parent::__construct($configuration->dsn(), $configuration->user, $configuration->password, \Tablo\PdoMysqlOptions::forConfiguration($configuration));
        \Tablo\DatabaseSession::register($this, $configuration);
        \Tablo\ServerSessionAdmission::attest($this, $configuration); \Tablo\ServerSessionSettings::configure($this);
        \Tablo\ServerSessionAdmission::tls($this, $configuration);
        if ($role === 'reader') { \Tablo\ServerSchema::admit($this); }
        if ($role === 'writer') {
            \Tablo\ServerSchema::admit($this);
            \Tablo\ServerKeyIdentity::authenticate($this, $fixture->vault);
            \Tablo\ExternalKeyAuthentication::authenticateExternalKey($this, $fixture->vault);
        }
    }

    public function exec(string $statement): int|false
    {
        if ($statement === 'ROLLBACK') {
            $this->rollbackAttempts++;
            if ($this->failRollback) {
                $error = new \PDOException('Synthetic private rollback diagnostic.');
                $error->errorInfo = ['HY000', 2013, 'Synthetic private rollback diagnostic.']; throw $error;
            }
        }
        $result = parent::exec($statement);
        if ($statement === 'COMMIT' && $this->loseAcknowledgement) {
            $this->loseAcknowledgement = false; $this->committedWithoutAcknowledgement = true;
            throw new \PDOException('Synthetic lost commit acknowledgement.');
        }
        return $result;
    }

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        if ($this->prepareFailure !== null) { $error = $this->prepareFailure; $this->prepareFailure = null; throw $error; }
        return parent::prepare($query, $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
    {
        if ($this->queryFailure !== null) { $error = $this->queryFailure; $this->queryFailure = null; throw $error; }
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}
