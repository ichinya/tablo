<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Test;

final class PasswordPrivacyTest
{
    #[Test]
    public function simulatedAuthOperationsUseSafe503WhileVerifierFalseRemainsAnInvalidLogin(): void
    {
        foreach (['hash-throw', 'unregistered', 'verify-throw', 'verify-false'] as $mode) { self::httpCase($mode); }
    }

    private static function httpCase(string $mode): void
    {
        $web = new \Tablo\Tests\Support\WebFixture(router: dirname(__DIR__) . '/password-race-router.php', environment: ['TABLO_TEST_PROVIDER_CASE' => $mode]);
        try {
            $setup = $web->request('/setup');
            $login = in_array($mode, ['verify-throw', 'verify-false'], strict: true);
            if ($login) {
                (new \Tablo\Auth($web->database()))->setup(\Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'), \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'));
            }
            $before = \Tablo\Tests\Support\ReadApiAssertions::snapshot($web->database());
            $form = $login ? $web->request('/login') : $setup;
            $response = $web->request($login ? '/login' : '/setup', ['_csrf' => \Tablo\Tests\Support\WebFixture::csrf($form),
                'password' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'), 'confirmation' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password')]);
            Assert::same($response['status'], $mode === 'verify-false' ? 422 : 503);
            Assert::false(str_contains($response['body'] . $web->server->diagnostics(), 'provider-private-marker'));
            Assert::same($web->request('/')['status'], 303, 'failure grants no authenticated cookie');
            if ($mode === 'verify-false') {
                Assert::same(count(\Tablo\Tests\Support\ReadApiAssertions::rows($web->database(), 'SELECT * FROM login_limits')), 1);
                return;
            }
            Assert::same(\Tablo\Tests\Support\ReadApiAssertions::snapshot($web->database()), $before);
        } catch (\Throwable $error) { $web->directory->retainFailure($error); throw $error; }
        finally { $web->close(); }
    }

    #[Test]
    public function redactsCompletePropagatedAndPreviousErrorTraces(): void
    {
        $directory = new TemporaryDirectory('tablo-password-trace-process-');
        try {
            // Capture COMPLETE arguments in a bare process without recursively
            // expanding the Testo runner and its entire prior assertion history.
            foreach (['password-privacy.php', 'password-hash-failure.php'] as $fixture) {
                $result = Subprocess::run(command: [PHP_BINARY, '-d', 'zend.exception_ignore_args=0', dirname(__DIR__) . '/fixtures/' . $fixture], directory: $directory, environment: ['TABLO_DB' => $directory->path . '/fixture.sqlite'], options: new \Tablo\Tests\Support\CommandOptions(timeout: 15));
                Assert::same($result['exit_code'], 0, $result['stderr']);
                Assert::true(str_contains($result['stdout'], 'redacted'));
            }
        } finally { $directory->close(); }
    }
}
