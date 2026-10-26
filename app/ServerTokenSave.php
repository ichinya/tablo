<?php
declare(strict_types=1);

namespace Tablo;

final class ServerTokenSave
{
    public function __construct(private readonly \PDO $db, private readonly GitTokenRepository $repository,
        #[\SensitiveParameter] private readonly TokenVault $vault) {}
    public function save(#[\SensitiveParameter] array $input, ?int $id = null): int
    {
        if ($id !== null && $id < 1) { throw new \OutOfBoundsException('Токен не найден.'); }
        $this->vault->assertAvailable(KeyRequirement::forDatabase($this->db));
        $existing = $id === null ? null : $this->repository->find($id);
        if ($id !== null && !$existing) { throw new \OutOfBoundsException('Токен не найден.'); }
        [$name,$provider,$value] = SavedGitTokenInput::parse($input,$existing);
        $encrypted = $value !== '' ? $this->vault->encrypt($value) : null;
        $transaction = WriteTransaction::owns($this->db) ? null : WriteTransaction::begin($this->db);
        try {
            if ($id === null) {
                $server = DatabaseSession::server($this->db);
                $now = ServerDialect::utc();
                \Tablo\SqlStatement::checked($this->db->prepare($server
                    ? 'INSERT INTO git_tokens (name, provider, encrypted_token, created_at, updated_at) VALUES (?, ?, ?, ?, ?)'
                    : 'INSERT INTO git_tokens (name, provider, encrypted_token) VALUES (?, ?, ?)'))
                    ->execute($server ? [$name, $provider, $encrypted, $now, $now] : [$name, $provider, $encrypted]);
                $id = DatabaseInteger::positive($this->db->lastInsertId());
            }
            if ($existing !== null) {
                (new SavedGitTokenUpdate($this->db))->update($name,$provider,$encrypted,$id);
            }
            $transaction?->commit();
            return $id;
        } catch (\Throwable $e) {
            $transaction?->rollbackPreserving();
            (new SavedGitTokenUpdate($this->db))->refuse($e);
        }
    }
}
