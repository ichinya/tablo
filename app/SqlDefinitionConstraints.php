<?php
declare(strict_types=1);

namespace Tablo;

/** Inspect attributes in one table column or table-constraint definition. */
final class SqlDefinitionConstraints
{
    /**
     * @param list<non-empty-string> $definition
     * @return array{checks: list<list<string>>, autoincrement: bool}
     */
    public static function read(array $definition): array
    {
        $checks = [];
        $autoincrement = false;
        if (!array_key_exists(0, $definition)) { SchemaShape::refuse(); }
        $id = SqlLexemes::identifier($definition[0]) === 'id';
        $primary = false;
        for ($offset = 0; $offset < count($definition); ++$offset) {
            $lexeme = SqlLexemes::at($definition, $offset);
            if ($lexeme === 'constraint') { ++$offset; continue; }
            $pair = array_slice($definition, offset: $offset, length: 2);
            if ($pair === ['check', '(']) {
                [$check, $offset] = self::check($definition, $offset);
                $checks[] = $check;
                continue;
            }
            if ($pair === ['primary', 'key']) { $primary = true; }
            if ($lexeme === 'autoincrement' && $id && $primary) { $autoincrement = true; }
        }
        return ['checks' => $checks, 'autoincrement' => $autoincrement];
    }

    /**
     * @param list<non-empty-string> $definition
     * @return list{list<string>, int}
     */
    private static function check(array $definition, int $opening): array
    {
        $depth = 1;
        $tokens = [];
        for ($offset = $opening + 2; $offset < count($definition); ++$offset) {
            $lexeme = SqlLexemes::at($definition, $offset);
            if ($lexeme === '(') { ++$depth; }
            if ($lexeme === ')') { --$depth; }
            if ($depth === 0) { return [$tokens, $offset]; }
            $tokens[] = SqlLexemes::comparable($lexeme);
        }
        SchemaShape::refuse();
    }
}
