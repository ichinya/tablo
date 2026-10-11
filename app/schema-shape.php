<?php
declare(strict_types=1);

namespace Tablo;

use PDO;
use RuntimeException;

/** Physical metadata and parsed constraints for the supported installation lineages. */
final class SchemaShape
{
    public static function rows(PDO $db, string $sql): array
    {
        $statement = $db->query($sql);
        try { return $statement->fetchAll(PDO::FETCH_ASSOC); }
        finally { $statement->closeCursor(); }
    }

    public static function objects(PDO $db): array
    {
        $objects = [];
        foreach (self::rows($db, 'SELECT name, type, tbl_name, sql FROM main.sqlite_schema') as $row) {
            $objects[strtolower($row['name'])] = $row;
        }
        return $objects;
    }

    public static function assert(PDO $db, PDO $expected, bool $legacy = false): void
    {
        $actualObjects = self::objects($db);
        foreach (self::objects($expected) as $name => $object) {
            if ($object['type'] !== 'table' || $name === 'sqlite_sequence') { continue; }
            $actual = $actualObjects[$name] ?? null;
            if ($actual === null && $legacy) { continue; }
            if ($actual === null || $actual['type'] !== 'table') { self::refuse(); }
            $columns = self::columns($db, $name);
            $wanted = self::columns($expected, $name);
            $missing = array_diff(array_keys($wanted), array_keys($columns));
            if ($legacy && $name === 'sites') {
                if (array_diff($missing, ['github_token', 'git_token_id', 'version_json_path', 'health_check_mode',
                    'health_json_path', 'health_json_operator', 'health_json_expected_value']) !== []) { self::refuse(); }
                $wanted = array_intersect_key($wanted, $columns);
            }
            if ($columns !== $wanted) { self::refuse(); }
            $constraints = self::constraints($object['sql']);
            if ($legacy && $missing !== []) {
                $constraints['checks'] = array_values(array_filter($constraints['checks'],
                    static fn (array $tokens): bool => array_intersect($tokens, $missing) === []));
            }
            if (self::constraints($actual['sql']) !== $constraints) { self::refuse(); }
            $foreign = self::foreignKeys($expected, $name);
            if ($legacy && in_array('git_token_id', $missing, true)) { $foreign = []; }
            if (self::foreignKeys($db, $name) !== $foreign) { self::refuse(); }
            $indexes = self::indexes($db, $name);
            foreach (self::indexes($expected, $name) as $index => $shape) {
                if (($indexes[$index] ?? null) !== $shape) { self::refuse(); }
            }
        }
    }

    private static function columns(PDO $db, string $table): array
    {
        $columns = [];
        foreach (self::rows($db, 'PRAGMA main.table_xinfo("' . $table . '")') as $row) {
            unset($row['cid']);
            $row['type'] = strtoupper($row['type']);
            $row['dflt_value'] = $row['dflt_value'] === null ? null : self::tokens($row['dflt_value']);
            $columns[$row['name']] = $row;
        }
        ksort($columns);
        return $columns;
    }

    private static function foreignKeys(PDO $db, string $table): array
    {
        $rows = self::rows($db, 'PRAGMA main.foreign_key_list("' . $table . '")');
        foreach ($rows as &$row) { unset($row['id']); }
        unset($row);
        sort($rows);
        return $rows;
    }

    private static function indexes(PDO $db, string $table): array
    {
        $indexes = [];
        foreach (self::rows($db, 'PRAGMA main.index_list("' . $table . '")') as $index) {
            $name = $index['name'];
            $columns = self::rows($db, 'PRAGMA main.index_xinfo("' . str_replace('"', '""', $name) . '")');
            foreach ($columns as &$column) { unset($column['cid']); }
            unset($column);
            $objects = self::objects($db);
            $sql = $objects[strtolower($name)]['sql'] ?? null;
            $tokens = $sql === null ? [] : self::tokens($sql);
            $where = array_search('where', $tokens, true);
            $key = $index['origin'] === 'c' ? $name : $index['origin'] . ':' . json_encode($columns, JSON_THROW_ON_ERROR);
            $indexes[$key] = [$index['unique'], $index['partial'], $columns, $where === false ? [] : array_slice($tokens, $where)];
        }
        ksort($indexes);
        return $indexes;
    }

    private static function constraints(string $sql): array
    {
        $tokens = self::tokens($sql);
        $checks = [];
        foreach ($tokens as $offset => $token) {
            if ($token !== 'check' || ($tokens[$offset + 1] ?? null) !== '(') { continue; }
            $depth = 1;
            $check = [];
            for ($position = $offset + 2; $position < count($tokens); ++$position) {
                $value = $tokens[$position];
                if ($value === '(') { ++$depth; }
                if ($value === ')') { --$depth; if ($depth === 0) { break; } }
                $check[] = $value;
            }
            if ($depth !== 0) { self::refuse(); }
            $checks[] = $check;
        }
        sort($checks);
        return ['checks' => $checks, 'autoincrement' => in_array('autoincrement', $tokens, true)];
    }

    private static function tokens(string $sql): array
    {
        preg_match_all('/\s+|--[^\r\n]*|\/\*[\s\S]*?\*\/|\'(?:\'\'|[^\'])*\'|"(?:""|[^"])*"|`[^`]*`|\[[^\]]*\]|[a-zA-Z_][a-zA-Z_0-9]*|[0-9]+|<>|>=|<=|==|!=|./', $sql, $matches);
        $tokens = [];
        foreach ($matches[0] as $token) {
            if (trim($token) === '' || str_starts_with($token, '--') || str_starts_with($token, '/*')) { continue; }
            if ($token[0] === "'") { $tokens[] = $token; }
            elseif (in_array($token[0], ['"', '`', '['], true)) { $tokens[] = strtolower(substr($token, 1, -1)); }
            else { $tokens[] = strtolower($token); }
        }
        return $tokens;
    }

    public static function refuse(): never
    {
        throw new RuntimeException('Incompatible SQLite schema layout.');
    }
}
