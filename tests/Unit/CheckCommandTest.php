<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\CheckOutput;
use Tablo\SiteChecker;
use Tablo\SiteRepository;
use Tablo\Tests\Support\CheckSchema;
use Tablo\Tests\Support\FakeHttp;
use Tablo\Tests\Support\FakeProvider;
use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;
use Testo\Test;

final class CheckCommandTest
{
    #[Test]
    public function scalarProjectionRejectsMalformedDomainsAndOmitsPrivateGraphs(): void
    {
        $site = SiteRepository::normalize(UnitFixtures::site()) + ['id' => 1, 'checked_at' => null];
        $state = (new SiteChecker(new FakeHttp([UnitFixtures::response(), UnitFixtures::response(200, '{"version":"safe"}')]), new FakeProvider()))->check($site);
        $private = 'synthetic-' . bin2hex(random_bytes(16));
        $site['name'] = $private . "\xff"; $site['checked_at'] = $private;
        $site['endpoint_token'] = new class { public function jsonSerialize(): never { throw new \RuntimeException('must not be traversed'); } };
        $state['deployed_version'] = $private . "\xff"; $state['latest_release'] = $private; $state['last_error'] = $private;
        $output = CheckOutput::site($site, 'checked', 'attention', true, $state, true, 'accepted');
        $bytes = CheckOutput::envelope('partial', 'attention', $state['checked_at'], [CheckOutput::encode($output)]);
        Assert::false(str_contains($bytes, $private)); Assert::true(CheckSchema::valid(json_decode($bytes, false, 64, JSON_THROW_ON_ERROR)));
        Assert::same($output['persistence']['previous_checked_at'], null); Assert::same($output['persistence']['previous_freshness'], 'unknown');
        foreach ([['online', 2], ['response_time_ms', -1], ['response_time_ms', INF], ['open_issues', '0'],
            ['open_prs', -1], ['checked_at', '2026-02-31T00:00:00Z'], ['checked_at', $private . "\xff"],
            ['health_http_status', 700], ['latest_release', new \stdClass()]] as [$field, $value]) {
            $changed = array_replace($state, [$field => $value]); $refused = false;
            try { CheckOutput::site($site, 'checked', 'attention', true, $changed); }
            catch (\UnexpectedValueException) { $refused = true; }
            Assert::true($refused, 'malformed scalar domain must refuse projection');
        }
        Assert::same(CheckOutput::timestamp('2024-02-29T00:00:00Z'), '2024-02-29T00:00:00Z');
        Assert::same(CheckOutput::timestamp('2023-02-29T00:00:00Z'), null);
    }

    #[Test]
    public function schemaNegativeControlsRejectShapeExitAndFieldSemanticCorruption(): void
    {
        $site = SiteRepository::normalize(UnitFixtures::site()) + ['id' => 1, 'checked_at' => null];
        $state = (new SiteChecker(new FakeHttp([UnitFixtures::response(), UnitFixtures::response(200, '{"version":"safe"}')]), new FakeProvider()))->check($site);
        $bytes = CheckOutput::envelope('success', 'complete', $state['checked_at'], [CheckOutput::encode(CheckOutput::site($site, 'checked', 'ok', false, $state, true, 'accepted'))]);
        foreach (['extra', 'exit', 'negative-count', 'false-zero', 'paused-observation', 'bad-timestamp', 'array-object', 'unknown-reason'] as $case) {
            $changed = json_decode($bytes, false, 64, JSON_THROW_ON_ERROR);
            switch ($case) {
                case 'extra': $changed->sites[0]->secret = 'positive'; break;
                case 'exit': $changed->run->exit_code = 1; break;
                case 'negative-count': $changed->sites[0]->observation->git->fields->open_issues->value = -1; break;
                case 'false-zero': $changed->sites[0]->observation->git->fields->open_issues->status = 'failed'; break;
                case 'paused-observation': $changed->sites[0]->status = 'paused'; break;
                case 'bad-timestamp': $changed->sites[0]->observation->checked_at = 'secret'; break;
                case 'array-object': $changed->sites[0]->observation->health = []; break;
                case 'unknown-reason': $changed->sites[0]->observation->health->reason = 'private'; break;
            }
            Assert::false(CheckSchema::valid($changed), 'real schema validator negative control: ' . $case);
        }
    }

