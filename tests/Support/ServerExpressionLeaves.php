<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use Tablo\DatabaseFailure;
use Tablo\JsonRecord;
use Tablo\RequiredFile;
use Tablo\ServerExpression;
use Tablo\ServerExpressionOperator;
use Tablo\ServerExpressionTokens;
use Tablo\SqlColumn;
use Testo\Assert;

/** Shared offline controls run in the existing SQLite and native expression cases. */
final class ServerExpressionLeaves
{
    public static function check(): void
    {
        self::canonicalValues();
        server_expression_leaf_frozen_checks();
        self::boundedOwnership();
        self::errorsAndDepth();
    }

    private static function canonicalValues(): void
    {
        foreach ([
            'id IN (1)' => '["=",["atom","id"],["atom","1"]]',
            "name = 'A'" => '["=",["atom","name"],["literal","41"]]',
            "0 = '0'" => '["=",["atom","0"],["literal","30"]]',
            'id = 1 OR id = 1' => '["or",["=",["atom","id"],["atom","1"]],["=",["atom","id"],["atom","1"]]]',
            'REPLACE(name,0x41,0x61)' => '["call","replace",[["atom","name"],["literal","41"],["literal","61"]]]',
            'CAST(name AS BINARY)' => '["call","cast",[["atom","name"],"binary"]]',
            'CASE WHEN online IS NULL THEN 0 ELSE online END' => '["case",["is null",["atom","online"]],["atom","0"],["atom","online"]]',
        ] as $sql => $expected) { Assert::same(ServerExpression::canonical($sql), $expected); }
        $table = self::table();
        Assert::same(ServerExpression::canonicalMysqlCheck("phase = _utf8mb4'intent'", $table), '["=",["atom","phase"],["literal","696e74656e74"]]');
        Assert::same(ServerExpression::canonicalMysqlCheck("_utf8mb4'intent' <=> phase", $table), '["<=>",["literal","696e74656e74"],["atom","phase"]]');
        $values = implode(',', range(0, end: 100));
        $members = array_map(static fn (int $value): array => ['atom', (string) $value], range(0, end: 100));
        Assert::same(ServerExpression::canonical('id IN (' . $values . ')'), \Tablo\JsonEncoding::encode(['in', ['atom', 'id'], $members]));
        $bytes = str_repeat('a', times: 1000);
        Assert::same(ServerExpression::canonical("name = '" . $bytes . "'"), \Tablo\JsonEncoding::encode(['=', ['atom', 'name'], ['literal', bin2hex($bytes)]]));
    }

    private static function boundedOwnership(): void
    {
        $operator = new ServerExpressionOperator();
        $atom = $operator->leaf(['atom', '61']); $literal = $operator->leaf(['literal', '61']);
        $changed = $operator->leaf(['atom', '61']); $changed[1] = 'caller-change';
        Assert::same($operator->leaf(['atom', '61']), $atom);
        Assert::same($literal, ['literal', '61']); Assert::notSame($atom, $literal);
        for ($index = 0; $index < 40; $index++) { Assert::same($operator->leaf(['atom', 'value' . $index]), ['atom', 'value' . $index]); }
        Assert::same(count(self::pool($operator)), 32);
        $before = self::pool($operator);
        Assert::same($operator->leaf(['literal', str_repeat('a', times: 65)]), ['literal', str_repeat('a', times: 65)]);
        Assert::same(self::pool($operator), $before);
        $boundary = new ServerExpressionOperator();
        Assert::same($boundary->leaf(['literal', str_repeat('a', times: 64)]), ['literal', str_repeat('a', times: 64)]);
        Assert::same(count(self::pool($boundary)), 1);
        $position = 0; $operator->parse(ServerExpressionTokens::tokenize('id = 1 OR id = 1'), $position);
        Assert::same(self::pool($operator), []);
        foreach (['id IN (1,)', 'id + ' . str_repeat('(', times: 64) . 'id' . str_repeat(')', times: 64)] as $broken) {
            $failed = new ServerExpressionOperator(); $position = 0; $caught = null;
            try { $failed->parse(ServerExpressionTokens::tokenize($broken), $position); } catch (DatabaseFailure $error) { $caught = $error; }
            Assert::same($caught?->category, 'schema'); Assert::same(self::pool($failed), []);
            $position = 0;
            Assert::same($failed->parse(ServerExpressionTokens::tokenize(str_repeat('(', times: 63) . 'id' . str_repeat(')', times: 63)), $position), ['atom', 'id']);
            Assert::same(self::pool($failed), []);
        }
    }

