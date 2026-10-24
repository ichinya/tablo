<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use Throwable;

/** @phpstan-type Result array{exit_code: ?int, stdout: string, stderr: string, files: array{stdout: string, stderr: string, receipt: string}} */
final class Subprocess
{
    private readonly SubprocessState $state;
    /** @param list<string> $command */
    public function __construct(array $command, TemporaryDirectory $directory, array $environment = [], #[\SensitiveParameter] ?CommandOptions $options = null)
    {
        $options ??= new CommandOptions();
        $this->state = new SubprocessState($directory, $options->stderr);
        SubprocessLaunch::launch($this->state, $command, $environment, $options->cwd, $options->replacementEnvironment);
    }
    /** @param list<string> $command
     * @return Result */
    public static function run(array $command, TemporaryDirectory $directory, array $environment, #[\SensitiveParameter] ?CommandOptions $options = null): array
    {
        $options ??= new CommandOptions();
        $child = new self($command, $directory, $environment, $options);
        try {
            $child->send($options->input);
            return $child->finish($options->timeout);
        } catch (Throwable $error) {
            $directory->retainFailure($error);
            $child->close($error);
            throw $error;
        }
    }
    public function send(#[\SensitiveParameter] string $input): void
    { SubprocessInput::input($this->state, $input); }
    /** Flush one barrier message while retaining this exact owned stdin pipe. */
    public function sendBarrier(#[\SensitiveParameter] string $input): void
    { SubprocessInput::barrier($this->state, $input); }
    public function awaitOutput(string $marker, float $timeout = 5): void
    { SubprocessSelection::waitForOutput($this->state, $marker, $timeout); }
    /** Observe the actual stopped child without closing it or collecting a finish result. */
    public function awaitExit(float $timeout = 5): void
    { SubprocessSelection::waitForExit($this->state, $timeout); }
    /** @return Result */
    public function finish(float $timeout = 8): array
    { return SubprocessCompletion::finish($this->state, $timeout); }
    public function close(?Throwable $primary = null): void
    { SubprocessClosure::close($this->state, $primary); }

}
