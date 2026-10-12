<?php
declare(strict_types=1);

namespace Tablo\Tools;

/** @phpstan-type Symbol array{id: string, kind: string, source: array{path: string, line: int, ...}, fingerprint: string, ...} */
final class BindingSymbol
{
    /** @return Symbol */
    public static function checked(mixed $value): array
    {
        if (!is_array($value) || !is_string($value['id'] ?? null) || !is_string($value['kind'] ?? null)
            || !is_array($value['source'] ?? null) || !is_string($value['source']['path'] ?? null)
            || !is_int($value['source']['line'] ?? null) || !is_string($value['fingerprint'] ?? null)) {
            throw new \RuntimeException('Invalid source binding declaration.');
        }
        return $value;
    }
}
