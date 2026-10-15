<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;

final class HistoryCliAssertions
{
    public function __construct(private readonly TemporaryDirectory $directory, private readonly \PDO $db) {}

    /** @param list<string> $read
     * @param array<string, string> $env */
    public function refused(array $read, array $env): void
    {
        $root = dirname(__DIR__, levels: 2);
            foreach ([[PHP_BINARY, $root . '/bin/history.php', '999', \Tablo\Tests\Support\FixtureValue::at($read, 3), \Tablo\Tests\Support\FixtureValue::at($read, 4)],
                [PHP_BINARY, $root . '/bin/history.php', '1', 'private-marker', \Tablo\Tests\Support\FixtureValue::at($read, 4)],
                [...$read, '101'], [...$read, '50', 'private-marker']] as $bad) {
                $result = Subprocess::run($bad, $this->directory, $env);
                Assert::same($result['exit_code'], 1);
                Assert::same($result['stdout'], '');
                Assert::true(in_array($result['stderr'], ['History site not found.' . PHP_EOL, 'History read failed. Check arguments and local storage.' . PHP_EOL], strict: true));
            }
            foreach (['0', '01', '3651', 'private-marker'] as $bad) {
                $result = Subprocess::run([PHP_BINARY, $root . '/bin/prune-history.php'], $this->directory,
                    $env + ['TABLO_HISTORY_RETENTION_DAYS' => $bad]);
                Assert::same($result['exit_code'], 1);
                Assert::same($result['stderr'], 'History prune failed. Check retention and local storage.' . PHP_EOL);
                Assert::same((int) \Tablo\SqlStatement::checked($this->db->query('SELECT COUNT(*) FROM check_history'))->fetchColumn(), 1);
            }

    }
}
