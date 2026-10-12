<?php
declare(strict_types=1);

namespace Tablo;

/**
 * @phpstan-import-type CheckResult from CheckState
 * @phpstan-import-type HttpResponse from HttpClient
 */
final class EndpointHealthStatus
{
    /**
     * @param CheckResult $state
     * @param HttpResponse $response
     * @param list<string> $errors
     */
    public static function apply(#[\SensitiveParameter] array $site,#[\SensitiveParameter] array $response,#[\SensitiveParameter] array &$state,#[\SensitiveParameter] array &$errors): bool
    {
        $homePage = SiteCheckInput::text($site, 'health_path') === '';
            $state['health_http_status'] = $response['status'];
            $state['online'] = (int) ($homePage ? $response['status'] === 200
                : ($response['status'] >= 200 && $response['status'] < 300));
            $state['response_time_ms'] = $response['time_ms'];
            if (!$state['online']) {
                $state['health_error_code'] = 'http';
                $errors[] = 'Health: HTTP ' . $response['status'] . ($homePage ? ' (нужен 200, без редиректов).' : ' (нужен итоговый 2xx).');
                if (in_array($response['status'], [401, 403], strict: true)) { $errors[] = 'Health: Проверьте разрешение доступа к endpoint.'; }
                return false;
            }
        return true;
    }
}
