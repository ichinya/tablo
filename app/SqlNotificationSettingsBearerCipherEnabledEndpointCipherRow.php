<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-type Record array{"bearer_cipher": null|string, "enabled": int, "endpoint_cipher": null|string, ...} */
final class SqlNotificationSettingsBearerCipherEnabledEndpointCipherRow
{
    /** @return Record */
    public static function checked(#[\SensitiveParameter] array $row): array
    {
        $row['bearer_cipher'] = SqlColumn::nullableString($row, 'bearer_cipher');
        $row['enabled'] = SqlColumn::integer($row, 'enabled');
        $row['endpoint_cipher'] = SqlColumn::nullableString($row, 'endpoint_cipher');
        return $row;
    }
}
