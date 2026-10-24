<?php
declare(strict_types=1);

namespace Tablo;

final class ServerBootstrapReceipt
{
    public function __construct(private readonly \PDO $db, #[\SensitiveParameter] private readonly TokenVault $vault) {}

    /** @return array{plan: string, installation: string, verifier: string} */
    public function read(#[\SensitiveParameter] string $path, int $objectCount): array
    {
        $session = DatabaseSession::get($this->db) ?? throw new DatabaseFailure('configuration');
        $target = $session->configuration->engine . ':' . $session->configuration->catalog;
        $bytes = server_bootstrap_receipt_file_read($path, $objectCount, function () use ($target): string {
            $installation = bin2hex(random_bytes(16));
            return JsonEncoding::encode(['format' => 1, 'target' => $target, 'layout' => ServerSchema::checksum($this->db), 'nonce' => bin2hex(random_bytes(16)),
                'installation' => $installation, 'verifier' => $this->vault->serverVerifier($installation)]);
        });
        $receipt = JsonRecord::decode($bytes);
        if ([$receipt['format'] ?? null, $receipt['target'] ?? null, $receipt['layout'] ?? null] !== [1, $target, ServerSchema::checksum($this->db)]) { throw new DatabaseFailure('schema'); }
        try {
            $nonce = SqlColumn::string($receipt, 'nonce'); $installation = SqlColumn::string($receipt, 'installation'); $verifier = SqlColumn::string($receipt, 'verifier');
        } catch (\RuntimeException) { throw new DatabaseFailure('schema'); }
        if (preg_match('/\A[0-9a-f]{32}\z/D', $nonce) !== 1 || !hash_equals($verifier, $this->vault->serverVerifier($installation))) { throw new DatabaseFailure('schema'); }
        $session->installationId = $installation;
        return ['plan' => hash('sha256', $bytes), 'installation' => $installation, 'verifier' => $verifier];
    }
}

// Private bounded receipt storage.

/** @param \Closure(): string $create */
function server_bootstrap_receipt_file_read(#[\SensitiveParameter] string $path, int $objectCount, #[\SensitiveParameter] \Closure $create): string
{
    if ($path === '' || str_contains($path, "\0") || str_contains($path, '://') || is_link($path)) { throw new DatabaseFailure('configuration'); }
    if (!file_exists($path)) {
        if ($objectCount !== 0) { throw new DatabaseFailure('schema'); }
        server_bootstrap_receipt_file_write($path, $create());
    }
    $bytes = NativeWarning::ignore(static fn () => file_get_contents($path, length: 4097));
    if (!is_string($bytes) || strlen($bytes) > 4096) { throw new DatabaseFailure('schema'); }
    return $bytes;
}


function server_bootstrap_receipt_file_write(#[\SensitiveParameter] string $path, #[\SensitiveParameter] string $bytes): void
{
    $handle = NativeWarning::ignore(static fn () => fopen($path, mode: 'xb'));
    if (!is_resource($handle)) { throw new DatabaseFailure('unavailable'); }
    try {
        if (!chmod($path, permissions: 0o600)) { throw new DatabaseFailure('unavailable'); }
        // Retain all native write/flush/sync outcomes; a partial result never publishes success.
        $observed = [fwrite($handle, $bytes), fflush($handle), fsync($handle)];
        if ($observed !== [strlen($bytes), true, true]) { throw new DatabaseFailure('unavailable'); }
    } finally { fclose($handle); }
}
