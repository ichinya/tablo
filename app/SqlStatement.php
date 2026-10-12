<?php
declare(strict_types=1);

namespace Tablo;

use PDOStatement;
use RuntimeException;

/** Checked acquisition leaves the caller's connection and native call untouched. */
final class SqlStatement
{
    public static function checked(#[\SensitiveParameter] PDOStatement|false $statement): PDOStatement
    {
        if ($statement === false) { throw new RuntimeException('Cannot acquire database statement.'); }
        return $statement;
    }
}
