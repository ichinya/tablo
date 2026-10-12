<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;

final class EquivalentCliAssertions
{
    public function __construct(private readonly TemporaryDirectory $directory) {}

    public function persisted(string $path, string $base, #[\SensitiveParameter] string $secret): void
    {
            for ($run = 0; $run < 2; ++$run) {
                $result = Subprocess::run([PHP_BINARY, dirname(__DIR__) . '/fixtures/github-budget-cli.php'], $this->directory,
                    ['TABLO_TEST_BUDGET_DB' => $path, 'TABLO_TEST_BUDGET_KEY' => $this->directory->path . '/external.key',
                        'TABLO_TEST_BUDGET_BASE' => $base]);
                Assert::same($result['exit_code'], 0);
                Assert::same($result['stderr'], '');
                Assert::same(json_decode($result['stdout'], associative: true, depth: 32, flags: JSON_THROW_ON_ERROR),
                    ['outcomes' => ['rate-limit', 'rate-limit', 'rate-limit', 'rate-limit', 'ok', 'ok'], 'core' => 2, 'search' => 0]);
                Assert::false(str_contains($result['stdout'] . $result['stderr'], $secret));
            }

    }
}
