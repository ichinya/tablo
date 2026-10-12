<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\SiteRepository;
use Tablo\SiteInput;

final class WorkerSearchPopulation
{
    public static function create(SiteRepository $sites, string $base): void
    {
            for ($i = 0; $i < 21; ++$i) {
                $sites->save(array_replace(SiteInput::defaults(), ['name' => 'Fixture ' . $i, 'url' => $base,
                    'repository' => 'fixture/project-' . $i, 'version_path' => '/version']));
            }

    }
}
