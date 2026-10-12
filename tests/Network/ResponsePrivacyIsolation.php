<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\CommandOptions;
use Testo\Assert;
use Throwable;

final class ResponsePrivacyIsolation
{
    private const SCENARIO_TIMEOUT = 30;

    public static function run(string $file, string $function): void
    {
            $isolated = new TemporaryDirectory('tablo-response-privacy-process-');
            $command = [PHP_BINARY, 'vendor/bin/testo', 'run', '--path', $file, '--filter', $function, '--json'];
            try {
                // This budget covers startup and all eight real cases; their network deadlines stay independent.
                $result = Subprocess::run($command, $isolated, ['TABLO_TEST_ISOLATED_RESPONSE_PRIVACY' => '1'],
                    new CommandOptions(timeout: self::SCENARIO_TIMEOUT));
                Assert::same($result['exit_code'], 0, $result['stderr']);
                $report = json_decode($result['stdout'], associative: true, depth: 32, flags: JSON_THROW_ON_ERROR);
                Assert::same($report['totals']['total'], 1);
                Assert::same($report['totals']['passed'], 1);
                Assert::true($report['totals']['assertions'] > 300);
            } catch (Throwable $error) { $isolated->retainFailure($error); throw $error; }
            finally {
                $isolated->cleanup(static fn () => ResponsePrivacyRecord::record($file, $function, $command, $isolated,
                    self::SCENARIO_TIMEOUT));
                $isolated->close();
            }

    }
}
