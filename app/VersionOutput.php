<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-type Version array{has_commit: ?bool, has_version: ?bool, http_status: ?int, reason: string, status: string} */
final class VersionOutput
{

    /** @return Version */
    public static function project(#[\SensitiveParameter] array $state): array
    {
        return self::checked($state['version_outcome'] ?? null, $state);
    }

    /** @return Version */
    private static function checked(#[\SensitiveParameter] mixed $version, #[\SensitiveParameter] array $state): array
    {
        if (!is_array($version)) { CheckScalar::invalid(); }
        $status = CheckScalar::choice($version['status'] ?? null, ['ok', 'failed', 'skipped']);
        $reason = CheckScalar::choice($version['reason'] ?? null, [...array_keys(HttpFailure::MESSAGES), 'ok', 'http', 'invalid-data', 'not-configured']);
        return ['status' => $status, 'reason' => $reason,
            'http_status' => CheckScalar::integer($version['http_status'] ?? null, 100, 599),
            'has_version' => $status === 'ok' ? CheckScalar::present($state['deployed_version'] ?? null) : null,
            'has_commit' => $status === 'ok' ? CheckScalar::present($state['deployed_commit'] ?? null) : null];
    }

}
