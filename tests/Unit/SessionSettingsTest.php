<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\SettingsRepository;
use Tablo\SqlStatement;
use Tablo\ValidationException;
use Testo\Assert;
use Testo\Test;

final class SessionSettingsTest
{
    #[Test]
    public function boundedAsciiInputAndIndependentWritesPreserveSiblingSettings(): void
    {
        $db = Database::connect(':memory:');
        $settings = new SettingsRepository($db);
        Assert::same($settings->getIdleTimeout(), 30);
        $settings->updateInterval('13');
        foreach (['1', '30', '0003', '720', '000720'] as $raw) {
            $settings->updateIdleTimeout($raw);
            Assert::same($settings->getIdleTimeout(), (int) $raw);
            Assert::same($settings->get(), 13);
        }
        $settings->updateInterval('17');
        Assert::same($settings->getIdleTimeout(), 720);
        foreach (['', '0', '00', '-1', '+1', '1.0', '1e2', ' 1', '1 ', "1\n", '١', '７', '721', str_repeat('9', times: 100),
            str_repeat('0', times: 16_385) . '1', [], true, 2, null] as $raw) {
            $rejected = false;
            try { $settings->updateIdleTimeout($raw); } catch (ValidationException) { $rejected = true; }
            Assert::true($rejected);
            Assert::same($settings->getIdleTimeout(), 720);
            Assert::same($settings->get(), 17);
        }
        $db->exec('DELETE FROM installation_settings');
        $changes = (int) SqlStatement::checked($db->query('SELECT total_changes()'))->fetchColumn();
        Assert::same($settings->getIdleTimeout(), 30);
        Assert::same($settings->get(), 10);
        Assert::same((int) SqlStatement::checked($db->query('SELECT total_changes()'))->fetchColumn(), $changes);
        Assert::same((int) SqlStatement::checked($db->query('SELECT count(*) FROM installation_settings'))->fetchColumn(), 0);
        $settings->updateIdleTimeout('2');
        Assert::same($settings->get(), 10);
        $db->exec('PRAGMA ignore_check_constraints = ON');
        foreach (["0", "721", "1.5", "'corrupt'", "-1"] as $sql) {
            $db->exec('UPDATE installation_settings SET session_idle_timeout_minutes = ' . $sql);
            $message = null;
            try { $settings->getIdleTimeout(); } catch (\RuntimeException $error) { $message = $error->getMessage(); }
            Assert::same($message, 'Invalid session idle timeout.');
        }
    }
}
