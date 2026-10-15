<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-type Record array{"activation_id": int, "bearer_cipher": null|string, "blocked": int, "enabled": int, "endpoint_cipher": null|string, "id": int, "include_name": int, "include_url": int, "installation_id": string, "recovery": int, "revision": int, "unavailable": int, "version_lag": int, ...} */
final class SqlNotificationSettingsActivationIdBearerCipherBlockedRow
{
    /** @return Record */
    public static function checked(#[\SensitiveParameter] array $row): array
    {
        $row['activation_id'] = SqlColumn::integer($row, 'activation_id');
        $row['bearer_cipher'] = SqlColumn::nullableString($row, 'bearer_cipher');
        $row['blocked'] = SqlColumn::integer($row, 'blocked');
        $row['enabled'] = SqlColumn::integer($row, 'enabled');
        $row['endpoint_cipher'] = SqlColumn::nullableString($row, 'endpoint_cipher');
        $row['id'] = SqlColumn::integer($row, 'id');
        $row['include_name'] = SqlColumn::integer($row, 'include_name');
        $row['include_url'] = SqlColumn::integer($row, 'include_url');
        $row['installation_id'] = SqlColumn::string($row, 'installation_id');
        $row['recovery'] = SqlColumn::integer($row, 'recovery');
        $row['revision'] = SqlColumn::integer($row, 'revision');
        $row['unavailable'] = SqlColumn::integer($row, 'unavailable');
        $row['version_lag'] = SqlColumn::integer($row, 'version_lag');
        return $row;
    }
}
