<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use RuntimeException;

final class SubprocessReceipt
{
    public static function write(SubprocessState $state): void
    {
        $receipt = ['pid' => $state->pid, 'exit_code' => $state->exitCode, 'root_exit_observed' => $state->pid !== null && !$state->running,
            'closed' => $state->closed, 'ownership' => 'direct-child-only', 'stream_eof' => 'not used; file backed',
            'events' => $state->events, 'streams' => []];
        foreach (['stdout', 'stderr'] as $stream) {
            $receipt['streams'][$stream] = ['path' => $state->files[$stream], 'sha256' => hash_file('sha256', $state->files[$stream])];
        }
        if ($state->stderr === StderrCapture::ClosedReader) {
            $receipt['streams']['stderr'] += ['capture' => 'unavailable; OS pipe reader deliberately closed',
                'bytes' => null, 'errno' => null];
        }
        $file = fopen($state->files['receipt'], mode: 'x');
        if ($file === false) { throw new RuntimeException('Cannot reserve test command receipt'); }
        try { fwrite($file, json_encode($receipt, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n"); }
        finally { fclose($file); }
        chmod($state->files['receipt'], permissions: 0o600);
    }
}
