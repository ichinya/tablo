<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use PDO;
use RuntimeException;
use Tablo\Database;
use Tablo\SchemaCheckShape;
use Tablo\SchemaColumnShape;
use Testo\Assert;
use Testo\Test;

final class SqlSchemaAdmissionTest extends SqlSchemaFixtureCase
{
    #[Test]
    public function refusesMissingAttributesAndKeywordNamesBeforeCurrentOrHistoricalEffects(): void
    {
        foreach (['current8', 'history5'] as $lineage) {
            foreach (['id INTEGER PRIMARY KEY', 'id INTEGER CONSTRAINT "autoincrement" PRIMARY KEY',
                "id INTEGER CONSTRAINT 'autoincrement' PRIMARY KEY", 'id INTEGER CONSTRAINT [AUTOINCREMENT] PRIMARY KEY',
                'id INTEGER CONSTRAINT `autoincrement` PRIMARY KEY', 'id INTEGER /* AUTOINCREMENT */ PRIMARY KEY',
                "id INTEGER -- AUTOINCREMENT\n PRIMARY KEY", 'id INTEGER CONSTRAINT "CHECK(autoincrement)" PRIMARY KEY'] as $declaration) {
                $db = self::database($lineage);
                self::redeclare($db, $declaration);
                $db->beginTransaction();
                $db->exec("INSERT OR IGNORE INTO users(id,password_hash) VALUES(1,'owned-caller-marker')");
                $before = self::snapshot($db);
                $db->statements = [];
                $error = self::error(static fn () => Database::migrate($db));
                Assert::instanceOf($error, RuntimeException::class, $declaration);
                Assert::same($error->getMessage(), 'Incompatible SQLite schema layout.');
                Assert::same(self::snapshot($db), $before);
                Assert::true($db->inTransaction());
                Assert::same(array_values(array_filter($db->statements,
                    static fn (string $sql): bool => preg_match('/^(BEGIN|CREATE|ALTER|DROP|INSERT|UPDATE|COMMIT|ROLLBACK)/', $sql) === 1)), []);
                $db->rollBack();
            }
        }
    }

    #[Test]
    public function genuineAttributesWithQuotedNamesAndChecksPreservePrunedHighWater(): void
    {
        foreach (['current8', 'history5'] as $lineage) {
            foreach (['id INTEGER CONSTRAINT "autoincrement" PRIMARY KEY AUTOINCREMENT',
                '"id" integer constraint [AUTOINCREMENT] primary /* attribute */ key autoincrement',
                '`id` INTEGER CONSTRAINT `autoincrement` PRIMARY KEY AUTOINCREMENT',
                "'id' INTEGER CONSTRAINT 'autoincrement' PRIMARY KEY AUTOINCREMENT"] as $declaration) {
                $db = self::database($lineage);
                self::redeclare($db, $declaration, self::quotedChecks(...));
                Assert::same(self::insertHistory($db), 1);
                $db->exec("UPDATE sqlite_sequence SET seq=900 WHERE name='check_history'");
                $db->exec('DELETE FROM check_history');
                Database::migrate($db);
                Assert::same(self::insertHistory($db), 901);
                Assert::same((int) \Tablo\SqlStatement::checked($db->query('PRAGMA user_version'))->fetchColumn(), \Tablo\Database::CURRENT_SCHEMA_VERSION);
                Assert::same(\Tablo\SqlStatement::checked($db->query('PRAGMA foreign_key_check'))->fetchAll(PDO::FETCH_ASSOC), []);
            }
        }
    }

    #[Test]
    public function quotedDefaultsAndCheckTermsRemainDistinctFromKeywords(): void
    {
        sql_schema_tokenizer_public_contract();
        \Tablo\Tests\Support\ServerExpressionLeaves::check();
        $wanted = new PDO('sqlite::memory:');
        $actual = new PDO('sqlite::memory:');
        $wanted->exec('CREATE TABLE probe(id INTEGER PRIMARY KEY, value TEXT DEFAULT NULL CHECK(value IS NULL))');
        $actual->exec('CREATE TABLE probe(id INTEGER PRIMARY KEY, value TEXT DEFAULT "null" CHECK(value IS "null"))');
        $wanted->exec('INSERT INTO probe DEFAULT VALUES');
        $actual->exec('INSERT INTO probe DEFAULT VALUES');
        Assert::same(\Tablo\SqlStatement::checked($wanted->query('SELECT value FROM probe'))->fetchColumn(), null);
        Assert::same(\Tablo\SqlStatement::checked($actual->query('SELECT value FROM probe'))->fetchColumn(), 'null');
        Assert::notSame(SchemaColumnShape::columns($actual, 'probe'), SchemaColumnShape::columns($wanted, 'probe'));
        $actualSql = \Tablo\Tests\Support\FixtureValue::string(\Tablo\SqlStatement::checked($actual->query("SELECT sql FROM sqlite_schema WHERE name='probe'"))->fetchColumn());
        $wantedSql = \Tablo\Tests\Support\FixtureValue::string(\Tablo\SqlStatement::checked($wanted->query("SELECT sql FROM sqlite_schema WHERE name='probe'"))->fetchColumn());
        Assert::instanceOf(self::error(static fn () => SchemaCheckShape::validate($actualSql, $wantedSql, version: 8, missing: [])), RuntimeException::class);
    }
}

/** Independent literal vocabulary from the original 200ff14 tokenizer, never its current private storage.
 * @return list<string> */
