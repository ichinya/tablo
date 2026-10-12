<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;

final class ResponsePrivacyIsolation
{
    public static function run(string $file, string $function): void
    {
            $isolated = new TemporaryDirectory('tablo-response-privacy-process-');
            try {
                $result = Subprocess::run([PHP_BINARY, 'vendor/bin/testo', 'run', '--path', $file,
                    '--filter', $function, '--json'], $isolated, ['TABLO_TEST_ISOLATED_RESPONSE_PRIVACY' => '1']);
                Assert::same($result['exit_code'], 0, $result['stderr']);
                $report = json_decode($result['stdout'], associative: true, depth: 32, flags: JSON_THROW_ON_ERROR);
                Assert::same($report['totals']['passed'], 1);
                Assert::true($report['totals']['assertions'] > 300);
            } finally { $isolated->close(); }

    }
}
