<?php
declare(strict_types=1);

namespace Tablo;

final class IdleTimeoutSettings
{
    public function __construct(private readonly \PDO $db) {}

    public function get(): int
    {
        $statement = SqlStatement::checked($this->db->query('SELECT session_idle_timeout_minutes FROM installation_settings WHERE id = 1'));
        try {
            /** @var int|float|string|null|false $value SQLite scalar or the no-row sentinel. */
            $value = $statement->fetchColumn();
        } finally { $statement->closeCursor(); }
        if ($value === false) { return 30; }
        if (!is_int($value) || $value < 1 || $value > 720) { throw new \RuntimeException('Invalid session idle timeout.'); }
        return $value;
    }

    public function update(mixed $input): void
    {
        $value = IdleTimeoutInput::parse($input);
        $statement = SqlStatement::checked($this->db->prepare('INSERT INTO installation_settings (id, session_idle_timeout_minutes) VALUES (1, ?)
            ON CONFLICT(id) DO UPDATE SET session_idle_timeout_minutes = excluded.session_idle_timeout_minutes'));
        try { $statement->bindValue(1, $value, \PDO::PARAM_INT); $statement->execute(); }
        finally { $statement->closeCursor(); }
    }
}
