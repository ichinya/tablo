<?php
declare(strict_types=1);

namespace Tablo;

final class EndpointHealthJson
{
    public static function apply(#[\SensitiveParameter] array $site,#[\SensitiveParameter] array $response,#[\SensitiveParameter] array &$state,#[\SensitiveParameter] array &$errors): void
    {
        $homePage = $site['health_path'] === '';
            if (!$homePage && ($site['health_check_mode'] ?? 'http') === 'json') {
                // Unknown until both extraction and comparison succeed.
                $state['online'] = null;
                $value = JsonField::extract(JsonField::decode($response['body']), $site['health_json_path']);
                $state['online'] = (int) JsonField::compare($value, $site['health_json_operator'], $site['health_json_expected_value']);
                if (!$state['online']) {
                    $state['health_error_code'] = 'json-condition';
                    $errors[] = 'Health: ' . HttpFailure::MESSAGES['json-condition'];
                }
            }
    }
}
