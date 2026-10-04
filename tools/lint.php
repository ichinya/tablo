<?php
declare(strict_types=1);

use Composer\InstalledVersions;

// Use the same pinned tool and failure threshold locally, in verify and in CI.
try {
    $root = dirname(__DIR__);
    chdir($root);
    require $root . '/vendor/autoload.php';
    $expected = InstalledVersions::getPrettyVersion('carthage-software/mago');
    if (!preg_match('/^version\s*=\s*"([^"]+)"\s*$/m', file_get_contents($root . '/mago.toml'), $matches)
        || $matches[1] !== $expected) {
        throw new RuntimeException('Mago configuration version must match the pinned Composer dependency.');
    }
    $command = [PHP_BINARY, $root . '/vendor/bin/mago'];
    $process = proc_open([...$command, '--version'], [1 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start the pinned Mago CLI.');
    }
    $version = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    if (proc_close($process) !== 0 || trim($version) !== 'mago ' . $expected) {
        throw new RuntimeException('Unexpected Mago CLI version; reinstall dependencies and clear the Mago binary cache.');
    }
    if ($argc === 2 && $argv[1] === '--version') {
        echo trim($version) . PHP_EOL;
        exit(0);
    }
    if ($argc !== 1) {
        throw new RuntimeException('Usage: php tools/lint.php [--version]');
    }
    $process = proc_open([...$command, 'lint', '--minimum-fail-level=note'], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start Mago lint.');
    }
    exit(proc_close($process));
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
