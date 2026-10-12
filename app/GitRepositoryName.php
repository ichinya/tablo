<?php
declare(strict_types=1);

namespace Tablo;

final class GitRepositoryName
{
    public static function normalize(#[\SensitiveParameter] string $repository): string
    {
        $repository = trim($repository);
        if (preg_match('~^https://github\.com/([^/?#]+/[^/?#]+)/?$~i', $repository, $match)) {
            $repository = $match[1];
        }
        $repository = preg_replace('~\.git$~', replacement: '', subject: $repository);
        if (!preg_match('~^[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,38})/[a-zA-Z0-9_.-]{1,100}$~D', $repository)
            || in_array(explode('/', $repository)[1] ?? '', ['.', '..'], strict: true)) {
            throw ValidationException::field(field: 'repository', message: 'Укажите owner/repository или URL репозитория на github.com.');
        }
        return $repository;
    }
}
