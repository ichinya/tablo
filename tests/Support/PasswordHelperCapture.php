<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

final class PasswordHelperCapture
{
    public static function snapshot(PasswordHelperEvidence $evidence, SubprocessState $state, int $sequence): void
    { self::capture($evidence, $state, 'snapshot-' . $sequence, ['classification' => 'SNAPSHOT; streams may still append', 'receipt' => null]); }

    public static function finalReceipt(PasswordHelperEvidence $evidence, SubprocessState $state): void
    {
        $receipt = PasswordHelperEvidenceFiles::copy($state->files['receipt'], $evidence->path . '/final.receipt.json');
        self::capture($evidence, $state, 'final', ['classification' => 'FINAL direct-child receipt; nested closure separate', 'receipt' => $receipt]);
        foreach (['stdout', 'stderr'] as $kind) {
            $source = $state->files[$kind] ?? throw new \RuntimeException('Missing helper stream');
            PasswordHelperEvidenceFiles::bytes($evidence->path . '/public.' . $kind . '.json',
                json_encode(PasswordHelperEvidenceFiles::summary(\Tablo\RequiredFile::bytes(file_get_contents($source)), $evidence->case->secrets),
                    JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n");
        }
    }

    /** @param array<string, mixed> $detail */
    private static function capture(PasswordHelperEvidence $evidence, SubprocessState $state, string $name, array $detail): void
    {
        $streams = [];
        foreach (['stdout', 'stderr'] as $kind) {
            $source = $state->files[$kind] ?? throw new \RuntimeException('Missing helper stream');
            $streams[$kind] = is_file($source) ? PasswordHelperEvidenceFiles::copy($source, $evidence->path . '/' . $name . '.' . $kind) : null;
        }
        PasswordHelperEvidenceFiles::record($evidence->path . '/' . $name . '.json', [
            ...$detail, 'case' => $evidence->case->identity, 'controller_pid' => getmypid(), 'direct_parent_pid' => getmypid(),
            'pid' => $state->pid, 'exit_code' => $state->exitCode, 'closed' => $state->closed,
            'running' => $state->running, 'raw_status' => $evidence->lastStatus, 'events' => $state->events,
            'streams' => $streams, 'ownership' => 'direct-child-only',
            'streams_classification' => 'byte-exact capture at observation; unobserved nested writer may still append to original',
            'nested_closure' => 'UNKNOWN unless separately observed in native-observation.jsonl',
            'observation' => PasswordHelperFixtureObservation::status($evidence,
                \Tablo\RequiredFile::bytes(file_get_contents($state->files['stderr'])))]);
    }
}