    #[Test]
    public function actualCopiedEntrypointMissingAutoloadAndMalformedDotenvEmitOneSafeDocument(): void
    {
        $directory = new TemporaryDirectory('tablo-check-bootstrap-');
        try {
            $root = dirname(__DIR__, 2); mkdir($directory->path . '/bin'); mkdir($directory->path . '/vendor'); mkdir($directory->path . '/app');
            copy($root . '/bin/check.php', $directory->path . '/bin/check.php');
            copy($root . '/app/bootstrap.php', $directory->path . '/app/bootstrap.php');
            copy($root . '/app/check-diagnostics.php', $directory->path . '/app/check-diagnostics.php');
            Assert::same(hash_file('sha256', $root . '/bin/check.php'), hash_file('sha256', $directory->path . '/bin/check.php'));
            $environment = ['TABLO_DB' => $directory->path . '/must-not-exist.sqlite', 'TABLO_TOKEN_KEY_FILE' => ''];
            $command = [PHP_BINARY, $directory->path . '/bin/check.php', '--json'];
            $result = Subprocess::run($command, $directory, $environment);
            Assert::same($result['exit_code'], 1); Assert::true(CheckSchema::valid(json_decode($result['stdout'], false, 64, JSON_THROW_ON_ERROR)));
            Assert::false(is_file($environment['TABLO_DB']));
            // Reuse the installed Dotenv library with only this copied bootstrap's root.
            $shim = '<?php require ' . var_export($root . '/vendor/autoload.php', true) . '; require __DIR__ . "/../app/bootstrap.php";';
            file_put_contents($directory->path . '/vendor/autoload.php', $shim);
            $secret = 'synthetic-' . bin2hex(random_bytes(16));
            file_put_contents($directory->path . '/.env', "PRIVATE=value with space " . $secret . "\n");
            $result = Subprocess::run($command, $directory, $environment);
            Assert::same($result['exit_code'], 1); Assert::true(CheckSchema::valid(json_decode($result['stdout'], false, 64, JSON_THROW_ON_ERROR)));
            Assert::false(str_contains($result['stdout'] . $result['stderr'], $secret)); Assert::false(is_file($environment['TABLO_DB']));
        } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
        finally { $directory->close(); }
    }

    #[Test]
    public function diagnosticGuardAlsoCatchesClosedResourceErrors(): void
    {
        $directory = new TemporaryDirectory('tablo-check-diagnostic-resource-');
        try {
            $code = 'require ' . var_export(dirname(__DIR__, 2) . '/app/check-diagnostics.php', true)
                . '; fclose(STDERR); set_error_handler(static function (): never { throw new RuntimeException("warning"); });'
                . ' Tablo\\CheckDiagnostics::write("safe fixed message\n");'
                . ' try { trigger_error("after", E_USER_WARNING); } catch (RuntimeException) { echo "restored\n"; } echo "survived\n";';
            $result = Subprocess::run([PHP_BINARY, '-r', $code], $directory, []);
            Assert::same($result['exit_code'], 0); Assert::same($result['stdout'], "restored\nsurvived\n");
            Assert::same($result['stderr'], '');
        } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
        finally { $directory->close(); }
    }

    #[Test]
    public function directChildReceiptsPreserveNativeSignedExitStatuses(): void
    {
        $directory = new TemporaryDirectory('tablo-check-exit-control-');
        try {
            // Deliberate exit control, not a claimed reproduction of a PHP crash.
            $result = Subprocess::run([PHP_BINARY, '-r', 'exit(-1073741819);'], $directory, []);
            $expected = PHP_OS_FAMILY === 'Windows' ? -1073741819 : 5;
            Assert::same($result['exit_code'], $expected);
            $receipt = json_decode(file_get_contents($result['files']['receipt']), true, 32, JSON_THROW_ON_ERROR);
            Assert::same($receipt['exit_code'], $expected);
            Assert::true($receipt['closed'] && $receipt['root_exit_observed']);
            $exits = array_values(array_filter($receipt['events'], static fn (array $event): bool => $event['stage'] === 'root-exit'));
            Assert::same($exits[0]['native_exit_code'], $expected);
        } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
        finally { $directory->close(); }
    }
}
