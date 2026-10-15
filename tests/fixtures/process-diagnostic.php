<?php
declare(strict_types=1);
require_once __DIR__ . '/../Support/FixtureValue.php';

// Synthetic direct child only. No process is launched from this fixture.
$mode = $argv[1] ?? 'ok';
fwrite(STDOUT, $mode === 'nonzero' ? "second stdout marker\n" : "diagnostic stdout marker\n");
fwrite(STDERR, $mode === 'nonzero' ? "second stderr marker\n" : "diagnostic stderr marker\n");
fflush(STDOUT);
fflush(STDERR);
if ($mode === 'wait') {
    $input = stream_get_contents(STDIN);
    fwrite(STDOUT, \Tablo\Tests\Support\FixtureValue::string($input));
    fflush(STDOUT);
    while (!is_file(\Tablo\Tests\Support\FixtureValue::at(\Tablo\Tests\Support\FixtureValue::row($argv), 2))) { usleep(20_000); }
}
if ($mode === 'malformed') { fwrite(STDOUT, data: "{malformed\n"); }
exit($mode === 'nonzero' ? 7 : 0);
