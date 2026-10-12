<?php
declare(strict_types=1);

namespace Tablo;

final class SiteFormErrors
{
    /** @return (array<string, string>)|(array{'storage': string})|(array{'github_token': string}) */
    public static function errors(\Throwable $e): array
    {
        if ($e instanceof ValidationException) { return $e->errors; }
        if ($e instanceof \PDOException) { return ['storage' => 'Не удалось сохранить настройки. Проверьте доступ к хранилищу.']; }
        return ['github_token' => $e->getMessage()];
    }
}
