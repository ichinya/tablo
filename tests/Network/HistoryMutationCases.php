<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;


final class HistoryMutationCases
{
    /** @return array<string, string|int> */
    public static function fields(): array
    {
            return ['name' => 'Changed', 'url' => 'https://other.example', 'repository' => 'other/project', 'branch' => 'other',
                'health_path' => '/other', 'version_path' => '/other', 'version_json_path' => '$.other', 'health_check_mode' => 'json',
                'health_json_path' => '$.other', 'health_json_operator' => '!=', 'health_json_expected_value' => 'different',
                'comparison_mode' => 'branch', 'enabled' => 0, 'sort_order' => 9];

    }
}
