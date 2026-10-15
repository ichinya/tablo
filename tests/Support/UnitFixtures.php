<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use Tablo\SiteInput;

/** @phpstan-import-type SiteData from SiteInput */
final class UnitFixtures
{
    /** @return SiteData */
    public static function site(): array
    {
        return [...SiteInput::defaults(), 'name' => 'Tempalog', 'url' => 'https://tempalog.example/',
            'repository' => 'https://github.com/ichinya/tempalog.git', 'version_path' => '/version'];
    }

    /** @return array{status: int, body: string, time_ms: int} */
    public static function response(int $status = 200, string $body = '{}'): array
    {
        return ['status' => $status, 'body' => $body, 'time_ms' => 87];
    }


}
