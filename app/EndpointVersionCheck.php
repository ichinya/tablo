<?php
declare(strict_types=1);

namespace Tablo;

/**
 * @phpstan-import-type CheckResult from CheckState
 */
final class EndpointVersionCheck
{
    public function __construct(private readonly HttpClient $http) {}
    /**
     * @param CheckResult $state
     * @param list<string> $errors
     */
    public function version(#[\SensitiveParameter] array $site, #[\SensitiveParameter] EndpointCheckAccess $access,
        #[\SensitiveParameter] array &$state, #[\SensitiveParameter] array &$errors): void
    {
        if (($site['version_path'] ?? '') !== '') {
            $state['version_outcome'] = ['status' => 'failed', 'reason' => 'invalid-data', 'http_status' => null];
            try {
                $access->assertReady();
                $response = $this->http->getEndpoint(rtrim(SiteCheckInput::text($site, 'url'), characters: '/') . SiteCheckInput::text($site, 'version_path'), $access->headers());
                EndpointResponse::validate($response);
                $state['version_outcome']['http_status'] = $response['status'];
                if ($response['status'] < 200 || $response['status'] >= 300) {
                    $state['version_outcome']['reason'] = 'http';
                    $errors[] = 'Version: HTTP ' . $response['status'];
                    if (in_array($response['status'], [401, 403], strict: true)) { $errors[] = 'Version: Проверьте разрешение доступа к endpoint.'; }
                    return;
                }
                    $headers = $access->headers();
                    $state = [...$state, ...VersionResponse::decode($response['body'], SiteCheckInput::optionalText($site, 'version_json_path'),
                        $headers === [] ? null : substr($headers[0], strlen('Authorization: Bearer ')))];
                    $state['version_outcome']['status'] = 'ok';
                    $state['version_outcome']['reason'] = 'ok';
            } catch (\PDOException | DatabaseFailure | SharedKeyFailure $error) { throw $error; }
            catch (\Throwable $e) {
                $state['version_outcome']['reason'] = $e instanceof HttpFailure ? $e->reason : 'invalid-data';
                $errors[] = 'Version: ' . ($e instanceof HttpFailure ? $e->getMessage() : 'Некорректный ответ проверки версии.');
            }
        }
    }
}
