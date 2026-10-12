<?php
declare(strict_types=1);

namespace Tablo\Tools;

use RuntimeException;

final class MagoAnalyze
{
    public static function run(string $root, array $arguments): int
    {
        if ($arguments !== []) { throw new RuntimeException('Analyze accepts no extra arguments.'); }
        $config = MagoConfig::verify($root);
        $pin = MagoPin::selected($root);
        MagoPin::verify($pin, $root);
        $files = MagoSource::files($root);
        $command = [$pin['binary_path'], '--workspace', $root, '--config', $config, '--php-version', '8.2', '--colors', 'never'];
        MagoSource::confirm($command, $root, $files, 'analyzer');
        fwrite(STDOUT, 'Analyze source files: ' . count($files) . PHP_EOL);
        $result = MagoProcess::capture([...$command, 'analyze', '--minimum-fail-level', 'note', '--ignore-baseline'], $root);
        fwrite(STDOUT, $result['stdout']);
        fwrite(STDERR, $result['stderr']);
        fwrite(STDOUT, 'Analyze pid: ' . $result['pid'] . '; initial exit: ' . $result['initial_exit']
            . '; proc_close: ' . $result['proc_close'] . '; exit: ' . $result['exit'] . PHP_EOL);
        return $result['exit'];
    }
}
