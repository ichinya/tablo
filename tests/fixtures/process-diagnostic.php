<?php
declare(strict_types=1);

// Synthetic direct child only. No process is launched from this fixture.
$mode = $argv[1] ?? 'ok';
fwrite(STDOUT, $mode === 'nonzero' ? "second stdout marker\n" : "diagnostic stdout marker\n");
fwrite(STDERR, $mode === 'nonzero' ? "second stderr marker\n" : "diagnostic stderr marker\n");
fflush(STDOUT);
fflush(STDERR);
if ($mode === 'wait') {
    $input = stream_get_contents(STDIN);
    fwrite(STDOUT, $input);
    fflush(STDOUT);
    while (!is_file($argv[2])) { usleep(20_000); }
}
if ($mode === 'malformed') { fwrite(STDOUT, data: "{malformed\n"); }
exit($mode === 'nonzero' ? 7 : 0);
