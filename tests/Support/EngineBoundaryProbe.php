<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use Tablo\DatabaseFailure;
use Tablo\Tests\Server\EngineReadApiCommitPdo;

/** Isolated child-only transport substitution; production admission/response bodies remain unchanged. */
final class EngineBoundaryProbe
{
    public static ?\PDO $connection = null;

    public static function open(#[\SensitiveParameter] mixed $configuration = null): \PDO
    {
        unset($configuration);
        return self::$connection ?? throw new \RuntimeException('Probe connection absent.');
    }

    public static function run(string $mode, string $case): array
    {
        $fixture = new EngineFixture($case);
        try { return $mode === 'metadata' ? self::metadata($fixture) : self::rollback($fixture, $mode); }
        finally { self::$connection = null; $fixture->close(); }
    }

    private static function metadata(EngineFixture $fixture): array
    {
        $configuration = \Tablo\DatabaseConfiguration::fromEnvironment(environment: $fixture->environment());
        $fault = new EngineReadApiCommitPdo($fixture, 'metadata'); self::$connection = $fault; $results = [];
        foreach (['busy' => ['40001', 1213], 'unavailable' => ['HY000', 2013]] as $name => [$state, $code]) {
            $fault->prepareFailure = self::native($state, $code); $caught = null;
            try { \Tablo\ServerMetadataAdmission::observe($configuration); } catch (DatabaseFailure $error) { $caught = $error; }
            $results[$name] = self::failure($caught);
        }
        // A real reader account cannot impersonate the explicitly configured metadata account.
        self::$connection = new EngineReadApiCommitPdo($fixture, 'reader'); $caught = null;
        try { \Tablo\ServerMetadataAdmission::observe($configuration); } catch (DatabaseFailure $error) { $caught = $error; }
        $results['structural'] = self::failure($caught);
        return $results;
    }

    private static function rollback(EngineFixture $fixture, string $mode): array
    {
        require_once dirname(__DIR__, levels: 2) . '/app/read-api-repository.php';
        $fault = new EngineReadApiCommitPdo($fixture, 'reader'); self::$connection = $fault;
        $primaries = $mode === 'primary' ? [new DatabaseFailure('busy'), new \RuntimeException('Synthetic private primary diagnostic.'), self::native('HY000', 2013)] : [null];
        $results = [];
        foreach ($primaries as $primary) {
            $fault->queryFailure = $primary; $fault->failRollback = true; $fault->rollbackAttempts = 0; $returned = false; $caught = null;
            try {
                \Tablo\read_api_response(\Tablo\ReadApiToken::generate(), ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/v1/sites', 'QUERY_STRING' => '']);
                $returned = true;
            } catch (\Throwable $error) { $caught = $error; }
            $row = ['returned' => $returned, 'primaryPreserved' => $primary !== null && $caught === $primary, 'rollbackAttempts' => $fault->rollbackAttempts,
                'transactionBeforeCleanup' => $fault->inTransaction(), 'failure' => $caught instanceof DatabaseFailure ? self::failure($caught) : null];
            $fault->failRollback = false; $fault->exec('ROLLBACK'); $row['transactionClosed'] = !$fault->inTransaction();
            $results[] = $row;
        }
        return $results;
    }

    private static function native(string $state, int $code): \PDOException
    {
        $error = new \PDOException('Synthetic private native diagnostic.');
        $error->errorInfo = [$state, $code, 'Synthetic private native diagnostic.']; return $error;
    }

    private static function failure(?DatabaseFailure $error): array
    {
        return ['category' => $error?->category, 'message' => $error?->getMessage(), 'sqlState' => $error?->sqlState,
            'nativeCode' => $error?->nativeCode, 'nativeReason' => $error?->nativeReason, 'previousAbsent' => $error !== null && $error->getPrevious() === null];
    }
}

// Never substitute a connection when this support owner is merely autoloaded in a test controller.
if (realpath($_SERVER['SCRIPT_FILENAME']) !== __FILE__) { return; }
$mode = $argv[1] ?? ''; $case = $argv[2] ?? '';
if (!in_array($mode, ['metadata', 'primary', 'standalone'], strict: true)) { throw new \RuntimeException('Probe mode refused.'); }
class_alias(EngineBoundaryProbe::class, $mode === 'metadata' ? 'Tablo\\ServerPdoConnection' : 'Tablo\\ReadApiConnection');
require dirname(__DIR__, levels: 2) . '/vendor/autoload.php';
echo json_encode(EngineBoundaryProbe::run($mode, $case), JSON_THROW_ON_ERROR), "\n";
