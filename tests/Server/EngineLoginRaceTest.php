<?php
declare(strict_types=1);

namespace Tablo\Tests\Server;

use Tablo\Auth;
use Tablo\ServerSchema;
use Tablo\Tests\Support\EngineFixture;
use Tablo\Tests\Support\EngineProcess;
use Tablo\Tests\Support\FixtureCredentials;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Test;

final class EngineLoginRaceTest
{
    #[Test]
    public function sixIndependentProcessesCommitExactlyFiveFailuresAndOneThrottle(): void
    {
        $fixture = new EngineFixture('login-six');
        $auth = new Auth($fixture->database());
        $password = FixtureCredentials::syntheticPassword(); $auth->setup($password, $password);
        $directory = new TemporaryDirectory('tablo-server-login-'); $children = [];
        try {
            $started = hrtime(true) / 1_000_000_000;
            for ($index = 0; $index < 6; $index++) { $children[] = new EngineProcess($fixture, $directory, FixtureCredentials::value(label: 'login-wrong'), '192.0.2.39'); }
            foreach ($children as $child) { $child->ready(min(2, $started + 2 - hrtime(true) / 1_000_000_000)); }
            foreach ($children as $child) { $child->release(); }
            $deadline = $started + 7;
            $results = [];
            foreach ($children as $child) { $results[] = $child->finish(min(6, $deadline - hrtime(true) / 1_000_000_000)); }
            Assert::same(count(array_unique(array_column($results, 'connectionId'))), 6);
            $counts = array_count_values(array_column($results, 'status'));
            Assert::same($counts['failed'] ?? null, 5); Assert::same($counts['throttled'] ?? null, 1);
            Assert::same(count($counts), 2);
            Assert::same($fixture->scalar('SELECT failures FROM login_limits'), 5);
            Assert::same($fixture->scalar('SELECT COUNT(*) FROM login_limits'), 1);
            Assert::true($auth->login($password, '192.0.2.40'));
            Assert::same($fixture->scalar('SELECT failures FROM login_limits'), 5);
        } finally {
            foreach ($children as $child) { $child->close(); }
            $directory->close(); $fixture->close();
        }
    }

    #[Test]
    public function expiredThrottleAndSuccessfulLoginClearOnlyTheirOwnAddress(): void
    {
        $fixture = new EngineFixture('login-expiry');
        $db = $fixture->database(); $auth = new Auth($db);
        $password = FixtureCredentials::syntheticPassword(); $auth->setup($password, $password);
        $address = '192.0.2.39'; $key = hash('sha256', $address);
        for ($index = 0; $index < 5; $index++) { Assert::false($auth->login(FixtureCredentials::value(label: 'login-wrong'), $address)); }
        $throttled = false;
        try { $auth->login($password, $address); } catch (\Tablo\ValidationException) { $throttled = true; }
        Assert::true($throttled);
        ServerSchema::rows($db, 'UPDATE login_limits SET window_start = ? WHERE `key` = ?', [time() - 901, $key]);
        Assert::false($auth->login(FixtureCredentials::value(label: 'login-wrong'), $address));
        Assert::same($fixture->scalar('SELECT failures FROM login_limits'), 1);
        Assert::false($auth->login(FixtureCredentials::value(label: 'login-wrong'), '192.0.2.40'));
        Assert::true($auth->login($password, $address));
        Assert::same(ServerSchema::rows($db, 'SELECT `key`,failures FROM login_limits'), [['key' => hash('sha256', data: '192.0.2.40'), 'failures' => 1]]);
        $fixture->close();
    }
}
