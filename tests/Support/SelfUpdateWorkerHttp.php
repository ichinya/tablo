<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use Tablo\HttpClient;

final class SelfUpdateWorkerHttp extends HttpClient
{
    public int $epoch = 1000;
    /** @var list<string> */
    public array $health = [];
    /** @var list<array{int, int}> */
    public array $updates = [];
    /** @var (\Closure(int): void)|null */
    public ?\Closure $afterHealth = null;

    public function getEndpoint(#[\SensitiveParameter] string $url, #[\SensitiveParameter] array $headers = [], int $maxRedirects = 3, ?int $deadline = null): array
    {
        $this->health[] = $url; $this->epoch += 7200;
        if ($this->afterHealth !== null) { ($this->afterHealth)(count($this->health)); }
        return UnitFixtures::response();
    }

    public function get(#[\SensitiveParameter] string $url, #[\SensitiveParameter] array $headers = []): array
    {
        if (str_starts_with($url, 'https://api.github.com/repos/ichinya/tablo/branches/')) {
            $this->updates[] = [$this->epoch, count($this->health)];
        }
        return UnitFixtures::response(200, match (true) {
            str_contains($url, '/branches/') => '{"name":"main","commit":{"sha":"' . str_repeat('a', times: 40) . '"}}',
            str_contains($url, '/search/') => '{"total_count":0,"incomplete_results":false}',
            default => '{"tag_name":"v1"}',
        });
    }
}
