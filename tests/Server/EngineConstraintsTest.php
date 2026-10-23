<?php
declare(strict_types=1);

namespace Tablo\Tests\Server;

use Tablo\DatabaseFailure;
use Tablo\ServerExpression;
use Tablo\Tests\Support\EngineFixture;
use Testo\Assert;
use Testo\Test;

final class EngineConstraintsTest
{
    #[Test]
    public function expressionAdmissionPreservesGroupingAndLiteralBytes(): void
    {
        Assert::same(ServerExpression::canonical('((`id` > 0))'), ServerExpression::canonical('id > 0'));
        Assert::false(ServerExpression::canonical('id = 1 AND (enabled = 1 OR online = 0)') === ServerExpression::canonical('(id = 1 AND enabled = 1) OR online = 0'));
        Assert::false(ServerExpression::canonical("event = 'pending '") === ServerExpression::canonical("event = 'pending'"));
        Assert::same(ServerExpression::canonical("event = X'41'"), ServerExpression::canonical("event = 'A'"));
        engine_constraints_substring_aliases();
        engine_constraints_expression_failure_origins();
    }

    #[Test]
    public function predicateParserRefusesUnknownSyntaxAndFiniteBounds(): void
    {
        foreach (['id /* hidden */ = 1', 'unknown_function(id) = 1', 'id(1) = 1', str_repeat('(', times: 65) . 'id' . str_repeat(')', times: 65),
            str_repeat(' ', times: 16_385)] as $invalid) {
            $caught = null;
            try { ServerExpression::canonical($invalid); } catch (DatabaseFailure $error) { $caught = $error; }
            Assert::same($caught?->category, 'schema');
        }
    }

    #[Test]
    public function selectedRealEngineUsesNativeIntegerNullAndMatchedRows(): void
    {
        $fixture = new EngineFixture('constraints');
        $db = $fixture->database();
        $statement = \Tablo\SqlStatement::checked($db->query('SELECT CAST(9223372036854775807 AS SIGNED) AS maximum, CAST(-9223372036854775808 AS SIGNED) AS minimum, NULL AS absent, 0 AS zero, COUNT(*) AS count_value FROM users'));
        $row = \Tablo\Tests\Support\FixtureValue::fetched(\Tablo\SqlRows::one($statement->fetch(), static fn (array $value): array => $value));
        $statement->closeCursor();
        Assert::same($row['maximum'] ?? null, PHP_INT_MAX);
        Assert::same($row['minimum'] ?? null, PHP_INT_MIN);
        Assert::same($row['absent'] ?? 'not-null', 'not-null');
        Assert::true(array_key_exists('absent', $row));
        Assert::same($row['zero'] ?? null, 0);
        Assert::true(is_int($row['count_value'] ?? null));
        $update = \Tablo\SqlStatement::checked($db->prepare('UPDATE worker_runtime SET stop_requested = 0 WHERE id = ?'));
        $update->execute([1]); Assert::same($update->rowCount(), 1);
        $update->execute([2]); Assert::same($update->rowCount(), 0);
        $fixture->close();
    }

    #[Test]
    public function directSqlEnforcesAsciiOnlyGeneratedNamesAndNoPadText(): void
    {
        $fixture = new EngineFixture('constraints-names');
        $db = $fixture->database();
        $sql = 'INSERT INTO git_tokens (name,provider,encrypted_token,created_at,updated_at) VALUES (?, ?, ?, ?, ?)';
        $cipher = $fixture->vault->encrypt(\Tablo\Tests\Support\FixtureCredentials::value(label: 'constraints-token'));
        foreach (['Token', 'Space', 'Space ', 'É', 'é', 'ß', 'ss', "e\u{0301}", '🧪', 'Сайт', 'сайт'] as $name) {
            \Tablo\ServerSchema::rows($db, $sql, [$name, 'github', $cipher, '0001-01-01T00:00:00Z', '0001-01-01T00:00:00Z']);
        }
        Assert::same($fixture->nativeFailure($sql, ['token', 'github', $cipher, '0001-01-01T00:00:00Z', '0001-01-01T00:00:00Z']), 1062);
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM git_tokens'), 11);
        Assert::same($fixture->scalar("SELECT COUNT(*) FROM git_tokens WHERE name = 'Space'"), 1);
        Assert::same($fixture->scalar("SELECT COUNT(*) FROM git_tokens WHERE name = 'Space '"), 1);
        $fixture->close();
    }

