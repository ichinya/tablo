<?php
declare(strict_types=1);

namespace Tablo\Tests\Server;

use Tablo\DatabaseConfiguration;
use Tablo\DatabaseFailure;
use Tablo\DatabaseInteger;
use Tablo\ServerExpression;
use Testo\Assert;
use Testo\Test;

final class EngineConfigurationTest
{
    #[Test]
    public function binaryPredicateLiteralsNormalizeOnlyProvenStringForms(): void
    { engine_configuration_binary_predicate_literals(); }

    #[Test]
    public function predicateLiteralIntroducersAndUnprovenHexContextsStayStrict(): void
    { engine_configuration_predicate_literal_refusals(); }

    #[Test]
    public function sqliteDefaultAndLegacyPathDoNotRequireServerCredentials(): void
    {
        Assert::same(DatabaseConfiguration::fromEnvironment(environment: [])->engine, 'sqlite');
        Assert::same(DatabaseConfiguration::fromEnvironment(environment: ['TABLO_DB' => 'file:synthetic.sqlite?mode=rw'])->engine, 'sqlite');
        Assert::false(DatabaseConfiguration::fromEnvironment(environment: ['TABLO_DB_DRIVER' => 'sqlite'])->server());
    }

    #[Test]
    public function rejectsAmbiguousSelectionAndDsnComponents(): void
    {
        foreach ([['TABLO_DB_DRIVER' => ''], ['TABLO_DB_DRIVER' => 'pgsql'], ['TABLO_DB_NAME' => 'unexpected'],
            ['TABLO_DB_DRIVER' => 'sqlite', 'TABLO_DB_PASSWORD' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'configuration-password')],
            ['TABLO_DB' => 'legacy.sqlite'], ['TABLO_DB_HOST' => 'localhost'], ['TABLO_DB_HOST' => 'host;dbname=other'],
            ['TABLO_DB_PORT' => '03306'], ['TABLO_DB_PORT' => '65536'], ['TABLO_DB_PORT' => '0'],
            ['TABLO_DB_NAME' => 'name;host=other'], ['TABLO_DB_SOCKET' => '/synthetic/socket'],
            ['TABLO_DB_TLS' => 'disabled'], ['TABLO_DB_TLS' => 'local', 'TABLO_DB_HOST' => 'remote.invalid']] as $index => $invalid) {
            $base = $index < 4 ? [] : self::local();
            self::refuses(array_replace($base, $invalid));
        }
    }

    #[Test]
    public function roleCredentialsAreIndependentAndEmptyPasswordIsExplicit(): void
    {
        $reader = ['TABLO_DB_DRIVER' => 'mariadb', 'TABLO_DB_NAME' => 'synthetic', 'TABLO_DB_HOST' => '127.0.0.1',
            'TABLO_DB_PORT' => '3306', 'TABLO_DB_TLS' => 'local', 'TABLO_DB_READ_USER' => 'reader', 'TABLO_DB_READ_PASSWORD' => ''];
        $configuration = DatabaseConfiguration::fromEnvironment('reader', $reader);
        Assert::same($configuration->engine, 'mariadb');
        Assert::same($configuration->password, '');
        Assert::same($configuration->dsn(), 'mysql:host=127.0.0.1;port=3306;dbname=synthetic;charset=utf8mb4');
        self::refuses($reader);
        unset($reader['TABLO_DB_READ_PASSWORD']);
        self::refuses($reader, 'reader');
    }

    #[Test]
    public function verifiedTlsAndSocketPoliciesCannotDowngrade(): void
    {
        $tls = self::local(); unset($tls['TABLO_DB_TLS']);
        self::refuses($tls);
        $tls['TABLO_DB_TLS_CA'] = '/synthetic/ca.pem';
        $configuration = DatabaseConfiguration::fromEnvironment(environment: $tls);
        Assert::same($configuration->tls, 'verify');
        $tls['TABLO_DB_TLS_CERT'] = '/synthetic/client.pem';
        self::refuses($tls);
        $tls['TABLO_DB_TLS_KEY'] = '/synthetic/client.key';
        Assert::same(DatabaseConfiguration::fromEnvironment(environment: $tls)->certificate, '/synthetic/client.pem');
        $socket = self::local(); unset($socket['TABLO_DB_HOST'], $socket['TABLO_DB_PORT']);
        $socket['TABLO_DB_SOCKET'] = '/synthetic/mysql.sock';
        Assert::same(DatabaseConfiguration::fromEnvironment(environment: $socket)->socket, '/synthetic/mysql.sock');
        $socket['TABLO_DB_TLS_CA'] = '/synthetic/ca.pem'; self::refuses($socket);
    }

    #[Test]
    public function integerBoundaryPreservesNullFalseAndCanonicalSigned64(): void
    {
        foreach ([PHP_INT_MIN, -1, 0, 1, PHP_INT_MAX] as $integer) { Assert::same(DatabaseInteger::checked((string) $integer), $integer); }
        foreach ([null, false, true, 1.0, '', '+1', '-0', '01', '1 ', '1e2', '9223372036854775808', '-9223372036854775809'] as $invalid) {
            $category = null;
            try { DatabaseInteger::checked($invalid); } catch (DatabaseFailure $error) { $category = $error->category; }
            Assert::same($category, 'schema');
        }
    }

    /** @return array<string, string> */
    private static function local(): array
    {
        return ['TABLO_DB_DRIVER' => 'mysql', 'TABLO_DB_NAME' => 'synthetic', 'TABLO_DB_HOST' => '127.0.0.1',
            'TABLO_DB_PORT' => '3306', 'TABLO_DB_TLS' => 'local', 'TABLO_DB_USER' => 'writer', 'TABLO_DB_PASSWORD' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'configuration-password')];
    }

    /** @param array<string, string> $environment */
    private static function refuses(array $environment, string $role = 'writer'): void
    {
        $caught = null;
        try { DatabaseConfiguration::fromEnvironment($role, $environment); } catch (DatabaseFailure $error) { $caught = $error; }
        Assert::same($caught?->category, 'configuration');
        Assert::same($caught?->getMessage(), 'Database configuration invalid.');
        Assert::same($caught?->getPrevious(), null);
    }
}

