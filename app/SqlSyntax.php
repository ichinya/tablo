<?php
declare(strict_types=1);

namespace Tablo;


final class SqlSyntax
{
    public static function constraints(string $sql): array
    {
        $lexemes = self::tokens($sql);
        $checks = [];
        foreach ($lexemes as $offset => $lexeme) {
            if ($lexeme !== 'check' || ($lexemes[$offset + 1] ?? null) !== '(') { continue; }
            $depth = 1;
            $check = [];
            for ($position = $offset + 2; $position < count($lexemes); ++$position) {
                $value = $lexemes[$position];
                if ($value === '(') { ++$depth; }
                if ($value === ')') { --$depth; if ($depth === 0) { break; } }
                $check[] = $value;
            }
            if ($depth !== 0) { SchemaShape::refuse(); }
            $checks[] = $check;
        }
        sort($checks);
        return ['checks' => $checks, 'autoincrement' => in_array('autoincrement', $lexemes, strict: true)];
    }
    public static function tokens(string $sql): array
    {
        preg_match_all('/\s+|--[^\r\n]*|\/\*[\s\S]*?\*\/|\'(?:\'\'|[^\'])*\'|"(?:""|[^"])*"|`[^`]*`|\[[^\]]*\]|[a-zA-Z_][a-zA-Z_0-9]*|[0-9]+|<>|>=|<=|==|!=|./', $sql, $matches);
        $lexemes = [];
        foreach ($matches[0] as $lexeme) {
            if (trim($lexeme) === '' || str_starts_with($lexeme, '--') || str_starts_with($lexeme, '/*')) { continue; }
            if ($lexeme[0] === "'") { $lexemes[] = $lexeme; continue; }
            if (in_array($lexeme[0], ['"', '`', '['], strict: true)) {
                $lexemes[] = strtolower(substr($lexeme, offset: 1, length: -1));
                continue;
            }
            $lexemes[] = strtolower($lexeme);
        }
        return $lexemes;
    }

}
