<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

/**
 * Optional owned receipt sink. Never used by production entry points.
 * @phpstan-import-type Result from Subprocess
 */
final class SessionEvidence
{
    public static function directory(): ?string
    {
        $directory = getenv('TABLO_SESSION_EVIDENCE_DIRECTORY');
        return $directory && is_dir($directory) ? $directory : null;
    }

    /** @param array<string, mixed> $record */
    public static function record(string $kind, array $record): void
    {
        $directory = self::directory();
        if ($directory === null) { return; }
        $name = $directory . '/' . $kind . '-' . bin2hex(random_bytes(12)) . '.json';
        file_put_contents($name, json_encode(['utc' => gmdate('c'), ...$record], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
    }

    /** @param array{argv: list<string>, pid: int, observedExit: ?int, procClose: int, observedPid: int, rootExitObserved: bool, signaled: bool, termSignal: ?int, platform: string, terminationRequests: list<array{signal: int, accepted: bool}>, errorLog: string} $record */
    public static function server(array $record, string $stdout, string $stderr): void
    {
        $directory = self::directory();
        if ($directory === null) { return; }
        $name = $directory . '/server-' . $record['pid'] . '-' . bin2hex(random_bytes(8));
        copy($stdout, $name . '.stdout'); copy($stderr, $name . '.stderr');
        if (is_file($record['errorLog'])) { copy($record['errorLog'], $name . '.php-errors'); }
        self::record('server-close', [...$record, 'cwd' => dirname(__DIR__, levels: 2), 'closed' => true, 'stdout' => $name . '.stdout', 'stderr' => $name . '.stderr',
            'stdoutSha256' => hash_file('sha256', $name . '.stdout'), 'stderrSha256' => hash_file('sha256', $name . '.stderr')]);
    }

    /** @param Result $result */
    public static function child(array $result): void
    {
        $directory = self::directory();
        if ($directory === null) { return; }
        $name = $directory . '/child-' . bin2hex(random_bytes(12));
        foreach ($result['files'] as $kind => $path) { copy($path, $name . '.' . $kind); }
        self::record('child-capture', ['prefix' => $name, 'childExit' => $result['exit_code'],
            'stdoutSha256' => hash('sha256', $result['stdout']), 'stderrSha256' => hash('sha256', $result['stderr'])]);
    }

    public static function rawFile(string $path, string $label): void
    {
        $directory = self::directory();
        if ($directory === null) { return; }
        $copy = $directory . '/' . $label . '-' . bin2hex(random_bytes(12));
        if (!copy($path, $copy)) { throw new \RuntimeException('Cannot retain owned session protocol file.'); }
        self::record('protocol-file', ['source' => $path, 'copy' => $copy, 'sha256' => hash_file('sha256', $copy), 'bytes' => filesize($copy)]);
    }

    /** Capture actual closure even when an expected timeout/failure prevents finish() returning a result. */
    public static function subprocessClosed(SubprocessState $state): void
    {
        $directory = self::directory();
        if ($directory === null) { return; }
        $source = $state->files['receipt'];
        $target = $directory . '/direct-child-' . hash('sha256', $source) . '.receipt';
        $handle = fopen($target, mode: 'xb');
        if (!is_resource($handle)) { throw new \RuntimeException('Cannot reserve exact owned child closure evidence.'); }
        try {
            $bytes = \Tablo\RequiredFile::bytes(file_get_contents($source));
            if (!chmod($target, permissions: 0o600) || fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle) || !fsync($handle)) {
                throw new \RuntimeException('Cannot retain exact owned child closure evidence.');
            }
        } finally { fclose($handle); }
        $receipt = SubprocessReceipt::read($source);
        $streams = ['stdout' => session_evidence_closed_stream($state->files['stdout'], $target . '.stdout', $receipt['streams']['stdout']['sha256']),
            'stderr' => session_evidence_closed_stream($state->files['stderr'], $target . '.stderr', $receipt['streams']['stderr']['sha256'])];
        self::record('direct-child-close', ['pid' => $state->pid, 'sourceReceipt' => $source,
            'receiptCopy' => $target, 'receiptSha256' => \Tablo\RequiredFile::digest($target), 'streams' => $streams]);
    }

}

/** Close without finish() must retain actual bytes before successful fixture removal.
 * @return array{path: string, sha256: string} */
function session_evidence_closed_stream(string $source, string $target, string $expected): array
{
    $bytes = \Tablo\RequiredFile::bytes(file_get_contents($source));
    if (!hash_equals($expected, hash('sha256', $bytes))) { throw new \RuntimeException('Closed child stream changed before evidence retention.'); }
    $handle = fopen($target, mode: 'xb');
    if (!is_resource($handle)) { throw new \RuntimeException('Cannot reserve closed child stream evidence.'); }
    try {
        if (!chmod($target, permissions: 0o600) || fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle) || !fsync($handle)) {
            throw new \RuntimeException('Cannot retain closed child stream evidence.');
        }
    } finally { fclose($handle); }
    return ['path' => $target, 'sha256' => $expected];
}
