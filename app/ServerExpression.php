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
