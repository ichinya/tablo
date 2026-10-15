<?php
declare(strict_types=1);
require_once dirname(__DIR__, levels: 2) . '/app/RuntimeIni.php';

\Tablo\RuntimeIni::set('display_errors', value: '0');
\Tablo\RuntimeIni::set('log_errors', value: '0');
try {
    require dirname(__DIR__, levels: 2) . '/vendor/autoload.php';
    $control = new \Tablo\Tests\Network\GitHubTraceInspectorTest();
    match ($argv[1] ?? '') {
        'supported' => $control->inspectSupportedActualArguments(),
        'opaque' => $control->inspectOpaqueObjectsAndCycles(),
        default => throw new \RuntimeException('Invalid control mode'),
    };
    fwrite(STDOUT, data: "Trace control passed.\n");
} catch (\Throwable) {
    fwrite(STDERR, data: "Trace control failed.\n");
    exit(1);
}
