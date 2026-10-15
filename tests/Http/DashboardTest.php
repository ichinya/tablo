<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Testo\Assert;
use Testo\Test;

final class DashboardTest extends DashboardFixtureCase
{



    #[Test]
    public function rendersNeutralFormsAndLoadsAuthenticatedBranches(): void
    {
        $csrf = $this->web->authenticate();
        $form = $this->web->request('/sites/new');
        Assert::true($form['status'] === 200 && str_contains($form['body'], 'Health endpoint'), 'new site form renders');
        Assert::true(str_contains($form['body'], 'placeholder="https://example.com"')
            && str_contains($form['body'], 'placeholder="https://github.com/owner/repository"')
            && !str_contains($form['body'], 'tempalog') && !str_contains($form['body'], 'ichinya'), 'neutral project placeholders');
        Assert::true(preg_match('/id="version_path"[^>]*value=""[^>]*>/', $form['body']) === 1
            && preg_match('/id="version_path"[^>]*required/', $form['body']) === 0, 'version field defaults to blank and is optional');
        Assert::true(str_contains($form['body'], 'name="github_token" value=""') && str_contains($form['body'], 'Получить ветки'), 'repository token and branch controls render');
        Assert::true(!str_contains($form['body'], 'токен установки') && !str_contains($form['body'], \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-token')), 'environment token neither advertised nor exposed');
        Assert::true($this->web->request('/sites/branches', ['repository' => 'fixture/private', 'github_token' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-token')])['status'] === 419, 'branch loading requires CSRF');
        $lookup = ['_csrf' => $csrf, 'repository' => 'https://github.com/fixture/private.git', 'github_token' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-token')];
        $branches = $this->web->request('/sites/branches', $lookup);
        $json = json_decode($branches['body'], associative: true, depth: 16, flags: JSON_THROW_ON_ERROR);
        Assert::true($branches['status'] === 200 && $json['branches'] === ['main', 'develop', 'feature/login']
            && $json['repository'] === 'fixture/private' && !str_contains($branches['body'], \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-token')), 'authenticated branch lookup normalizes URL and does not echo token');
        $withoutToken = array_replace($lookup, ['github_token' => '']);
        Assert::true($this->web->request('/sites/branches', $withoutToken)['status'] === 422, 'legacy environment token does not grant private repository access');
        Assert::true($this->web->request('/sites/branches', array_replace($withoutToken, ['repository' => 'fixture/public']))['status'] === 200, 'public repository branches load without a token');
        foreach ([\Tablo\Tests\Support\FixtureCredentials::value(label: 'invalid-fixture-token') => '401', \Tablo\Tests\Support\FixtureCredentials::value(label: 'forbidden-fixture-token') => '403', '' => '404'] as $value => $status) {
            $errorResponse = $this->web->request('/sites/branches', array_replace($lookup, ['github_token' => $value]));
            Assert::true($errorResponse['status'] === 422 && str_contains($errorResponse['body'], $status)
                && ($value === '' || !str_contains($errorResponse['body'], $value)), 'GitHub ' . $status . ' error is safe and actionable');
        }
    }
}
