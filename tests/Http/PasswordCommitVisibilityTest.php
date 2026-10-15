<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;


use Tablo\Tests\Support\PasswordProcess;
use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\WebFixture;
use Testo\Assert;
use Testo\Test;

final class PasswordCommitVisibilityTest extends PasswordHttpFixtureCase
{


    #[Test]
    public function actualWriterDeathBeforeCommitAndOutputFailureAfterCommitHaveDifferentOutcomes(): void
    {
        $web = new WebFixture();
        $db = null;
        $process = null;
        try {
            $csrf = $web->authenticate();
            $db = $web->database();
            $old = $db->query('SELECT * FROM users')->fetchAll();
            $process = new PasswordProcess($web->directory, $web->directory->path . '/test.sqlite',
                dirname(__DIR__) . '/fixtures/password-write-barrier.php', ['TABLO_TEST_WRITE_BARRIER' => $web->directory->path]);
            $process->send(\Tablo\Tests\Support\FixtureCredentials::confirmationInput(label: 'replacement-secret'));
            $written = $web->directory->path . '/written';
            $deadline = microtime(true) + 3;
            while (!file_exists($written) && microtime(true) < $deadline) { clearstatcache(); usleep(20_000); }
            Assert::true(file_exists($written), 'actual CLI verified its uncommitted UPDATE before COMMIT');
            Assert::same($web->request('/setup', ['_csrf' => $csrf])['status'], 404);
            $process->close(); $process = null;
            Assert::same($db->query('SELECT * FROM users')->fetchAll(), $old);
            Assert::same($web->request('/')['status'], 200);
            $result = Subprocess::run(command: [PHP_BINARY, dirname(__DIR__) . '/fixtures/PasswordOutputFailure.php', '--password-stdin'], directory: $web->directory, environment: ['TABLO_DB' => $web->directory->path . '/test.sqlite'], options: new \Tablo\Tests\Support\CommandOptions(input: \Tablo\Tests\Support\FixtureCredentials::confirmationInput(label: 'replacement-secret')));
            Assert::same($result['exit_code'], 1);
            Assert::true(!str_contains($result['stdout'], 'Administrator password changed.'));
            Assert::true(str_contains($result['stderr'], 'outcome may be uncertain'));
            Assert::true(password_verify(\Tablo\Tests\Support\FixtureCredentials::value(label: 'replacement-secret'), $db->query('SELECT password_hash FROM users')->fetchColumn()));
            Assert::same($web->request('/')['status'], 303);
            Assert::same(self::login($web, \Tablo\Tests\Support\FixtureCredentials::value(label: 'replacement-secret'))['status'], 303);
            Assert::same($web->request('/setup')['status'], 404);
        } finally {
            $process?->close();
            if ($db?->inTransaction()) { $db->rollBack(); }
            $db = null; $web->close();
        }
    }
}
