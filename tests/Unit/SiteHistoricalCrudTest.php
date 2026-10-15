<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Auth;
use Tablo\Database;
use Tablo\SiteRepository;
use Tablo\TokenVault;
use Tablo\GitTokenRepository;

use PDO;
use Testo\Assert;
use Testo\Test;
use Tablo\Tests\Support\TemporaryDirectory;

final class SiteHistoricalCrudTest
{


    #[Test]
    public function migratesExistingDatabaseWithoutLosingAdministrator(): void
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $schema = file_get_contents(dirname(__DIR__, levels: 2) . '/database/schema.sql');
        $db->exec(preg_replace('/^\s*github_token TEXT,\R/m', replacement: '', subject: $schema));
        (new Auth($db))->setup(\Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'), \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'));
        $db->exec("INSERT INTO sites (name,url,repository,branch) VALUES ('Existing','https://example.com','owner/repo','main')");
        $hash = $db->query('SELECT password_hash FROM users')->fetchColumn();
        Database::migrate($db);
        Database::migrate($db);
        Assert::true($db->query('SELECT COUNT(*) FROM sites')->fetchColumn() === 1 && $db->query('SELECT github_token FROM sites')->fetchColumn() === null, 'sites lost during migration');
        Assert::true($db->query('SELECT password_hash FROM users')->fetchColumn() === $hash, 'administrator changed during migration');
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
            $schema = file_get_contents(dirname(__DIR__, levels: 2) . '/database/schema.sql');
            $schema = preg_replace('/CREATE TABLE IF NOT EXISTS git_tokens \(.*?\);\s*/s', replacement: '', subject: $schema);
            $schema = preg_replace('/^\s*git_token_id INTEGER[^\r\n]*\R/m', replacement: '', subject: $schema);
            $old->exec($schema);
            (new Auth($old))->setup(\Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'), \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'));
            $hash = $old->query('SELECT password_hash FROM users')->fetchColumn();
            $old->prepare('INSERT INTO sites (name, url, repository, github_token, version_path) VALUES (?, ?, ?, ?, ?)')
                ->execute(['Existing', 'https://example.com', 'owner/repo', $cipher, '/version']);
            unset($old);
            $db = Database::connect($path);
            Database::migrate($db);
            $sites = new SiteRepository($db);
            $site = $sites->find(1);
            Assert::true( hash_equals($site['github_token'], $cipher) && $site['git_token_id'] === null && $site['version_path'] === '/version', 'upgrade changed old configuration');
            Assert::true($sites->tokenFor($site) === 'legacy-token' && $db->query('SELECT password_hash FROM users')->fetchColumn() === $hash, 'upgrade lost credentials');
            $tokens = new GitTokenRepository($db);
            Assert::true($tokens->all() === [], 'upgrade invented shared tokens');
            $id = $tokens->save(['name' => 'New shared', 'provider' => 'github', 'token' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'new-token')]);
            Assert::true($tokens->tokenFor($id, 'github') === \Tablo\Tests\Support\FixtureCredentials::value(label: 'new-token') && $sites->tokenFor($site) === 'legacy-token', 'vault key changed on upgrade');
            unset($sites, $tokens, $db);
        } finally {
            unset($sites, $tokens, $db, $old);
            $temporary->close();
        }
    }
}
