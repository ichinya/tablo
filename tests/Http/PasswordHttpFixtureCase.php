<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;


use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\WebFixture;
use Testo\Assert;

/**
 * @phpstan-import-type Result from \Tablo\Tests\Support\Subprocess
 */
abstract class PasswordHttpFixtureCase
{
    /**
     * @return Result
     */
    protected static function reset(WebFixture $web): array
    {
        return Subprocess::run(command: [PHP_BINARY, dirname(__DIR__, levels: 2) . '/bin/admin-password.php', '--password-stdin'], directory: $web->directory, environment: ['TABLO_DB' => $web->directory->path . '/test.sqlite'], options: new \Tablo\Tests\Support\CommandOptions(input: \Tablo\Tests\Support\FixtureCredentials::confirmationInput(label: 'replacement-secret')));
    }

    /**
     * @return array{status: int, headers: string, body: string}
     */
    protected static function login(WebFixture $web, #[\SensitiveParameter] string $password): array
    {
        return $web->request('/login', ['_csrf' => WebFixture::csrf($web->request('/login')), 'password' => $password]);
    }

    protected static function sessionFile(WebFixture $web): string
    {
        $files = glob($web->directory->path . '/runtime/sessions/sess_*');
        if ($files === false) { throw new \RuntimeException('Cannot enumerate owned browser sessions.'); }
        Assert::same(count($files), 1, 'one owned browser session');
        return \Tablo\Tests\Support\FixtureValue::at($files, 0);
    }

    protected function loginRace(\Tablo\Tests\Support\PasswordRacePhase $phase): void
    {
        $barrier = new TemporaryDirectory('tablo-login-barrier-');
        $web = null;
        $process = null;
        $pipes = [];
        try {
            $web = new WebFixture(router: dirname(__DIR__) . '/password-race-router.php', environment: ['TABLO_TEST_AUTH_BARRIER' => $barrier->path]);
            $csrf = $web->authenticate();
            $web->request('/logout', ['_csrf' => $csrf]);
            $csrf = WebFixture::csrf($web->request('/login'));
            if ($phase === \Tablo\Tests\Support\PasswordRacePhase::PasswordRehash) {
                \Tablo\SqlStatement::checked($web->database()->prepare('UPDATE users SET password_hash=? WHERE id=1'))
                    ->execute([password_hash(\Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'), PASSWORD_BCRYPT, ['cost' => 4])]);
            }
            file_put_contents($barrier->path . '/armed', data: 'ready');
            $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/fixtures/password-login.php', $web->server->base,
                $web->directory->path . '/cookies', $csrf], [0 => ['pipe', 'r'], 1 => ['file', $barrier->path . '/out', 'w'],
                2 => ['file', $barrier->path . '/err', 'w']], $pipes, dirname(__DIR__, levels: 2));
            if (!is_resource($process)) { throw new \RuntimeException('Cannot start HTTP login fixture'); }
            fclose(\Tablo\Tests\Support\FixturePipes::pipe($pipes, 0));
            $deadline = microtime(true) + 5;
            while (!is_file($barrier->path . '/verified') && microtime(true) < $deadline) { clearstatcache(); usleep(20_000); }
            Assert::true(is_file($barrier->path . '/verified'), 'actual old login returned verified snapshot');
            Assert::same(self::reset($web)['exit_code'], 0);
            unlink($barrier->path . '/armed');
            file_put_contents($barrier->path . '/release', data: 'ready');
            Assert::same(proc_close($process), 0);
            $process = null;
            Assert::same($web->request('/')['status'], 303, 'late cookie is stale');
            Assert::same(self::login($web, \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'))['status'], 422);
            Assert::same(self::login($web, \Tablo\Tests\Support\FixtureCredentials::value(label: 'replacement-secret'))['status'], 303);
            Assert::same($web->request('/')['status'], 200);
        } finally {
            self::closeLoginProcess($process);
            $web?->close(); $barrier->close();
        }
    }
    /** @param resource|null $process */
    private static function closeLoginProcess(mixed $process): void
    {
        if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    }
}
