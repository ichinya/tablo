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
        engine_constraints_modulo_forms();
        engine_constraints_mysql_metadata();
        engine_constraints_replace_hex();
        engine_constraints_mysql_metadata_bytes();
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

function engine_constraints_modulo_forms(): void
{
    foreach (['id MOD step' => 'MOD(id,step)', 'id % step' => 'MOD(id,step)',
        'id MOD step MOD cursor' => 'MOD(MOD(id,step),cursor)',
        'id * step MOD cursor' => 'MOD(id * step,cursor)', 'id MOD step * cursor' => 'MOD(id,step) * cursor',
        'id + step MOD cursor' => 'id + MOD(step,cursor)', '-id MOD step' => 'MOD(-id,step)',
        'id % 0' => 'MOD(id,0)'] as $actual => $expected) {
        Assert::same(ServerExpression::canonical($actual), ServerExpression::canonical($expected));
    }
    Assert::false(ServerExpression::canonical('id MOD step MOD cursor') === ServerExpression::canonical('id MOD (step MOD cursor)'));
    Assert::false(ServerExpression::canonical('id * step MOD cursor') === ServerExpression::canonical('id * (step MOD cursor)'));
    Assert::false(ServerExpression::canonical('-0 MOD step') === ServerExpression::canonical('0 MOD step'));
    Assert::false(ServerExpression::canonical("event = '%' ") === ServerExpression::canonical("event = 'MOD' "));
    /** @var \Tablo\ServerSchema::Layout $layout Checked-in public descriptor, including the complete timestamp predicate. */
    $layout = json_decode(\Tablo\RequiredFile::bytes(file_get_contents(dirname(__DIR__, levels: 2) . '/database/mariadb114-layout.json')),
        associative: true, flags: JSON_THROW_ON_ERROR);
    $users = $layout['tables'][3] ?? throw new \RuntimeException('Missing public users descriptor.');
    $check = $users['checks'][1] ?? throw new \RuntimeException('Missing public timestamp constraint.');
    $timestamp = $check['expression'];
    foreach (['MOD', '%'] as $operator) {
        $actual = $timestamp;
        foreach ([4, 100, 400] as $divisor) {
            $needle = 'MOD(CAST(SUBSTRING(`created_at`,1,4) AS UNSIGNED),' . $divisor . ')';
            Assert::same(substr_count($actual, $needle), 1, 'the public timestamp fixture contains this exact modulo call');
            $actual = str_replace($needle, '(CAST(SUBSTR(`created_at`,1,4) AS UNSIGNED) ' . $operator . ' ' . $divisor . ')', $actual);
        }
        $actual = str_replace('SUBSTRING(', replace: 'substr(', subject: $actual);
        Assert::same(ServerExpression::canonical($actual), ServerExpression::canonical($timestamp));
    }
}

function engine_constraints_mysql_metadata(): void
{
    /** @var \Tablo\ServerSchema::Layout $layout Checked-in public MySQL descriptor. */
    $layout = json_decode(\Tablo\RequiredFile::bytes(file_get_contents(dirname(__DIR__, levels: 2) . '/database/mysql84-layout.json')),
        associative: true, flags: JSON_THROW_ON_ERROR);
    foreach ($layout['tables'] as $table) {
        foreach ($table['checks'] as $check) {
            $encoded = engine_constraints_mysql_encode($check['expression']);
            Assert::same(ServerExpression::canonical(ServerExpression::decodeMysqlMetadata($encoded)), ServerExpression::canonical($check['expression']));
        }
        foreach ($table['columns'] as $column) {
            if (!array_key_exists('generated', $column)) { continue; }
            $encoded = engine_constraints_mysql_encode($column['generated']);
            $actual = ['generation' => ServerExpression::decodeMysqlMetadata($encoded), 'extra' => $column['name'] === 'unresolved_site_id' ? 'VIRTUAL GENERATED' : 'STORED GENERATED'];
            \Tablo\ServerCheckAdmission::generated($column, $actual);
            Assert::same(ServerExpression::canonical(ServerExpression::decodeMysqlMetadata($encoded)), ServerExpression::canonical($column['generated']));
        }
    }
    $plain = "phase IN (_binary'intent',_binary'done',_binary'failed')";
    $encoded = engine_constraints_mysql_encode($plain);
    foreach ([$encoded, engine_constraints_mysql_encode($encoded)] as $invalid) {
        $category = null;
        try { ServerExpression::canonical($invalid); } catch (DatabaseFailure $error) { $category = $error->category; }
        Assert::same($category, 'schema', 'plain SQL never gains implicit metadata decoding');
    }
    foreach ([$plain, "id \\q PRIVATE", 'id' . chr(92), engine_constraints_mysql_encode($encoded), str_repeat(' ', times: 32_769)] as $invalid) {
        $caught = null;
        try { ServerExpression::canonical(ServerExpression::decodeMysqlMetadata($invalid)); } catch (DatabaseFailure $error) { $caught = $error; }
        Assert::same($caught?->getMessage(), 'Database schema incompatible or in progress.');
        Assert::same($caught?->getPrevious(), null);
        Assert::same($caught?->category, 'schema');
    }
    Assert::false(ServerExpression::canonical(ServerExpression::decodeMysqlMetadata(engine_constraints_mysql_encode("event = 'intent'")))
        === ServerExpression::canonical("event = 'done'"));
}

/** Independent producer fixture: the six fixed escapes emitted by MySQL String::print. */
function engine_constraints_mysql_encode(string $sql): string
{
    return strtr($sql, [chr(92) => chr(92) . chr(92), "'" => chr(92) . "'", "\0" => chr(92) . '0',
        "\n" => chr(92) . 'n', "\r" => chr(92) . 'r', chr(26) => chr(92) . 'Z']);
}

