<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use Throwable;

final class Subprocess
{
    private readonly SubprocessState $state;
    public function __construct(array $command, TemporaryDirectory $directory, array $environment = [], #[\SensitiveParameter] ?CommandOptions $options = null)
    {
        $this->state = new SubprocessState($directory, ($options ?? new CommandOptions())->stderr);
        SubprocessLaunch::launch($this->state, $command, $environment, $options?->cwd);
    }
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
    public function awaitOutput(string $marker, float $timeout = 5): void
    { SubprocessSelection::waitForOutput($this->state, $marker, $timeout); }
    public function finish(float $timeout = 8): array
    { return SubprocessCompletion::finish($this->state, $timeout); }
    public function close(?Throwable $primary = null): void
    { SubprocessClosure::close($this->state, $primary); }

}
