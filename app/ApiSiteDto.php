<?php
declare(strict_types=1);

namespace Tablo;

require_once __DIR__ . '/read-api-repository.php';

/**
 * Deliberate external whitelist, never a Presenter or database-row serializer.
 * @phpstan-type Latest array{status: string, checked_at: ?string, freshness: string, online: ?bool, health_error_code: ?string, health_http_status: ?int, response_time_ms: ?int, open_issues: ?int, open_prs: ?int, comparison: string}
 * @phpstan-type Dto array{id: string, config_revision: string, name: string, origin: ?string, provider: string, repository: string, branch: string, enabled: bool, sort_order: int, comparison_mode: string, health_check_mode: string, health_target: string, tracks_version: bool, created_at: string, updated_at: string, latest: Latest}
 */
final class ApiSiteDto
{
    /** @return Dto */
    public static function fromRow(#[\SensitiveParameter] array $row, string $generatedAt): array
    { return read_api_dto($row, $generatedAt); }

    public static function text(#[\SensitiveParameter] mixed $value, int $maximum): string
    { return read_api_text($value, $maximum); }

    /** @param list<string> $allowed */
    public static function choice(mixed $value, array $allowed): string
    { return read_api_choice($value, $allowed); }

    public static function integer(mixed $value, int $maximum = 2_147_483_647): int
    { return read_api_integer($value, $maximum); }

    public static function flag(mixed $value): bool
    { return read_api_flag($value); }

    public static function timestamp(mixed $value): ?string
    { return read_api_timestamp($value); }

    public static function origin(#[\SensitiveParameter] mixed $value): ?string
    { return read_api_origin($value); }
}
