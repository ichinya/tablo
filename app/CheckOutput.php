<?php
declare(strict_types=1);

namespace Tablo;

/**
 * Public scalar projection; never a provider or database row serializer.
 * @phpstan-import-type Record from CheckSiteOutput
 */
final class CheckOutput
{
    public const GIT_FIELDS = ['latest_release', 'latest_commit', 'open_issues', 'open_prs'];
















    public static function encode(array $site): string { return JsonEncoding::encode($site); }

    /** @param list<string> $fragments */
    public static function envelope(string $status, string $reason, ?string $started, array $fragments): string
    {
        $run = ['status' => $status, 'reason' => $reason, 'exit_code' => $status === 'success' ? 0 : 1,
            'started_at' => OutputTimestamp::timestamp($started), 'finished_at' => OutputTimestamp::timestamp(gmdate('Y-m-d\TH:i:s\Z'))];
        // The envelope has only fixed enums and validated ASCII timestamps. Assemble it
        // independently of the fragment encoder so its failure cannot erase prior sites.
        CheckScalar::choice($status, ['success', 'partial', 'fatal']);
        CheckScalar::choice($reason, ['attention', 'empty', 'complete', 'storage-failed', 'key-unavailable', 'check-failed', 'serialization-failed']);
        $start = $run['started_at'] === null ? 'null' : '"' . $run['started_at'] . '"';
        $finish = $run['finished_at'] === null ? 'null' : '"' . $run['finished_at'] . '"';
        return '{"schema_version":"tablo/check/v1","run":{"status":"' . $status . '","reason":"' . $reason
            . '","exit_code":' . $run['exit_code'] . ',"started_at":' . $start . ',"finished_at":' . $finish
            . '},"sites":[' . implode(',', $fragments) . "]}\n";
    }

    public static function fatalReason(\Throwable $error): string
    {
        return match (true) {
            $error instanceof \PDOException || $error instanceof DatabaseFailure => 'storage-failed',
            $error instanceof SharedKeyFailure => 'key-unavailable',
            default => 'check-failed',
        };
    }

    public static function diagnose(\Throwable $error): void
    {
        CheckDiagnostics::write("Check failed. Check installation configuration and local storage.\n");
        if ($error instanceof SharedKeyFailure && $error->getCode() === SharedKeyFailure::UNVERIFIED) {
            CheckDiagnostics::write(SharedKeyFailure::UNVERIFIED_DIAGNOSTIC . "\n");
        }
    }
    public static function timestamp(mixed $value): ?string { return OutputTimestamp::timestamp($value); }
    /** @return Record */
    public static function site(#[\SensitiveParameter] array $site, string $status, string $reason, ?bool $attention, #[\SensitiveParameter] ?CheckSiteObservation $context = null): array
    { return CheckSiteOutput::site($site, $status, $reason, $attention, $context); }
}
