<?php
declare(strict_types=1);

namespace Tablo;

final class WorkerHooks
{
    public function __construct(#[\SensitiveParameter] public readonly ?\Closure $connection = null, public readonly ?\Closure $clock = null,
        public readonly ?\Closure $sleep = null, public readonly ?\Closure $output = null) {}
}
