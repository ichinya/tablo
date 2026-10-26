<?php
declare(strict_types=1);

namespace Tablo;

/** Internal canonical line writer for complete, validated lists yielded by DatabaseTransferRows::read(). */
final class DatabaseTransferWire
{
    /** @internal
     * @param \Tablo\DatabaseTransferRows::Values $values */
    public static function completeLine(#[\SensitiveParameter] array $values): string
    {
        $line = '['; $separator = '';
        foreach ($values as $value) {
            $line .= $separator; $separator = ',';
            database_transfer_wire_cell($line, $value);
        }
        $line .= "]\n";
        return $line;
    }
}

function database_transfer_wire_cell(#[\SensitiveParameter] string &$line, #[\SensitiveParameter] int|string|null $value): void
{
    if ($value === null) { $line .= '["null"]'; return; }
    if (is_int($value)) { $line .= '["integer","' . (string) $value . '"]'; return; }
    $line .= '["bytes","';
    database_transfer_wire_bytes($line, $value);
    $line .= '"]';
}

function database_transfer_wire_bytes(#[\SensitiveParameter] string &$line, #[\SensitiveParameter] string $value): void
{
    $length = strlen($value);
    for ($offset = 0; $offset < $length; $offset += 3072) {
        $line .= str_replace(search: '/', replace: '\\/', subject: base64_encode(substr($value, offset: $offset, length: 3072)));
    }
}
