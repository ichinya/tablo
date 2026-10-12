<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use PDO;
use Testo\Assert;
use Testo\Test;

final class SavedTokenPageTest extends DashboardFixtureCase
{




    #[Test]
    public function managesSharedTokensAndOptionalVersionProjects(): void
    {
        $csrf = $this->web->authenticate();
        $private = array_replace($this->site($csrf), ['name' => 'Private fixture', 'url' => 'http://127.0.0.1',
            'repository' => 'fixture/private', 'branch' => 'feature/login', 'github_token' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-token')]);
        $db = $this->web->database();
        $settings = $this->web->request('/settings');
        Assert::true($settings['status'] === 200 && str_contains($settings['body'], 'Пока нет сохранённых токенов'), 'settings token list renders empty state');
        $shared = ['_csrf' => $csrf, 'name' => 'Shared <token>', 'provider' => 'github', 'token' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-token')];
        (new SavedTokenCreateAssertions($this->web))->admitted($shared);
        $credential = $db->query('SELECT * FROM git_tokens')->fetch(PDO::FETCH_ASSOC);
        $tokenId = (string) $credential['id'];
        (new SavedTokenPageAssertions($this->web))->privateMetadata($credential, $tokenId);
        $selectedLookup = ['_csrf' => $csrf, 'repository' => 'fixture/private', 'git_token_id' => $tokenId];
        Assert::true($this->web->request('/sites/branches', $selectedLookup)['status'] === 200, 'branch lookup uses selected shared token');
        Assert::true($this->web->request('/sites/branches', array_replace($selectedLookup, ['git_token_id' => '999']))['status'] === 422, 'missing saved token does not fall back silently');
        $sharedSite = array_replace($private, ['name' => 'Shared project', 'github_token' => '', 'git_token_id' => $tokenId, 'version_path' => '']);
        Assert::true($this->web->request('/sites/new', array_replace($sharedSite, ['github_token' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-token')]))['status'] === 422, 'ambiguous manual and saved token rejected');
        Assert::true($this->web->request('/sites/new', $sharedSite)['status'] === 303, 'project accepts saved token and no version endpoint');
        $poolSite = $db->query("SELECT * FROM sites WHERE name = 'Shared project'")->fetch(PDO::FETCH_ASSOC);
        $projectId = (string) $poolSite['id'];
        (new SavedTokenPageAssertions($this->web))->admittedProject($poolSite, $tokenId, $credential, $csrf);
        (new SavedTokenEditAssertions($this->web))->rotate($shared, $credential, [$projectId, $tokenId], $csrf);
        (new SavedTokenDetachAssertions($this->web))->detached($selectedLookup, $sharedSite, [$projectId, $tokenId], $csrf);

    }
}
