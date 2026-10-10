<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use RuntimeException;

/** Owns one actual CLI, whose flushed selection line is its before-input barrier. */
final class PasswordProcess
{
    private mixed $process = null;
    private mixed $input = null;
    private readonly string $stdout;
    private readonly string $stderr;

    public function __construct(TemporaryDirectory $directory, string $database, ?string $entrypoint = null, array $environment = [])
    {
        $name = bin2hex(random_bytes(4));
        $this->stdout = $directory->path . '/' . $name . '.out';
        $this->stderr = $directory->path . '/' . $name . '.err';
        try {
            $this->process = proc_open([PHP_BINARY, '-d', 'zend.exception_ignore_args=0',
                $entrypoint ?? dirname(__DIR__, 2) . '/bin/admin-password.php', '--password-stdin'],
                [0 => ['pipe', 'r'], 1 => ['file', $this->stdout, 'w'], 2 => ['file', $this->stderr, 'w']],
                $pipes, $directory->path, array_replace(getenv(), ['TABLO_DB' => $database], $environment));
            if (!is_resource($this->process)) { throw new RuntimeException('Cannot start password fixture'); }
            $this->input = $pipes[0];
            $deadline = microtime(true) + 5;
            do {
                if (str_contains(file_get_contents($this->stdout), 'Installation:')) { return; }
                if (!proc_get_status($this->process)['running']) { break; }
                usleep(20000);
            } while (microtime(true) < $deadline);
            throw new RuntimeException('Password selection barrier failed: ' . file_get_contents($this->stderr));
        } catch (\Throwable $error) { $this->close(); throw $error; }
    }

    public function send(#[\SensitiveParameter] string $input): void
    {
        fwrite($this->input, $input);
        fclose($this->input);
        $this->input = null;
    }

    public function finish(float $timeout = 8): array
    {
        $deadline = microtime(true) + $timeout;
        do {
            $status = proc_get_status($this->process);
            if (!$status['running']) {
                return ['exit_code' => $status['exitcode'], 'stdout' => file_get_contents($this->stdout),
                    'stderr' => file_get_contents($this->stderr)];
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Password process did not finish');
    }

    public function close(): void
    {
        if (is_resource($this->input)) { fclose($this->input); }
        $this->input = null;
        if (!is_resource($this->process)) { return; }
        if (proc_get_status($this->process)['running']) {
            proc_terminate($this->process);
            $deadline = microtime(true) + 2;
            while (proc_get_status($this->process)['running'] && microtime(true) < $deadline) { usleep(20000); }
            if (proc_get_status($this->process)['running']) { proc_terminate($this->process, 9); }
        }
        proc_close($this->process);
        $this->process = null;
    }
}
