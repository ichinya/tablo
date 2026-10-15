<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-import-type Target from EndpointTarget */
final class UriRelativePath
{
    /**
     * @param Target $base
     * @param array{path?: string, query?: string, ...} $parts
     * @return array{path: string, query: ?string}
     */
    public static function against(#[\SensitiveParameter] array $base, #[\SensitiveParameter] array $parts): array
    {
            $path = $parts['path'] ?? '';
            if ($path === '') {
                $path = $base['path'];
                $query = array_key_exists('query', $parts) ? $parts['query'] : $base['query'];
                return ['path' => $path, 'query' => $query];
            }
                if (!str_starts_with($path, '/')) {
                    $slash = strrpos($base['path'], needle: '/');
                    $path = substr($base['path'], offset: 0, length: ($slash === false ? 0 : $slash) + 1) . $path;
                }
                $query = $parts['query'] ?? null;

        return ['path' => $path, 'query' => $query];
    }

}
