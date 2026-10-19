<?php
declare(strict_types=1);

namespace Tablo\Tests\Server;

use Tablo\DatabaseFailure;
use Tablo\DatabaseTransfer;
use Tablo\DatabaseTransferArchive;
use Tablo\DatabaseTransferManifest;
use Tablo\DatabaseTransferPreflight;
use Tablo\DatabaseTransferVerification;
use Tablo\JsonEncoding;
use Tablo\JsonRecord;
use Tablo\RequiredFile;
use Tablo\Tests\Support\EngineFixture;
use Tablo\Tests\Support\EngineSchedule;
use Testo\Assert;
use Testo\Test;

final class EngineTransferEnvelopeTest
{
    #[Test]
    public function sourceCardinalityThatCannotFitTheChunkBudgetRefusesBeforeExport(): void
    {
        $fixture = new EngineFixture('transfer-source-cardinality'); $root = EngineSchedule::directory() . '/cases/' . $fixture->case;
        $source = EngineTransferTest::source($root . '/source.sqlite', $fixture->vault);
        $source->beginTransaction();
        for ($batch = 0; $batch < 32; $batch++) {
            $values = [];
            for ($index = 0; $index < 512; $index++) { $values[] = "('synthetic-overflow-" . $batch . '-' . $index . "',1,2)"; }
            $source->exec('INSERT INTO login_limits (`key`,failures,window_start) VALUES ' . implode(',', $values));
        }
        $source->commit(); $before = $fixture->scalar('SELECT COUNT(*) FROM login_limits', $source);
        Assert::true($before > DatabaseTransferManifest::MAX_ROWS);
        $caught = null;
        try { DatabaseTransfer::preflight($source, $fixture->vault, $root . '/scratch.sqlite', offline: true); } catch (DatabaseFailure $error) { $caught = $error; }
        Assert::same($caught?->category, 'schema'); Assert::same($fixture->scalar('SELECT COUNT(*) FROM login_limits', $source), $before);
        Assert::false(file_exists($root . '/archive')); $fixture->close();
    }

    #[Test]
    public function oversizedSelfConsistentManifestIsRefusedBeforeChunkParsing(): void
    {
        $fixture = new EngineFixture('transfer-manifest-bytes'); $directory = self::archive($fixture);
        $envelope = JsonRecord::decode(RequiredFile::bytes(file_get_contents($directory . '/manifest.json')));
        $header = JsonRecord::checked($envelope['payload'] ?? null);
        $header['unexpectedPadding'] = str_repeat('x', DatabaseTransferManifest::MANIFEST_BYTES);
        self::manifest($directory, $header);
        $caught = null;
        try { DatabaseTransferVerification::manifest($directory, $fixture->vault); } catch (DatabaseFailure $error) { $caught = $error; }
        Assert::same($caught?->category, 'schema'); Assert::same($caught?->getPrevious(), null);
        $fixture->close();
    }

    #[Test]
    public function globalChunkCountRefusesMetadataBelowTheEncodedByteLimit(): void
    {
        $fixture = new EngineFixture('transfer-manifest-chunks'); $directory = self::archive($fixture);
        $envelope = JsonRecord::decode(RequiredFile::bytes(file_get_contents($directory . '/manifest.json')));
        $header = JsonRecord::checked($envelope['payload'] ?? null); $tables = JsonRecord::checked($header['tables'] ?? null);
        $table = JsonRecord::checked($tables[0] ?? null); $chunks = JsonRecord::checked($table['chunks'] ?? null);
        Assert::same($table['name'] ?? null, 'users'); Assert::same($table['rows'] ?? null, 1);
        $table['chunks'] = array_fill(0, DatabaseTransferManifest::MAX_CHUNKS + 1, JsonRecord::checked($chunks[0] ?? null));
        $table['rows'] = DatabaseTransferManifest::MAX_CHUNKS + 1; $tables[0] = $table; $header['tables'] = $tables;
        self::manifest($directory, $header);
        Assert::true(strlen(RequiredFile::bytes(file_get_contents($directory . '/manifest.json'))) < DatabaseTransferManifest::MANIFEST_BYTES);
        $caught = null;
        // This checks metadata admission directly; absent additional chunk files cannot cause a false pass.
        try { DatabaseTransferVerification::manifest($directory, $fixture->vault); } catch (DatabaseFailure $error) { $caught = $error; }
        Assert::same($caught?->category, 'schema'); $fixture->close();
    }

    #[Test]
    public function exhaustedExportBudgetRefusesBeforeWritingAnotherChunk(): void
    {
        $fixture = new EngineFixture('transfer-chunk-zero'); $root = EngineSchedule::directory() . '/cases/' . $fixture->case;
        $source = EngineTransferTest::source($root . '/source.sqlite', $fixture->vault);
        $scratch = DatabaseTransferPreflight::scratch($root . '/scratch.sqlite');
        $directory = $root . '/archive'; mkdir($directory, permissions: 0o700);
        $table = DatabaseTransferManifest::tables()[0] ?? throw new \RuntimeException('Expected fixed users table.');
        Assert::same($table['name'], 'users');
        $caught = null;
        try { DatabaseTransferArchive::write($source, $scratch, $table, $directory, remainingChunks: 0); } catch (DatabaseFailure $error) { $caught = $error; }
        Assert::same($caught?->category, 'schema'); Assert::false(file_exists($directory . '/users.0.jsonl'));
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM users', $source), 1);
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM users', $scratch), 0); $fixture->close();
    }

    public static function archive(EngineFixture $fixture): string
    {
        $root = EngineSchedule::directory() . '/cases/' . $fixture->case;
        $source = EngineTransferTest::source($root . '/source.sqlite', $fixture->vault);
        DatabaseTransfer::export($source, $fixture->vault, $root . '/archive', $root . '/export.sqlite', offline: true);
        return $root . '/archive';
    }

    public static function manifest(string $directory, array $header): void
    {
        $bytes = JsonEncoding::encode(['payload' => $header, 'sha256' => hash('sha256', JsonEncoding::encode($header))]);
        Assert::same(file_put_contents($directory . '/manifest.json', $bytes), strlen($bytes));
    }
}