function sql_schema_tokenizer_identifiers(): array
{
    return explode(' ', 'plan step checksum predecessor target before_predicate after_predicate phase cursor failure_category '
        . 'id format logical_version layout_checksum installation_id key_verifier committed_history_high_watermark password_hash '
        . 'created_at name provider encrypted_token updated_at name_key url repository github_token git_token_id branch health_path '
        . 'version_path version_json_path health_check_mode health_json_path health_json_operator health_json_expected_value '
        . 'comparison_mode enabled sort_order online deployed_version deployed_commit latest_release latest_commit open_issues open_prs '
        . 'response_time_ms last_error checked_at health_error_code health_http_status config_revision endpoint_token key failures '
        . 'window_start scope resource eligible_at check_interval_minutes session_idle_timeout_minutes run_id stop_requested fairness_turn '
        . 'site_id version_status version_error_code version_http_status release_error_code release_http_status commit_error_code '
        . 'commit_http_status opened_at recovery_history_id recovered_at interruption_history_id end_reason uncertain clock_invalid '
        . 'unresolved_site_id last_history_id watermark revision activation_id blocked endpoint_cipher bearer_cipher unavailable recovery '
        . 'version_lag include_name include_url state event event_id source_id channel_revision payload private_cipher expires_at due_at '
        . 'status attempts claim lease_until last_code http_status acknowledged_at coalesced secret_hash administrator_fingerprint '
        . 'ascii substring cast binary unsigned replace locate octet_length mod case when then else end null not and or is in between '
        . 'as substr length char charset');
}

function sql_schema_tokenizer_public_contract(): void
{
    $names = sql_schema_tokenizer_identifiers();
    Assert::same(count($names), 137);
    Assert::same(hash('sha256', \Tablo\JsonEncoding::encode($names)), 'f94d52ed9f4681cb9719ddf8a910c102aae2c1c8f561a64dbc34dc5854a46bea');
    sql_schema_tokenizer_case(implode(' ', $names), $names);
    foreach ($names as $name) {
        $upper = strtoupper($name);
        sql_schema_tokenizer_case($name, [$name]);
        sql_schema_tokenizer_case($upper, [$upper]);
        sql_schema_tokenizer_case('(' . $name . ') = ' . $upper, ['(', $name, ')', '=', $upper]);
    }
    sql_schema_tokenizer_literals();
    sql_schema_tokenizer_limits();
    foreach (['i', 'token', 'version', 'id_extra', 'plan_step', 'key_verifie', 'crypted_token', 'charset_extra',
        'id|name', 'id;name', 'id.name', '[id]', '"id"', "id\0", "id\xff"] as $sql) {
        sql_schema_tokenizer_refusal($sql);
    }
    foreach (range(0, end: 255) as $byte) { sql_schema_tokenizer_refusal('id' . chr($byte) . '_unsupported'); }
}

/** @param list<string> $expected */
function sql_schema_tokenizer_case(string $sql, array $expected): void
{
    Assert::same(\Tablo\ServerExpressionTokens::tokenize($sql), $expected);
    Assert::same(\Tablo\ServerExpressionTokens::mysqlCheckTokens($sql), $expected);
}

function sql_schema_tokenizer_literals(): void
{
    sql_schema_tokenizer_case("`unknown_name` = 'plan'", ['`unknown_name`', '=', "'plan'"]);
    sql_schema_tokenizer_case("'a''b' <> ''", ["'a''b'", '<>', "''"]);
    sql_schema_tokenizer_case("_binary 'x' = X'00ff'", ["_binary 'x'", '=', "X'00ff'"]);
    sql_schema_tokenizer_case("NULL IS NOT 'null'", ['NULL', 'IS', 'NOT', "'null'"]);
    sql_schema_tokenizer_case('(id + 1) % 2 = 0', ['(', 'id', '+', '1', ')', '%', '2', '=', '0']);
    sql_schema_tokenizer_case('id <=> 0 AND id != 1 OR id <= 2 AND id >= 3',
        ['id', '<=>', '0', 'AND', 'id', '!=', '1', 'OR', 'id', '<=', '2', 'AND', 'id', '>=', '3']);
    Assert::same(\Tablo\ServerExpressionTokens::mysqlCheckTokens("_utf8mb4 'é'"), ["_utf8mb4 'é'"]);
    $caught = null;
    try { \Tablo\ServerExpressionTokens::tokenize("_utf8mb4 'é'"); }
    catch (\Tablo\DatabaseFailure $error) { $caught = $error; }
    Assert::same($caught?->category, 'schema');
}

function sql_schema_tokenizer_limits(): void
{
    sql_schema_tokenizer_case(str_pad('id', length: 16_384), ['id']);
    sql_schema_tokenizer_refusal(str_pad('id', length: 16_385));
    sql_schema_tokenizer_case(str_repeat('1 ', times: 2048), array_fill(0, count: 2048, value: '1'));
    sql_schema_tokenizer_refusal(str_repeat('1 ', times: 2049));
}

function sql_schema_tokenizer_refusal(string $sql): void
{
    foreach ([\Tablo\ServerExpressionTokens::tokenize(...), \Tablo\ServerExpressionTokens::mysqlCheckTokens(...)] as $tokenize) {
        $caught = null; ob_start();
        try { $tokenize($sql); } catch (\Tablo\DatabaseFailure $error) { $caught = $error; }
        finally { $output = ob_get_clean(); }
        Assert::same($caught?->category, 'schema'); Assert::same($caught?->getPrevious(), null); Assert::same($output, '');
    }
}
