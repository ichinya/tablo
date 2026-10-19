<?php
declare(strict_types=1);

namespace Tablo;

use PDO;
use RuntimeException;

// Pinned Lekalo requires lowercase physical source paths for this contracted owner.
// @mago-expect lint:file-name
final class SettingsRepository
{
    public function __construct(private readonly PDO $db) {}

    public function get(): int
    {
        $statement = \Tablo\SqlStatement::checked($this->db->query('SELECT check_interval_minutes FROM installation_settings WHERE id = 1'));
        try {
            // A SQLite scalar column or the distinct no-row sentinel; admission below remains authoritative.
            /** @var int|float|string|null|false $value */
            $value = $statement->fetchColumn();
        }
        finally { $statement->closeCursor(); }
        if ($value === false) { return 10; }
        if (!is_int($value) || $value < 1 || $value > intdiv(PHP_INT_MAX, num2: 60)) {
            throw new RuntimeException('Invalid worker interval.');
        }
        return $value;
    }

    public function updateInterval(mixed $input): void
    {
        if (DatabaseSession::server($this->db) && !WriteTransaction::owns($this->db)) {
            WriteTransaction::run($this->db, function () use ($input): void { $this->updateInterval($input); }); return;
        }
        $value = is_string($input) ? ltrim($input, characters: '0') : '';
        $maximum = (string) intdiv(PHP_INT_MAX, num2: 60);
        if (!is_string($input) || !preg_match('/^[0-9]+$/D', $input) || $value === ''
            || strlen($value) > strlen($maximum) || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)) {
            throw ValidationException::field(field: 'check_interval_minutes', message: 'Укажите положительное целое число минут.');
        }
        $statement = \Tablo\SqlStatement::checked($this->db->prepare(ServerDialect::choose($this->db,
            'INSERT INTO installation_settings (id, check_interval_minutes) VALUES (1, ?) ON CONFLICT(id) DO UPDATE SET check_interval_minutes = excluded.check_interval_minutes',
            'INSERT INTO installation_settings (id, check_interval_minutes) VALUES (1, ?) ON DUPLICATE KEY UPDATE check_interval_minutes = ?')));
        $statement->bindValue(1, (int) $value, PDO::PARAM_INT);
        if (DatabaseSession::server($this->db)) { $statement->bindValue(2, (int) $value, PDO::PARAM_INT); }
        $statement->execute();
    }

    public function getIdleTimeout(): int
    { return (new IdleTimeoutSettings($this->db))->get(); }

    public function updateIdleTimeout(mixed $input): void
    { (new IdleTimeoutSettings($this->db))->update($input); }
}
