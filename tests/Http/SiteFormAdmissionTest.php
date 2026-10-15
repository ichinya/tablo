<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Testo\Assert;
use Testo\Test;

final class SiteFormAdmissionTest extends DashboardFixtureCase
{




    #[Test]
    public function validatesCrudEscapesXssAndGuardsPausedChecks(): void
    {
        $csrf = \Tablo\Tests\Support\FixtureValue::object($this->web)->authenticate();
        $site = ['_csrf' => $csrf, 'name' => '<img src=x onerror=alert(1)>', 'url' => 'https://example.com',
            'repository' => 'https://github.com/ichinya/lekalo', 'branch' => 'main', 'health_path' => '/up',
            'version_path' => '/version', 'comparison_mode' => 'release', 'enabled' => '1', 'sort_order' => '0'];
        Assert::true(\Tablo\Tests\Support\FixtureValue::object($this->web)->request('/sites/new', array_replace($site, ['url' => 'file:///etc/passwd']))['status'] === 422, 'invalid site returns 422');
        Assert::true(\Tablo\Tests\Support\FixtureValue::object($this->web)->request('/sites/new', array_replace($site, ['branch' => 'v1.0.0']))['status'] === 422, 'save refuses a tag or absent branch');
        Assert::true(\Tablo\Tests\Support\FixtureValue::object($this->web)->request('/sites/new', $site)['status'] === 303, 'site create persists');
        $dashboard = \Tablo\Tests\Support\FixtureValue::object($this->web)->request('/');
        Assert::true(str_contains($dashboard['body'], '&lt;img') && !str_contains($dashboard['body'], '<img src=x'), 'stored XSS escaped by Volt');
        Assert::true(\Tablo\Tests\Support\FixtureValue::object($this->web)->request('/sites/1/edit')['status'] === 200, 'edit form renders');
        Assert::true(\Tablo\Tests\Support\FixtureValue::object($this->web)->request('/sites/1/edit', array_replace($site, ['name' => 'Renamed site', 'comparison_mode' => 'branch']))['status'] === 303, 'site update persists');
        Assert::true(str_contains(\Tablo\Tests\Support\FixtureValue::object($this->web)->request('/')['body'], 'Renamed site'), 'updated state visible');
        Assert::true(\Tablo\Tests\Support\FixtureValue::object($this->web)->request('/sites/1/check', ['_csrf' => 'invalid'])['status'] === 419, 'manual check requires CSRF');
        $paused = array_replace($site, ['name' => 'Renamed site']);
        unset($paused['enabled']);
        Assert::true(\Tablo\Tests\Support\FixtureValue::object($this->web)->request('/sites/1/edit', $paused)['status'] === 303
            && \Tablo\Tests\Support\FixtureValue::object($this->web)->request('/sites/1/check', ['_csrf' => $csrf])['status'] === 303
            && str_contains(\Tablo\Tests\Support\FixtureValue::object($this->web)->request('/')['body'], 'Проверки этого сайта на паузе.'), 'disabled site skips network checks');
        Assert::true(\Tablo\Tests\Support\FixtureValue::object($this->web)->request('/sites/1/delete')['status'] === 200 && str_contains(\Tablo\Tests\Support\FixtureValue::object($this->web)->request('/')['body'], 'Renamed site'), 'GET deletion only confirms');
        Assert::true(\Tablo\Tests\Support\FixtureValue::object($this->web)->request('/sites/1/delete', ['_csrf' => 'invalid'])['status'] === 419 && str_contains(\Tablo\Tests\Support\FixtureValue::object($this->web)->request('/')['body'], 'Renamed site'), 'delete requires CSRF');
        Assert::true(\Tablo\Tests\Support\FixtureValue::object($this->web)->request('/sites/999/edit')['status'] === 404, 'missing site returns 404');
        Assert::true(\Tablo\Tests\Support\FixtureValue::object($this->web)->request('/sites/1/delete', ['_csrf' => $csrf])['status'] === 303 && !str_contains(\Tablo\Tests\Support\FixtureValue::object($this->web)->request('/')['body'], 'Renamed site'), 'confirmed POST deletes');
    }
}
