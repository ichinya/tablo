<?php
declare(strict_types=1);

namespace Tablo;

final class GitRepositoryPath
{
    public static function path(string $repository): string
    {
        return '/repos/' . implode('/', array_map('rawurlencode', explode('/', $repository)));
    }
}
