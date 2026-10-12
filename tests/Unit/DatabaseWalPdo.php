<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use PDO;






use Testo\Assert;



final class DatabaseWalPdo extends PDO
{
    public int $timeout = 5000;
    /** @var list<int> */
    public array $timeouts = [];
    /** @var list<string> */
    public array $sql = [];
    /** @var list<\WeakReference<DatabaseWalStatement>> */
    public array $statements = [];
    /** @var list<int> */
    public array $attemptTimes = [];
    public int $setters = 0;
    /** @var array{mode: string, returnedMode: string, failure: ?\PDOException, failureStage: string, repeatFailure: bool, cleanupFailure: \RuntimeException|false|null, restoreFailure: ?\RuntimeException, initialFailure: ?\PDOException} */
    public array $plan = ['mode' => 'delete', 'returnedMode' => 'wal', 'failure' => null, 'failureStage' => 'execute',
        'repeatFailure' => false, 'cleanupFailure' => null, 'restoreFailure' => null, 'initialFailure' => null];

    public function __construct() {}

    public static function error(mixed $code): \PDOException
    {
        $error = new \PDOException('Synthetic HY000 database is locked', 5);
        $error->errorInfo = ['HY000', $code, null];
        return $error;
    }

    public function exec(string $statement): int|false
    {
        $this->timeout = $statement === 'PRAGMA busy_timeout = 0' ? 0 : 5000;
        $this->timeouts[] = $this->timeout;
        if ($this->timeout === 0 && $this->plan['initialFailure'] !== null) { throw $this->plan['initialFailure']; }
        if ($this->timeout === 5000 && $this->plan['restoreFailure'] !== null) { throw $this->plan['restoreFailure']; }
        return 0;
    }

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        Assert::same($this->timeout, 0);
        Assert::true($this->allReleased(), 'Statement remained strongly reachable before next prepare');
        $this->sql[] = $query;
        $this->attemptTimes[] = \Tablo\Tests\Support\WalClock\Clock::$now;
        $setter = $query === 'PRAGMA main.journal_mode = WAL';
        if ($setter) { $this->setters++; }
        $failure = $setter && ($this->setters === 1 || $this->plan['repeatFailure']) ? $this->plan['failure'] : null;
        if ($failure !== null && $this->plan['failureStage'] === 'prepare') { throw $failure; }
        $statement = new DatabaseWalStatement($this, $setter, $failure);
        $this->statements[] = \WeakReference::create($statement);
        return $statement;
    }

    public function allReleased(): bool
    {
        foreach ($this->statements as $reference) { if ($reference->get() !== null) { return false; } }
        return true;
    }
}
