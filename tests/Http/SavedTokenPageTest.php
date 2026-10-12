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
        $web = \Tablo\Tests\Support\FixtureValue::object($this->web);
        $csrf = $web->authenticate();
        $private = array_replace($this->site($csrf), ['name' => 'Private fixture', 'url' => 'http://127.0.0.1',
            'repository' => 'fixture/private', 'branch' => 'feature/login', 'github_token' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-token')]);
        $db = $web->database();
        $settings = $web->request('/settings');
        Assert::true($settings['status'] === 200 && str_contains($settings['body'], 'Пока нет сохранённых токенов'), 'settings token list renders empty state');
        $shared = ['_csrf' => $csrf, 'name' => 'Shared <token>', 'provider' => 'github', 'token' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-token')];
        (new SavedTokenCreateAssertions($web))->admitted($shared);
        $credential = \Tablo\Tests\Support\FixtureValue::fetched(\Tablo\SqlRows::one(\Tablo\SqlStatement::checked($db->query('SELECT * FROM git_tokens'))->fetch(PDO::FETCH_ASSOC), \Tablo\SqlGitTokensCreatedAtEncryptedTokenIdRow::checked(...)));
        $tokenId = (string) $credential['id'];
        (new SavedTokenPageAssertions($web))->privateMetadata($credential, $tokenId);
        $selectedLookup = ['_csrf' => $csrf, 'repository' => 'fixture/private', 'git_token_id' => $tokenId];
        Assert::true($web->request('/sites/branches', $selectedLookup)['status'] === 200, 'branch lookup uses selected shared token');
        Assert::true($web->request('/sites/branches', array_replace($selectedLookup, ['git_token_id' => '999']))['status'] === 422, 'missing saved token does not fall back silently');
        $sharedSite = array_replace($private, ['name' => 'Shared project', 'github_token' => '', 'git_token_id' => $tokenId, 'version_path' => '']);
        Assert::true($web->request('/sites/new', array_replace($sharedSite, ['github_token' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-token')]))['status'] === 422, 'ambiguous manual and saved token rejected');
        Assert::true($web->request('/sites/new', $sharedSite)['status'] === 303, 'project accepts saved token and no version endpoint');
        $poolSite = \Tablo\Tests\Support\FixtureValue::fetched(\Tablo\SqlRows::one(\Tablo\SqlStatement::checked($db->query("SELECT * FROM sites WHERE name = 'Shared project'"))->fetch(PDO::FETCH_ASSOC), \Tablo\SqlSitesBranchCheckedAtComparisonModeRow::checked(...)));
        $projectId = (string) $poolSite['id'];
        (new SavedTokenPageAssertions($web))->admittedProject($poolSite, $tokenId, $credential, $csrf);
        (new SavedTokenEditAssertions($web))->rotate($shared, $credential, [$projectId, $tokenId], $csrf);
        (new SavedTokenDetachAssertions($web))->detached($selectedLookup, $sharedSite, [$projectId, $tokenId], $csrf);

    }
}
