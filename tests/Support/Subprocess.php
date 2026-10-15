<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use RuntimeException;
use Throwable;

/** Owns one direct child, not an arbitrary descendant tree. Streams are files, never EOF barriers. */
final class Subprocess
{
    private mixed $process = null;
    private mixed $input = null;
    private ?int $exitCode = null;
    private ?int $pid = null;
    private bool $running = false;
    private bool $closed = false;
    private array $events = [];
    private array $secrets = [];
    private readonly array $files;

    public function __construct(array $command, private readonly TemporaryDirectory $directory,
        array $environment = [], ?string $cwd = null, private readonly bool $failedStderr = false)
    {
        $name = $directory->path . '/command-' . bin2hex(random_bytes(12));
        $this->files = ['stdout' => $name . '.out', 'stderr' => $name . '.err', 'receipt' => $name . '.json'];
        foreach (['stdout', 'stderr'] as $stream) {
            $file = fopen($this->files[$stream], 'x');
            if ($file === false) { throw new RuntimeException('Cannot reserve test command stream'); }
            fclose($file);
            chmod($this->files[$stream], 0600);
        }
        $this->event('spawn', ['argument_count' => count($command)]); // no argv, environment or input values
        try {
            set_error_handler(static function (): never { throw new RuntimeException('Test command spawn failed'); });
            try { $this->process = proc_open($command, [0 => ['pipe', 'r'],
                1 => ['file', $this->files['stdout'], 'a'],
                2 => $failedStderr ? ['pipe', 'w'] : ['file', $this->files['stderr'], 'a']],
                $pipes, $cwd ?? dirname(__DIR__, 2), array_replace(getenv(), $environment), ['bypass_shell' => true]); }
            finally { restore_error_handler(); }
            if (!is_resource($this->process)) { throw new RuntimeException('Cannot start test command'); }
            $this->input = $pipes[0];
            $directory->holdProcess();
            $this->running = true;
            if ($failedStderr) {
                // The fixture waits for stdin before any diagnostic write. Closing
                // this OS pipe reader before send() is deterministic on both hosts.
                fclose($pipes[2]);
                $this->event('stderr-reader-closed-before-input');
            }
            $this->observe();
        } catch (Throwable $error) { $this->fail('spawn', $error); }
    }

