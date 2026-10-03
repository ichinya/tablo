<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use Tablo\SiteRepository;
use Tablo\ValidationException;
use Testo\Assert;

final class UnitFixtures
{
    public static function site(): array
    {
        return array_replace(SiteRepository::defaults(), ['name' => 'Tempalog', 'url' => 'https://tempalog.example/',
            'repository' => 'https://github.com/ichinya/tempalog.git', 'version_path' => '/version']);
    }

    public static function response(int $status = 200, string $body = '{}'): array
    {
        return ['status' => $status, 'body' => $body, 'time_ms' => 87];
    }

    public static function rejects(callable $action, string $message): void
    {
        $exception = null;
        try { $action(); } catch (ValidationException $error) { $exception = $error; }
        Assert::instanceOf($exception, ValidationException::class, $message);
    }
}
