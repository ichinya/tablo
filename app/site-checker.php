<?php
declare(strict_types=1);

namespace Tablo;

final class SiteChecker
{
    public function __construct(private readonly HttpClient $http, private readonly RepositoryProvider $provider) {}

    public function check(array $site): array
    {
        $state = ['online' => null, 'deployed_version' => null, 'deployed_commit' => null,
            'latest_release' => null, 'latest_commit' => null, 'open_issues' => null, 'open_prs' => null,
            'response_time_ms' => null, 'last_error' => null, 'checked_at' => gmdate('Y-m-d\TH:i:s\Z')];
        $errors = [];
        try {
            $response = $this->http->get($site['url'] . $site['health_path']);
            $state['online'] = $response['status'] >= 200 && $response['status'] < 300 ? 1 : 0;
            $state['response_time_ms'] = $response['time_ms'];
            if (!$state['online']) {
                $errors[] = 'Health: HTTP ' . $response['status'] . ' (нужен 2xx, без редиректов).';
            }
        } catch (\Throwable $e) {
            // A blocked/DNS/transport check does not prove that the site is offline.
            $errors[] = 'Health: ' . $e->getMessage();
        }
        if (($site['version_path'] ?? '') !== '') {
            try {
                $response = $this->http->get($site['url'] . $site['version_path']);
                if ($response['status'] < 200 || $response['status'] >= 300) {
                    throw new \RuntimeException('HTTP ' . $response['status']);
                }
                $json = json_decode($response['body'], true, 16, JSON_THROW_ON_ERROR);
                if (!is_array($json)) {
                    throw new \RuntimeException('Ожидается JSON-объект.');
                }
                $version = $json['version'] ?? $json['deployed_version'] ?? null;
                $commit = $json['commit'] ?? $json['sha'] ?? $json['deployed_commit'] ?? null;
                if ($version !== null && (!is_string($version) || $version === '' || strlen($version) > 200)) {
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
            } catch (\Throwable $e) {
                $errors[] = 'Version: ' . $e->getMessage();
            }
        }
        $calls = [
            'latest_release' => fn () => $this->provider->getLatestRelease($site['repository']),
            'latest_commit' => fn () => $this->provider->getLatestCommit($site['repository'], $site['branch']),
            'open_issues' => fn () => $this->provider->getOpenIssuesCount($site['repository']),
            'open_prs' => fn () => $this->provider->getOpenPullRequestsCount($site['repository']),
        ];
        foreach ($calls as $field => $call) {
            try {
                $state[$field] = $call();
            } catch (\Throwable $e) {
                $errors[] = $field . ': ' . $e->getMessage();
            }
        }
        $state['last_error'] = $errors ? implode(' ', $errors) : null;
        return $state;
    }
}
