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
    public function redactsCompletePropagatedAndPreviousErrorTraces(): void
    {
        $directory = new TemporaryDirectory('tablo-password-trace-process-');
        try {
            // Capture COMPLETE arguments in a bare process without recursively
            // expanding the Testo runner and its entire prior assertion history.
            foreach (['password-privacy.php', 'password-hash-failure.php'] as $fixture) {
                $result = Subprocess::run([PHP_BINARY, '-d', 'zend.exception_ignore_args=0', dirname(__DIR__) . '/fixtures/' . $fixture],
                    $directory, ['TABLO_DB' => $directory->path . '/fixture.sqlite'], timeout: 15);
                Assert::same($result['exit_code'], 0, $result['stderr']);
                Assert::true(str_contains($result['stdout'], 'redacted'));
            }
        } finally { $directory->close(); }
    }
}
