<?php
declare(strict_types=1);

namespace Tablo;

final class SiteChecker
{
    public function __construct(private readonly HttpClient $http, private readonly RepositoryProvider $provider) {}

    public function check(#[\SensitiveParameter] array $site, ?array $fieldOrder = null): array
    {
        $state = ['online' => null, 'deployed_version' => null, 'deployed_commit' => null,
            'latest_release' => null, 'latest_commit' => null, 'open_issues' => null, 'open_prs' => null,
            'health_error_code' => null, 'health_http_status' => null,
            'version_status' => ($site['version_path'] ?? '') === '' ? 'skipped' : 'error',
            'version_error_code' => null, 'version_http_status' => null,
            'release_error_code' => 'check-error', 'release_http_status' => null,
            'commit_error_code' => 'check-error', 'commit_http_status' => null,
            'response_time_ms' => null, 'last_error' => null, 'checked_at' => gmdate('Y-m-d\TH:i:s\Z')];
        $errors = [];
        try {
            $response = $this->http->get($site['url'] . $site['health_path']);
            if (!is_int($response['status'] ?? null) || $response['status'] < 100 || $response['status'] > 599
                || !is_string($response['body'] ?? null)) { throw new HttpFailure('invalid-response'); }
            $state['health_http_status'] = $response['status'];
            $homePage = $site['health_path'] === '';
            $state['online'] = (int) ($homePage ? $response['status'] === 200
                : ($response['status'] >= 200 && $response['status'] < 300));
            $state['response_time_ms'] = $response['time_ms'];
            if (!$state['online']) {
                $state['health_error_code'] = 'http';
                $errors[] = 'Health: HTTP ' . $response['status'] . ($homePage ? ' (нужен 200, без редиректов).' : ' (нужен 2xx, без редиректов).');
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
        if (($site['version_path'] ?? '') !== '') {
            try {
                $response = $this->http->get($site['url'] . $site['version_path']);
                if (!is_int($response['status'] ?? null) || $response['status'] < 100 || $response['status'] > 599
                    || !is_string($response['body'] ?? null)) { throw new HttpFailure('invalid-response'); }
                $state['version_http_status'] = $response['status'];
                if ($response['status'] < 200 || $response['status'] >= 300) {
                    $state['version_error_code'] = 'http';
                    $errors[] = 'Version: HTTP ' . $response['status'];
                } else {
                    $document = JsonField::decode($response['body']);
                    $json = $document instanceof \stdClass ? (array) $document : [];
                    $jsonPath = $site['version_json_path'] ?? '';
                    if ($jsonPath === '' && !($document instanceof \stdClass)) {
                        throw new \RuntimeException('Ожидается JSON-объект.');
                    }
                    $version = $jsonPath === '' ? ($json['version'] ?? $json['deployed_version'] ?? null)
                        : JsonField::extract($document, $jsonPath);
                    $commit = $json['commit'] ?? $json['sha'] ?? $json['deployed_commit'] ?? null;
                    if ($version !== null && (!is_string($version) || $version === '' || strlen($version) > 200
                        || preg_match('//u', $version) !== 1 || str_contains($version, "\0"))) {
                        throw new \RuntimeException('Некорректное поле version.');
                    }
                    if ($commit !== null && (!is_string($commit) || !preg_match('/^[a-f0-9]{7,64}$/iD', $commit))) {
                        throw new \RuntimeException('Некорректное поле commit (нужен SHA, от 7 символов).');
                    }
                    if ($version === null && $commit === null) {
                        throw new \RuntimeException('Нужны поля version и/или commit.');
                    }
                    $state['deployed_version'] = $version;
                    $state['deployed_commit'] = $commit === null ? null : strtolower($commit);
                    $state['version_status'] = 'ok';
                }
            } catch (\PDOException | SharedKeyFailure $error) { throw $error; }
            catch (\Throwable $e) {
                $state['version_error_code'] = $e instanceof HttpFailure ? $e->reason : 'invalid-data';
                $errors[] = 'Version: ' . ($e instanceof HttpFailure ? $e->getMessage() : 'Некорректный ответ проверки версии.');
            }
        }
        if ($this->provider instanceof GitHubProvider) { $this->provider->beginCheck(); }
        $calls = [
            'latest_release' => fn () => $this->provider->getLatestRelease($site['repository']),
            'latest_commit' => fn () => $this->provider->getLatestCommit($site['repository'], $site['branch']),
            'open_issues' => fn () => $this->provider->getOpenIssuesCount($site['repository']),
            'open_prs' => fn () => $this->provider->getOpenPullRequestsCount($site['repository']),
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
            } catch (\PDOException | SharedKeyFailure $error) { throw $error; }
            catch (\Throwable $e) {
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
        $state['last_error'] = $errors ? implode(' ', $errors) : null;
        if ($fieldOrder !== null) { $state['worker_service'] = $service; }
        return $state;
    }
}
