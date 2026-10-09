<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Database;
use Tablo\GitHubClock;
use Tablo\GitHubConnection;
use Tablo\GitHubFailure;
use Tablo\GitHubRequestPolicy;
use Tablo\GitTokenRepository;
use Tablo\SiteRepository;
use Tablo\TokenVault;
use Tablo\Tests\Support\MeasuredGitHubHttp;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Testo\Assert;
use Testo\Test;

final class GitHubResponsePrivacyTest
{
    private static function failure(\Closure $call): GitHubFailure
    {
        $failure = null;
        try { $call(); } catch (GitHubFailure $caught) { $failure = $caught; }
        Assert::instanceOf($failure, GitHubFailure::class);
        Assert::same($failure->reason, 'rate-limit');
        return $failure;
    }

    private static function hasPrivateTrace(#[\SensitiveParameter] \Throwable $error, #[\SensitiveParameter] array $markers): bool
    {
        $seen = new \SplObjectStorage();
        do {
            // Inspect every propagated/previous frame and string form only in memory.
            $pending = [$error->getTrace(), $error->getTraceAsString(), (string) $error];
            $visited = 0;
            while ($pending !== []) {
                if (++$visited > 10000) { throw new \RuntimeException('Trace inspection bound exceeded'); }
                $value = array_pop($pending);
                if (is_string($value)) {
                    foreach ($markers as $marker) { if (str_contains($value, $marker)) { return true; } }
                } elseif (is_array($value)) {
                    foreach ($value as $key => $item) { $pending[] = $key; $pending[] = $item; }
                } elseif (is_object($value) && !$value instanceof \SensitiveParameterValue && !$seen->contains($value)) {
                    // Inspect public argument data once; never unwrap a redacted parameter.
                    $seen->attach($value);
                    $pending[] = get_object_vars($value);
                }
            }
            $error = $error->getPrevious();
        } while ($error !== null);
        return false;
    }

    private static function requests(string $path): array
    {
        return array_map('intval', file($path, FILE_IGNORE_NEW_LINES));
    }

    #[Test]
    public function realRateResponsesKeepPrivateBodiesOutOfCompleteProviderTraces(): void
    {
        $directory = new TemporaryDirectory('tablo-github-response-r3-');
        $server = null;
        $original = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            Assert::same(ini_get('zend.exception_ignore_args'), '0');
            $now = time();
            $secret = bin2hex(random_bytes(24));
            $marker = bin2hex(random_bytes(24));
            $count = $directory->path . '/count';
            $server = new TestServer($directory, dirname(__DIR__) . '/fixtures/github-response-router.php', environment: [
                'TABLO_TEST_RESPONSE_NOW' => (string) $now, 'TABLO_TEST_RESPONSE_MARKER' => $marker,
                'TABLO_TEST_RESPONSE_COUNT' => $count,
            ]);
            foreach (['manual', 'saved'] as $kind) {
                foreach (['secondary403' => 60, 'bare429' => 60, 'retry403' => 7200, 'primary429' => 7200] as $case => $delay) {
                    file_put_contents($count, '');
                    $path = $directory->path . '/' . $kind . '-' . $case . '.sqlite';
                    $db = Database::connect($path);
                    $vault = new TokenVault($path . '.key');
                    $sites = new SiteRepository($db, $vault);
                    $tokens = new GitTokenRepository($db, $vault);
                    $input = $kind === 'manual' ? ['github_token' => $secret] : ['git_token_id' =>
                        $tokens->save(['name' => 'Saved', 'provider' => 'github', 'token' => $secret])];
                    $http = new MeasuredGitHubHttp($server->base);
                    $policy = new GitHubRequestPolicy($sites->githubCooldowns(), clock: new GitHubClock(epoch: static fn (): int => $now));
                    $connection = new GitHubConnection($sites, $http, $policy);
                    $provider = $connection->provider(null, $input);
                    Assert::same($provider->getLatestRelease('fixture/control'), 'v1');
                    Assert::same($provider->getLatestRelease('fixture/control'), 'v1');
                    Assert::same(self::requests($count), [200], 'successful revision memo avoids a second HTTP request');
                    $error = self::failure(fn () => $provider->getLatestRelease('fixture/' . $case));
                    // Assert privacy first: the exact frozen negative must fail here, not at fixture setup.
                    Assert::false(self::hasPrivateTrace($error, [$secret, $marker]), 'response trace must redact every private marker');
                    Assert::same($error->eligibleAt, $now + $delay);
                    Assert::same($error->httpStatus, null, 'existing typed rate failure status contract');
                    Assert::same($error->getMessage(), (new GitHubFailure('rate-limit', $now + $delay))->getMessage());
                    $masked = false;
                    foreach ($error->getTrace() as $frame) {
                        if (($frame['class'] ?? '') === GitHubRequestPolicy::class && ($frame['function'] ?? '') === 'observe') {
                            $masked = ($frame['args'][1] ?? null) instanceof \SensitiveParameterValue;
                        }
                    }
                    Assert::true($masked, 'real propagated observe frame masks the complete response');
                    $rows = $db->query('SELECT scope,resource,eligible_at FROM github_cooldowns ORDER BY scope')->fetchAll();
                    $primary = $case === 'primary429';
                    Assert::same(count($rows), $primary && $kind === 'saved' ? 2 : 1);
                    foreach ($rows as $row) {
                        Assert::same($row['resource'], $primary ? 'core' : 'secondary');
                        Assert::same((int) $row['eligible_at'], $now + $delay);
                        Assert::true($primary ? str_starts_with($row['scope'], 'credential:v1:') || str_starts_with($row['scope'], 'saved:')
                            : $row['scope'] === 'shared');
                    }
                    $status = str_contains($case, '429') ? 429 : 403;
                    Assert::same(self::requests($count), [200, $status], 'one actual rate response and no retry');
                    $again = self::failure(fn () => $provider->getLatestRelease('fixture/' . $case));
                    Assert::false(self::hasPrivateTrace($again, [$secret, $marker]));
                    Assert::same(self::requests($count), [200, $status]);
                    unset($frame, $again, $error, $provider, $connection, $policy, $tokens, $sites, $vault, $db);
                    // Fresh PDO/connection sees only persisted safe quota state.
                    $db = Database::connect($path);
                    $sites = new SiteRepository($db, new TokenVault($path . '.key'));
                    $policy = new GitHubRequestPolicy($sites->githubCooldowns(), clock: new GitHubClock(epoch: static fn (): int => $now));
                    $connection = new GitHubConnection($sites, $http, $policy);
                    $provider = $connection->provider(null, $input);
                    $error = self::failure(fn () => $provider->getLatestRelease('fixture/' . $case));
                    Assert::false(self::hasPrivateTrace($error, [$secret, $marker]));
                    foreach ([bin2hex(random_bytes(24)), ''] as $allowed) {
                        $other = $connection->provider(null, ['github_token' => $allowed]);
                        if ($primary) { Assert::same($other->getLatestRelease('fixture/control'), 'v1'); }
                        else {
                            $blocked = self::failure(fn () => $other->getLatestRelease('fixture/control'));
                            Assert::false(self::hasPrivateTrace($blocked, [$secret, $marker]));
                        }
                    }
                    Assert::same(self::requests($count), $primary ? [200, $status, 200, 200] : [200, $status]);
                    foreach ([$secret, $marker] as $private) {
                        Assert::false(str_contains(json_encode($rows), $private));
                        Assert::false(str_contains(file_get_contents($path), $private));
                        Assert::false(str_contains($server->diagnostics(), $private));
                    }
                    unset($blocked, $error, $other, $provider, $connection, $policy, $sites, $http, $db);
                }
            }
        } finally {
            unset($frame, $blocked, $again, $error, $other, $provider, $connection, $policy, $tokens, $sites, $vault, $http, $db);
            ini_set('zend.exception_ignore_args', $original);
            $server?->close();
            $directory->close();
        }
    }
}
