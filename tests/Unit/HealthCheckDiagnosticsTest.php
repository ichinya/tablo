<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\SiteRepository;
use Tablo\Tests\Support\HealthCheckDiagnostics;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;
use Testo\Test;

final class HealthCheckDiagnosticsTest
{
    #[Test]
    public function capturesChildStatusAndStateWithoutArbitraryMessagesOrArguments(): void
    {
        $db = Database::connect(':memory:');
        $id = (new SiteRepository($db))->save(UnitFixtures::site());
        $db->exec("UPDATE sites SET online=0,health_error_code='http',health_http_status=503,last_error='private-body' WHERE id=" . $id);
        $stderr = "PHP Fatal error: Uncaught RuntimeException: secret-value in fixture.php on line 42\n#0 fixture.php(42): check('secret-argument')";
        $message = HealthCheckDiagnostics::capture(['exit_code' => 1, 'stdout' => "1: attention\nsecret-output", 'stderr' => $stderr], $db);
        $data = \Tablo\JsonRecord::checked(json_decode($message, associative: true, depth: 8, flags: JSON_THROW_ON_ERROR));
        Assert::same(\Tablo\Tests\Support\FixtureValue::at($data, 'exit_code'), 1);
        Assert::same(\Tablo\Tests\Support\FixtureValue::at($data, 'stdout_status'), ['1: attention']);
        Assert::same(\Tablo\Tests\Support\FixtureValue::at(\Tablo\JsonRecord::checked(\Tablo\Tests\Support\FixtureValue::at($data, 'stderr')), 'bytes'), strlen($stderr));
        Assert::same(\Tablo\Tests\Support\FixtureValue::at(\Tablo\JsonRecord::checked(\Tablo\Tests\Support\FixtureValue::at($data, 'stderr')), 'sha256'), hash('sha256', $stderr));
        Assert::same(\Tablo\Tests\Support\FixtureValue::at(\Tablo\JsonRecord::checked(\Tablo\Tests\Support\FixtureValue::at($data, 'stderr')), 'safe_markers'), ['PHP Fatal error:', 'Uncaught RuntimeException', ' on line 42']);
        Assert::same(\Tablo\Tests\Support\FixtureValue::at(\Tablo\JsonRecord::checked(\Tablo\Tests\Support\FixtureValue::at(\Tablo\JsonRecord::checked(\Tablo\Tests\Support\FixtureValue::at($data, 'state')), 0)), 'online'), 0);
        Assert::same(\Tablo\Tests\Support\FixtureValue::at(\Tablo\JsonRecord::checked(\Tablo\Tests\Support\FixtureValue::at(\Tablo\JsonRecord::checked(\Tablo\Tests\Support\FixtureValue::at($data, 'state')), 0)), 'health_error_code'), 'http');
        Assert::same(\Tablo\Tests\Support\FixtureValue::at(\Tablo\JsonRecord::checked(\Tablo\Tests\Support\FixtureValue::at(\Tablo\JsonRecord::checked(\Tablo\Tests\Support\FixtureValue::at($data, 'state')), 0)), 'health_http_status'), 503);
        Assert::false(str_contains($message, 'secret-'));
        Assert::false(str_contains($message, 'private-body'));
    }
}
