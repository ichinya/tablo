<?php
declare(strict_types=1);

namespace Tablo;

final class EndpointVersionCheck
{
    public function __construct(private readonly HttpClient $http) {}
    public function version(#[\SensitiveParameter] array $site, #[\SensitiveParameter] EndpointCheckAccess $access,
        #[\SensitiveParameter] array &$state, #[\SensitiveParameter] array &$errors): void
    {
        if (($site['version_path'] ?? '') !== '') {
            $state['version_outcome'] = ['status' => 'failed', 'reason' => 'invalid-data', 'http_status' => null];
            try {
                $access->assertReady();
                $response = $this->http->getEndpoint(rtrim($site['url'], characters: '/') . $site['version_path'], $access->headers());
                EndpointResponse::validate($response);
                $state['version_outcome']['http_status'] = $response['status'];
                if ($response['status'] < 200 || $response['status'] >= 300) {
                    $state['version_outcome']['reason'] = 'http';
                    $errors[] = 'Version: HTTP ' . $response['status'];
                    if (in_array($response['status'], [401, 403], strict: true)) { $errors[] = 'Version: Проверьте разрешение доступа к endpoint.'; }
                    return;
                }
                    $state = array_replace($state, VersionResponse::decode($response['body'], $site['version_json_path'] ?? '',
                        $access->headers() === [] ? null : substr($access->headers()[0], strlen('Authorization: Bearer '))));
                    $state['version_outcome']['status'] = 'ok';
                    $state['version_outcome']['reason'] = 'ok';
            } catch (\PDOException | SharedKeyFailure $error) { throw $error; }
            catch (\Throwable $e) {
                $state['version_outcome']['reason'] = $e instanceof HttpFailure ? $e->reason : 'invalid-data';
                $errors[] = 'Version: ' . ($e instanceof HttpFailure ? $e->getMessage() : 'Некорректный ответ проверки версии.');
            }
        }
    }
}
