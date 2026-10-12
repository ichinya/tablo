<?php
declare(strict_types=1);

namespace Tablo\Tools;

use RuntimeException;

require_once dirname(__DIR__) . '/app/RequiredFile.php';
require_once __DIR__ . '/BindingSymbol.php';

/** @phpstan-import-type Symbol from BindingSymbol */
final class BindingsDeclaration
{
    /** @return array{symbols: list<Symbol>, ...} */
    public static function read(string $path): array
    {
        return self::checked(json_decode(\Tablo\RequiredFile::bytes(file_get_contents($path)), associative: true, depth: 64, flags: JSON_THROW_ON_ERROR));
    }

    /** @return array{symbols: list<Symbol>, ...} */
    private static function checked(mixed $value): array
    {
        if (!is_array($value) || !is_array($value['symbols'] ?? null) || !array_is_list($value['symbols'])) { throw new RuntimeException('Invalid source binding declaration.'); }
        $symbols = [];
        foreach (array_keys($value['symbols']) as $index) { $symbols[] = BindingSymbol::checked($value['symbols'][$index] ?? null); }
        $value['symbols'] = $symbols;
        return $value;
    }

}
