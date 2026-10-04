<?php
declare(strict_types=1);

namespace Tablo;

final class GitHubProvider implements RepositoryProvider
{
    public function __construct(private readonly HttpClient $http, #[\SensitiveParameter] private readonly string $token = '') {}

    // Allow only the expected missing-release response, rather than every HTTP error.
    // @mago-expect lint:no-boolean-flag-parameter
    private function request(string $path, bool $allowMissing = false): ?array
    {
        $headers = ['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28'];
        if ($this->token !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }
        $response = $this->http->get('https://api.github.com' . $path, $headers);
        if ($allowMissing && $response['status'] === 404) {
            return null;
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $message = match ($response['status']) {
                401 => 'Токен недействителен или истёк. Введите новый токен.',
                403 => 'Нет разрешения или исчерпан лимит API. Проверьте доступ токена к репозиторию и Contents: read.',
                404 => 'Репозиторий или ветка не найдены. Для приватного репозитория проверьте доступ токена.',
                301, 302 => 'Репозиторий или ветка переименованы. Укажите актуальный адрес и ветку.',
                default => 'Проверьте доступ к репозиторию и повторите запрос.',
            };
            throw new \RuntimeException('GitHub HTTP ' . $response['status'] . '. ' . $message);
        }
        $json = json_decode($response['body'], associative: true, depth: 32, flags: JSON_THROW_ON_ERROR);
        if (!is_array($json)) {
            throw new \RuntimeException('GitHub вернул некорректный JSON.');
        }
        return $json;
    }

    private function path(string $repository): string
    {
        return '/repos/' . implode('/', array_map('rawurlencode', explode('/', $repository)));
    }

    public function getBranches(string $repository): array
    {
        $started = hrtime(true);
        $metadata = $this->request($this->path($repository));
        $default = $metadata['default_branch'] ?? null;
        if (!is_string($default) || $default === '' || strlen($default) > 128) {
            throw new \RuntimeException('GitHub не вернул основную ветку репозитория.');
        }
        $branches = [];
        for ($page = 1; $page <= 10; ++$page) {
            if ((hrtime(true) - $started) / 1e9 > 24) {
                throw new \RuntimeException('Загрузка веток заняла слишком много времени. Повторите запрос или укажите ветку вручную.');
            }
            $items = $this->request($this->path($repository) . '/branches?per_page=100&page=' . $page);
            if (!array_is_list($items) || count($items) > 100) {
                throw new \RuntimeException('GitHub вернул некорректный список веток.');
            }
            foreach ($items as $item) {
                $name = $item['name'] ?? null;
                if (!is_string($name) || $name === '' || strlen($name) > 128 || preg_match('/[\s\x00-\x1f]/', $name)
                    || !preg_match('/^[a-f0-9]{40,64}$/iD', $item['commit']['sha'] ?? '')) {
                    throw new \RuntimeException('GitHub вернул некорректную ветку.');
                }
                // Prefix keys so a numeric branch name remains a string in JSON.
                $branches['branch:' . $name] = $name;
            }
            if (count($items) < 100) {
                if (!$branches) {
                    throw new \RuntimeException('В репозитории пока нет веток. Создайте первый коммит.');
                }
                return ['repository' => $repository, 'default_branch' => $default, 'branches' => array_values($branches)];
            }
        }
        throw new \RuntimeException('Список веток слишком большой. Укажите нужную ветку вручную — она будет проверена при сохранении.');
    }

    public function requireBranch(string $repository, string $branch): void
    {
        $this->getLatestCommit($repository, $branch);
    }

    public function getLatestRelease(string $repository): ?string
    {
        $json = $this->request($this->path($repository) . '/releases/latest', allowMissing: true);
        if ($json === null) {
            // Distinguish a repository with no releases from a missing/private repository.
            $this->request($this->path($repository));
            return null;
        }
        $tag = $json['tag_name'] ?? null;
        if (!is_string($tag) || $tag === '' || strlen($tag) > 200) {
            throw new \RuntimeException('GitHub не вернул тег релиза.');
        }
        return $tag;
    }

    public function getLatestCommit(string $repository, string $branch): string
    {
        $json = $this->request($this->path($repository) . '/branches/' . rawurlencode($branch));
        if (($json['name'] ?? null) !== $branch || !is_string($json['commit']['sha'] ?? null)
            || !preg_match('/^[a-f0-9]{40,64}$/iD', $json['commit']['sha'])) {
            throw new \RuntimeException('GitHub не подтвердил выбранную ветку. Обновите список веток.');
        }
        return strtolower($json['commit']['sha']);
    }

    private function count(string $repository, string $kind): int
    {
        $json = $this->request('/search/issues?q=' . rawurlencode("repo:{$repository} is:{$kind} is:open") . '&per_page=1');
        if (($json['incomplete_results'] ?? true) !== false || !is_int($json['total_count'] ?? null) || $json['total_count'] < 0) {
            throw new \RuntimeException('GitHub вернул неполный результат поиска.');
        }
        return $json['total_count'];
    }

    public function getOpenIssuesCount(string $repository): int { return $this->count($repository, 'issue'); }
    public function getOpenPullRequestsCount(string $repository): int { return $this->count($repository, 'pr'); }
}
