<?php
declare(strict_types=1);

namespace Tablo;

require_once __DIR__ . '/read-api-repository.php';

final class ApiSiteState
{
    /** @param array{enabled: bool, tracks: bool, mode: string} $config
     * @return \Tablo\ApiSiteDto::Latest */
    public static function fromRow(#[\SensitiveParameter] array $row, array $config, string $generatedAt): array
    { return read_api_state($row, $config, $generatedAt); }

    public static function epoch(string $value): int { return read_api_epoch($value); }

    public static function operand(#[\SensitiveParameter] mixed $value): ?string
    { return read_api_operand($value); }

    public static function comparison(#[\SensitiveParameter] array $row, string $mode): string
    { return read_api_comparison($row, $mode); }
}
