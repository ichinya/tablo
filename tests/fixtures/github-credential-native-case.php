<?php
declare(strict_types=1);

require dirname(__DIR__, levels: 2) . '/vendor/autoload.php';

// The original whole case runs in a native caller with no runner argument graph.
// Assertions use the actual locked Testo collector and still throw on failure.
$state = new \Testo\Assert\TestState();
$previous = \Testo\Assert\Internal\StaticState::swap($state);
putenv('TABLO_TEST_ISOLATED_TRACE=privateQuotaIdentityPreservesCiphertextAndDefaultAndExternalKeys');
try {
    (new \Tablo\Tests\Unit\GitHubCredentialTest())->privateQuotaIdentityPreservesCiphertextAndDefaultAndExternalKeys();
    echo json_encode(['totals' => ['total' => 1, 'passed' => 1, 'assertions' => count($state->history)]], JSON_THROW_ON_ERROR);
} catch (\Throwable $error) {
    echo json_encode(['totals' => ['total' => 1, 'failed' => 1, 'assertions' => count($state->history)],
        'exception' => get_class($error), 'message' => $error->getMessage()], JSON_THROW_ON_ERROR);
    exit(1);
} finally {
    \Testo\Assert\Internal\StaticState::swap($previous);
}
