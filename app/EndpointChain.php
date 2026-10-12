<?php
declare(strict_types=1);

namespace Tablo;

/**
 * @phpstan-import-type TransportResponse from CurlGetTransport
 * @phpstan-type Chain array{deadline: int, body_bytes: int, header_bytes: int, too_large: bool}
 */
final class EndpointChain
{
    /**
     * @param list<string> $headers
     * @param \Closure(string, list<string>, array{deadline: int, body_bytes: int, header_bytes: int, too_large: bool}): TransportResponse $request
     * @return TransportResponse
     */
    public static function run(#[\SensitiveParameter] string $url, #[\SensitiveParameter] array $headers,
        int $maxRedirects, int $deadline, #[\SensitiveParameter] \Closure $request): array
    {
        EndpointRequestHeaders::validate($headers, $maxRedirects);
        $start = MonotonicTime::nanoseconds();
        $chain = ['deadline' => min($start + 6_000_000_000, $deadline),
            'body_bytes' => 0, 'header_bytes' => 0, 'too_large' => false];
        $target = EndpointTarget::parse($url);
        $origin = $target['origin'];
        $visited = [];
        $transitions = 0;
        while (true) {
            HttpDeadline::remaining($chain['deadline']);
            $identity = hash('sha256', $target['url']);
            if (($visited[$identity] ?? null) !== null) { throw new HttpFailure('redirect-cycle'); }
            $visited[$identity] = true;
            $response = $request($target['url'], $headers, $chain);
            HttpDeadline::remaining($chain['deadline']);
            if (!in_array($response['status'], [301, 302, 303, 307, 308], strict: true)) {
                unset($response['location'], $response['location_invalid']);
                $response['time_ms'] = (int) round((MonotonicTime::nanoseconds() - $start) / 1_000_000);
                return $response;
            }
            $next = EndpointTransition::next($response, $target, $origin, $headers, $visited);
            unset($response);
            if ($transitions >= $maxRedirects) { throw new HttpFailure('redirect-limit'); }
            $target = $next;
            ++$transitions;
        }
    }
}
