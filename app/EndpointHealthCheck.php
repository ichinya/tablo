<?php
declare(strict_types=1);

namespace Tablo;

/**
 * @phpstan-import-type CheckResult from CheckState
 */
final class EndpointHealthCheck
{
    public function __construct(private readonly HttpClient $http) {}
    /**
     * @param CheckResult $state
     * @param list<string> $errors
     */
    public function health(#[\SensitiveParameter] array $site, #[\SensitiveParameter] EndpointCheckAccess $access,
        #[\SensitiveParameter] array &$state, #[\SensitiveParameter] array &$errors): void
    {
        try {
            $access->assertReady();
            $homePage = SiteCheckInput::text($site, 'health_path') === '';
            $response = $homePage ? $this->http->get(SiteCheckInput::text($site, 'url'), $access->headers())
                : $this->http->getEndpoint(rtrim(SiteCheckInput::text($site, 'url'), characters: '/') . SiteCheckInput::text($site, 'health_path'), $access->headers());
            EndpointResponse::validate($response);
            if (!EndpointHealthStatus::apply($site,$response,$state,$errors)) { return; }
            EndpointHealthJson::apply($site,$response,$state,$errors);
        } catch (\PDOException | DatabaseFailure | SharedKeyFailure $error) { throw $error; }
        catch (\Throwable $e) {
            // A blocked/DNS/transport check does not prove that the site is offline.
            $state['online'] = null;
            $state['health_error_code'] = EndpointHealthFailure::reason($e,$state);
            $errors[] = 'Health: ' . (HttpFailure::MESSAGES[$state['health_error_code']] ?? HttpFailure::MESSAGES['check-error']);
        }
    }
}
