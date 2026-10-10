<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Auth;
use Tablo\Database;
use Tablo\GitHubConnection;
use Tablo\SiteRepository;
use Tablo\TokenVault;
use Tablo\GitTokenRepository;
use Tablo\GitProviders;

use PDO;
use RuntimeException;
use LogicException;
use Testo\Assert;
use Testo\Test;
use Tablo\Tests\Support\FakeHttp;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\UnitFixtures;

final class SiteRepositoryTest
{
    #[Test]
    public function normalizesCrudAndGuardsStaleChecks(): void
    {
        $sample = UnitFixtures::site();
        $db = Database::connect(':memory:');
        $sites = new SiteRepository($db);
        $id = $sites->save($sample);
        $site = $sites->find($id);
        Assert::true($site['repository'] === 'ichinya/tempalog' && $site['url'] === 'https://tempalog.example/', 'normalization preserves the site URL');
        $state = ['online' => 1, 'deployed_version' => '1.3.1', 'checked_at' => gmdate('c')];
        Assert::true($sites->storeCheck($site, $state), 'check not saved');
        $changed = $sample;
        $changed['url'] = 'https://other.example';
        $changed['sort_order'] = 4;
        $sites->save($changed, $id);
        Assert::true($sites->find($id)['checked_at'] === null, 'stale check survived edit');
        Assert::true(!$sites->storeCheck($site, $state), 'old in-flight check overwrote edit');
        $first = $sites->save($sample);
        Assert::true($sites->all()[0]['id'] === $first, 'sort order ignored');
        $sites->delete($id);
        Assert::true($sites->find($id) === null && count($sites->all()) === 1, 'delete removed wrong record');
    }

    #[Test]
    public function rejectsMaliciousSiteInputs(): void
    {
        $sample = UnitFixtures::site();
        foreach ([['url' => 'file:///etc/passwd'], ['url' => 'https://user:pass@example.com'],
            ['url' => 'https://example.com/#test'], ['repository' => 'https://evil.example/a/b'],
            ['repository' => 'owner/..'], ['health_path' => '//evil.example/up'], ['health_path' => '/a\\b'],
            ['version_path' => '/version#other'], ['branch' => "main\nother"], ['comparison_mode' => 'evil'],
            ['sort_order' => '3.5'], ['name' => ['array']]] as $bad) {
            UnitFixtures::rejects(fn () => SiteRepository::normalize(array_replace($sample, $bad)), 'invalid input accepted: ' . json_encode($bad));
        }
    }

    #[Test]
    public function encryptsAndIsolatesProjectTokens(): void
    {
        $sample = UnitFixtures::site();
        $temporary = new TemporaryDirectory('tablo-token-');
        $directory = $temporary->path;
        try {
            $key = $directory . '/key';
            $vault = new TokenVault($key);
            $sites = new SiteRepository(Database::connect(':memory:'), $vault);
            $id = $sites->save($sample + ['github_token' => 'fixture-token']);
            $site = $sites->find($id);
            Assert::true(str_starts_with($site['github_token'], 'v1:') && !str_contains($site['github_token'], 'fixture-token'), 'plaintext stored');
            Assert::true($sites->tokenFor($site) === 'fixture-token' && filesize($key) === 32, 'token not restored');
            $other = $sites->save(array_replace($sample, ['name' => 'Second', 'github_token' => 'other-token']));
            Assert::true($sites->tokenFor($sites->find($other)) === 'other-token' && $sites->tokenFor($site) === 'fixture-token', 'tokens crossed sites');
            Assert::true($sites->resolveToken([], $site) === 'fixture-token', 'saved site token not used');
            Assert::true($sites->resolveToken(['github_token' => 'new-token', 'remove_github_token' => '1'], $site) === 'new-token', 'replacement lost');
            Assert::true($sites->resolveToken(['remove_github_token' => '1'], $site) === '', 'removed token was reused');
            Assert::true($sites->resolveToken([], null) === '', 'credentials invented for a tokenless project');
            $sites->save($sample, $id);
            Assert::true($sites->find($id)['github_token'] === $site['github_token'], 'empty token erased saved token');
            $sites->save($sample + ['github_token' => 'replacement-token'], $id);
            Assert::true(!$sites->storeCheck($site, ['online' => 1]), 'old check survived token-only replacement');
            Assert::true($sites->tokenFor($sites->find($id)) === 'replacement-token', 'new token lost');
            $sites->save($sample + ['remove_github_token' => '1'], $id);
            Assert::true($sites->find($id)['github_token'] === null, 'token not deleted');
            foreach (["token\r\nInjected: value", 'a b', str_repeat('a', 513), ['array']] as $bad) {
                UnitFixtures::rejects(fn () => $sites->save($sample + ['github_token' => $bad]), 'unsafe token accepted');
            }
            $cipher = $vault->encrypt('tamper-fixture');
            $bytes = base64_decode(substr($cipher, 3));
            $bytes[28] = chr(ord($bytes[28]) ^ 1);
            try {
                $vault->decrypt('v1:' . base64_encode($bytes));
                throw new LogicException('tampered token accepted');
            } catch (RuntimeException $e) { Assert::true(!($e instanceof LogicException), 'tampered token accepted'); }
            unlink($key);
            try {
                $vault->decrypt($cipher);
                throw new LogicException('missing key regenerated silently');
            } catch (RuntimeException $e) { Assert::true(!($e instanceof LogicException) && !is_file($key), 'missing key regenerated silently'); }
        } finally {
            unset($sites, $tokens, $db, $old);
            $temporary->close();
        }
    }