    #[Test]
    public function directSqlChecksGregorianUtcIncludingEarlyYearsAndSingleton(): void
    {
        $fixture = new EngineFixture('constraints-calendar');
        $db = $fixture->database();
        $db->exec("INSERT INTO users (id,password_hash,created_at) VALUES (1,'synthetic-hash','0001-01-01T00:00:00Z')");
        $code = getenv('TABLO_TEST_DB_DRIVER') === 'mariadb' ? 4025 : 3819;
        foreach (['0000-01-01T00:00:00Z', '0100-02-29T00:00:00Z', '2026-02-29T00:00:00Z', '2024-04-31T00:00:00Z',
            '0001-01-01T24:00:00Z', '0001-01-01T00:60:00Z', '0001-01-01T00:00:60Z', '0001-01-01 00:00:00Z'] as $time) {
            Assert::same($fixture->nativeFailure('UPDATE users SET created_at = ?', [$time]), $code);
        }
        foreach (['0004-02-29T00:00:00Z', '0099-12-31T23:59:59Z', '0999-01-01T00:00:00Z', '2000-02-29T12:34:56Z'] as $time) {
            \Tablo\ServerSchema::rows($db, 'UPDATE users SET created_at = ?', [$time]);
            Assert::same(\Tablo\ServerSchema::rows($db, 'SELECT created_at FROM users'), [['created_at' => $time]]);
        }
        Assert::same($fixture->nativeFailure("INSERT INTO users (id,password_hash,created_at) VALUES (2,'synthetic-hash','0001-01-01T00:00:00Z')"), $code);
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM users'), 1);
        $fixture->close();
    }

    #[Test]
    public function nativeForeignKeysRestrictSavedTokenDeletionAndCascadeAllSiteDependents(): void
    { engine_constraints_foreign_keys(); }

    #[Test]
    public function directSqlRejectsMalformedDomainRowsAcrossEveryCurrentTable(): void
    { engine_constraints_domain_rows(); }

}

/** Server printers use SUBSTR for the standard SUBSTRING call; never rewrite literal bytes or argument trees. */
function engine_constraints_substring_aliases(): void
{
    foreach (['SUBSTR(created_at,1,4)', 'substr(created_at,1,4)', 'SuBsTr (created_at,1,4)'] as $alias) {
        Assert::same(ServerExpression::canonical($alias), ServerExpression::canonical('SUBSTRING(created_at,1,4)'));
    }
    Assert::false(ServerExpression::canonical('SUBSTR(created_at,2,4)') === ServerExpression::canonical('SUBSTRING(created_at,1,4)'));
    Assert::false(ServerExpression::canonical("event = 'substr(' ") === ServerExpression::canonical("event = 'substring(' "));
    foreach (['substr_oracle(created_at,1,4)', 'substring_index(created_at,1,4)', 'unknown_function(created_at,1,4)',
        'mariadb.substr(created_at,1,4)', 'SUBSTR(created_at FROM 1 FOR 4)'] as $invalid) {
        $category = null;
        try { ServerExpression::canonical($invalid); } catch (DatabaseFailure $error) { $category = $error->category; }
        Assert::same($category, 'schema');
    }
    foreach (['mysql84', 'mariadb114'] as $engine) {
        /** @var array{tables: list<array{checks: list<array{expression: string}>}>} $layout Checked-in public schema descriptor. */
        $layout = json_decode(\Tablo\RequiredFile::bytes(file_get_contents(dirname(__DIR__, levels: 2) . '/database/' . $engine . '-layout.json')),
            associative: true, flags: JSON_THROW_ON_ERROR);
        foreach ($layout['tables'] as $table) {
            foreach ($table['checks'] as $check) {
                $expression = $check['expression'];
                Assert::same(ServerExpression::canonical(str_replace('SUBSTRING(', replace: 'substr(', subject: $expression)),
                    ServerExpression::canonical($expression));
            }
        }
    }
}