function engine_configuration_binary_predicate_literals(): void
{
    $expected = ServerExpression::canonical("event = 'A'");
    foreach (["event = _binary'A'", "event = _binary 'A'", "event = _BINARY\n'A'",
        "event = _binary X'41'", "event = _binary x'41'", 'event = _binary 0x41'] as $sql) {
        Assert::same(ServerExpression::canonical($sql), $expected);
    }
    $journal = ServerExpression::canonical("phase IN ('intent','done','failed')");
    foreach (["phase IN (_binary 'intent',_binary 'done',_binary 'failed')",
        "phase IN (_binary X'696E74656E74',_binary X'646F6E65',_binary X'6661696C6564')",
        'phase IN (_binary 0x696E74656E74,_binary 0x646f6e65,_binary 0x6661696c6564)'] as $sql) {
        Assert::same(ServerExpression::canonical($sql), $journal);
    }
    Assert::same(ServerExpression::canonical('event = _binary 0xf'), ServerExpression::canonical("event = X'0f'"));
    Assert::same(ServerExpression::canonical('event = _binary 0x000f'), ServerExpression::canonical("event = X'000f'"));
    Assert::same(ServerExpression::canonical("event = _binary X''"), ServerExpression::canonical("event = ''"));
    Assert::same(ServerExpression::canonical("event = _binary 'can''t'"), ServerExpression::canonical("event = 'can''t'"));
    Assert::same(ServerExpression::canonical('event = _binary 0x00'), ServerExpression::canonical("event = X'00'"));
    foreach (["event = _binary 0x4120", 'event = _binary 0x61', 'event = 65', 'event = _binary 0x0041'] as $different) {
        Assert::false(ServerExpression::canonical($different) === $expected);
    }
}

function engine_configuration_predicate_literal_refusals(): void
{
    foreach (["event = _unknown'A'", "event = _utf16'A'", "event = _utf8mb4'A'", "event = _latin1'A'",
        "event = _binaryx'41'", 'event = _binary0x41', 'event = _binary 0X41', 'event = _binary 0x',
        'event = _binary 0xGG', "event = _binary X'4'", "event = _binary X'GG'", 'event = 0x41',
        'event = 0X41', "event = 'a' COLLATE utf8mb4_0900_ai_ci", "event = _binary 'a' COLLATE binary",
        "event /* hidden */ = _binary 'A'"] as $invalid) {
        $category = null;
        try { ServerExpression::canonical($invalid); } catch (DatabaseFailure $error) { $category = $error->category; }
        Assert::same($category, 'schema', $invalid);
    }
}
