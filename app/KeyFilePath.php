<?php
declare(strict_types=1);

namespace Tablo;

final class KeyFilePath
{
    public static function external(#[\SensitiveParameter] string $path): string
    {
        // Literal local paths only; drive paths are not stream schemes. Relative paths
        // always belong to the project root, independently of the invoking directory.
        if ($path === '' || str_contains($path, "\0") || str_starts_with($path, '//')
            || str_starts_with($path, '\\\\')
            || (preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $path)
                && !preg_match('~^[a-zA-Z]:[\\\\/]~', $path))) {
            throw new SharedKeyFailure('External token key file is unavailable or invalid.');
        }
        if (str_starts_with($path, '/') || preg_match('~^[a-zA-Z]:[\\\\/]~', $path)) { return $path; }
        return dirname(__DIR__) . '/' . $path;
    }
}
