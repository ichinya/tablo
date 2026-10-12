<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;

/** @phpstan-import-type Result from Subprocess */
abstract class AdminPasswordFixtureCase
{
    /** @param list<string> $arguments
     * @return Result */
    protected static function command(TemporaryDirectory $directory, string $path, array $arguments,
        #[\SensitiveParameter] string $input = ''): array
    {
        return Subprocess::run(command: [PHP_BINARY, '-d', 'zend.exception_ignore_args=0', dirname(__DIR__, levels: 2) . '/bin/admin-password.php', ...$arguments], directory: $directory, environment: ['TABLO_DB' => $path], options: new \Tablo\Tests\Support\CommandOptions(input: $input, cwd: $directory->path));
    }
}
