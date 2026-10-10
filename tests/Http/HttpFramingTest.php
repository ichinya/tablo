<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\HttpFailure;
use Tablo\SiteRepository;
use Tablo\Tests\Support\RawHttpServer;
use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\WebFixture;
use Testo\Assert;
use Testo\Test;

final class HttpFramingTest
{
    #[Test]
    public function classifiesMalformedChunksWhenCliRunsBeforeManualCheck(): void
    {
        $server = new RawHttpServer();
        $web = null;
        $repository = null;
        try {
            $web = new WebFixture(allowPrivateNetwork: true);
            $web->authenticate();
            $repository = new SiteRepository($web->database());
            foreach (['', '/up'] as $path) {
                $id = $repository->save(array_replace(SiteRepository::defaults(), ['name' => 'CLI framing fixture',
                    'url' => $server->base . '/invalid-hex', 'health_path' => $path, 'version_path' => '',
                    'repository' => 'fixture/public', 'enabled' => 1]));
                $root = dirname(__DIR__, 2);
                $cli = Subprocess::run([PHP_BINARY, '-d', 'auto_prepend_file=' . $root . '/tests/cli-github-fixture.php',
                    $root . '/bin/check.php'], $web->directory, ['TABLO_DB' => $web->directory->path . '/test.sqlite',
                        'TABLO_ALLOW_PRIVATE_NETWORK' => '1']);
                Assert::same($cli['exit_code'], 1);
                Assert::same($cli['stderr'], '');
                Assert::same($web->database()->query('SELECT online,health_error_code,health_http_status,response_time_ms,last_error FROM sites WHERE id=' . $id)->fetch(),
                    ['online' => null, 'health_error_code' => 'invalid-response', 'health_http_status' => null,
                        'response_time_ms' => null, 'last_error' => 'Health: ' . HttpFailure::MESSAGES['invalid-response']]);
                $repository->delete($id);
            }
        } finally {
            $repository = null;
            $web?->close();
            $server->close();
        }
    }

    #[Test]
    public function persistsAndRendersFramingReasonsThroughManualAndCliChecks(): void
    {
        $server = new RawHttpServer();
        $web = null;
        $repository = null;
        try {
            $web = new WebFixture(allowPrivateNetwork: true);
            $csrf = $web->authenticate();
            $repository = new SiteRepository($web->database());
            foreach (['invalid-hex' => 'invalid-response', 'reset' => 'network',
                'incomplete' => 'invalid-response', 'valid' => null] as $mode => $reason) {
                foreach (['', '/up'] as $path) {
                    $id = $repository->save(array_replace(SiteRepository::defaults(), ['name' => 'Framing fixture',
                        'url' => $server->base . '/' . $mode, 'health_path' => $path, 'version_path' => '/version',
                        'repository' => 'fixture/public', 'enabled' => 1]));
                    Assert::same($web->request('/sites/' . $id . '/check', ['_csrf' => $csrf])['status'], 303);
                    $query = 'SELECT online,health_error_code,health_http_status,response_time_ms,deployed_version,deployed_commit,latest_commit,open_issues,open_prs,last_error FROM sites WHERE id=' . $id;
                    $manual = $web->database()->query($query)->fetch();
                    Assert::same($manual['online'], $reason === null ? 1 : null, $mode);
                    Assert::same($manual['health_error_code'], $reason, $mode);
                    Assert::same($manual['health_http_status'], $reason === null ? 200 : null, $mode);
                    Assert::same($manual['deployed_version'], '1.3.1', $mode);
                    Assert::same($manual['deployed_commit'], 'a61de82', $mode);
                    Assert::same($manual['open_issues'], 2, $mode);
                    Assert::same($manual['open_prs'], 2, $mode);
                    Assert::same($manual['last_error'], $reason === null ? null : 'Health: ' . HttpFailure::MESSAGES[$reason], $mode);
                    $panel = $web->request('/')['body'];
                    if ($reason !== null) {
                        Assert::same($manual['response_time_ms'], null, $mode);
                        Assert::true(str_contains($panel, 'data-health-error="' . $reason . '"'), $mode);
                        Assert::true(str_contains($panel, 'Нет данных'), $mode);
                    }
                    $root = dirname(__DIR__, 2);
                    $cli = Subprocess::run([PHP_BINARY, '-d', 'auto_prepend_file=' . $root . '/tests/cli-github-fixture.php',
                        $root . '/bin/check.php'], $web->directory, ['TABLO_DB' => $web->directory->path . '/test.sqlite',
                            'TABLO_ALLOW_PRIVATE_NETWORK' => '1']);
                    Assert::same($cli['exit_code'], $reason === null ? 0 : 1, $mode);
                    Assert::same($cli['stderr'], '', $mode);
                    Assert::false(str_contains($panel . $cli['stdout'], 'private-marker'), $mode);
                    $after = $web->database()->query($query)->fetch();
                    if ($reason !== null) { Assert::same($after['response_time_ms'], null, $mode); }
                    // Completed-request timings vary; incomplete responses must stay null.
                    unset($after['response_time_ms'], $manual['response_time_ms']);
                    Assert::same($after, $manual, $mode);
                    $repository->delete($id);
                }
            }
            Assert::false(str_contains($web->server->diagnostics(), 'private-marker'));
            Assert::false(str_contains($web->server->diagnostics(), 'hex-length'));
        } finally {
            $repository = null;
            $web?->close();
            $server->close();
        }
    }
}
