<?php
declare(strict_types=1);

// Stand-in for Mago in isolated process/exit-code tests; no downloads or real sources.
if (($argv[1] ?? '') === '--version') {
    echo getenv('TABLO_LINT_TEST_VERSION') . PHP_EOL;
    exit((int) getenv('TABLO_LINT_TEST_VERSION_EXIT'));
}
file_put_contents(dirname(__DIR__, levels: 2) . '/invocation.json', json_encode(array_slice($argv, offset: 1), JSON_THROW_ON_ERROR));
fwrite(STDERR, 'fixture lint diagnostic' . PHP_EOL);
exit((int) getenv('TABLO_LINT_TEST_EXIT'));
