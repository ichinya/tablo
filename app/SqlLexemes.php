<?php
declare(strict_types=1);

namespace Tablo;

/** Keep quoted names and literals distinct until their SQL context is known. */
final class SqlLexemes
{
    /** @return list<non-empty-string> */
    public static function read(string $sql): array
    {
        $matches = [];
        if (preg_match_all('/\s+|--[^\r\n]*|\/\*[\s\S]*?\*\/|\'(?:\'\'|[^\'])*\'|"(?:""|[^"])*"|`(?:``|[^`])*`|\[[^\]]*\]|[a-zA-Z_][a-zA-Z_0-9]*|[0-9]+|<>|>=|<=|==|!=|./', $sql, $matches) === false) { SchemaShape::refuse(); }
        // Every alternative in this fixed lexer consumes at least one byte.
        /** @var list<non-empty-string> $matched */
        $matched = $matches[0] ?? [];
        $lexemes = [];
        foreach ($matched as $lexeme) {
            if (trim($lexeme) === '' || str_starts_with($lexeme, '--') || str_starts_with($lexeme, '/*')) { continue; }
            $lexemes[] = in_array($lexeme[0], ["'", '"', '`', '['], strict: true) ? $lexeme : strtolower($lexeme);
        }
        return $lexemes;
    }

    /** @param non-empty-string $lexeme */
    public static function comparable(string $lexeme): string
    {
        if (!in_array($lexeme[0], ['"', '`', '['], strict: true)) { return $lexeme; }
        $name = self::identifier($lexeme);
        if (in_array($name, ['autoincrement', 'check', 'constraint', 'primary', 'key', 'in', 'not', 'null', 'is',
            'and', 'or', 'where', 'true', 'false', 'default', 'collate', 'current_timestamp', 'current_date', 'current_time'], strict: true)) {
            return 'quoted:' . $name;
        }
        return $name;
    }

    /** @param non-empty-string $lexeme */
    public static function identifier(string $lexeme): string
    {
        if (!in_array($lexeme[0], ["'", '"', '`', '['], strict: true)) { return $lexeme; }
        $name = substr($lexeme, offset: 1, length: -1);
        if ($lexeme[0] !== '[') { $name = str_replace(str_repeat($lexeme[0], times: 2), $lexeme[0], $name); }
        return strtolower($name);
    }

    /**
     * @param list<non-empty-string> $lexemes
     * @return non-empty-string
     */
    public static function at(array $lexemes, int $offset): string
    {
        if (!array_key_exists($offset, $lexemes)) { SchemaShape::refuse(); }
        return $lexemes[$offset];
    }
}
