<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\Tests\Support\SessionFixture;
use Tablo\Tests\Support\WebRequestOptions;
use Testo\Assert;

final class SessionClockAction
{
    public function __construct(private readonly SessionFixture $fixture, private readonly WebRequestOptions $options) {}

    public function run(string $race): void
    {
        if ($race === 'rollback') {
            $this->fixture->clock(SessionFixture::START + 1);
            Assert::same($this->fixture->web->request('/login', options: $this->options)['status'], 303);
            $this->fixture->clock(SessionFixture::START);
            return;
        }
        $this->fixture->clock(SessionFixture::START + ($race === 'idle' ? 120 : 43_200));
        Assert::same($this->fixture->web->request('/settings', options: $this->options)['status'], 303);
    }
}
