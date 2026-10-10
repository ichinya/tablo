<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\SettingsRepository;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;
use Testo\Test;

final class SettingsRepositoryTest
{
    #[Test]
    public function validatesRawIntegersWithoutOverflowOrDefaultRewrites(): void
    {
        $db = Database::connect(':memory:');
        $settings = new SettingsRepository($db);
        Assert::same($settings->get(), 10);
        foreach (['1', '10', '0003', (string) intdiv(PHP_INT_MAX, 60)] as $raw) {
            $settings->updateInterval($raw);
            Assert::same($settings->get(), (int) $raw);
            Database::migrate($db);
            Assert::same($settings->get(), (int) $raw);
        }
        foreach (['', '0', '00', '-1', '+1', '1.2', '1e3', ' 1', '1 ', '١', [], true, 2, null,
            (string) PHP_INT_MAX, str_repeat('9', 100)] as $raw) {
            UnitFixtures::rejects(fn () => $settings->updateInterval($raw), 'invalid raw interval');
            Assert::same($settings->get(), intdiv(PHP_INT_MAX, 60));
        }
        $db->exec('DELETE FROM installation_settings');
        Assert::same($settings->get(), 10);
        Assert::same((int) $db->query('SELECT count(*) FROM installation_settings')->fetchColumn(), 0);
        $settings->updateInterval('2');
        $db->exec('PRAGMA ignore_check_constraints = ON; UPDATE installation_settings SET check_interval_minutes = 0');
        $error = null;
        try { $settings->get(); } catch (\RuntimeException $caught) { $error = $caught->getMessage(); }
        Assert::same($error, 'Invalid worker interval.');
    }
}
