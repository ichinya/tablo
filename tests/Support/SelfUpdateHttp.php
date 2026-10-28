<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use Tablo\HttpClient;
use Tablo\JsonEncoding;

/** @phpstan-import-type HttpResponse from HttpClient */
final class SelfUpdateHttp extends HttpClient
{
    /** @var list<string> */
    public array $urls = [];
    /** @var list<HttpResponse> */
    public array $responses = [];
    public ?\Closure $during = null;
    public function get(#[\SensitiveParameter] string $url, #[\SensitiveParameter] array $headers = []): array
    {
        $this->urls[] = $url;
        if ($this->during !== null) { ($this->during)(); }
        return array_shift($this->responses) ?? throw new \RuntimeException('Unexpected self-update fixture request.');
    }
    public function branch(string $sha, string $branch = 'main'): void
    { $this->responses[] = UnitFixtures::response(200, JsonEncoding::encode(['name' => $branch, 'commit' => ['sha' => $sha]])); }
    public function comparison(string $status, int $ahead = 0, int $behind = 0): void
    { $this->responses[] = UnitFixtures::response(200, JsonEncoding::encode(['status' => $status, 'ahead_by' => $ahead, 'behind_by' => $behind])); }
}
