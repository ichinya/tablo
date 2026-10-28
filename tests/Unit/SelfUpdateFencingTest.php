<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\BuildMetadata;
use Tablo\SelfUpdateRepository;
use Tablo\SelfUpdateResult;
use Tablo\Tests\Support\ReadApiAssertions as A;
use Testo\Assert;
use Testo\Test;

final class SelfUpdateFencingTest
{
    #[Test]
    public function singleClaimAndRevisionBindingRejectExpiredConfigurationAndBuildResults(): void
    {
        $db = A::database(); $repository = new SelfUpdateRepository($db); $build = new BuildMetadata('ichinya/tablo', 'main', str_repeat('a', times: 40));
        $claim = $repository->claim($build, 1000) ?? throw new \RuntimeException('Claim required.');
        Assert::same($repository->claim($build, 1001), null);
        $replacement = $repository->claim($build, 1060) ?? throw new \RuntimeException('Replacement claim required.');
        Assert::false($repository->complete($claim, $build, 1061, new SelfUpdateResult('current', $build->sha)));
        $repository->configure('1', '60');
        Assert::false($repository->complete($replacement, $build, 1061, new SelfUpdateResult('current', $build->sha)));
        $claim = $repository->claim($build, 1062) ?? throw new \RuntimeException('Claim required.');
        $newBuild = new BuildMetadata('ichinya/tablo', 'next', str_repeat('b', times: 40));
        $newClaim = $repository->claim($newBuild, 1063) ?? throw new \RuntimeException('New build claim required.');
        Assert::false($repository->complete($claim, $build, 1064, new SelfUpdateResult('behind', str_repeat('c', times: 40), 4)));
        Assert::false($repository->cancel($claim, $build), 'stale cancellation cannot release the new build claim');
        Assert::true($repository->complete($newClaim, $newBuild, 1064, new SelfUpdateResult('current', $newBuild->sha)));
        Assert::same($repository->get()['status'], 'current');
    }
}
