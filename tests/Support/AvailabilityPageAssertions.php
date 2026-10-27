<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use PDO;
use Testo\Assert;

/** @phpstan-type Response array{status:int,headers:string,body:string} */
final class AvailabilityPageAssertions
{
    /** @param list<string> $tables */
    public static function guard(PDO $db, array $tables): void
    {
        foreach ($tables as $table) {
            foreach (['INSERT', 'UPDATE', 'DELETE'] as $operation) {
                $db->exec('CREATE TRIGGER availability_guard_' . $table . '_' . $operation . ' BEFORE ' . $operation . ' ON ' . $table
                    . " BEGIN SELECT RAISE(ABORT,'availability-read-mutation'); END");
            }
        }
    }

    /** @param Response $response */
    public static function finiteProjection(array $response): void
    {
        Assert::same($response['status'], 200);
        Assert::true(str_contains($response['body'], '&lt;img src=x onerror=alert(1)&gt;'));
        Assert::false(str_contains($response['body'], '<img src=x'));
        Assert::true(str_contains($response['body'], '50,0%'));
        Assert::true(str_contains($response['body'], '10,0'));
        Assert::true(str_contains($response['body'], 'Unknown'));
        Assert::true(str_contains($response['body'], 'паузе'));
        Assert::true(str_contains($response['body'], 'UTC'));
        Assert::true(str_contains($response['body'], 'мс'));
        Assert::same(substr_count($response['body'], needle: 'data-availability-bucket='), 120);
        foreach (['availability-private-error', 'availability-private-version', FixtureCredentials::value('fixture-token')] as $marker) {
            Assert::false(str_contains($response['body'], $marker));
        }
        foreach (['X-Content-Type-Options: nosniff', 'X-Frame-Options: DENY', 'Cache-Control: no-store', "frame-ancestors 'none'"] as $header) {
            Assert::true(str_contains($response['headers'], $header));
        }
    }

    /** @param Response $response */
    public static function noPercentage(array $response, string $state): void
    {
        Assert::same($response['status'], 200);
        Assert::true(str_contains($response['body'], 'data-availability-state="' . $state . '"'));
        Assert::false(str_contains($response['body'], '>0%<'));
        Assert::false(str_contains($response['body'], '>100%<'));
        Assert::false(str_contains($response['body'], '>0,0%<'));
    }

    /** @param Response $response */
    public static function loginRedirect(array $response): void
    {
        Assert::same($response['status'], 303);
        Assert::true(str_contains($response['headers'], 'Location: /login'));
    }
}
