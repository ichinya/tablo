<?php
declare(strict_types=1);

namespace Tablo;

final class WorkerHooks
{
    /**
     * @param (\Closure(): GitHubConnection)|null $connection
     * @param (\Closure(): (int|float))|null $clock
     * @param (\Closure(float): void)|null $sleep
     * @param (\Closure(string): void)|null $output
     * @param (\Closure(): int)|null $epoch
     */
    public function __construct(#[\SensitiveParameter] public readonly ?\Closure $connection = null, public readonly ?\Closure $clock = null,
        public readonly ?\Closure $sleep = null, public readonly ?\Closure $output = null, public readonly ?\Closure $epoch = null) {}
}
