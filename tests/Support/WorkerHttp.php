<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use Tablo\HttpClient;

// Real local HTTP transport; only the fixture API origin is substituted.
/** @phpstan-import-type HttpResponse from HttpClient */
final class WorkerHttp extends HttpClient
{
    private readonly HttpClient $real;
    public array $calls = [];
    public int $window = 0;

    public function __construct(private readonly string $base) { $this->real = new HttpClient(true); }

    /** @return HttpResponse */
    public function getEndpoint(#[\SensitiveParameter] string $url, #[\SensitiveParameter] array $headers = [],
        int $maxRedirects = 3, ?int $deadline = null): array
    {
        $this->calls[] = parse_url($url, PHP_URL_PATH);
        return $this->real->getEndpoint($url, $headers, $maxRedirects, $deadline);
    }

    /** @return HttpResponse */
    public function get(#[\SensitiveParameter] string $url, #[\SensitiveParameter] array $headers = []): array
    {
        $path = parse_url($url, PHP_URL_PATH);
        $this->calls[] = $path;
        if (str_starts_with($url, 'https://api.github.com')) {
            $url = $this->base . substr($url, strlen('https://api.github.com'));
        }
        return $this->real->get($url, [...$headers, 'X-Fixture-Window: ' . $this->window]);
    }
}
