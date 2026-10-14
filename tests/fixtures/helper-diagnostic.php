<?php
declare(strict_types=1);
require_once __DIR__ . '/../Support/FixtureCredentials.php';

// One synthetic direct child; deliberately never starts a descendant.
$mode = $argv[1] ?? 'ok';
if ($mode === 'silent') { while (true) { usleep(20_000); } }
if ($mode === 'metadata-live') {
    fwrite(STDOUT, data: "HELPER_METADATA={\"helperExit\":0}\n");
    fflush(STDOUT);
    while (true) { usleep(20_000); }
}
fwrite(STDOUT, match ($mode) {
    'malformed' => "HELPER_METADATA={malformed\n",
    'private' => str_repeat('z', times: 2300) . \Tablo\Tests\Support\FixtureCredentials::value(label: 'helper-privacy-marker') . "\n",
    default => "diagnostic closed-child stdout\n",
});
fwrite(STDERR, data: "diagnostic closed-child stderr\n");
exit($mode === 'nonzero' || $mode === 'private' ? 7 : 0);