/** Only a complete direct REPLACE argument has the proven binary-string context. */
function engine_constraints_replace_hex(): void
{
    foreach (["REPLACE(name,0x41,0x61)" => "REPLACE(name,X'41',X'61')",
        "REPLACE(0x4142,0x41,0x61)" => "REPLACE(X'4142',X'41',X'61')",
        "REPLACE(name,0xf,0x000F)" => "REPLACE(name,X'0f',X'000f')",
        "((REPLACE(name,0xAa,0xbB)))" => "REPLACE(name,X'aa',X'bb')",
        "ASCII(REPLACE(name,0x41,0x61))" => "ASCII(REPLACE(name,X'41',X'61'))",
        "REPLACE(name,X'',0x61)" => "REPLACE(name,X'',X'61')"] as $actual => $expected) {
        Assert::same(ServerExpression::canonical($actual), ServerExpression::canonical($expected));
    }
    Assert::false(ServerExpression::canonical('REPLACE(name,0x00,0x61)') === ServerExpression::canonical('REPLACE(name,0x0000,0x61)'));
    Assert::false(ServerExpression::canonical("REPLACE(name,'0x41',0x61)") === ServerExpression::canonical('REPLACE(name,0x41,0x61)'));
    foreach (['event = 0x41', '0x41 + 0', 'CAST(0x41 AS BINARY)', 'ASCII(0x41)', 'SUBSTR(0x41,1,1)',
        'REPLACE(name,(0x41),0x61)', 'REPLACE(name,0x41 + 0,0x61)', 'REPLACE(name,-0x41,0x61)',
        'REPLACE(name,CAST(0x41 AS BINARY),0x61)', 'REPLACE(name,ASCII(0x41),0x61)',
        'REPLACE(name,0X41,0x61)', 'REPLACE(name,0x,0x61)', 'REPLACE(name,0x41G,0x61)',
        'REPLACE(name,0x41/* comment */,0x61)', 'REPLACE(name,0x41)', 'REPLACE(name,0x41,0x61,0x62)'] as $invalid) {
        $caught = null;
        try { ServerExpression::canonical($invalid); } catch (DatabaseFailure $error) { $caught = $error; }
        Assert::same($caught?->category, 'schema');
        Assert::same($caught?->getMessage(), 'Database schema incompatible or in progress.');
        Assert::same($caught?->getPrevious(), null);
    }
    engine_constraints_generated_hex();
}

function engine_constraints_generated_hex(): void
{
    /** @var \Tablo\ServerSchema::Layout $layout Checked-in public MySQL descriptor. */
    $layout = json_decode(\Tablo\RequiredFile::bytes(file_get_contents(dirname(__DIR__, levels: 2) . '/database/mysql84-layout.json')),
        associative: true, flags: JSON_THROW_ON_ERROR);
    $seen = 0;
    foreach ($layout['tables'] as $table) {
        foreach ($table['columns'] as $column) {
            if (!array_key_exists('generated', $column)) { continue; }
            $expression = $column['generated'];
            if ($column['name'] === 'name_key') {
                $count = 0;
                $expression = preg_replace_callback("/X'([0-9a-fA-F]+)'/", static fn (array $match): string => '0x' . strtolower(\Tablo\RegexCapture::text($match, 1)), $expression, count: $count)
                    ?? throw new \RuntimeException('Invalid public generated expression fixture.');
                Assert::same($count, 52);
                Assert::false($expression === $column['generated']);
            }
            $actual = ['generation' => ServerExpression::decodeMysqlMetadata(engine_constraints_mysql_encode($expression)),
                'extra' => $column['name'] === 'unresolved_site_id' ? 'VIRTUAL GENERATED' : 'STORED GENERATED'];
            \Tablo\ServerCheckAdmission::generated($column, $actual);
            $mutated = $column['name'] === 'name_key' ? str_replace('0x61', replace: '0x62', subject: $expression) : '(' . $expression . ') + 0';
            Assert::false($mutated === $expression);
            $actual['generation'] = ServerExpression::decodeMysqlMetadata(engine_constraints_mysql_encode($mutated));
            $category = null;
            try { \Tablo\ServerCheckAdmission::generated($column, $actual); } catch (DatabaseFailure $error) { $category = $error->category; }
            Assert::same($category, 'schema');
            $seen++;
        }
    }
    Assert::same($seen, 2);
}

/** Check the wrapper's complete byte alphabet and both finite size boundaries independently of SQL parsing. */
function engine_constraints_mysql_metadata_bytes(): void
{
    foreach (["'", chr(92), "\0", "\n", "\r", chr(26), "\t", "id = '" . chr(92) . "n'", chr(92) . "'\0\n\r" . chr(26)] as $plain) {
        Assert::same(ServerExpression::decodeMysqlMetadata(engine_constraints_mysql_encode($plain)), $plain);
    }
    $boundary = str_repeat("'", times: 16_384);
    Assert::same(strlen(engine_constraints_mysql_encode($boundary)), 32_768);
    Assert::same(ServerExpression::decodeMysqlMetadata(engine_constraints_mysql_encode($boundary)), $boundary);
    foreach (["\0", "\n", "\r", chr(26), chr(92) . 't', str_repeat(' ', times: 16_385)] as $invalid) {
        $caught = null;
        try { ServerExpression::decodeMysqlMetadata($invalid); } catch (DatabaseFailure $error) { $caught = $error; }
        Assert::same($caught?->category, 'schema');
        Assert::same($caught?->getMessage(), 'Database schema incompatible or in progress.');
        Assert::same($caught?->getPrevious(), null);
    }
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
