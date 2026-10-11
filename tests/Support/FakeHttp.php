<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use Tablo\HttpClient;
use RuntimeException;
use Throwable;

final class FakeHttp extends HttpClient
{
    public array $requests = [];
    public array $endpointRequests = [];
    public function __construct(private array $responses) {}
    public function getEndpoint(#[\SensitiveParameter] string $url, #[\SensitiveParameter] array $headers = [],
        int $maxRedirects = 3, ?int $deadline = null): array
    {
        $this->endpointRequests[] = [$maxRedirects, $deadline];
        return $this->get($url, $headers);
    }
    public function get(#[\SensitiveParameter] string $url, #[\SensitiveParameter] array $headers = []): array
    {
        $this->requests[] = [$url, $headers];
        $response = array_shift($this->responses);
        if ($response instanceof Throwable) {
            throw $response;
        }
        if (!is_array($response)) {
            throw new RuntimeException('Unexpected HTTP request: ' . $url);
        }
        return $response;
    }
}
