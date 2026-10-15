<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\SiteRepository;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;

final class WorkerAbaAssertions
{
    public function __construct(private readonly SiteRepository $sites, private readonly TemporaryDirectory $directory) {}

    public function firstPass(array $ids): void
    {
            [$first, $later, $new] = $ids;
            Assert::same($this->sites->find($first)['checked_at'], null);
            Assert::same($this->sites->find($later)['checked_at'], null);
            Assert::same($this->sites->find($new)['checked_at'], null, 'finite ID list admits new row next pass');
            $requests = file_get_contents($this->directory->path . '/requests.jsonl');
            Assert::false(str_contains($requests, '/later') || str_contains($requests, '/deleted') || str_contains($requests, '/new'));

    }
}
