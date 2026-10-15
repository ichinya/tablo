<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Database;
use Tablo\GitHubConnection;
use Tablo\GitHubFailure;
use Tablo\GitTokenRepository;
use Tablo\SiteRepository;
use Tablo\TokenVault;
use Tablo\Tests\Support\MeasuredGitHubHttp;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;

/** @phpstan-import-type Record from \Tablo\SqlGithubCooldownsEligibleAtResourceScopeRow as Cooldown */
final class ExternalQuotaFixture
{
    public function __construct(private readonly TemporaryDirectory $directory, private readonly TestServer $server) {}

    /** @return array{string, \PDO, TokenVault, SiteRepository, string, MeasuredGitHubHttp, GitHubConnection, list<Cooldown>, string} */
    public function create(): array
    {
            $path=$this->directory->path.'/db.sqlite'; $db=Database::connect($path);
            $vault=TokenVault::forDatabase($db); $secret=bin2hex(random_bytes(24));
            $sites=new SiteRepository($db,$vault);
            $id=$sites->save(array_replace(UnitFixtures::site(),['url'=>$this->server->base,'repository'=>'fixture/primary','health_path'=>'','version_path'=>'','github_token'=>$secret]));
            (new GitTokenRepository($db,$vault))->save(['name'=>'Synthetic','provider'=>'github','token'=>$secret]);
            $http=new MeasuredGitHubHttp($this->server->base); $connection=new GitHubConnection($sites,$http);
            try { $connection->provider(null,['github_token'=>$secret])->getLatestRelease('fixture/primary'); }
            catch (GitHubFailure $error) { Assert::same($error->reason,'rate-limit'); unset($error); }
            Assert::same($http->core,1);
            $before=\Tablo\SqlRows::all(\Tablo\SqlStatement::checked($db->query('SELECT * FROM github_cooldowns ORDER BY scope,resource'))->fetchAll(), \Tablo\SqlGithubCooldownsEligibleAtResourceScopeRow::checked(...));
            Assert::true(count($before)>0); Assert::true(str_starts_with(\Tablo\Tests\Support\FixtureValue::at($before, 0)['scope'],'credential:v1:'));
            $cipher=\Tablo\Tests\Support\FixtureValue::string(\Tablo\SqlStatement::checked($db->query('SELECT github_token FROM sites'))->fetchColumn());
        return [$path, $db, $vault, $sites, $secret, $http, $connection, $before, $cipher];
    }
}
