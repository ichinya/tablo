<?php
declare(strict_types=1);

namespace Tablo;

use PDO;
use PDOException;

final class Auth
{
    public function __construct(private readonly PDO $db) {}

    public function needsSetup(): bool
    {
        return !$this->db->query('SELECT 1 FROM users WHERE id = 1')->fetchColumn();
    }

    public function setup(string $password, string $confirmation): void
    {
        if (strlen($password) < 12 || strlen($password) > 72) {
            throw new ValidationException(['password' => 'Пароль должен содержать от 12 до 72 байт.']);
        }
        if (!hash_equals($password, $confirmation)) {
            throw new ValidationException(['confirmation' => 'Пароли не совпадают.']);
        }
        try {
            $this->db->prepare('INSERT INTO users (id, password_hash) VALUES (1, ?)')
                ->execute([password_hash($password, PASSWORD_DEFAULT)]);
        } catch (PDOException $e) {
            if (!$this->needsSetup()) {
                throw new ValidationException(['password' => 'Администратор уже создан. Войдите с существующим паролем.']);
            }
            throw $e;
        }
    }

    public function login(string $password, string $address): bool
    {
        $key = hash('sha256', $address);
        $now = time();
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $this->db->prepare('DELETE FROM login_limits WHERE window_start < ?')->execute([$now - 900]);
            $statement = $this->db->prepare('SELECT failures FROM login_limits WHERE key = ?');
            $statement->execute([$key]);
            if ((int) $statement->fetchColumn() >= 5) {
                throw new ValidationException(['password' => 'Слишком много попыток. Повторите через 15 минут.']);
            }
            $hash = $this->db->query('SELECT password_hash FROM users WHERE id = 1')->fetchColumn();
            $valid = is_string($hash) && password_verify($password, $hash);
            if ($valid) {
                $this->db->prepare('DELETE FROM login_limits WHERE key = ?')->execute([$key]);
                if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                    $this->db->prepare('UPDATE users SET password_hash = ? WHERE id = 1')
                        ->execute([password_hash($password, PASSWORD_DEFAULT)]);
                }
            } else {
                $this->db->prepare('INSERT INTO login_limits (key, failures, window_start) VALUES (?, 1, ?)
                    ON CONFLICT(key) DO UPDATE SET failures = failures + 1')->execute([$key, $now]);
            }
            $this->db->exec('COMMIT');
            return $valid;
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
    }
}
