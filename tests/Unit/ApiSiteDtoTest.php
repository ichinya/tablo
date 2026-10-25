<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\ApiSiteDto;
use Tablo\Tests\Support\FixtureValue;
use Tablo\Tests\Support\ReadApiAssertions as A;
use Testo\Assert;
use Testo\Test;

#[Test]
function exact_whitelist_preserves_unknown_null_measured_zero_and_paused_observations(): void
    {
        $unknown = A::dto(); A::exactDto($unknown);
        Assert::same($unknown['latest']['status'], 'unknown'); Assert::null($unknown['latest']['online']);
        Assert::same($unknown['latest']['comparison'], 'not_tracked'); Assert::same($unknown['origin'], 'https://example.test');
        foreach ([0, 1] as $online) {
            $dto = A::dto(['checked_at' => A::NOW, 'online' => $online, 'open_issues' => 0, 'open_prs' => 0, 'response_time_ms' => 0]);
            A::exactDto($dto); Assert::same($dto['latest']['status'], 'complete'); Assert::same($dto['latest']['online'], $online === 1);
            Assert::same($dto['latest']['response_time_ms'], 0); Assert::same($dto['latest']['open_issues'], 0);
            $paused = A::dto(['enabled' => 0, 'checked_at' => A::NOW, 'online' => $online, 'open_issues' => 0]);
            Assert::same($paused['latest']['status'], 'paused'); Assert::same($paused['latest']['online'], $online === 1);
            Assert::same($paused['latest']['checked_at'], A::NOW);
        }
        $partial = A::dto(['checked_at' => A::NOW, 'online' => 0]);
        Assert::same($partial['latest']['status'], 'partial'); Assert::null($partial['latest']['open_issues']);
    }

    #[Test]
function freshness_equality_and_future_clock_use_one_strict_utc_response_epoch(): void
    {
        foreach ([899 => 'fresh', 900 => 'fresh', 901 => 'stale', -1 => 'clock_invalid'] as $age => $expected) {
            $checked = (new \DateTimeImmutable(A::NOW))->modify(sprintf('%+d seconds', -$age))->format('Y-m-d\TH:i:s\Z');
            Assert::same(A::dto(['checked_at' => $checked])['latest']['freshness'], $expected);
            Assert::same(A::dto(['enabled' => 0, 'checked_at' => $checked])['latest']['freshness'], $expected);
        }
        Assert::same(A::dto()['latest']['freshness'], 'never_checked');
        foreach (['2026-02-30T00:00:00Z', '2026-10-14T10:00:00+00:00', '2026-10-14T10:00:60Z', "2026-10-14T10:00:00Z\n"] as $time) {
            A::reject(static fn () => A::dto(['checked_at' => $time]));
        }
    }

    #[Test]
function comparison_uses_private_operands_without_returning_them_or_inventing_ancestry(): void
    {
        foreach ([['v1.2.3', '1.2.3', 'current'], ['1.2.2', '1.2.3', 'update_available'], ['2.0.0', '1.2.3', 'ahead'],
            ['label-one', 'label-two', 'different'], [null, '1.2.3', 'unknown'], [str_repeat('x', times: 257), '1.2.3', 'unknown']] as [$a, $b, $comparison]) {
            $dto = A::dto(['tracks_version' => 1, 'checked_at' => A::NOW, 'deployed_version' => $a, 'latest_release' => $b]);
            A::exactDto($dto); Assert::same($dto['latest']['comparison'], $comparison);
        }
        foreach ([['abcdef0', 'abcdef0123456789', 'current'], ['fffffff', 'abcdef0123456789', 'different'], ['private-value', 'abcdef0123456789', 'unknown']] as [$a, $b, $comparison]) {
            Assert::same(A::dto(['tracks_version' => 1, 'comparison_mode' => 'branch', 'checked_at' => A::NOW,
                'deployed_commit' => $a, 'latest_commit' => $b])['latest']['comparison'], $comparison);
        }
    }

    #[Test]
function hostile_legacy_values_fail_closed_and_future_secret_columns_cannot_expand_dto(): void
    {
        foreach ([['name' => str_repeat('x', times: 321)], ['name' => "bad\xff"], ['enabled' => '1'], ['sort_order' => -1], ['sort_order' => 10_000],
            ['config_revision' => '01'], ['id' => '9223372036854775808'], ['repository' => 'https://example.test/repo'], ['online' => 1],
            ['checked_at' => A::NOW, 'open_issues' => -1], ['checked_at' => A::NOW, 'open_prs' => 2_147_483_648],
            ['checked_at' => A::NOW, 'response_time_ms' => 1.5], ['checked_at' => A::NOW, 'health_error_code' => 'private-diagnostic']] as $bad) {
            A::reject(static fn () => A::dto($bad));
        }
        $dto = A::dto(['future_secret' => \Tablo\Tests\Support\FixtureCredentials::value('future-column-canary'), 'github_token' => \Tablo\Tests\Support\FixtureCredentials::value('credential-canary'), 'last_error' => 'raw-error-canary']);
        A::exactDto($dto);
        Assert::false(str_contains(FixtureValue::string(json_encode($dto)), 'canary'));
        foreach (['https://u:p@example.test/private', 'https://example.test/private?secret=x', 'https://example.test/#secret', 'file:///private', 'https://example.test\\evil/path'] as $url) { Assert::null(ApiSiteDto::origin($url)); }
        Assert::same(ApiSiteDto::origin('https://Example.test:8443/private/path'), 'https://example.test:8443');
        Assert::same(ApiSiteDto::origin('http://[::1]:8080/private'), 'http://[::1]:8080');
    }
