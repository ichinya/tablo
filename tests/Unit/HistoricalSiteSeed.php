<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;




final class HistoricalSiteSeed
{
    public static function fields(): array
    {
        return ['name' => 'Existing', 'url' => 'https://example.com', 'repository' => 'example/project',
                    'branch' => 'feature/demo', 'health_path' => '/up/', 'version_path' => '/version',
                    'online' => 1, 'deployed_version' => '1.2.3', 'checked_at' => '2026-01-01T00:00:00Z'];
    }
}
