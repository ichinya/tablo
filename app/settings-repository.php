<?php
declare(strict_types=1);

namespace Tablo;

use PDO;
use RuntimeException;

final class SettingsRepository
{
    public function __construct(private readonly PDO $db) {}

    public function get(): int
    {
        $statement = $this->db->query('SELECT check_interval_minutes FROM installation_settings WHERE id = 1');
        try { $value = $statement->fetchColumn(); }
        finally { $statement->closeCursor(); }
        if ($value === false) { return 10; }
        if (!is_int($value) || $value < 1 || $value > intdiv(PHP_INT_MAX, 60)) {
            throw new RuntimeException('Invalid worker interval.');
        }
        return $value;
    }

    public function updateInterval(mixed $input): void
    {
        $value = is_string($input) ? ltrim($input, '0') : '';
        $maximum = (string) intdiv(PHP_INT_MAX, 60);
        if (!is_string($input) || !preg_match('/^[0-9]+$/D', $input) || $value === ''
            || strlen($value) > strlen($maximum) || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)) {
            throw new ValidationException(['check_interval_minutes' => 'Укажите положительное целое число минут.']);
        }
        $statement = $this->db->prepare('INSERT INTO installation_settings (id, check_interval_minutes) VALUES (1, ?)
            ON CONFLICT(id) DO UPDATE SET check_interval_minutes = excluded.check_interval_minutes');
        $statement->bindValue(1, (int) $value, PDO::PARAM_INT);
        $statement->execute();
    }
}
