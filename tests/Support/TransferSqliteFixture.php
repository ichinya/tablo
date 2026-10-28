<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use PDO;
use Tablo\DatabaseTransferManifest;
use Tablo\JsonEncoding;
use Tablo\JsonRecord;
use Tablo\RequiredFile;
use Tablo\SchemaReference;
use Tablo\ServerSchema;
use Tablo\TokenVault;

/** Fresh production SQLite schema and synthetic encrypted credentials only. */
final class TransferSqliteFixture
{
    public readonly TemporaryDirectory $directory;
    public readonly TokenVault $vault;

    public function __construct()
    {
        $this->directory = new TemporaryDirectory('tablo-transfer-boundary-');
        $key = $this->path('synthetic.key');
        if (file_put_contents($key, str_repeat('s', times: 32)) !== 32 || !chmod($key, permissions: 0o600)) {
            throw new \RuntimeException('Synthetic transfer key setup failed.');
        }
        $this->vault = new TokenVault($key, external: true);
    }

    public function path(string $name): string { return $this->directory->path . '/' . $name; }

    public function source(string $name): PDO
    {
        $db = new PDO('sqlite:' . $this->path($name), options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        self::seed($db, $this->vault);
        return $db;
    }

    public static function seed(PDO $db, TokenVault $vault): void
    {
        $db->exec('PRAGMA foreign_keys = ON');
        SchemaReference::reference(11, ['endpoint' => true], $db); $db->exec('PRAGMA main.user_version = 11');
        ServerSchema::rows($db, 'INSERT INTO users (id,password_hash,created_at) VALUES (1,?,?)', ['synthetic-password-hash', '2026-01-01T00:00:00Z']);
        ServerSchema::rows($db, 'INSERT INTO git_tokens (id,name,provider,encrypted_token,created_at,updated_at) VALUES (1,?,?,?,?,?)',
            ['Synthetic', 'github', $vault->encrypt(str_repeat('t', times: 32)), '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z']);
    }

    /** @return \Tablo\DatabaseTransferManifest::Table */
    public static function table(string $name): array
    {
        foreach (DatabaseTransferManifest::tables() as $table) { if ($table['name'] === $name) { return $table; } }
        throw new \RuntimeException('Fixed transfer table absent.');
    }

    /** @return array<string, array> */
    public static function snapshot(PDO $db): array
    {
        $result = [];
        foreach (DatabaseTransferManifest::tables() as $table) {
            $order = implode(',', array_map(static fn (string $name): string => chr(96) . $name . chr(96), $table['primary']));
            $result[$table['name']] = ServerSchema::rows($db, 'SELECT * FROM ' . $table['name'] . ' ORDER BY ' . $order);
        }
        $result['sqlite_sequence'] = ServerSchema::rows($db, 'SELECT * FROM sqlite_sequence ORDER BY name');
        return $result;
    }

    /** Preserve all receipt identities while replacing the single canonical name cell. */
    public static function replaceArchiveName(string $directory, #[\SensitiveParameter] string $name): void
    {
        $envelope = JsonRecord::decode(RequiredFile::bytes(file_get_contents($directory . '/manifest.json')));
        $header = JsonRecord::checked($envelope['payload'] ?? null);
        $tables = JsonRecord::checked($header['tables'] ?? null);
        foreach (array_keys($tables) as $index) {
            $receipt = JsonRecord::checked($tables[$index] ?? null);
            if (($receipt['name'] ?? null) !== 'git_tokens') { continue; }
            $path = $directory . '/git_tokens.0.jsonl';
            $cells = JsonRecord::decode(rtrim(RequiredFile::bytes(file_get_contents($path)), characters: "\n"));
            $cells[1] = ['bytes', base64_encode($name)];
            $bytes = JsonEncoding::encode($cells) . "\n";
            $chunks = JsonRecord::checked($receipt['chunks'] ?? null);
            $chunk = JsonRecord::checked($chunks[0] ?? null);
            $chunk['bytes'] = strlen($bytes); $chunk['sha256'] = hash('sha256', $bytes);
            $receipt['sha256'] = hash('sha256', $bytes); $receipt['chunks'] = [$chunk];
            $tables[$index] = $receipt; $header['tables'] = $tables;
            $wire = JsonEncoding::encode(['payload' => $header, 'sha256' => hash('sha256', JsonEncoding::encode($header))]);
            if (file_put_contents($path, $bytes) !== strlen($bytes) || file_put_contents($directory . '/manifest.json', $wire) !== strlen($wire)) {
                throw new \RuntimeException('Synthetic archive mutation failed.');
            }
            return;
        }
        throw new \RuntimeException('Synthetic token archive absent.');
    }
}
