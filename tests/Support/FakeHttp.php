<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use Tablo\HttpClient;
use RuntimeException;
use Throwable;

final class FakeHttp extends HttpClient
{
    public array $requests = [];
    public function __construct(private array $responses) {}
    public function get(string $url, array $headers = []): array
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
