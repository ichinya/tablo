<?php
declare(strict_types=1);

namespace Tablo;

use Phalcon\Http\Response;

/** Preserve the controller's callback scope across Micro's closure rebinding. */
final class WebAction
{
    /** @param \Closure(mixed...): Response $handler The registered route arguments stay heterogeneous. */
    public function __construct(private readonly \Closure $handler) {}

    public function __invoke(mixed ...$arguments): Response
    {
        return ($this->handler)(...$arguments);
    }
}
