<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\HttpFailure;
use Tablo\Tests\Support\WebFixture;
use Testo\Assert;

/**
 * @phpstan-import-type Framing from CheckObservation
 */
final class FramingObservationAssertions
{
    public function __construct(private readonly \Tablo\Tests\Support\WebFixture $web) {}

    /**
     * @param Framing $manual
     */
    public function manual(array $manual, ?string $reason, string $mode): string
    {
                    Assert::same($manual['online'], $reason === null ? 1 : null, $mode);
                    Assert::same($manual['health_error_code'], $reason, $mode);
                    Assert::same($manual['health_http_status'], $reason === null ? 200 : null, $mode);
                    Assert::same($manual['deployed_version'], '1.3.1', $mode);
                    Assert::same($manual['deployed_commit'], 'a61de82', $mode);
                    Assert::same($manual['open_issues'], 2, $mode);
                    Assert::same($manual['open_prs'], 2, $mode);
                    Assert::same($manual['last_error'], $reason === null ? null : 'Health: ' . \Tablo\Tests\Support\FixtureValue::at(HttpFailure::MESSAGES, $reason), $mode);
                    $panel = $this->web->request('/')['body'];
                    if ($reason !== null) {
                        Assert::same($manual['response_time_ms'], null, $mode);
                        Assert::true(str_contains($panel, 'data-health-error="' . $reason . '"'), $mode);
                        Assert::true(str_contains($panel, 'Нет данных'), $mode);
                    }
        return $panel;
    }
}
