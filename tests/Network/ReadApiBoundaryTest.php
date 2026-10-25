<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\ReadApiConnection;
use Tablo\ReadApiRepository;
use Tablo\ReadApiSchemaAdmission;
use Tablo\ReadApiTokenRepository;
use Tablo\Tests\Support\ReadApiAssertions as A;
use Tablo\Tests\Support\ReadApiFixture;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Testo\Assert;
use Testo\Test;

#[Test]
function public_api_missing_dependencies_and_ordinary_output_are_fixed_without_database_or_browser_effects(): void
{
    foreach (['absent','complete','output','exception'] as $fault) {
        $directory = new TemporaryDirectory('tablo-read-api-ingress-'); $server = null; $db = null; $tokens = null;
        try {
            $root = dirname(__DIR__, levels: 2); $copy = $directory->path . '/source'; mkdir($copy . '/public', permissions: 0o700, recursive: true); mkdir($copy . '/app');
            $files = read_api_copy_boundary_sources($root, $copy);
            $secret = ReadApiFixture::wrong(); $before = [];
            if ($fault !== 'absent') {
                $db = new \PDO('sqlite:' . $directory->path . '/current.sqlite', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
                \Tablo\Database::migrate($db); A::administrator($db); $tokens = new ReadApiTokenRepository($db);
                $fingerprint = ReadApiTokenRepository::administrator($db); Assert::notNull($fingerprint);
                $secret = $tokens->rotate($fingerprint, static fn (): bool => true); $before = A::snapshot($db);
                mkdir($copy . '/vendor'); file_put_contents($copy . '/vendor/autoload.php', read_api_fault_autoload($root, $copy, $files, $fault));
            }
            $router = $directory->path . '/router.php'; file_put_contents($router, data: '<?php require __DIR__ . "/source/public/index.php";');
            $database = $directory->path . ($fault === 'absent' ? '/absent.sqlite' : '/current.sqlite');
            $server = new TestServer($directory, $router, $copy . '/public', ['TABLO_DB' => $database, 'TABLO_HSTS_MODE' => 'off', 'TABLO_HSTS_MAX_AGE' => '']);
            ReadApiFixture::error(read_api_boundary_request($server), 401, 'unauthorized');
            $response = read_api_boundary_request($server, $secret);
            if ($fault === 'complete') { Assert::same($response['status'], 200); Assert::same(A::page($response['body'])['data'], []); }
            if ($fault !== 'complete') { ReadApiFixture::error($response, 500, 'unavailable'); }
            if ($db !== null) { Assert::same(A::snapshot($db), $before); }
            Assert::false(is_file($directory->path . '/absent.sqlite')); Assert::false(is_dir($copy . '/storage'));
            Assert::false(str_contains($server->diagnostics(), 'private-exception-canary'));
        } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
        finally {
            unset($tokens, $db);
            $directory->cleanup(static function () use ($server, $directory): void {
                $server?->close();
                if ($server !== null) { \Tablo\Tests\Support\read_api_fixture_close_port($server->port); }
                $directory->close();
            });
        }
    }
}

#[Test]
function schema_transition_after_connection_admission_is_checked_inside_the_actual_api_snapshot(): void
{
    foreach ([\Tablo\Database::CURRENT_SCHEMA_VERSION, \Tablo\Database::CURRENT_SCHEMA_VERSION - 1, \Tablo\Database::CURRENT_SCHEMA_VERSION + 1] as $version) {
        $directory = new TemporaryDirectory('tablo-read-api-schema-transition-'); $server = null; $db = null; $tokens = null;
        try {
            $root = dirname(__DIR__, levels: 2); $copy = $directory->path . '/source'; mkdir($copy . '/public', permissions: 0o700, recursive: true); mkdir($copy . '/app');
            $files = read_api_copy_boundary_sources($root, $copy);
            $path = $directory->path . '/current.sqlite';
            $db = new \PDO('sqlite:' . $path, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            \Tablo\Database::migrate($db); A::administrator($db); A::insert($db);
            $tokens = new ReadApiTokenRepository($db); $fingerprint = ReadApiTokenRepository::administrator($db); Assert::notNull($fingerprint);
            $secret = $tokens->rotate($fingerprint, static fn (): bool => true); $before = A::snapshot($db);
            read_api_commit_schema_on_open($copy, $version);
            mkdir($copy . '/vendor'); file_put_contents($copy . '/vendor/autoload.php', read_api_fault_autoload($root, $copy, $files, fault: 'complete'));
            $router = $directory->path . '/router.php'; file_put_contents($router, data: '<?php require __DIR__ . "/source/public/index.php";');
            $server = new TestServer($directory, $router, $copy . '/public', ['TABLO_DB' => $path, 'TABLO_HSTS_MODE' => 'off', 'TABLO_HSTS_MAX_AGE' => '']);
            $response = read_api_boundary_request($server, $secret);
            Assert::same(json_decode(\Tablo\RequiredFile::bytes(file_get_contents($copy . '/app/transition.json')), associative: true, flags: JSON_THROW_ON_ERROR),
                ['admitted' => \Tablo\Database::CURRENT_SCHEMA_VERSION, 'committed' => $version]);
            if ($version === \Tablo\Database::CURRENT_SCHEMA_VERSION) {
                Assert::same($response['status'], 200); ReadApiFixture::headers($response);
                Assert::same(array_column(A::page($response['body'])['data'], 'id'), ['1']);
            }
            if ($version !== \Tablo\Database::CURRENT_SCHEMA_VERSION) { ReadApiFixture::error($response, 500, 'unavailable'); }
            $expected = $before; $expected['version'] = [['user_version' => $version]];
            Assert::same(A::snapshot($db), $expected);
            Assert::false(is_dir($copy . '/storage'));
            Assert::false(str_contains($server->diagnostics(), $secret));
        } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
        finally {
            unset($tokens, $db);
            $directory->cleanup(static function () use ($server, $directory): void {
                $server?->close();
                if ($server !== null) { \Tablo\Tests\Support\read_api_fixture_close_port($server->port); }
                $directory->close();
            });
        }
    }
}

function read_api_commit_schema_on_open(string $copy, int $version): void
{
    // The real open/version/query_only admission still runs. Commit on another
    // native connection immediately before open returns, without a scheduling race.
    $connection = \Tablo\RequiredFile::bytes(file_get_contents($copy . '/app/ReadApiConnection.php'));
    $hook = '$admitted = SchemaVersion::schemaVersion($db);'
        . '$writer = new \\PDO("sqlite:" . ExistingConnection::resolvePath(null), options: [\\PDO::ATTR_ERRMODE => \\PDO::ERRMODE_EXCEPTION]);'
        . '$writer->exec("PRAGMA main.user_version = ' . $version . '"); $writer = null;'
        . 'file_put_contents(__DIR__ . "/transition.json", json_encode(["admitted" => $admitted, "committed" => ' . $version . '], JSON_THROW_ON_ERROR));';
    Assert::same(substr_count($connection, needle: 'return $db;'), 1);
    file_put_contents($copy . '/app/ReadApiConnection.php', str_replace('return $db;', $hook . 'return $db;', $connection));
}

/** @return list<string> */
function read_api_copy_boundary_sources(string $root, string $copy): array
{
    $files = ['public/index.php','app/RuntimeIni.php','app/ReadApi.php','app/ReadApiRequest.php','app/ReadApiResponse.php','app/read-api-repository.php',
        'app/HstsPolicy.php','app/HstsConfigurationError.php','app/ClientAddress.php','app/ProxyConfiguration.php','app/IpAddress.php',
        'app/ReadApiConnection.php','app/ReadApiSchemaAdmission.php','app/ReadApiRepository.php','app/ApiSiteDto.php','app/ApiSiteState.php',
        'app/ReadApiToken.php','app/ReadApiTokenRepository.php','app/read-api-token-repository.php','app/ReadApiTokenSchema.php','app/read-api-token-schema.php'];
    foreach ($files as $file) { copy($root . '/' . $file, $copy . '/' . $file); }
    return $files;
}

/** @param list<string> $files */
function read_api_fault_autoload(string $root, string $copy, array $files, string $fault): string
{
    $map = [];
    foreach ($files as $file) {
        $match = [];
        if (!str_starts_with($file, 'app/') || preg_match('~^app/([A-Z][A-Za-z]+)\.php$~D', $file, $match) !== 1) { continue; }
        $map['Tablo\\' . ($match[1] ?? '')] = $copy . '/' . $file;
    }
    $injection = match ($fault) { 'output' => 'echo "ordinary-output-canary";', 'exception' => 'throw new RuntimeException("private-exception-canary");', default => '' };
    return '<?php $loader=require ' . var_export($root . '/vendor/autoload.php', return: true) . '; $loader->addClassMap(' . var_export($map, return: true) . '); ' . $injection . ' return $loader;';
}

/** @return array{status: int, headers: string, body: string} */
function read_api_boundary_request(TestServer $server, #[\SensitiveParameter] ?string $secret = null): array
{
    $curl = curl_init($server->base . '/api/v1/sites');
    if ($curl === false) { throw new \RuntimeException('Cannot open boundary fixture client.'); }
    try {
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 8, CURLOPT_PROXY => '',
            CURLOPT_HTTPHEADER => $secret === null ? [] : ['Authorization: Bearer ' . $secret]]);
        $raw = curl_exec($curl);
        if (!is_string($raw)) { throw new \RuntimeException('Boundary fixture request failed.'); }
        $size = \Tablo\Tests\Support\FixtureValue::integer(curl_getinfo($curl, CURLINFO_HEADER_SIZE));
        return ['status' => \Tablo\Tests\Support\FixtureValue::integer(curl_getinfo($curl, CURLINFO_RESPONSE_CODE)),
            'headers' => substr($raw, offset: 0, length: $size), 'body' => substr($raw, $size)];
    } finally { unset($curl); }
}

#[Test]
function admitted_wal_read_snapshot_can_finish_after_revoke_and_later_old_token_reads_are_refused(): void
{
    $directory = new TemporaryDirectory('tablo-read-api-snapshot-'); $writer = null; $reader = null; $tokens = null;
    try {
        $path = $directory->path . '/current.sqlite'; $writer = \Tablo\Database::connect($path); A::administrator($writer); A::insert($writer);
        $fingerprint = ReadApiTokenRepository::administrator($writer); Assert::notNull($fingerprint);
        $tokens = new ReadApiTokenRepository($writer); $secret = $tokens->rotate($fingerprint, static fn (): bool => true);
        $reader = ReadApiConnection::open($path); $reader->exec('BEGIN'); ReadApiSchemaAdmission::authentication($reader);
        Assert::true((new ReadApiTokenRepository($reader))->authenticate($secret)); ReadApiSchemaAdmission::sites($reader);
        $before = A::snapshot($reader); $tokens->revoke($fingerprint, static fn (): bool => true);
        Assert::same(count((new ReadApiRepository($reader))->page()), 1); Assert::same(A::snapshot($reader), $before);
        $reader->exec('ROLLBACK'); Assert::false((new ReadApiTokenRepository($reader))->authenticate($secret));
        Assert::same(A::rows($writer, 'SELECT * FROM read_api_token'), []);
        $reader = null; $tokens = null; $writer = null;
    } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
    finally { unset($reader, $tokens, $writer); $directory->cleanup($directory->close(...)); }
}

#[Test]
function real_competing_rotations_and_revoke_serialize_and_post_lock_stale_owner_is_refused(): void
{
    foreach (['rotate','revoke','stale'] as $mode) { read_api_competing_case($mode); }
}

function read_api_competing_case(string $mode): void
{
    $directory = new TemporaryDirectory('tablo-read-api-competing-'); $db = null; $tokens = null; $statement = null; $children = [];
    try {
        $path = $directory->path . '/current.sqlite'; $db = \Tablo\Database::connect($path); A::administrator($db);
        $fingerprint = ReadApiTokenRepository::administrator($db); Assert::notNull($fingerprint);
        $tokens = new ReadApiTokenRepository($db); $old = $tokens->rotate($fingerprint, static fn (): bool => true);
        $script = $directory->path . '/child.php'; file_put_contents($script, read_api_competing_script());
        \Tablo\Tests\Support\SessionEvidence::rawFile($script, 'read-api-competing-input');
        if ($mode === 'stale') { $db->exec('BEGIN IMMEDIATE'); }
        if ($mode !== 'stale') {
            $children[] = read_api_start_competing($directory, $script, $path, mode: 'hold', label: 'first');
            read_api_wait_marker($directory->path . '/first.admitted');
        }
        $children[] = read_api_start_competing($directory, $script, $path, $mode, label: 'second');
        read_api_wait_marker($directory->path . '/second.ready');
        Assert::false(is_file($directory->path . '/second.admitted'), 'Competing request must not authorize before obtaining the write lock.');
        if ($mode === 'stale') {
            $statement = \Tablo\SqlStatement::checked($db->prepare('UPDATE users SET password_hash=? WHERE id=1'));
            try { $statement->execute([password_hash('synthetic-password-rehash', PASSWORD_DEFAULT)]); } finally { $statement->closeCursor(); }
            $db->exec('COMMIT');
        }
        if ($mode !== 'stale') { file_put_contents($directory->path . '/release', data: 'release'); }
        foreach ($children as &$child) { read_api_finish_competing($child, $directory); }
        unset($child);
        $expected = ['rotate' => 'replaced', 'revoke' => 'revoked', 'stale' => 'refused'];
        Assert::same(trim(\Tablo\RequiredFile::bytes(file_get_contents($directory->path . '/second.stdout'))), $expected[$mode] ?? 'invalid');
        Assert::false($tokens->authenticate($old));
        if ($mode === 'stale') { Assert::false(is_file($directory->path . '/second.admitted')); }
        if ($mode === 'revoke') { Assert::same(A::rows($db, 'SELECT * FROM read_api_token'), []); }
        $tokens = null; $db = null;
    } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
    finally {
        foreach ($children as &$child) { $directory->cleanup(static function () use (&$child, $directory): void { read_api_finish_competing($child, $directory); }); }
        unset($child, $statement, $tokens, $db); $directory->cleanup($directory->close(...));
    }
}

function read_api_competing_script(): string
{
    return <<<'PHP'
<?php
declare(strict_types=1);
require $argv[1] . '/vendor/autoload.php';
$db = \Tablo\ExistingConnection::openExistingConnection($argv[2]);
$mode = $argv[3]; $directory = $argv[4]; $label = $argv[5];
$expected = \Tablo\ReadApiTokenRepository::administrator($db);
if ($expected === null) { exit(2); }
file_put_contents($directory . '/' . $label . '.ready', 'ready');
$authorize = static function () use ($directory, $label, $mode): bool {
    file_put_contents($directory . '/' . $label . '.admitted', 'admitted');
    if ($mode !== 'hold') { return true; }
    $deadline = microtime(true) + 5;
    while (!is_file($directory . '/release')) {
        if (microtime(true) > $deadline) { throw new RuntimeException('Owned release deadline.'); }
        usleep(10000);
    }
    return true;
};
try {
    $repository = new \Tablo\ReadApiTokenRepository($db);
    if ($mode === 'revoke') { $repository->revoke($expected, $authorize); echo "revoked\n"; }
    else {
        $secret = $repository->rotate($expected, $authorize);
        if ($mode === 'hold') { echo "issued\n"; }
        elseif ($repository->authenticate($secret)) { echo "replaced\n"; }
        else { exit(3); }
    }
} catch (Throwable) {
    if ($mode !== 'stale') { fwrite(STDERR, "Owned competing action failed.\n"); exit(1); }
    echo "refused\n";
}
$db = null;
PHP;
}

/** @return array{process: resource|null, pid: int, argv: list<string>, stdout: string, stderr: string} */
function read_api_start_competing(TemporaryDirectory $directory, string $script, string $path, string $mode, string $label): array
{
    $argv = [PHP_BINARY, $script, dirname(__DIR__, levels: 2), $path, $mode, $directory->path, $label];
    $stdout = $directory->path . '/' . $label . '.stdout'; $stderr = $directory->path . '/' . $label . '.stderr'; $pipes = [];
    $process = \Tablo\Tests\Support\FixtureSpawn::append($argv, $stdout, $stderr, $pipes,
        ['cwd' => dirname(__DIR__, levels: 2), 'environment' => \Tablo\Tests\Support\FixtureSpawn::environment([])]);
    if (!is_resource($process)) { throw new \RuntimeException('Cannot start owned competing action.'); }
    $directory->holdProcess(); fclose(\Tablo\Tests\Support\FixturePipes::pipe($pipes, 0));
    $pid = proc_get_status($process)['pid'];
    \Tablo\Tests\Support\SessionEvidence::record('read-api-child-start', ['pid' => $pid, 'argv' => $argv, 'cwd' => dirname(__DIR__, levels: 2)]);
    return ['process' => $process, 'pid' => $pid, 'argv' => $argv, 'stdout' => $stdout, 'stderr' => $stderr];
}

function read_api_wait_marker(string $path): void
{
    $deadline = microtime(true) + 5;
    while (!is_file($path)) {
        if (microtime(true) > $deadline) { throw new \RuntimeException('Owned competing marker deadline.'); }
        usleep(10_000);
    }
}

/** @param array{process: resource|null, pid: int, argv: list<string>, stdout: string, stderr: string} $child */
function read_api_finish_competing(array &$child, TemporaryDirectory $directory): void
{
    $process = $child['process'];
    if (!is_resource($process)) { return; }
    $deadline = microtime(true) + 8; $status = proc_get_status($process);
    while ($status['running'] && microtime(true) < $deadline) { usleep(10_000); $status = proc_get_status($process); }
    $closed = \Tablo\Tests\Support\ServerClosure::close($process); $child['process'] = null; $directory->releaseProcess();
    \Tablo\Tests\Support\SessionEvidence::record('read-api-child-close', ['pid' => $child['pid'], 'argv' => $child['argv'], 'closed' => true, 'finalStatus' => $status, ...$closed]);
    \Tablo\Tests\Support\SessionEvidence::rawFile($child['stdout'], 'read-api-child-stdout');
    \Tablo\Tests\Support\SessionEvidence::rawFile($child['stderr'], 'read-api-child-stderr');
    Assert::false($status['running'], 'Owned child failed to exit before its unchanged deadline.');
    Assert::same($status['exitcode'], 0, 'Owned child native exit must be zero.');
}
