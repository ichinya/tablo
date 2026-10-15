<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use Throwable;

/** Owns one actual CLI, whose flushed selection line is its before-input barrier. */
final class PasswordProcess
{
    private readonly Subprocess $child;

    public function __construct(TemporaryDirectory $directory, string $database, ?string $entrypoint = null, array $environment = [])
    {
        $this->child = new Subprocess([PHP_BINARY, '-d', 'zend.exception_ignore_args=0',
            $entrypoint ?? dirname(__DIR__, 2) . '/bin/admin-password.php', '--password-stdin'],
            $directory, array_replace(['TABLO_DB' => $database], $environment), $directory->path);
        $this->child->awaitOutput('Installation:', timeout: 5);
    }

    public function send(#[\SensitiveParameter] string $input): void { $this->child->send($input); }

    public function finish(float $timeout = 8): array { return $this->child->finish($timeout); }

    public function close(?Throwable $primary = null): void { $this->child->close($primary); }
}
