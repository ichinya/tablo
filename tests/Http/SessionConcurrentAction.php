<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\Tests\Support\CommandOptions;
use Tablo\Tests\Support\FixtureCredentials;
use Tablo\Tests\Support\SessionFixture;
use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\WebFixture;
use Tablo\Tests\Support\WebRequestOptions;
use Testo\Assert;

final class SessionConcurrentAction
{
    private readonly WebRequestOptions $options;
    private readonly WebRequestOptions $policyOptions;
    private ?string $policyCsrf = null;

    public function __construct(private readonly SessionFixture $fixture, string $base)
    {
        $this->options = new WebRequestOptions(base: $base);
        $this->policyOptions = new WebRequestOptions(base: $base, jar: 'policy-cookies');
    }

    public function prepare(string $race): void
    {
        if ($race !== 'decrease') { return; }
        $web = $this->fixture->web;
        $login = $web->request('/login', options: $this->policyOptions);
        Assert::same($web->request('/login', ['_csrf' => WebFixture::csrf($login), 'password' => FixtureCredentials::value('fixture-password')], $this->policyOptions)['status'], 303);
        $this->policyCsrf = WebFixture::csrf($web->request('/', options: $this->policyOptions));
    }

    public function run(string $race, string $csrf): void
    {
        match ($race) {
            'idle', 'absolute', 'rollback' => (new SessionClockAction($this->fixture, $this->options))->run($race),
            'logout' => $this->logout($csrf),
            'password' => $this->password(),
            'login' => $this->login($csrf),
            'decrease' => $this->decrease(),
            default => $this->navigation(),
        };
    }

    private function logout(string $csrf): void
    { Assert::same($this->fixture->web->request('/logout', ['_csrf' => $csrf], $this->options)['status'], 303); }

    private function password(): void
    {
        $web = $this->fixture->web;
        $reset = Subprocess::run([PHP_BINARY, dirname(__DIR__, levels: 2) . '/bin/admin-password.php', '--password-stdin'], $web->directory,
            ['TABLO_DB' => $web->directory->path . '/test.sqlite'], new CommandOptions(input: FixtureCredentials::confirmationInput('replacement-secret')));
        Assert::same($reset['exit_code'], 0, 'actual password CLI revocation');
    }

    private function login(string $csrf): void
    {
        $this->fixture->clock(SessionFixture::START + 1);
        Assert::same($this->fixture->web->request('/login', ['_csrf' => $csrf, 'password' => FixtureCredentials::value('fixture-password')], $this->options)['status'], 303);
    }

    private function decrease(): void
    {
        $this->fixture->clock(SessionFixture::START + 100);
        Assert::same($this->fixture->web->request('/settings/session-timeout', ['_csrf' => $this->policyCsrf, 'session_idle_timeout_minutes' => '1'], $this->policyOptions)['status'], 303);
    }

    private function navigation(): void
    { Assert::same($this->fixture->web->request('/settings', options: $this->options)['status'], 200, 'independent request acquired native lock while network is blocked'); }
}
