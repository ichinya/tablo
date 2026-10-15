<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\GitHubFailure;
use Tablo\GitHubRequestPolicy;
use Testo\Assert;

final class ResponseRateAssertions
{
    public static function typed(#[\SensitiveParameter] GitHubFailure $error, int $now, int $delay): void
    {
                    Assert::same($error->eligibleAt, $now + $delay);
                    Assert::same($error->httpStatus, null, 'existing typed rate failure status contract');
                    Assert::same($error->getMessage(), (new GitHubFailure('rate-limit', $now + $delay))->getMessage());
                    $masked = false;
                    foreach (\Tablo\SqlRows::all($error->getTrace(), \Tablo\JsonRecord::checked(...)) as $frame) {
                        if (!(($frame['class'] ?? '') === GitHubRequestPolicy::class && ($frame['function'] ?? '') === 'observe')) { continue; }

                            $masked = ($frame['args'][1] ?? null) instanceof \SensitiveParameterValue;

                    }
                    Assert::true($masked, 'real propagated observe frame masks the complete response');

    }
}
