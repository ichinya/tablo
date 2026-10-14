<?php
declare(strict_types=1);
namespace Tablo\Tests\Support;

use PDO;
use RuntimeException;

/** Creates and seals only the new retention control's synthetic marker database. */
final class PasswordHelperDatabaseFixture
{
    public readonly string $path;
    public readonly string $sha256;
    public readonly int $bytes;
    public readonly string $journalMode;

    public function __construct(private readonly TemporaryDirectory $directory, public readonly string $scenario = 'retention')
    {
        $marker = match ($scenario) { 'retention' => 'retention-control', 'replacement' => 'replacement-control',
            default => throw new RuntimeException('Unregistered synthetic database recipe') };
        $this->path = $directory->path . '/synthetic.sqlite';
        if (file_exists($this->path) || is_link($this->path)) { throw new RuntimeException('Synthetic database path must be new'); }
        $db = new PDO('sqlite:' . $this->path);
        try {
            $db->exec('CREATE TABLE synthetic_marker (value TEXT)');
            \Tablo\SqlStatement::checked($db->prepare('INSERT INTO synthetic_marker VALUES (?)'))->execute([$marker]);
            $this->journalMode = FixtureValue::string(\Tablo\SqlStatement::checked($db->query('PRAGMA journal_mode'))->fetchColumn());
        } finally { $db = null; }
        $this->sha256 = \Tablo\RequiredFile::digest($this->path);
        $this->bytes = strlen(\Tablo\RequiredFile::bytes(file_get_contents($this->path)));
    }

    /** No caller-supplied database path is accepted. Altered/unsealed inputs are refused. */
    public function requireSealedSource(): void
    {
        $parent = realpath($this->directory->path);
        $path = realpath($this->path);
        if ($parent === false || $path === false || is_link($this->path)
            || str_replace('\\', replace: '/', subject: $path) !== str_replace('\\', replace: '/', subject: $parent) . '/synthetic.sqlite') {
            throw new RuntimeException('Synthetic database provenance path refused');
        }
        if ($this->journalMode !== 'delete' || file_exists($this->path . '-wal') || file_exists($this->path . '-shm')) {
            throw new RuntimeException('Synthetic database WAL requires separately observed coherent custody');
        }
        if (!hash_equals($this->sha256, \Tablo\RequiredFile::digest($this->path))) {
            throw new RuntimeException('Synthetic database provenance bytes changed');
        }
    }
}
