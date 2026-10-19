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

}
