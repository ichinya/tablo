<?php
declare(strict_types=1);

namespace Tablo;

final class SiteChecker
{
    public function __construct(private readonly HttpClient $http, private readonly RepositoryProvider $provider,
        #[\SensitiveParameter] private readonly ?TokenVault $vault = null) {}

    public function check(#[\SensitiveParameter] array $site, ?array $fieldOrder = null): array
    {
        $state = ['online' => null, 'deployed_version' => null, 'deployed_commit' => null,
            'latest_release' => null, 'latest_commit' => null, 'open_issues' => null, 'open_prs' => null,
            'health_error_code' => null, 'health_http_status' => null,
            'version_status' => ($site['version_path'] ?? '') === '' ? 'skipped' : 'error',
            'version_error_code' => null, 'version_http_status' => null,
            'release_error_code' => 'check-error', 'release_http_status' => null,
            'commit_error_code' => 'check-error', 'commit_http_status' => null,
            'response_time_ms' => null, 'last_error' => null, 'checked_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'version_outcome' => ['status' => 'skipped', 'reason' => 'not-configured', 'http_status' => null],
            'git_outcomes' => []];
        $errors = [];
        $headers = [];
        $credentialFailed = false;
        try { $headers = $this->endpointHeaders($site); }
        catch (\PDOException | SharedKeyFailure $error) { throw $error; }
        catch (\Throwable) { $credentialFailed = true; }
        $this->health($site, $headers, $credentialFailed, $state, $errors);
        $this->version($site, $headers, $credentialFailed, $state, $errors);
        $state['version_status'] = match ($state['version_outcome']['status']) { 'ok' => 'ok', 'skipped' => 'skipped', default => 'error' };
        $state['version_error_code'] = $state['version_status'] === 'error' ? $state['version_outcome']['reason'] : null;
        $state['version_http_status'] = $state['version_outcome']['http_status'];
        unset($headers);
        $this->git($site['repository'], $site['branch'], $fieldOrder, $state, $errors);
        $state['last_error'] = $errors ? implode(' ', $errors) : null;
        return $state;
    }

    private function endpointHeaders(#[\SensitiveParameter] array $site): array
    {
        $encrypted = $site['endpoint_token'] ?? null;
        if ($encrypted === null || $encrypted === '') { return []; }
        if (!is_string($encrypted) || $this->vault === null) { throw new HttpFailure('check-error'); }
        $token = SiteRepository::validateEndpointToken($this->vault->decrypt($encrypted));
        if ($token === '') { throw new HttpFailure('check-error'); }
        return ['Authorization: Bearer ' . $token];
    }

    private function health(#[\SensitiveParameter] array $site, #[\SensitiveParameter] array $headers, bool $credentialFailed,
        #[\SensitiveParameter] array &$state, #[\SensitiveParameter] array &$errors): void
    {
        try {
            if ($credentialFailed) { throw new HttpFailure('check-error'); }
            $homePage = $site['health_path'] === '';
            $response = $homePage ? $this->http->get($site['url'], $headers)
                : $this->http->getEndpoint(rtrim($site['url'], '/') . $site['health_path'], $headers);
            if (!is_int($response['status'] ?? null) || $response['status'] < 100 || $response['status'] > 599
                || !is_string($response['body'] ?? null)) { throw new HttpFailure('invalid-response'); }
            $state['health_http_status'] = $response['status'];
            $state['online'] = (int) ($homePage ? $response['status'] === 200
                : ($response['status'] >= 200 && $response['status'] < 300));
            $state['response_time_ms'] = $response['time_ms'];
            if (!$state['online']) {
                $state['health_error_code'] = 'http';
                $errors[] = 'Health: HTTP ' . $response['status'] . ($homePage ? ' (нужен 200, без редиректов).' : ' (нужен итоговый 2xx).');
                if (in_array($response['status'], [401, 403], true)) { $errors[] = 'Health: Проверьте разрешение доступа к endpoint.'; }
            } elseif (!$homePage && ($site['health_check_mode'] ?? 'http') === 'json') {
                // Unknown until both extraction and comparison succeed.
                $state['online'] = null;
                $value = JsonField::extract(JsonField::decode($response['body']), $site['health_json_path']);
                $state['online'] = (int) JsonField::compare($value, $site['health_json_operator'], $site['health_json_expected_value']);
                if (!$state['online']) {
                    $state['health_error_code'] = 'json-condition';
                    $errors[] = 'Health: ' . HttpFailure::MESSAGES['json-condition'];
                }
            }
        } catch (\PDOException | SharedKeyFailure $error) { throw $error; }
        catch (\Throwable $e) {
            // A blocked/DNS/transport check does not prove that the site is offline.
            $state['online'] = null;
            $state['health_error_code'] = $e instanceof HttpFailure ? $e->reason
                : ($state['health_http_status'] !== null ? 'invalid-response' : 'check-error');
            $errors[] = 'Health: ' . (HttpFailure::MESSAGES[$state['health_error_code']] ?? HttpFailure::MESSAGES['check-error']);
        }
    }

    private function version(#[\SensitiveParameter] array $site, #[\SensitiveParameter] array $headers, bool $credentialFailed,
        #[\SensitiveParameter] array &$state, #[\SensitiveParameter] array &$errors): void
    {
        if (($site['version_path'] ?? '') !== '') {
            $state['version_outcome'] = ['status' => 'failed', 'reason' => 'invalid-data', 'http_status' => null];
            try {
                if ($credentialFailed) { throw new HttpFailure('check-error'); }
                $response = $this->http->getEndpoint(rtrim($site['url'], '/') . $site['version_path'], $headers);
                if (!is_int($response['status'] ?? null) || $response['status'] < 100 || $response['status'] > 599
                    || !is_string($response['body'] ?? null)) { throw new HttpFailure('invalid-response'); }
                $state['version_outcome']['http_status'] = $response['status'];
                if ($response['status'] < 200 || $response['status'] >= 300) {
                    $state['version_outcome']['reason'] = 'http';
                    $errors[] = 'Version: HTTP ' . $response['status'];
                    if (in_array($response['status'], [401, 403], true)) { $errors[] = 'Version: Проверьте разрешение доступа к endpoint.'; }
                } else {
                    $state = array_replace($state, VersionResponse::decode($response['body'], $site['version_json_path'] ?? '',
                        $headers === [] ? null : substr($headers[0], strlen('Authorization: Bearer '))));
                    $state['version_outcome']['status'] = 'ok';
                    $state['version_outcome']['reason'] = 'ok';
                }
            } catch (\PDOException | SharedKeyFailure $error) { throw $error; }
            catch (\Throwable $e) {
                $state['version_outcome']['reason'] = $e instanceof HttpFailure ? $e->reason : 'invalid-data';
                $errors[] = 'Version: ' . ($e instanceof HttpFailure ? $e->getMessage() : 'Некорректный ответ проверки версии.');
            }
        }
    }

    private function git(string $repository, string $branch, ?array $fieldOrder,
        #[\SensitiveParameter] array &$state, #[\SensitiveParameter] array &$errors): void
    {
        if ($this->provider instanceof GitHubProvider) { $this->provider->beginCheck(); }
        $calls = [
            'latest_release' => fn () => $this->provider->getLatestRelease($repository),
            'latest_commit' => fn () => $this->provider->getLatestCommit($repository, $branch),
            'open_issues' => fn () => $this->provider->getOpenIssuesCount($repository),
            'open_prs' => fn () => $this->provider->getOpenPullRequestsCount($repository),
        ];
        $service = [];
        foreach ($fieldOrder ?? array_keys($calls) as $field) {
            if (!isset($calls[$field])) { throw new \InvalidArgumentException('Invalid check field.'); }
            $call = $calls[$field];
            $before = $this->provider instanceof GitHubProvider ? $this->provider->admissions() : 0;
            $completed = false;
            try {
                $state[$field] = $call();
                // Preserve partial successes while classifying invalid values at their boundary.
                if (($field === 'latest_release' && $state[$field] !== null
                    && (!is_string($state[$field]) || $state[$field] === '' || strlen($state[$field]) > 200
                        || preg_match('//u', $state[$field]) !== 1 || str_contains($state[$field], "\0")))
                    || ($field === 'latest_commit' && (!is_string($state[$field]) || !preg_match('/^[a-f0-9]{7,64}$/iD', $state[$field])))) {
                    $state[$field] = null;
                    throw new GitHubFailure('invalid-data');
                }
                if (in_array($field, ['latest_release', 'latest_commit'], true)) {
                    $state[($field === 'latest_release' ? 'release' : 'commit') . '_error_code'] = null;
                }
                $completed = true;
                $absent = $field === 'latest_release' && $state[$field] === null;
                $state['git_outcomes'][$field] = ['status' => $absent ? 'absent' : 'ok',
                    'reason' => $absent ? 'no-release' : 'ok', 'http_status' => null, 'eligible_at' => null];
            } catch (\PDOException | SharedKeyFailure $error) { throw $error; }
            catch (\Throwable $e) {
                $state['git_outcomes'][$field] = ['status' => 'failed',
                    'reason' => $e instanceof GitHubFailure ? $e->reason : 'unavailable',
                    'http_status' => $e instanceof GitHubFailure ? $e->httpStatus : null,
                    'eligible_at' => $e instanceof GitHubFailure ? $e->eligibleAt : null];
                if (in_array($field, ['latest_release', 'latest_commit'], true)) {
                    $prefix = $field === 'latest_release' ? 'release' : 'commit';
                    $state[$prefix . '_error_code'] = $e instanceof GitHubFailure && in_array($e->reason, CheckHistorySnapshot::GIT_CODES, true)
                        ? $e->reason : 'check-error';
                    $status = $e instanceof GitHubFailure ? $e->httpStatus : null;
                    $state[$prefix . '_http_status'] = is_int($status) && $status >= 100 && $status <= 599 ? $status : null;
                }
                $errors[] = $field . ': ' . ($fieldOrder === null || $e instanceof GitHubFailure
                    ? $e->getMessage() : (new GitHubFailure('unavailable'))->getMessage());
            }
            $service[$field] = $completed || ($this->provider instanceof GitHubProvider && $this->provider->admissions() > $before);
        }
        if ($fieldOrder !== null) { $state['worker_service'] = $service; }
    }
}