    /** @return array */
    private static function pool(ServerExpressionOperator $operator): array
    {
        return JsonRecord::checked((new \ReflectionProperty($operator, 'leaves'))->getValue($operator));
    }

    private static function errorsAndDepth(): void
    {
        foreach (['id /* hidden */ = 1', 'unknown_function(id) = 1', 'id IN ()', 'id IN (1,)', 'id = 1 PRIVATE',
            str_repeat(' ', times: 16_385), implode(' + ', array_fill(0, count: 1100, value: '1')),
            str_repeat('(', times: 64) . 'id' . str_repeat(')', times: 64)] as $invalid) {
            foreach ([static fn (): string => ServerExpression::canonical($invalid),
                static fn (): string => ServerExpression::canonicalMysqlCheck($invalid, self::table())] as $call) { self::refuses($call); }
        }
        Assert::same(ServerExpression::canonical(str_repeat('(', times: 63) . 'id' . str_repeat(')', times: 63)), '["atom","id"]');
        $atLimit = implode(' AND ', array_fill(0, count: 128, value: 'id = 1'));
        Assert::same(ServerExpression::canonicalMysqlCheck($atLimit, self::table()), ServerExpression::canonical($atLimit));
        self::refuses(static fn (): string => ServerExpression::canonicalMysqlCheck($atLimit . ' AND id = 1', self::table()));
        foreach (["phase > _utf8mb4'intent'", "name = _utf8mb4'A'", "phase = _latin1'intent'"] as $invalid) {
            self::refuses(static fn (): string => ServerExpression::canonicalMysqlCheck($invalid, self::table()));
        }
        self::refuses(static fn (): string => ServerExpression::canonical("phase = _utf8mb4'intent'"));
    }

    /** @param \Closure(): string $call */
    private static function refuses(\Closure $call): void
    {
        $caught = null;
        try { $call(); } catch (DatabaseFailure $error) { $caught = $error; }
        Assert::same($caught?->category, 'schema');
        Assert::same($caught?->getMessage(), 'Database schema incompatible or in progress.'); Assert::same($caught?->getPrevious(), null);
    }

    /** @return \Tablo\ServerSchema::AdmissionTable */
    private static function table(): array
    {
        return ['name' => 'fixture', 'columns' => [
            ['name' => 'phase', 'type' => 'varbinary(16)', 'nullable' => false, 'default' => null],
            ['name' => 'name', 'type' => 'longtext', 'nullable' => false, 'default' => null],
        ], 'primary' => [], 'checks' => [], 'indexes' => [], 'foreign' => []];
    }
}

/** Literal frozen outputs were captured from ceaf255, not this parser or its cache. */
function server_expression_leaf_frozen_checks(): void
{
    $root = dirname(__DIR__) . '/fixtures/';
    $oracle = JsonRecord::decode(RequiredFile::bytes(file_get_contents($root . 'server-expression-canonical-v1.json')));
    Assert::same($oracle['schema'] ?? null, 'tablo/server-expression-oracle/v1');
    Assert::same($oracle['sourceCommit'] ?? null, 'ceaf255a9d5a80481a190af4569dbb4072f91e93');
    $fixtures = JsonRecord::checked($oracle['fixtures'] ?? null); $outputs = JsonRecord::checked($oracle['checks'] ?? null);
    $checked = 0;
    foreach (['mysql' => 'mysql84', 'mariadb' => 'mariadb114'] as $engine => $stem) {
        $fixture = JsonRecord::checked($fixtures[$engine] ?? null); $relative = 'server-layouts/v11/' . $stem . '-layout.json';
        Assert::same(SqlColumn::string($fixture, 'path'), $relative);
        Assert::same(RequiredFile::digest($root . $relative), SqlColumn::string($fixture, 'sha256'));
        $layout = JsonRecord::decode(RequiredFile::bytes(file_get_contents($root . $relative)));
        $tables = JsonRecord::checked($layout['tables'] ?? null);
        foreach (array_keys($tables) as $offset) {
            $table = JsonRecord::checked($tables[$offset] ?? null); $name = SqlColumn::string($table, 'name');
            $checks = JsonRecord::checked($table['checks'] ?? null);
            foreach (array_keys($checks) as $index) {
                $check = JsonRecord::checked($checks[$index] ?? null); $key = $engine . ':' . $name . ':' . SqlColumn::string($check, 'name');
                Assert::same(hash('sha256', ServerExpression::canonical(SqlColumn::string($check, 'expression'))), SqlColumn::string($outputs, $key));
                unset($outputs[$key]); $checked++;
            }
        }
    }
    Assert::same($checked, 216); Assert::same($outputs, []);
}

