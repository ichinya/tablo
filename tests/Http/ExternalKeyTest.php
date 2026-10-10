<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\WebFixture;
use Testo\Assert;
use Testo\Test;

final class ExternalKeyTest
{
    #[Test]
    public function actualPublicEntrypointRefusesMissingKeyBeforeSessionAndDatabase(): void
    {
        $web = new WebFixture(router: dirname(__DIR__,2).'/public/index.php',
            environment:['TABLO_TOKEN_KEY_FILE'=>'missing-'.bin2hex(random_bytes(20))]);
        try {
            $response=$web->request('/'); Assert::same($response['status'],500);
            Assert::false(is_file($web->directory->path.'/test.sqlite'));
            Assert::false(is_dir($web->directory->path.'/runtime'));
            Assert::false(is_file($web->directory->path.'/github-token.key'));
            Assert::false(str_contains($response['body'],'missing-'));
            Assert::true(str_contains($web->server->diagnostics(),'Tablo initialization or operation failed.'));
            Assert::false(str_contains($web->server->diagnostics(),'missing-'));
        } finally {$web->close();}
    }

    #[Test]
    public function actualAuthenticatedFormsBranchesSettingsAndMetadataUseExternalKey(): void
    {
        $directory=new TemporaryDirectory('tablo-external-http-'); $web=$db=null;
        try {
            $key=$directory->path.'/raw'; $bytes=random_bytes(32); file_put_contents($key,$bytes);
            $web=new WebFixture(environment:['TABLO_TOKEN_KEY_FILE'=>$key]); $csrf=$web->authenticate();
            $secret='fixture-token';
            $invalid=$web->request('/settings/tokens/new',['_csrf'=>$csrf,'provider'=>'github','name'=>'','token'=>$secret]);
            Assert::same($invalid['status'],422); Assert::false(str_contains($invalid['body'],$secret));
            Assert::same($web->request('/settings/tokens/new',['_csrf'=>$csrf,'provider'=>'github','name'=>'Synthetic','token'=>$secret])['status'],303);
            $input=['_csrf'=>$csrf,'name'=>'Synthetic','url'=>'https://example.com','repository'=>'fixture/private',
                'branch'=>'main','comparison_mode'=>'release','enabled'=>'1','health_path'=>'','version_path'=>'','sort_order'=>'0','git_token_id'=>'1'];
            Assert::same($web->request('/sites/new',$input)['status'],303);
            $branches=$web->request('/sites/branches',['_csrf'=>$csrf,'repository'=>'fixture/private','git_token_id'=>'1']);
            Assert::same($branches['status'],200); Assert::false(str_contains($branches['body'],$secret));
            foreach(['/settings','/settings/tokens/1/edit','/sites/1/edit','/'] as $route){
                $page=$web->request($route);Assert::same($page['status'],200);
                Assert::false(str_contains($page['body'],$secret));Assert::false(str_contains($page['body'],$key));
            }
            Assert::same($web->request('/settings',['_csrf'=>$csrf,'check_interval_minutes'=>'2'])['status'],303);
            Assert::same($web->request('/settings',['_csrf'=>'bad','check_interval_minutes'=>'3'])['status'],419);
            $db=$web->database();Assert::same((int)$db->query('SELECT check_interval_minutes FROM installation_settings')->fetchColumn(),2);
            Assert::same(file_get_contents($key),$bytes); Assert::false(is_file($web->directory->path.'/github-token.key'));
            Assert::false(str_contains($web->server->diagnostics(),$secret));
        } finally {$db=null;$web?->close();$web=null;$directory->close();}
    }
}