    #[Test]
    public function migratesExistingDatabaseWithoutLosingAdministrator(): void
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $schema = file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql');
        $db->exec(preg_replace('/^\s*github_token TEXT,\R/m', '', $schema));
        (new Auth($db))->setup('fixture-password', 'fixture-password');
        $db->exec("INSERT INTO sites (name,url,repository,branch) VALUES ('Existing','https://example.com','owner/repo','main')");
        $hash = $db->query('SELECT password_hash FROM users')->fetchColumn();
        Database::migrate($db);
        Database::migrate($db);
        Assert::true($db->query('SELECT COUNT(*) FROM sites')->fetchColumn() === 1 && $db->query('SELECT github_token FROM sites')->fetchColumn() === null, 'sites lost during migration');
        Assert::true($db->query('SELECT password_hash FROM users')->fetchColumn() === $hash, 'administrator changed during migration');
    }

    #[Test]
    public function guardsReusableTokensAndRotation(): void
    {
        $sample = UnitFixtures::site();
        $temporary = new TemporaryDirectory('tablo-saved-token-');
        $directory = $temporary->path;
        try {
            $db = Database::connect(':memory:');
            $vault = new TokenVault($directory . '/key');
            $tokens = new GitTokenRepository($db, $vault);
            $sites = new SiteRepository($db, $vault);
            $input = ['name' => 'Shared token', 'provider' => 'github', 'token' => 'fixture-token'];
            $id = $tokens->save($input);
            $cipher = $db->query('SELECT encrypted_token FROM git_tokens')->fetchColumn();
            Assert::true(str_starts_with($cipher, 'v1:') && $cipher !== 'fixture-token', 'pool token stored in plaintext');
            Assert::true(!str_contains(json_encode($tokens->all()), 'fixture-token') && !str_contains(json_encode($tokens->find($id)), $cipher), 'pool metadata exposed token');
            UnitFixtures::rejects(fn () => $tokens->save(array_replace($input, ['name' => 'SHARED TOKEN'])), 'duplicate name accepted');
            UnitFixtures::rejects(fn () => $tokens->save(array_replace($input, ['name' => 'Another', 'token' => ''])), 'empty new token accepted');
            UnitFixtures::rejects(fn () => $tokens->save(array_replace($input, ['provider' => 'gitlab'])), 'unimplemented provider accepted');
            Assert::true(GitProviders::available() === ['github' => 'GitHub'], 'providers misrepresented');
            $poolSite = $sample + ['git_token_id' => (string) $id];
            $first = $sites->save($poolSite);
            $second = $sites->save(array_replace($poolSite, ['name' => 'Second']));
            $site = $sites->find($first);
            Assert::true($site['github_token'] === null && $site['git_token_id'] === $id, 'saved token copied instead of referenced');
            Assert::true($sites->resolveToken([], $site) === 'fixture-token'
                && $sites->resolveToken(['git_token_id' => (string) $id], null) === 'fixture-token', 'saved token not resolved');
            Assert::true($sites->resolveToken(['git_token_id' => ''], $site) === '', 'deselected pool token was reused');
            Assert::true($tokens->all()[0]['site_count'] === 2, 'usage count wrong');
            UnitFixtures::rejects(fn () => $sites->resolveToken(['git_token_id' => (string) $id, 'github_token' => 'own-token'], null), 'ambiguous token accepted');
            UnitFixtures::rejects(fn () => $sites->save($sample + ['git_token_id' => '999']), 'missing pool token accepted');
            UnitFixtures::rejects(fn () => $sites->save($sample + ['git_token_id' => ['array']]), 'malformed pool id accepted');
            $db->prepare('INSERT INTO git_tokens (name, provider, encrypted_token) VALUES (?, ?, ?)')->execute(['Future provider', 'gitlab', $cipher]);
            $foreign = (string) $db->lastInsertId();
            UnitFixtures::rejects(fn () => $sites->resolveToken(['git_token_id' => $foreign], null), 'token from another provider accepted');
            $unsupportedHttp = new FakeHttp([]);
            UnitFixtures::rejects(fn () => (new GitHubConnection($sites, $unsupportedHttp))->provider(['provider' => 'gitlab', 'git_token_id' => (int) $foreign]), 'unsupported provider token could reach GitHub');
            Assert::true($unsupportedHttp->requests === [], 'unsupported provider reached network');
            $tokens->save(array_replace($input, ['name' => 'Renamed', 'token' => '']), $id);
            Assert::true($tokens->tokenFor($id, 'github') === 'fixture-token' && $sites->storeCheck($site, ['online' => 1, 'checked_at' => gmdate('c')]), 'metadata edit invalidated credential');
            $tokens->save(array_replace($input, ['name' => 'Renamed', 'token' => 'replacement-token']), $id);
            Assert::true($sites->find($first)['checked_at'] === null && $sites->find($second)['checked_at'] === null, 'rotation did not invalidate checks');
            Assert::true(!$sites->storeCheck($site, ['online' => 1]), 'in-flight check with old shared token survived');
            Assert::true($sites->tokenFor($sites->find($first)) === 'replacement-token', 'rotation not used');
            UnitFixtures::rejects(fn () => $tokens->delete($id), 'used token deleted');
            $sites->save(array_replace($sample, ['git_token_id' => '', 'github_token' => 'own-token']), $first);
            Assert::true($sites->find($first)['git_token_id'] === null && $sites->tokenFor($sites->find($first)) === 'own-token', 'switch to manual token failed');
            $sites->save($sample + ['git_token_id' => ''], $second);
            $tokens->delete($id);
            Assert::true($tokens->find($id) === null && $sites->find($first) !== null && $sites->find($second) !== null, 'delete removed sites');
        } finally {
            unset($sites, $tokens, $db, $old);
            $temporary->close();
        }
    }

    #[Test]
    public function preservesLegacyCredentialsDuringAdditiveMigration(): void
    {
        $temporary = new TemporaryDirectory('tablo-upgrade-');
        $directory = $temporary->path;
        try {
            $path = $directory . '/test.sqlite';
            $vault = new TokenVault($directory . '/github-token.key');
            $cipher = $vault->encrypt('legacy-token');
            $old = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            $schema = file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql');
            $schema = preg_replace('/CREATE TABLE IF NOT EXISTS git_tokens \(.*?\);\s*/s', '', $schema);
            $schema = preg_replace('/^\s*git_token_id INTEGER[^\r\n]*\R/m', '', $schema);
            $old->exec($schema);
            (new Auth($old))->setup('fixture-password', 'fixture-password');
            $hash = $old->query('SELECT password_hash FROM users')->fetchColumn();
            $old->prepare('INSERT INTO sites (name, url, repository, github_token, version_path) VALUES (?, ?, ?, ?, ?)')
                ->execute(['Existing', 'https://example.com', 'owner/repo', $cipher, '/version']);
            unset($old);
            $db = Database::connect($path);
            Database::migrate($db);
            $sites = new SiteRepository($db);
            $site = $sites->find(1);
            Assert::true($site['github_token'] === $cipher && $site['git_token_id'] === null && $site['version_path'] === '/version', 'upgrade changed old configuration');
            Assert::true($sites->tokenFor($site) === 'legacy-token' && $db->query('SELECT password_hash FROM users')->fetchColumn() === $hash, 'upgrade lost credentials');
            $tokens = new GitTokenRepository($db);
            Assert::true($tokens->all() === [], 'upgrade invented shared tokens');
            $id = $tokens->save(['name' => 'New shared', 'provider' => 'github', 'token' => 'new-token']);
            Assert::true($tokens->tokenFor($id, 'github') === 'new-token' && $sites->tokenFor($site) === 'legacy-token', 'vault key changed on upgrade');
            unset($sites, $tokens, $db);
        } finally {
            unset($sites, $tokens, $db, $old);
            $temporary->close();
        }
    }
}
