<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use PDO;
use Testo\Assert;
use Testo\Test;
use Testo\Lifecycle\BeforeTest;
use Testo\Lifecycle\AfterTest;
use Tablo\Tests\Support\WebFixture;

final class DashboardTest
{
    private ?WebFixture $web = null;

    #[BeforeTest]
    public function start(): void { $this->web = new WebFixture(); }

    #[AfterTest]
    public function stop(): void
    {
        $this->web?->close();
        $this->web = null;
    }

    private function site(string $csrf): array
    {
        return ['_csrf' => $csrf, 'name' => 'Fixture project', 'url' => 'https://example.com',
            'repository' => 'https://github.com/ichinya/lekalo', 'branch' => 'main', 'health_path' => '/up',
            'version_path' => '/version', 'comparison_mode' => 'release', 'enabled' => '1', 'sort_order' => '0'];
    }

    #[Test]
    public function setsUpAdministratorAndRotatesProtectedSession(): void
    {
        $setup = $this->web->request('/setup');
        Assert::true($setup['status'] === 200 && str_contains($setup['body'], 'Создать панель'), 'Volt setup page renders');
        Assert::true(str_contains(strtolower($setup['headers']), 'httponly') && str_contains(strtolower($setup['headers']), 'samesite=strict'), 'session cookie protections');
        Assert::true(str_contains($setup['headers'], "frame-ancestors 'none'"), 'CSP present');
        $csrf = WebFixture::csrf($setup);
        Assert::true($this->web->request('/setup', ['password' => 'fixture-password', 'confirmation' => 'fixture-password'])['status'] === 419, 'setup rejects missing CSRF');
        $result = $this->web->request('/setup', ['_csrf' => $csrf, 'password' => 'fixture-password', 'confirmation' => 'fixture-password']);
        Assert::true($result['status'] === 303 && str_contains($result['headers'], 'Location: /'), 'setup creates admin and authenticates');
        $dashboard = $this->web->request('/');
        Assert::true($dashboard['status'] === 200 && str_contains($dashboard['body'], 'Ваши сайты.'), 'dashboard renders');
        $csrf = WebFixture::csrf($dashboard);
        Assert::true($csrf !== WebFixture::csrf($setup), 'authentication rotates CSRF');
    }

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
        Assert::true(!str_contains($form['body'], 'токен установки') && !str_contains($form['body'], 'fixture-token'), 'environment token neither advertised nor exposed');
        Assert::true($this->web->request('/sites/branches', ['repository' => 'fixture/private', 'github_token' => 'fixture-token'])['status'] === 419, 'branch loading requires CSRF');
        $lookup = ['_csrf' => $csrf, 'repository' => 'https://github.com/fixture/private.git', 'github_token' => 'fixture-token'];
        $branches = $this->web->request('/sites/branches', $lookup);
        $json = json_decode($branches['body'], true, 16, JSON_THROW_ON_ERROR);
        Assert::true($branches['status'] === 200 && $json['branches'] === ['main', 'develop', 'feature/login']
            && $json['repository'] === 'fixture/private' && !str_contains($branches['body'], 'fixture-token'), 'authenticated branch lookup normalizes URL and does not echo token');
        $withoutToken = array_replace($lookup, ['github_token' => '']);
        Assert::true($this->web->request('/sites/branches', $withoutToken)['status'] === 422, 'legacy environment token does not grant private repository access');
        Assert::true($this->web->request('/sites/branches', array_replace($withoutToken, ['repository' => 'fixture/public']))['status'] === 200, 'public repository branches load without a token');
        foreach (['invalid-fixture-token' => '401', 'forbidden-fixture-token' => '403', '' => '404'] as $value => $status) {
            $errorResponse = $this->web->request('/sites/branches', array_replace($lookup, ['github_token' => $value]));
            Assert::true($errorResponse['status'] === 422 && str_contains($errorResponse['body'], $status)
                && ($value === '' || !str_contains($errorResponse['body'], $value)), 'GitHub ' . $status . ' error is safe and actionable');
        }
    }

    #[Test]
    public function validatesCrudEscapesXssAndGuardsPausedChecks(): void
    {
        $csrf = $this->web->authenticate();
        $site = ['_csrf' => $csrf, 'name' => '<img src=x onerror=alert(1)>', 'url' => 'https://example.com',
            'repository' => 'https://github.com/ichinya/lekalo', 'branch' => 'main', 'health_path' => '/up',
            'version_path' => '/version', 'comparison_mode' => 'release', 'enabled' => '1', 'sort_order' => '0'];
        Assert::true($this->web->request('/sites/new', array_replace($site, ['url' => 'file:///etc/passwd']))['status'] === 422, 'invalid site returns 422');
        Assert::true($this->web->request('/sites/new', array_replace($site, ['branch' => 'v1.0.0']))['status'] === 422, 'save refuses a tag or absent branch');
        Assert::true($this->web->request('/sites/new', $site)['status'] === 303, 'site create persists');
        $dashboard = $this->web->request('/');
        Assert::true(str_contains($dashboard['body'], '&lt;img') && !str_contains($dashboard['body'], '<img src=x'), 'stored XSS escaped by Volt');
        Assert::true($this->web->request('/sites/1/edit')['status'] === 200, 'edit form renders');
        Assert::true($this->web->request('/sites/1/edit', array_replace($site, ['name' => 'Renamed site', 'comparison_mode' => 'branch']))['status'] === 303, 'site update persists');
        Assert::true(str_contains($this->web->request('/')['body'], 'Renamed site'), 'updated state visible');
        Assert::true($this->web->request('/sites/1/check', ['_csrf' => 'invalid'])['status'] === 419, 'manual check requires CSRF');
        $paused = array_replace($site, ['name' => 'Renamed site']);
        unset($paused['enabled']);
        Assert::true($this->web->request('/sites/1/edit', $paused)['status'] === 303
            && $this->web->request('/sites/1/check', ['_csrf' => $csrf])['status'] === 303
            && str_contains($this->web->request('/')['body'], 'Проверки этого сайта на паузе.'), 'disabled site skips network checks');
        Assert::true($this->web->request('/sites/1/delete')['status'] === 200 && str_contains($this->web->request('/')['body'], 'Renamed site'), 'GET deletion only confirms');
        Assert::true($this->web->request('/sites/1/delete', ['_csrf' => 'invalid'])['status'] === 419 && str_contains($this->web->request('/')['body'], 'Renamed site'), 'delete requires CSRF');
        Assert::true($this->web->request('/sites/999/edit')['status'] === 404, 'missing site returns 404');
        Assert::true($this->web->request('/sites/1/delete', ['_csrf' => $csrf])['status'] === 303 && !str_contains($this->web->request('/')['body'], 'Renamed site'), 'confirmed POST deletes');
    }

    #[Test]
    public function encryptsPreservesReplacesAndRemovesProjectTokens(): void
    {
        $csrf = $this->web->authenticate();
        $site = $this->site($csrf);
        $private = array_replace($site, ['name' => 'Private fixture', 'url' => 'http://127.0.0.1',
            'repository' => 'fixture/private', 'branch' => 'feature/login', 'github_token' => 'fixture-token']);
        Assert::true($this->web->request('/sites/new', $private)['status'] === 303, 'private repository and slash branch save with token');
        $db = $this->web->database();
        $row = $db->query('SELECT * FROM sites')->fetch(PDO::FETCH_ASSOC);
        $id = $row['id'];
        Assert::true($row['github_token'] !== 'fixture-token' && str_starts_with($row['github_token'], 'v1:'), 'database stores only encrypted token');
        $edit = $this->web->request('/sites/' . $id . '/edit');
        Assert::true(!str_contains($edit['body'], 'fixture-token') && !str_contains($edit['body'], $row['github_token'])
            && str_contains($edit['body'], 'Токен сохранён.'), 'edit shows saved status without credentials');
        $existingLookup = ['_csrf' => $csrf, 'site_id' => (string) $id, 'repository' => 'fixture/private'];
        Assert::true($this->web->request('/sites/branches', $existingLookup)['status'] === 200, 'branch lookup reuses saved token');
        Assert::true($this->web->request('/sites/branches', array_replace($existingLookup, ['remove_github_token' => '1']))['status'] === 422,
            'removal preview stops using saved token');
        $blank = array_replace($private, ['github_token' => '']);
        Assert::true($this->web->request('/sites/' . $id . '/edit', $blank)['status'] === 303
            && $db->query('SELECT github_token FROM sites')->fetchColumn() === $row['github_token'], 'blank token preserves existing ciphertext and access');
        $failure = $this->web->request('/sites/' . $id . '/edit', array_replace($private, ['github_token' => 'invalid-fixture-token']));
        Assert::true($failure['status'] === 422 && !str_contains($failure['body'], 'invalid-fixture-token')
            && $db->query('SELECT github_token FROM sites')->fetchColumn() === $row['github_token'], 'invalid replacement neither persists nor reflects secret');
        Assert::true($this->web->request('/sites/' . $id . '/check', ['_csrf' => $csrf])['status'] === 303
            && $db->query('SELECT latest_commit FROM sites')->fetchColumn() === str_repeat('a', 40), 'dashboard checks use site token');
        Assert::true($this->web->request('/sites/' . $id . '/edit', array_replace($private, ['github_token' => 'replacement-token']))['status'] === 303
            && $db->query('SELECT github_token FROM sites')->fetchColumn() !== $row['github_token'], 'token replacement persists');
        $remove = array_replace($blank, ['repository' => 'ichinya/lekalo', 'remove_github_token' => '1']);
        Assert::true($this->web->request('/sites/' . $id . '/edit', $remove)['status'] === 303
            && $db->query('SELECT github_token FROM sites')->fetchColumn() === null, 'token removal persists');
    }

    #[Test]
    public function managesSharedTokensAndOptionalVersionProjects(): void
    {
        $csrf = $this->web->authenticate();
        $private = array_replace($this->site($csrf), ['name' => 'Private fixture', 'url' => 'http://127.0.0.1',
            'repository' => 'fixture/private', 'branch' => 'feature/login', 'github_token' => 'fixture-token']);
        $db = $this->web->database();
        $settings = $this->web->request('/settings');
        Assert::true($settings['status'] === 200 && str_contains($settings['body'], 'Пока нет сохранённых токенов'), 'settings token list renders empty state');
        $shared = ['_csrf' => $csrf, 'name' => 'Shared <token>', 'provider' => 'github', 'token' => 'fixture-token'];
        Assert::true($this->web->request('/settings/tokens/new', ['name' => 'Without CSRF', 'provider' => 'github', 'token' => 'fixture-token'])['status'] === 419, 'settings token create requires CSRF');
        Assert::true($this->web->request('/settings/tokens/new', array_replace($shared, ['token' => '']))['status'] === 422, 'new shared token requires a value');
        Assert::true($this->web->request('/settings/tokens/new', array_replace($shared, ['provider' => 'gitlab']))['status'] === 422, 'unsupported Git provider is rejected');
        Assert::true($this->web->request('/settings/tokens/new', $shared)['status'] === 303, 'shared token create succeeds');
        $credential = $db->query('SELECT * FROM git_tokens')->fetch(PDO::FETCH_ASSOC);
        $tokenId = (string) $credential['id'];
        Assert::true(str_starts_with($credential['encrypted_token'], 'v1:') && $credential['encrypted_token'] !== 'fixture-token', 'shared token encrypted at rest');
        $settings = $this->web->request('/settings');
        Assert::true(str_contains($settings['body'], 'Shared &lt;token&gt;') && !str_contains($settings['body'], 'fixture-token')
            && !str_contains($settings['body'], $credential['encrypted_token']), 'settings exposes escaped metadata only');
        $tokenEdit = $this->web->request('/settings/tokens/' . $tokenId . '/edit');
        Assert::true($tokenEdit['status'] === 200 && str_contains($tokenEdit['body'], 'name="token" value=""')
            && !str_contains($tokenEdit['body'], 'fixture-token'), 'shared token edit never returns value');
        Assert::true(str_contains($this->web->request('/sites/new')['body'], 'Shared &lt;token&gt;'), 'project selector includes saved token');
        $selectedLookup = ['_csrf' => $csrf, 'repository' => 'fixture/private', 'git_token_id' => $tokenId];
        Assert::true($this->web->request('/sites/branches', $selectedLookup)['status'] === 200, 'branch lookup uses selected shared token');
        Assert::true($this->web->request('/sites/branches', array_replace($selectedLookup, ['git_token_id' => '999']))['status'] === 422, 'missing saved token does not fall back silently');
        $sharedSite = array_replace($private, ['name' => 'Shared project', 'github_token' => '', 'git_token_id' => $tokenId, 'version_path' => '']);
        Assert::true($this->web->request('/sites/new', array_replace($sharedSite, ['github_token' => 'fixture-token']))['status'] === 422, 'ambiguous manual and saved token rejected');
        Assert::true($this->web->request('/sites/new', $sharedSite)['status'] === 303, 'project accepts saved token and no version endpoint');
        $poolSite = $db->query("SELECT * FROM sites WHERE name = 'Shared project'")->fetch(PDO::FETCH_ASSOC);
        $projectId = (string) $poolSite['id'];
        Assert::true((string) $poolSite['git_token_id'] === $tokenId && $poolSite['github_token'] === null && $poolSite['version_path'] === '', 'project references saved token without copying secret');
        $projectEdit = $this->web->request('/sites/' . $projectId . '/edit');
        Assert::true(preg_match('/<option value="' . $tokenId . '" selected/', $projectEdit['body']) === 1
            && !str_contains($projectEdit['body'], $credential['encrypted_token']), 'edit preserves token selection without secret');
        Assert::true($this->web->request('/sites/' . $projectId . '/check', ['_csrf' => $csrf])['status'] === 303, 'manual check accepts project without version endpoint');
        $state = $db->query("SELECT * FROM sites WHERE id = $projectId")->fetch(PDO::FETCH_ASSOC);
        Assert::true($state['latest_commit'] === str_repeat('a', 40) && $state['deployed_version'] === null
            && !str_contains($state['last_error'] ?? '', 'Version:'), 'optional version check skipped while Git metrics still update');
        Assert::true($this->web->request('/settings/tokens/' . $tokenId . '/delete')['status'] === 200
            && $db->query('SELECT COUNT(*) FROM git_tokens')->fetchColumn() === 1, 'token GET delete only confirms');
        Assert::true($this->web->request('/settings/tokens/' . $tokenId . '/delete', ['_csrf' => $csrf])['status'] === 422
            && $db->query('SELECT COUNT(*) FROM git_tokens')->fetchColumn() === 1, 'used token cannot be deleted');
        Assert::true($this->web->request('/settings/tokens/' . $tokenId . '/edit', array_replace($shared, ['name' => 'Renamed', 'token' => '']))['status'] === 303
            && $db->query('SELECT encrypted_token FROM git_tokens')->fetchColumn() === $credential['encrypted_token'], 'blank shared token preserves secret');
        $invalidToken = $this->web->request('/settings/tokens/' . $tokenId . '/edit', array_replace($shared, ['token' => "invalid\r\nfixture-secret"]));
        Assert::true($invalidToken['status'] === 422 && !str_contains($invalidToken['body'], 'fixture-secret')
            && $db->query('SELECT encrypted_token FROM git_tokens')->fetchColumn() === $credential['encrypted_token'], 'invalid token value neither reflects nor overwrites secret');
        Assert::true($this->web->request('/settings/tokens/' . $tokenId . '/edit', array_replace($shared, ['token' => 'replacement-token']))['status'] === 303
            && $db->query("SELECT checked_at FROM sites WHERE id = $projectId")->fetchColumn() === null, 'shared token rotation invalidates project state');
        Assert::true($this->web->request('/sites/branches', array_replace($selectedLookup, ['site_id' => $projectId]))['status'] === 200, 'rotated token used for branches');
        Assert::true($this->web->request('/sites/' . $projectId . '/edit', array_replace($sharedSite, ['git_token_id' => '', 'github_token' => 'fixture-token']))['status'] === 303, 'project can switch to manually entered token');
        Assert::true($this->web->request('/settings/tokens/' . $tokenId . '/delete', ['_csrf' => 'wrong'])['status'] === 419, 'token delete requires CSRF');
        Assert::true($this->web->request('/settings/tokens/' . $tokenId . '/delete', ['_csrf' => $csrf])['status'] === 303
            && $db->query('SELECT COUNT(*) FROM git_tokens')->fetchColumn() === 0
            && $db->query("SELECT COUNT(*) FROM sites WHERE id = $projectId")->fetchColumn() === 1, 'unused shared token deletes without removing project');
    }

    #[Test]
    public function logsOutAndRequiresLoginAndClosesPublicSetup(): void
    {
        $csrf = $this->web->authenticate();
        $lookup = ['_csrf' => $csrf, 'repository' => 'fixture/private', 'github_token' => 'fixture-token'];
        $shared = ['_csrf' => $csrf, 'name' => 'Shared token', 'provider' => 'github', 'token' => 'fixture-token'];
        Assert::true($this->web->request('/logout', ['_csrf' => $csrf])['status'] === 303, 'logout succeeds');
        $anonymous = $this->web->request('/');
        Assert::true($anonymous['status'] === 303 && str_contains($anonymous['headers'], 'Location: /login'), 'dashboard requires login');
        Assert::true($this->web->request('/sites/branches', $lookup)['status'] === 303, 'branch loading requires login');
        Assert::true($this->web->request('/settings')['status'] === 303 && $this->web->request('/settings/tokens/new', $shared)['status'] === 303, 'token settings require login');
        $login = $this->web->request('/login');
        Assert::true($login['status'] === 200 && str_contains($login['body'], 'Войти в Tablo'), 'login page renders');
        Assert::true($this->web->request('/login', ['_csrf' => WebFixture::csrf($login), 'password' => 'wrong'])['status'] === 422, 'wrong password refused');
        Assert::true($this->web->request('/login', ['_csrf' => WebFixture::csrf($login), 'password' => 'fixture-password'])['status'] === 303, 'existing admin can log in');
        Assert::true($this->web->request('/setup')['status'] === 303, 'public setup closes after first admin');
        Assert::true($this->web->request('/storage/tablo.sqlite')['status'] === 404 && $this->web->request('/vendor/autoload.php')['status'] === 404, 'storage and source inaccessible through web root');
    }
}
