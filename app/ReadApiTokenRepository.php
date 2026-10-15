<?php
declare(strict_types=1);

namespace Tablo;

require_once __DIR__ . '/read-api-token-repository.php';

final class ReadApiTokenRepository
{
    public function __construct(private readonly \PDO $db) {}
    public function authenticate(#[\SensitiveParameter] string $secret): bool { return authenticate_read_api($this->db, $secret); }
    /** @return array{present: bool, active: bool, created_at: ?string} */
    public function metadata(): array { return read_api_token_metadata($this->db); }
    /** @param \Closure(): bool $authorize Rechecks current session and committed policy under the owned lock. */
    public function rotate(#[\SensitiveParameter] string $fingerprint, \Closure $authorize): string { return rotate_read_api_token($this->db, $fingerprint, $authorize); }
    /** @param \Closure(): bool $authorize */
    public function revoke(#[\SensitiveParameter] string $fingerprint, \Closure $authorize): void { revoke_read_api_token($this->db, $fingerprint, $authorize); }

    public static function administrator(\PDO $db): ?string
    { return read_api_administrator($db); }

    /** @return array{secret_hash: string, administrator_fingerprint: string, created_at: string}|null */
    public static function stored(\PDO $db): ?array
    { return read_api_stored_token($db); }

    /** @param \Closure(): bool $authorize
     * @param \Closure(): void $write */
    public static function locked(\PDO $db, #[\SensitiveParameter] string $expected, \Closure $authorize, #[\SensitiveParameter] \Closure $write): void
    { read_api_token_locked($db, $expected, $authorize, $write); }

    /** @param list<string> $values */
    public static function write(\PDO $db, string $sql, #[\SensitiveParameter] array $values = []): void
    { read_api_token_write($db, $sql, $values); }
}
