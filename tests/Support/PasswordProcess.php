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
        $this->child = new Subprocess(command: [PHP_BINARY, '-d', 'zend.exception_ignore_args=0',
            $entrypoint ?? dirname(__DIR__, levels: 2) . '/bin/admin-password.php', '--password-stdin'], directory: $directory, environment: array_replace(['TABLO_DB' => $database], $environment), options: new \Tablo\Tests\Support\CommandOptions(cwd: $directory->path));
        $this->child->awaitOutput('Installation:', timeout: 5);
    }

    public function send(#[\SensitiveParameter] string $input): void { $this->child->send($input); }

    /** @return array{'exit_code': int|null, 'files': array{'receipt': string, 'stderr': string, 'stdout': string}, 'stderr': string, 'stdout': string} */
    public function finish(float $timeout = 8): array { return $this->child->finish($timeout); }

    public function close(?Throwable $primary = null): void { $this->child->close($primary); }
}
