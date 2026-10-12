<?php
declare(strict_types=1);

namespace Tablo;

/** Keep quoted names and literals distinct until their SQL context is known. */
final class SqlLexemes
{
    public static function read(string $sql): array
    {
        preg_match_all('/\s+|--[^\r\n]*|\/\*[\s\S]*?\*\/|\'(?:\'\'|[^\'])*\'|"(?:""|[^"])*"|`(?:``|[^`])*`|\[[^\]]*\]|[a-zA-Z_][a-zA-Z_0-9]*|[0-9]+|<>|>=|<=|==|!=|./', $sql, $matches);
        $lexemes = [];
        foreach ($matches[0] as $lexeme) {
            if (trim($lexeme) === '' || str_starts_with($lexeme, '--') || str_starts_with($lexeme, '/*')) { continue; }
            $lexemes[] = in_array($lexeme[0], ["'", '"', '`', '['], strict: true) ? $lexeme : strtolower($lexeme);
        }
        return $lexemes;
    }

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

    public static function identifier(string $lexeme): string
    {
        if (!in_array($lexeme[0], ["'", '"', '`', '['], strict: true)) { return $lexeme; }
        $name = substr($lexeme, offset: 1, length: -1);
        if ($lexeme[0] !== '[') { $name = str_replace(str_repeat($lexeme[0], times: 2), $lexeme[0], $name); }
        return strtolower($name);
    }
}
