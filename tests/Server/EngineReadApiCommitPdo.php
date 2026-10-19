<?php
declare(strict_types=1);

namespace Tablo\Tests\Server;

use Tablo\Tests\Support\EngineFixture;

/** Real selected PDO commits first; the test then injects the lost acknowledgement at its boundary. */
final class EngineReadApiCommitPdo extends \PDO
{
    public bool $loseAcknowledgement = false;
    public bool $committedWithoutAcknowledgement = false;

    public function __construct(EngineFixture $fixture)
    {
        $configuration = \Tablo\DatabaseConfiguration::fromEnvironment(environment: $fixture->environment());
        parent::__construct($configuration->dsn(), $configuration->user, $configuration->password, \Tablo\PdoMysqlOptions::forConfiguration($configuration));
        \Tablo\DatabaseSession::register($this, $configuration);
        \Tablo\ServerSessionAdmission::attest($this, $configuration); \Tablo\ServerSessionSettings::configure($this);
        \Tablo\ServerSessionAdmission::tls($this, $configuration); \Tablo\ServerSchema::admit($this);
        \Tablo\ServerKeyIdentity::authenticate($this, $fixture->vault);
        \Tablo\ExternalKeyAuthentication::authenticateExternalKey($this, $fixture->vault);
    }

    public function exec(string $statement): int|false
    {
        $result = parent::exec($statement);
        if ($statement === 'COMMIT' && $this->loseAcknowledgement) {
            $this->loseAcknowledgement = false; $this->committedWithoutAcknowledgement = true;
            throw new \PDOException('Synthetic lost commit acknowledgement.');
        }
        return $result;
    }
}
