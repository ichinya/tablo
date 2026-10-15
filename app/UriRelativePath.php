<?php
declare(strict_types=1);

namespace Tablo;

final class UriRelativePath
{
    public static function against(#[\SensitiveParameter] array $base, #[\SensitiveParameter] array $parts): array
    {
            $path = $parts['path'] ?? '';
            if ($path === '') {
                $path = $base['path'];
                $query = array_key_exists('query', $parts) ? $parts['query'] : $base['query'];
                return ['path' => $path, 'query' => $query];
            }
                if (!str_starts_with($path, '/')) {
                    $path = substr($base['path'], offset: 0, length: strrpos($base['path'], needle: '/') + 1) . $path;
                }
                $query = $parts['query'] ?? null;

        return ['path' => $path, 'query' => $query];
    }

}
