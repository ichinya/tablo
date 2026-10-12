<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;












final class DatabaseWalStatement extends \PDOStatement
{
    public function __construct(private readonly DatabaseWalPdo $db, private readonly bool $setter, private readonly ?\PDOException $failure) {}

    public function execute(?array $params = null): bool
    {
        if ($this->failure !== null && $this->db->plan['failureStage'] === 'execute') { throw $this->failure; }
        return true;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        if ($this->failure !== null && $this->db->plan['failureStage'] === 'fetch') { throw $this->failure; }
        return $this->setter ? $this->db->plan['returnedMode'] : $this->db->plan['mode'];
    }

    public function closeCursor(): bool
    {
        // In primary-error controls, fail only on the failing setter's cleanup.
        if ($this->db->plan['cleanupFailure'] !== null && ($this->db->plan['failure'] === null || $this->failure !== null)) {
            if ($this->db->plan['cleanupFailure'] === false) { return false; }
            throw $this->db->plan['cleanupFailure'];
        }
        return true;
    }
}
