<?php
declare(strict_types=1);

namespace Tablo;

/**
 * @phpstan-import-type Target from EndpointTarget
 * @phpstan-import-type HttpResponse from HttpClient
 */
final class EndpointTransition
{
    /**
     * @param HttpResponse $response
     * @param Target $target
     * @param list<string> $headers
     * @param array<string, true> $visited
     * @return Target
     */
    public static function next(#[\SensitiveParameter] array $response, #[\SensitiveParameter] array $target, string $origin, #[\SensitiveParameter] array &$headers, array $visited): array
    {
            if ($response['location_invalid'] ?? false) { throw new HttpFailure('redirect-location-invalid'); }
            if (($response['location'] ?? null) === null || $response['location'] === '') { throw new HttpFailure('redirect-location-missing'); }
            $next = EndpointTarget::redirect($target, $response['location']);

            if ($next['origin'] !== $origin) {
                $headers = []; // Detach the entire credential set before rejecting.
                throw new HttpFailure('redirect-origin');
            }
            if (($visited[hash('sha256', $next['url'])] ?? null) !== null) { throw new HttpFailure('redirect-cycle'); }
            return $next;
    }

}
