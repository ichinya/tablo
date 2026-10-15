<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use PDO;
use Testo\Assert;
use Testo\Test;

final class SiteCredentialPageTest extends DashboardFixtureCase
{



    #[Test]
    public function encryptsPreservesReplacesAndRemovesProjectTokens(): void
    {
        $csrf = $this->web->authenticate();
        $site = $this->site($csrf);
        $private = array_replace($site, ['name' => 'Private fixture', 'url' => 'http://127.0.0.1',
            'repository' => 'fixture/private', 'branch' => 'feature/login', 'github_token' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-token')]);
        Assert::true($this->web->request('/sites/new', $private)['status'] === 303, 'private repository and slash branch save with token');
        $db = $this->web->database();
        $row = $db->query('SELECT * FROM sites')->fetch(PDO::FETCH_ASSOC);
        $id = $row['id'];
        (new SiteCredentialPageAssertions($this->web))->privateLookup($csrf, $row, $id);
        $blank = array_replace($private, ['github_token' => '']);
        Assert::true($this->web->request('/sites/' . $id . '/edit', $blank)['status'] === 303
            && hash_equals($db->query('SELECT github_token FROM sites')->fetchColumn(), $row['github_token']) , 'blank token preserves existing ciphertext and access');
        $failure = $this->web->request('/sites/' . $id . '/edit', array_replace($private, ['github_token' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'invalid-fixture-token')]));
        Assert::true($failure['status'] === 422 && !str_contains($failure['body'], \Tablo\Tests\Support\FixtureCredentials::value(label: 'invalid-fixture-token'))
            && hash_equals($db->query('SELECT github_token FROM sites')->fetchColumn(), $row['github_token']) , 'invalid replacement neither persists nor reflects secret');
        Assert::true($this->web->request('/sites/' . $id . '/check', ['_csrf' => $csrf])['status'] === 303
            && $db->query('SELECT latest_commit FROM sites')->fetchColumn() === str_repeat('a', times: 40), 'dashboard checks use site token');
        Assert::true($this->web->request('/sites/' . $id . '/edit', array_replace($private, ['github_token' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'replacement-token')]))['status'] === 303
            && !hash_equals($db->query('SELECT github_token FROM sites')->fetchColumn(), $row['github_token']) , 'token replacement persists');
        $remove = array_replace($blank, ['repository' => 'ichinya/lekalo', 'remove_github_token' => \Tablo\FormCheckbox::VALUE]);
        Assert::true($this->web->request('/sites/' . $id . '/edit', $remove)['status'] === 303
            && $db->query('SELECT github_token FROM sites')->fetchColumn() === null, 'token removal persists');
    }
}
