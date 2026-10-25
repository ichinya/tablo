<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Tablo\Tests\Support\DashboardModesFixture;

// Parent must hash-copy source, allowlist its environment and retain this fresh namespace.
if (getenv('TABLO_DASHBOARD_RETAIN') !== '1' || getenv('TABLO_SESSION_EVIDENCE_DIRECTORY') === false) {
    throw new RuntimeException('Browser launcher requires an owned evidence namespace.');
}
$fixture = new DashboardModesFixture();
try {
    $screenshots = ($argv[1] ?? '') === '--screenshots';
    $ids = DashboardModesFixture::seed($fixture->web->database(), $screenshots ? null : $fixture->peer->base, $screenshots ? 'demo' : 'matrix');
    echo json_encode(['ready' => true, 'base' => $fixture->web->server->base, 'peer' => $fixture->peer->base,
        'cookieJar' => $fixture->web->directory->path . '/cookies', 'ids' => $ids, 'csrf' => $fixture->csrf,
        'fixture' => $fixture->web->directory->path, 'peerFixture' => $fixture->directory->path,
        'ports' => [$fixture->web->server->port, $fixture->peer->port]], JSON_THROW_ON_ERROR) . "\n";
    fflush(STDOUT);
    while (($line = fgets(STDIN)) !== false) {
        $command = \Tablo\JsonRecord::decode($line, 8);
        $operation = \Tablo\SqlColumn::string($command, 'operation');
        if ($operation === 'close') { break; }
        if ($operation === 'release_database') {
            $fixture->web->reopenDatabase();
            echo json_encode(['operation' => $operation, 'databaseReleased' => true], JSON_THROW_ON_ERROR) . "\n";
            fflush(STDOUT);
            continue;
        }
        if ($operation === 'clock') { $fixture->clock(\Tablo\SqlColumn::integer($command, 'epoch')); }
        if ($operation === 'empty') { $fixture->web->database()->exec('DELETE FROM sites'); }
        if ($operation === 'long') {
            $statement = \Tablo\SqlStatement::checked($fixture->web->database()->prepare('UPDATE sites SET name=?, branch=?, last_error=?, url=? WHERE id=?'));
            $text = str_repeat('ДлинныйUnicodeЖ', times: 30);
            try { $statement->execute([$text, 'feature/' . $text, $text, 'https://long.example.com/' . str_repeat('long-path/', times: 40), \Tablo\Tests\Support\FixtureValue::integer(\Tablo\Tests\Support\FixtureValue::at($ids, 0))]); }
            finally { $statement->closeCursor(); unset($statement); }
        }
        echo json_encode(['operation' => $operation, 'counts' => $fixture->counts(), 'peerCount' => $fixture->peerCount(),
            'timestamps' => $operation === 'clock' ? [] : $fixture->timestamps()], JSON_THROW_ON_ERROR) . "\n";
        fflush(STDOUT);
    }
} catch (Throwable $error) {
    $fixture->web->directory->retainFailure($error);
    $fixture->directory->retainFailure($error);
    throw $error;
} finally { $fixture->directory->cleanup($fixture->close(...)); }
