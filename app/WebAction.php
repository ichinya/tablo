<?php
declare(strict_types=1);

namespace Tablo;

use Phalcon\Http\Response;

/** Preserve the controller's callback scope across Micro's closure rebinding. */
final class WebAction
{
    /** @param \Closure(mixed...): Response $handler The registered route arguments stay heterogeneous. */
    public function __construct(private readonly \Closure $handler,
        private readonly SessionActivity $activity = SessionActivity::Automatic,
        public readonly SessionResponse $response = SessionResponse::Html) {}

    public function __invoke(mixed ...$arguments): Response
    {
        WebSession::classify($this->activity);
        try {
            $response = ($this->handler)(...$arguments);
            if ($this->activity === SessionActivity::HumanPage && $response->getStatusCode() === 200) { WebSession::recordActivity(); }
            return $response;
        } finally { WebSession::classify(SessionActivity::Automatic); }
    }
}
