<?php
declare(strict_types=1);

namespace Tablo {
    // Test-only interception at the actual Web authentication boundary: Auth has
    // returned its committed verified snapshot, but no auth cookie is created yet.
    function session_regenerate_id(bool $deleteOldSession = false): bool
    {
        $barrier = getenv('TABLO_TEST_AUTH_BARRIER');
        if ($barrier && is_file($barrier . '/armed')) {
            file_put_contents($barrier . '/verified', 'ready');
            $deadline = microtime(true) + 8;
            while (!is_file($barrier . '/release') && microtime(true) < $deadline) {
                clearstatcache();
                usleep(20000);
            }
            if (!is_file($barrier . '/release')) { throw new \RuntimeException('Authentication barrier timed out'); }
        }
        return \session_regenerate_id($deleteOldSession);
    }
}
namespace { require __DIR__ . '/web-router.php'; }
