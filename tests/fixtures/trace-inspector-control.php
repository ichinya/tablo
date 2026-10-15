<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '0');
try {
    require dirname(__DIR__, 2) . '/vendor/autoload.php';
    $control = new \Tablo\Tests\Network\GitHubTraceInspectorTest();
    match ($argv[1] ?? '') {
        'supported' => $control->inspectSupportedActualArguments(),
        'opaque' => $control->inspectOpaqueObjectsAndCycles(),
        default => throw new \RuntimeException('Invalid control mode'),
    };
    fwrite(STDOUT, "Trace control passed.\n");
} catch (\Throwable) {
    fwrite(STDERR, "Trace control failed.\n");
    exit(1);
}
