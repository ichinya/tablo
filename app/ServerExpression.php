<?php
declare(strict_types=1);

namespace Tablo;

final class ServerExpression
{
    public static function canonical(string $sql): string
    {
        $tokens = ServerExpressionTokens::tokenize($sql);
        $position = 0;
        $tree = (new ServerExpressionOperator())->parse($tokens, $position);
        if ($position !== count($tokens)) { throw new DatabaseFailure('schema'); }
        unset($tokens);
        return JsonEncoding::encode($tree);
    }

    /** @param \Tablo\ServerSchema::ExpectedTable $expectedTable */
    public static function canonicalMysqlCheck(string $decodedSql, array $expectedTable): string
    {
        $tokens = ServerExpressionTokens::mysqlCheckTokens($decodedSql);
        $position = 0;
        $tree = (new ServerExpressionOperator())->parse($tokens, $position);
        if ($position !== count($tokens)) { throw new DatabaseFailure('schema'); }
        unset($tokens);
        $binaryColumns = [];
        foreach ($expectedTable['columns'] as $column) {
            if (preg_match('/\A(?:varbinary\([1-9][0-9]*\)|longblob)\z/D', strtolower($column['type'])) !== 1) { continue; }
            $binaryColumns[] = strtolower($column['name']);
        }
        $tree = server_mysql_check_tree($tree, $binaryColumns);
        if (server_mysql_check_has_tag($tree)) { throw new DatabaseFailure('schema'); }
        return JsonEncoding::encode($tree);
    }

    /** MySQL information_schema stores one String::print escape layer around the SQL expression. */
    public static function decodeMysqlMetadata(#[\SensitiveParameter] string $sql): string
    {
        if (strlen($sql) > 32_768) { throw new DatabaseFailure('schema'); }
        $decoded = strtr($sql, ['\\\\' => '\\', "\\'" => "'", '\\0' => "\0", '\\n' => "\n", '\\r' => "\r", '\\Z' => chr(26)]);
        $encoded = strtr($decoded, ['\\' => '\\\\', "'" => "\\'", "\0" => '\\0', "\n" => '\\n', "\r" => '\\r', chr(26) => '\\Z']);
        if (strlen($decoded) > 16_384 || $encoded !== $sql) { throw new DatabaseFailure('schema'); }
        return $decoded;
    }

}

/** @param list<string> $binaryColumns */
function server_mysql_check_tree(array $tree, array $binaryColumns, int $depth = 0): array
{
    if ($depth > 128) { throw new DatabaseFailure('schema'); }
    foreach (array_keys($tree) as $key) {
        if (!is_array($tree[$key] ?? null)) { continue; }
        $tree[$key] = server_mysql_check_tree($tree[$key], $binaryColumns, $depth + 1);
    }
    $tree = server_mysql_check_replace($tree, $binaryColumns);
    return server_mysql_check_predicate($tree, $binaryColumns);
}

/** @param list<string> $binaryColumns */
function server_mysql_check_predicate(array $tree, array $binaryColumns): array
{
    if (!is_array($tree[1] ?? null) || !is_array($tree[2] ?? null)) { return $tree; }
    $left = $tree[1]; $right = $tree[2];
    if (($tree[0] ?? null) === 'in' && server_mysql_check_binary($left, $binaryColumns)) {
        foreach (array_keys($right) as $key) {
            if (!is_array($right[$key] ?? null)) { continue; }
            $right[$key] = server_mysql_check_literal($right[$key]);
        }
        $tree[2] = $right;
    }
    if (!in_array($tree[0] ?? null, ['=', '<=>'], strict: true)) { return $tree; }
    if (server_mysql_check_binary($left, $binaryColumns)) { $tree[2] = server_mysql_check_literal($right); }
    if (server_mysql_check_binary($right, $binaryColumns)) { $tree[1] = server_mysql_check_literal($left); }
    return $tree;
}

/** @param list<string> $binaryColumns */
function server_mysql_check_replace(array $tree, array $binaryColumns): array
{
    if (($tree[0] ?? null) !== 'call' || ($tree[1] ?? null) !== 'replace') { return $tree; }
    /** @var array<array-key, array|string> $arguments */
    $arguments = $tree[2] ?? [];
    if (count($arguments) !== 3 || !is_array($arguments[0] ?? null)
        || !server_mysql_check_binary($arguments[0], $binaryColumns)) { return $tree; }
    foreach ([1, 2] as $index) {
        if (!is_array($arguments[$index] ?? null)) { continue; }
        $arguments[$index] = server_mysql_check_literal($arguments[$index]);
    }
    $tree[2] = $arguments;
    return $tree;
}

/** Literals never establish binary provenance; REPLACE's result charset follows only its haystack.
 * @param list<string> $binaryColumns */
function server_mysql_check_binary(array $tree, array $binaryColumns, int $depth = 0): bool
{
    if ($depth > 128) { throw new DatabaseFailure('schema'); }
    if (($tree[0] ?? null) === 'atom') { return in_array($tree[1] ?? null, $binaryColumns, strict: true); }
    if (($tree[0] ?? null) !== 'call') { return false; }
    if (!is_array($tree[2] ?? null)) { return false; }
    $arguments = $tree[2];
    if (($tree[1] ?? null) === 'cast') { return count($arguments) === 2 && ($arguments[1] ?? null) === 'binary'; }
    if (!server_mysql_check_string_result($tree)) { return false; }
    if (!is_array($arguments[0] ?? null)) { return false; }
    return server_mysql_check_binary($arguments[0], $binaryColumns, $depth + 1);
}

function server_mysql_check_string_result(array $tree): bool
{
    if (!is_array($tree[2] ?? null)) { return false; }
    return in_array(count($tree[2]), match ($tree[1] ?? null) { 'substring' => [2, 3], 'replace' => [3], default => [] }, strict: true);
}

function server_mysql_check_literal(array $tree): array
{
    if (($tree[0] ?? null) !== 'mysql-utf8mb4') { return $tree; }
    if (count($tree) !== 2 || !is_string($tree[1] ?? null)
        || preg_match('/\A(?:[0-9a-f]{2})*\z/D', $tree[1]) !== 1) { throw new DatabaseFailure('schema'); }
    return ['literal', $tree[1]];
}

function server_mysql_check_has_tag(array $tree, int $depth = 0): bool
{
    if ($depth > 128) { throw new DatabaseFailure('schema'); }
    if (($tree[0] ?? null) === 'mysql-utf8mb4') { return true; }
    foreach (array_keys($tree) as $key) {
        if (is_array($tree[$key] ?? null) && server_mysql_check_has_tag($tree[$key], $depth + 1)) { return true; }
    }
    return false;
}
