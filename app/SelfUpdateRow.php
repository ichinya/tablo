<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-type Row array{id: int, enabled: int, interval_minutes: int, revision: int, build_fingerprint: string, observed_sha: ?string, status: string, latest_sha: ?string, new_commits: ?int, successful_at: ?int, attempted_at: ?int, error_code: ?string, failures: int, next_due: int, claim_token: ?string, claim_until: int} */
final class SelfUpdateRow
{
    /** @return Row */
    public static function checked(array $row): array
    {
        return ['id' => DatabaseInteger::checked($row['id'] ?? null), 'enabled' => DatabaseInteger::checked($row['enabled'] ?? null),
            'interval_minutes' => DatabaseInteger::checked($row['interval_minutes'] ?? null), 'revision' => DatabaseInteger::checked($row['revision'] ?? null),
            'build_fingerprint' => SqlColumn::string($row, 'build_fingerprint'), 'observed_sha' => SqlColumn::nullableString($row, 'observed_sha'),
            'status' => SqlColumn::string($row, 'status'), 'latest_sha' => SqlColumn::nullableString($row, 'latest_sha'),
            'new_commits' => SqlColumn::nullableInteger($row, 'new_commits'), 'successful_at' => SqlColumn::nullableInteger($row, 'successful_at'),
            'attempted_at' => SqlColumn::nullableInteger($row, 'attempted_at'), 'error_code' => SqlColumn::nullableString($row, 'error_code'),
            'failures' => DatabaseInteger::checked($row['failures'] ?? null), 'next_due' => DatabaseInteger::checked($row['next_due'] ?? null),
            'claim_token' => SqlColumn::nullableString($row, 'claim_token'), 'claim_until' => DatabaseInteger::checked($row['claim_until'] ?? null)];
    }
}
