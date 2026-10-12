<?php
declare(strict_types=1);

namespace Tablo;

/**
 * @phpstan-import-type CheckResult from CheckState
 * @phpstan-import-type HttpResponse from HttpClient
 */
final class EndpointHealthJson
{
    /**
     * @param CheckResult $state
     * @param HttpResponse $response
     * @param list<string> $errors
     */
    public static function apply(#[\SensitiveParameter] array $site,#[\SensitiveParameter] array $response,#[\SensitiveParameter] array &$state,#[\SensitiveParameter] array &$errors): void
    {
        $homePage = SiteCheckInput::text($site, 'health_path') === '';
            if (!$homePage && ($site['health_check_mode'] ?? 'http') === 'json') {
                // Unknown until both extraction and comparison succeed.
                $state['online'] = null;
                $state['online'] = (int) JsonField::compare(
                    JsonField::extract(JsonField::decode($response['body']), SiteCheckInput::text($site, 'health_json_path')),
                    SiteCheckInput::text($site, 'health_json_operator'), SiteCheckInput::text($site, 'health_json_expected_value'));
                if (!$state['online']) {
                    $state['health_error_code'] = 'json-condition';
                    $errors[] = 'Health: ' . HttpFailure::MESSAGES['json-condition'];
                }
            }
    }
}