    public static function run(array $command, TemporaryDirectory $directory, array $environment, float $timeout = 8,
        #[\SensitiveParameter] string $input = '', ?string $cwd = null, bool $failedStderr = false): array
    {
        $child = new self($command, $directory, $environment, $cwd, $failedStderr);
        try {
            $child->send($input);
            return $child->finish($timeout);
        } catch (Throwable $error) {
            $directory->retainFailure($error);
            $child->close($error);
            throw $error;
        }
    }

    public function send(#[\SensitiveParameter] string $input): void
    {
        $this->secrets = array_values(array_filter(preg_split('/[\r\n]+/', $input), static fn(string $line): bool => $line !== ''));
        try {
            if (!is_resource($this->input)) { throw new RuntimeException('Test command input already closed'); }
            $offset = 0;
            while ($offset < strlen($input)) {
                set_error_handler(static function (): never { throw new RuntimeException('Test command input failed'); });
                try { $written = fwrite($this->input, substr($input, $offset)); }
                finally { restore_error_handler(); }
                if ($written === false || $written === 0) { throw new RuntimeException('Cannot write test command input'); }
                $offset += $written;
            }
            fclose($this->input);
            $this->input = null;
            $this->event('input-closed');
        } catch (Throwable $error) { $this->fail('input', $error); }
    }

    public function awaitOutput(string $marker, float $timeout = 5): void
    {
        $this->event('selection', ['timeout_seconds' => $timeout]);
        $deadline = microtime(true) + $timeout;
        do {
            if (str_contains(file_get_contents($this->files['stdout']), $marker)) { return; }
            if (!$this->observe()) { break; }
            usleep(20_000);
        } while (microtime(true) < $deadline);
        $this->fail('selection', new RuntimeException('Password selection barrier failed'));
    }

    public function finish(float $timeout = 8): array
    {
        $this->event('finish', ['timeout_seconds' => $timeout]);
        $deadline = microtime(true) + $timeout;
        do {
            if (!$this->observe()) {
                $this->close();
                return ['exit_code' => $this->exitCode, 'stdout' => file_get_contents($this->files['stdout']),
                    'stderr' => file_get_contents($this->files['stderr']), 'files' => $this->files];
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);
        $this->fail('timeout', new RuntimeException('Test command timed out'));
    }

    private function observe(): bool
    {
        if (!is_resource($this->process) || !$this->running) { return false; }
        $status = proc_get_status($this->process);
        $this->pid ??= $status['pid'];
        $this->running = $status['running'];
        // PHP 8.2 exposes the actual exit only on the first stopped observation.
        if (!$this->running) {
            // Windows native crash statuses can be negative signed integers. Only -1
            // means unavailable; preserve actual OS statuses instead of losing them.
            if ($status['exitcode'] !== -1) { $this->exitCode ??= $status['exitcode']; }
            $this->event('root-exit', ['exit_code' => $this->exitCode, 'native_exit_code' => $status['exitcode'], 'signal' => $status['termsig']]);
        }
        return $this->running;
    }

    public function close(?Throwable $primary = null): void
    {
        if ($this->closed) { return; }
        try {
            if (is_resource($this->input)) { fclose($this->input); }
            $this->input = null;
            if (is_resource($this->process)) {
                foreach ([15, 9] as $signal) {
                    if (!$this->observe()) { break; }
                    $this->event('terminate-direct-child', ['signal' => $signal]);
                    proc_terminate($this->process, $signal);
                    $deadline = microtime(true) + 2;
                    while ($this->observe() && microtime(true) < $deadline) { usleep(20_000); }
                }
                if ($this->observe()) { throw new RuntimeException('Direct test child closure unconfirmed'); }
                $exit = proc_close($this->process);
                $this->event('proc-close', ['native_exit_code' => $exit]);
                if ($exit !== -1) { $this->exitCode ??= $exit; }
                $this->process = null;
                $this->directory->releaseProcess();
            }
            $this->closed = true;
            $this->event('closed');
            $this->receipt();
        } catch (Throwable $cleanup) {
            $primaryExists = $primary !== null || $this->directory->hasFailure();
            $this->directory->retainFailure($primary ?? $cleanup);
            $this->directory->recordCleanupFailure($cleanup);
            if (!$primaryExists) { throw $cleanup; }
        }
    }

    private function fail(string $stage, Throwable $cause): never
    {
        $this->event('failure', ['stage' => $stage, 'type' => $cause::class]);
        $this->directory->retainFailure($cause);
        $this->close($cause);
        $message = match ($stage) {
            'spawn' => 'Cannot start test command', 'input' => 'Cannot write test command input',
            'selection' => 'Password selection barrier failed', default => 'Test command timed out',
        };
        throw new RuntimeException($message . '; stage=' . $stage . '; ' . $this->diagnostic());
    }

    private function diagnostic(): string
    {
        $parts = ['receipt=' . $this->files['receipt']];
        foreach (['stdout', 'stderr'] as $stream) {
            $bytes = file_get_contents($this->files[$stream]);
            $bytes = str_replace($this->secrets, '[stdin redacted]', $bytes);
            $bytes = preg_replace('/\$(?:2[aby]|argon2id|argon2i)\$[^\s"<>]+/', '[hash redacted]', $bytes);
            $parts[] = $stream . '=' . json_encode(substr($bytes, 0, 2048), JSON_INVALID_UTF8_SUBSTITUTE);
        }
        return implode('; ', $parts);
    }

    private function event(string $stage, array $detail = []): void
    {
        $this->events[] = ['utc' => gmdate('c'), 'stage' => $stage, ...$detail];
    }

    private function receipt(): void
    {
        $receipt = ['pid' => $this->pid, 'exit_code' => $this->exitCode, 'root_exit_observed' => $this->pid !== null && !$this->running,
            'closed' => $this->closed, 'ownership' => 'direct-child-only', 'stream_eof' => 'not used; file backed',
            'events' => $this->events, 'streams' => []];
        foreach (['stdout', 'stderr'] as $stream) {
            $receipt['streams'][$stream] = ['path' => $this->files[$stream], 'sha256' => hash_file('sha256', $this->files[$stream])];
        }
        if ($this->failedStderr) {
            $receipt['streams']['stderr'] += ['capture' => 'unavailable; OS pipe reader deliberately closed',
                'bytes' => null, 'errno' => null];
        }
        $file = fopen($this->files['receipt'], 'x');
        if ($file === false) { throw new RuntimeException('Cannot reserve test command receipt'); }
        try { fwrite($file, json_encode($receipt, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n"); }
        finally { fclose($file); }
        chmod($this->files['receipt'], 0600);
    }
}
