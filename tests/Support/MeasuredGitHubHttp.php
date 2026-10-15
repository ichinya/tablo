<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use Tablo\HttpClient;

final class MeasuredGitHubHttp extends HttpClient
{
    public int $core = 0;
    public int $search = 0;
    private readonly HttpClient $real;

    public function __construct(private readonly string $base) { $this->real = new HttpClient(true); }

    public function get(string $url, array $headers = []): array
    {
        if (!str_starts_with($url, 'https://api.github.com/')) { throw new \RuntimeException('Unexpected fixture origin'); }
        $path = substr($url, strlen('https://api.github.com'));
        if (str_starts_with($path, '/search/')) { ++$this->search; } else { ++$this->core; }
        // Fixture pacing for the Windows single-process dev server, not application retries.
        usleep(4000);
        return $this->real->get($this->base . $path, $headers);
    }
}
