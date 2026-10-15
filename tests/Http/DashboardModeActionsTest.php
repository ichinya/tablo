<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\Tests\Support\DashboardModesFixture;
use Tablo\Tests\Support\CookiePolicy;
use Tablo\Tests\Support\WebRequestOptions;
use Testo\Assert;
use Testo\Test;

final class DashboardModeActionsTest
{
    #[Test]
    public function bothModesAssociateNativeCsrfFormsLinksAndPausedControls(): void
    {
        DashboardModesFixture::exercise(static function (DashboardModesFixture $fixture): void {
            $ids = DashboardModesFixture::seed($fixture->web->database(), $fixture->peer->base, 'demo');
            $html = $fixture->web->request('/')['body'];
            foreach ($ids as $id) {
                $form = DashboardModesFixture::elements($html, '//form[@id="check-site-' . $id . '"]');
                Assert::same(count($form), 1);
                Assert::same(\Tablo\Tests\Support\FixtureValue::object($form[0] ?? null)->getAttribute('action'), '/sites/' . $id . '/check');
                Assert::same(\Tablo\Tests\Support\FixtureValue::object($form[0] ?? null)->getAttribute('method'), 'post');
                Assert::same(\Tablo\Tests\Support\FixtureValue::object(DashboardModesFixture::elements($html, '//form[@id="check-site-' . $id . '"]/input[@name="_csrf"]')[0] ?? null)->getAttribute('value'), $fixture->csrf);
                $buttons = DashboardModesFixture::elements($html, '//button[@form="check-site-' . $id . '"]');
                Assert::same(count($buttons), 2);
                foreach ($buttons as $button) { Assert::same($button->getAttribute('type'), 'submit'); Assert::same($button->hasAttribute('disabled'), $id === \Tablo\Tests\Support\FixtureValue::integer(\Tablo\Tests\Support\FixtureValue::at($ids, 4))); }
                Assert::same(count(DashboardModesFixture::elements($html, '//a[@href="/sites/' . $id . '/edit"]')), 2);
            }
            foreach (DashboardModesFixture::elements($html, '//a[@target="_blank"]') as $link) { Assert::same($link->getAttribute('rel'), 'noopener noreferrer'); }
            $before = $fixture->counts();
            Assert::same($fixture->web->request('/sites/' . \Tablo\Tests\Support\FixtureValue::integer(\Tablo\Tests\Support\FixtureValue::at($ids, 0)) . '/check', ['_csrf' => $fixture->csrf])['status'], 303);
            Assert::same($fixture->peerCount(), 2, 'native check contacts independent local health/version peer');
            Assert::same($fixture->counts()['check_history'], $before['check_history'] + 1, 'one native check settles once');
            Assert::same($fixture->web->request('/sites/' . \Tablo\Tests\Support\FixtureValue::integer(\Tablo\Tests\Support\FixtureValue::at($ids, 0)) . '/edit')['status'], 200, 'native edit route');
            $counts = $fixture->counts();
            Assert::same($fixture->web->request('/sites/' . \Tablo\Tests\Support\FixtureValue::integer(\Tablo\Tests\Support\FixtureValue::at($ids, 4)) . '/check', ['_csrf' => $fixture->csrf])['status'], 303);
            Assert::same($fixture->peerCount(), 2, 'forged paused check has no peer traffic');
            Assert::same($fixture->counts(), $counts);
        });
    }

    #[Test]
    public function csrfAnonymousExpiredAndRevokedSessionsCannotCheck(): void
    {
        DashboardModesFixture::exercise(static function (DashboardModesFixture $fixture): void {
            $ids = DashboardModesFixture::seed($fixture->web->database(), $fixture->peer->base, 'demo');
            $path = '/sites/' . \Tablo\Tests\Support\FixtureValue::integer(\Tablo\Tests\Support\FixtureValue::at($ids, 0)) . '/check';
            $before = $fixture->counts();
            Assert::same($fixture->web->request($path, ['_csrf' => 'wrong'])['status'], 419);
            Assert::same($fixture->web->request($path, ['_csrf' => $fixture->csrf], new WebRequestOptions(cookies: CookiePolicy::Omitted))['status'], 303);
            Assert::same($fixture->peerCount(), 0);
            Assert::same($fixture->counts(), $before);
            $fixture->clock(DashboardModesFixture::EPOCH + 1800);
            Assert::same($fixture->web->request($path, ['_csrf' => $fixture->csrf])['status'], 303, 'inclusive idle deadline rejects old native form');
            Assert::same($fixture->peerCount(), 0);
            Assert::same($fixture->counts(), $before);
        });
        DashboardModesFixture::exercise(static function (DashboardModesFixture $fixture): void {
            $ids = DashboardModesFixture::seed($fixture->web->database(), $fixture->peer->base, 'demo');
            $before = $fixture->counts();
            Assert::same($fixture->web->request('/logout', ['_csrf' => $fixture->csrf])['status'], 303);
            Assert::same($fixture->web->request('/sites/' . \Tablo\Tests\Support\FixtureValue::integer(\Tablo\Tests\Support\FixtureValue::at($ids, 0)) . '/check', ['_csrf' => $fixture->csrf])['status'], 303);
            Assert::same($fixture->peerCount(), 0);
            Assert::same($fixture->counts(), $before, 'logout leaves no result/history/incident/outbox writes');
        });
    }
}
