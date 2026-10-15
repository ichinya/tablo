<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use Tablo\SiteInput;

final class UnitFixtures
{
    public static function site(): array
    {
        return array_replace(SiteInput::defaults(), ['name' => 'Tempalog', 'url' => 'https://tempalog.example/',
            'repository' => 'https://github.com/ichinya/tempalog.git', 'version_path' => '/version']);
    }

    public static function response(int $status = 200, string $body = '{}'): array
    {
        return ['status' => $status, 'body' => $body, 'time_ms' => 87];
    }


}