/** Fixed guard origins distinguish failures without retaining any predicate text in diagnostics. */
function engine_constraints_expression_failure_origins(): void
{
    $lines = [];
    foreach ([str_repeat(' ', times: 16_385), 'id @ 1', str_repeat('id ', times: 2_049), 'unknown_word = 1', '(', 'id(1)'] as $invalid) {
        $caught = null;
        try { ServerExpression::canonical($invalid); } catch (DatabaseFailure $error) { $caught = $error; }
        Assert::same($caught?->category, 'schema');
        Assert::same($caught?->getMessage(), 'Database schema incompatible or in progress.');
        Assert::same($caught?->getPrevious(), null);
        Assert::same(basename($caught->getFile()), 'ServerExpressionTokens.php');
        $lines[] = $caught?->getLine();
    }
    Assert::same(count(array_unique($lines)), 6, 'each lexer guard must identify its own public source line');
}

function engine_constraints_foreign_keys(): void
{
    $fixture = new EngineFixture('constraints-foreign-keys');
    try {
        engine_constraints_current_data($fixture);
        Assert::same($fixture->nativeFailure('DELETE FROM git_tokens WHERE id = 7'), 1451);
        Assert::same($fixture->nativeFailure('UPDATE sites SET git_token_id = 999 WHERE id = 20'), 1452);
        Assert::same($fixture->scalar('SELECT git_token_id FROM sites WHERE id = 20'), 7);
        foreach (['worker_progress', 'check_history', 'incidents', 'incident_checkpoints', 'notification_checkpoints', 'notification_slots'] as $table) {
            Assert::same($fixture->scalar('SELECT COUNT(*) FROM ' . $table), 1, $table);
        }
        $fixture->database()->exec('DELETE FROM sites WHERE id = 20');
        foreach (['worker_progress', 'check_history', 'incidents', 'incident_checkpoints', 'notification_checkpoints', 'notification_slots'] as $table) {
            Assert::same($fixture->scalar('SELECT COUNT(*) FROM ' . $table), 0, $table);
        }
        $fixture->database()->exec('DELETE FROM git_tokens WHERE id = 7');
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM git_tokens'), 0);
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM users'), 1);
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM github_cooldowns'), 1);
        Assert::same($fixture->scalar('SELECT committed_history_high_watermark FROM tablo_database_state'), 9000);
    } finally { $fixture->close(); }
}

function engine_constraints_domain_rows(): void
{
    $fixture = new EngineFixture('constraints-domain-rows');
    try {
        engine_constraints_current_data($fixture); $code = getenv('TABLO_TEST_DB_DRIVER') === 'mariadb' ? 4025 : 3819;
        $invalid = ['UPDATE users SET id = 2', "UPDATE git_tokens SET name = ''", 'UPDATE sites SET enabled = 2',
            'UPDATE github_cooldowns SET eligible_at = -1', 'UPDATE installation_settings SET check_interval_minutes = 0',
            'UPDATE worker_runtime SET stop_requested = 2', 'UPDATE worker_progress SET config_revision = -1',
            'UPDATE check_history SET online = 2', 'UPDATE incidents SET uncertain = 2',
            'UPDATE incident_checkpoints SET last_history_id = 0', 'UPDATE notification_settings SET enabled = 2',
            'UPDATE notification_checkpoints SET last_history_id = 0', 'UPDATE notification_slots SET attempts = 4',
            "UPDATE read_api_token SET secret_hash = REPEAT('A',64)"];
        foreach ($invalid as $sql) { Assert::same($fixture->nativeFailure($sql), $code, $sql); }
        Assert::same($fixture->nativeFailure('UPDATE login_limits SET `key` = NULL'), 1048);
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM users'), 1);
        Assert::same($fixture->scalar('SELECT enabled FROM sites'), 1);
        Assert::same($fixture->scalar('SELECT attempts FROM notification_slots'), 2);
        Assert::same($fixture->scalar('SELECT committed_history_high_watermark FROM tablo_database_state'), 9000);
    } finally { $fixture->close(); }
}

function engine_constraints_current_data(EngineFixture $fixture): void
{
    $root = \Tablo\Tests\Support\EngineSchedule::directory() . '/cases/' . $fixture->case;
    $source = EngineTransferTest::source($root . '/source.sqlite', $fixture->vault);
    $digest = \Tablo\DatabaseTransfer::export($source, $fixture->vault, $root . '/archive', $root . '/export.sqlite', offline: true);
    Assert::same(\Tablo\DatabaseTransfer::importServer($fixture->maintenance(), $fixture->vault, $root . '/archive', $root . '/import.sqlite', offline: true), $digest);
    Assert::same($fixture->scalar('SELECT COUNT(*) FROM sites'), 1);
}
