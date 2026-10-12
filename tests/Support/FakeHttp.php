<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use Tablo\HttpClient;
use RuntimeException;
use Throwable;

/** @phpstan-type Response array{status: int, body: string, time_ms: int, headers?: array<string, string|null>, location?: ?string, location_invalid?: bool} */
final class FakeHttp extends HttpClient
{
    /** @var list<array{string, list<string>}> */
    public array $requests = [];
    /** @var list<array{int, ?int}> */
    public array $endpointRequests = [];
    /** @param list<Response|Throwable> $responses */
    public function __construct(private array $responses) {}
    /** @param list<string> $headers
     * @return Response */
    public function getEndpoint(#[\SensitiveParameter] string $url, #[\SensitiveParameter] array $headers = [],
        int $maxRedirects = 3, ?int $deadline = null): array
    {
        $this->endpointRequests[] = [$maxRedirects, $deadline];
        return $this->get($url, $headers);
    }
    /** @param list<string> $headers
     * @return Response */
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
