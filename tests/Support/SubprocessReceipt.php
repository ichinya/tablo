<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use RuntimeException;

/** @phpstan-import-type Receipt from SubprocessReceiptData */
final class SubprocessReceipt
{
    /** @return Receipt */
    public static function read(string $path): array
    {
        return SubprocessReceiptData::checked(json_decode(\Tablo\RequiredFile::bytes(file_get_contents($path)), associative: true, depth: 32, flags: JSON_THROW_ON_ERROR));
    }

    public static function write(SubprocessState $state): void
    {
        $stopped = SubprocessObservation::stopped($state);
        $receipt = ['pid' => $state->pid, 'exit_code' => $state->exitCode, 'root_exit_observed' => $state->pid !== null && $stopped['observed'],
            'platform' => PHP_OS_FAMILY, 'signaled' => $stopped['signaled'], 'term_signal' => $stopped['term_signal'], 'proc_close' => $stopped['proc_close'],
            'closed' => $state->closed, 'ownership' => 'direct-child-only', 'stream_eof' => 'not used; file backed',
            'events' => $state->events, 'streams' => []];
        if ($state->evidence !== null) {
            $receipt += ['controller_pid' => getmypid(), 'direct_parent_pid' => getmypid(),
                'raw_status' => $state->evidence->lastStatus, 'case' => $state->evidence->case->identity,
                'nested_closure' => 'UNKNOWN; independent native observation required'];
        }
        foreach (['stdout', 'stderr'] as $stream) {
            $path = $state->files[$stream] ?? throw new RuntimeException('Missing direct test child stream path');
            $receipt['streams'][$stream] = ['path' => $path, 'sha256' => \Tablo\RequiredFile::digest($path)];
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
        $state->evidence?->closed($state);
        SessionEvidence::subprocessClosed($state);
    }
}
