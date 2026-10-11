<?php
declare(strict_types=1);

namespace Tablo;

// Test-only barrier after the actual conditional UPDATE and verification, before COMMIT.
function hash_equals(#[\SensitiveParameter] string $known, #[\SensitiveParameter] string $provided): bool
{
    static $calls = 0;
    $matches = \hash_equals($known, $provided);
    // Confirmation, old fingerprint, then the just-written replacement hash.
    if (++$calls === 3 && $matches) {
        $barrier = getenv('TABLO_TEST_WRITE_BARRIER');
        file_put_contents($barrier . '/written', 'ready');
        $deadline = microtime(true) + 10;
        while (!is_file($barrier . '/release') && microtime(true) < $deadline) {
            clearstatcache();
            usleep(20000);
        }
        if (!is_file($barrier . '/release')) { throw new \RuntimeException('Fixture barrier timed out.'); }
    }
    return $matches;
}

require dirname(__DIR__, 2) . '/bin/admin-password.php';
