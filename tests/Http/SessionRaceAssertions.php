<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\SqlStatement;
use Tablo\Tests\Support\FixtureValue;
use Tablo\Tests\Support\SessionFixture;
use Tablo\Tests\Support\WebRequestOptions;
use Testo\Assert;

/** @phpstan-import-type Result from \Tablo\Tests\Support\Subprocess */
final class SessionRaceAssertions
{
    public function __construct(private readonly SessionFixture $fixture, private readonly WebRequestOptions $options) {}

    /** @param Result $result */
    public function result(string $race, array $result): void
    {
        Assert::same($result['exit_code'], 0, $race . ': real HTTP child closure');
        $observation = \Tablo\JsonRecord::decode($result['stdout']);
        Assert::same(FixtureValue::at($observation, 'status'), 303);
        Assert::same(FixtureValue::at($observation, 'loginRedirect'), $race !== 'navigation');
        Assert::same(FixtureValue::at($observation, 'sessionCookieIssued'), false, 'internal reopening cannot replace the browser cookie');
        Assert::same(FixtureValue::at($observation, 'cachePolicyPreserved'), true, 'internal reopening preserves explicit no-store');
        $web = $this->fixture->web;
        $history = (int) SqlStatement::checked($web->database()->query('SELECT count(*) FROM check_history'))->fetchColumn();
        /** @var string|null|false $checked SQLite timestamp or the distinct no-row sentinel. */
        $checked = SqlStatement::checked($web->database()->query('SELECT checked_at FROM sites WHERE id=1'))->fetchColumn();
        Assert::same($history, $race === 'navigation' ? 1 : 0);
        Assert::same($checked !== null, $race === 'navigation');
        Assert::same((int) SqlStatement::checked($web->database()->query('SELECT count(*) FROM incidents'))->fetchColumn(), 0);
        Assert::same((int) SqlStatement::checked($web->database()->query('SELECT count(*) FROM notification_slots'))->fetchColumn(), 0);
        $this->session($race);
    }

    private function session(string $race): void
    {
        $web = $this->fixture->web;
        if ($race === 'login') {
            Assert::same($web->request('/settings', options: $this->options)['status'], 200, 'concurrent new authentication survives stale request');
            Assert::same($this->fixture->timestamps()['authenticated_at'], SessionFixture::START + 1);
        }
        if ($race === 'navigation') { return; }
        $files = glob($web->directory->path . '/runtime/sessions/sess_*');
        if ($files === false) { throw new \RuntimeException('Cannot enumerate owned session files.'); }
        foreach ($files as $file) {
            if ($race === 'decrease' && $file === $this->fixture->sessionFile('policy-cookies')) { continue; }
            Assert::false(str_contains(\Tablo\RequiredFile::bytes(file_get_contents($file)), 'notice|'), 'no stale post-network notice');
        }
    }
}
