<?php
declare(strict_types=1);

namespace Tablo;


final class SqlSyntax
{
    /** @return array{checks: list<list<string>>, autoincrement: bool} */
    public static function constraints(string $sql): array
    {
        $lexemes = SqlLexemes::read($sql);
        $checks = [];
        $autoincrement = false;
        foreach (self::definitions($lexemes) as $definition) {
            $constraints = SqlDefinitionConstraints::read($definition);
            array_push($checks, ...$constraints['checks']);
            $autoincrement = $autoincrement || $constraints['autoincrement'];
        }
        sort($checks);
        return ['checks' => $checks, 'autoincrement' => $autoincrement];
    }

    /** @return list<string> */
    public static function tokens(string $sql): array
    {
        return array_map(SqlLexemes::comparable(...), SqlLexemes::read($sql));
    }

    /**
     * @param list<non-empty-string> $lexemes
     * @return list<list<non-empty-string>>
     */
    private static function definitions(array $lexemes): array
    {
        $opening = array_search('(', $lexemes, strict: true);
        if ($opening === false) { SchemaShape::refuse(); }
        $definitions = [];
        $definition = [];
        $depth = 1;
        foreach (array_slice($lexemes, offset: $opening + 1) as $lexeme) {
            if ($lexeme === '(') { ++$depth; }
            if ($lexeme === ')') { --$depth; }
            if ($depth === 0) { $definitions[] = $definition; return $definitions; }
            if ($lexeme === ',' && $depth === 1) {
                $definitions[] = $definition;
                $definition = [];
                continue;
            }
            $definition[] = $lexeme;
        }
        SchemaShape::refuse();
    }

}
