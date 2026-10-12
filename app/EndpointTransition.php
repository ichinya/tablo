<?php
declare(strict_types=1);

namespace Tablo;

final class EndpointTransition
{
    public static function next(#[\SensitiveParameter] array $response, #[\SensitiveParameter] array $target, string $origin, #[\SensitiveParameter] array &$headers, array $visited): array
    {
            if ($response['location_invalid']) { throw new HttpFailure('redirect-location-invalid'); }
            if ($response['location'] === null || $response['location'] === '') { throw new HttpFailure('redirect-location-missing'); }
            $next = EndpointTarget::redirect($target, $response['location']);

            if ($next['origin'] !== $origin) {
                $headers = []; // Detach the entire credential set before rejecting.
                throw new HttpFailure('redirect-origin');
            }
            if (($visited[hash('sha256', $next['url'])] ?? null) !== null) { throw new HttpFailure('redirect-cycle'); }
            return $next;
    }

}
