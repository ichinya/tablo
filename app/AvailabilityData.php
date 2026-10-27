<?php
declare(strict_types=1);

namespace Tablo;

/**
 * @phpstan-type Site array{id:int,name:string,enabled:int,current_revision:int}
 * @phpstan-type Sample array{id:int,config_revision:int,checked_at:string,online:?int,response_time_ms:?int}
 * @phpstan-type Read array{site:Site,overflow:bool,rows:list<Sample>}
 */
final class AvailabilityData
{
    /** @return Site */
    public static function site(array $row): array
    {
        $site = ['id' => SqlColumn::integer($row, 'id'), 'name' => SqlColumn::string($row, 'name'),
            'enabled' => SqlColumn::integer($row, 'enabled'), 'current_revision' => SqlColumn::integer($row, 'current_revision')];
        if ($site['id'] < 1 || !in_array($site['enabled'], [0, 1], strict: true) || $site['current_revision'] < 0) { self::invalid(); }
        return $site;
    }

    /** @return Sample */
    public static function sample(array $row): array
    {
        $sample = ['id' => SqlColumn::integer($row, 'id'), 'config_revision' => SqlColumn::integer($row, 'config_revision'),
            'checked_at' => SqlColumn::string($row, 'checked_at'), 'online' => SqlColumn::nullableInteger($row, 'online'),
            'response_time_ms' => SqlColumn::nullableInteger($row, 'response_time_ms')];
        if ($sample['id'] < 1 || $sample['config_revision'] < 0 || !in_array($sample['online'], [null, 0, 1], strict: true)
            || ($sample['response_time_ms'] !== null && ($sample['response_time_ms'] < 0 || $sample['response_time_ms'] > 2_147_483_647))
            || HistoryTime::canonical($sample['checked_at']) !== $sample['checked_at']) { self::invalid(); }
        return $sample;
    }

    private static function invalid(): never { throw new \RuntimeException('Invalid availability row.'); }
}
