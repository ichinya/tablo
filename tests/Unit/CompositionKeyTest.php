<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use PDO;
use Tablo\Database;
use Tablo\SharedKeyFailure;
use Tablo\SiteKeyState;
use Tablo\TokenVault;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Test;

final class CompositionKeyTest
{
    #[Test]
    public function everyHistoricalCipherFamilyAuthenticatesBeforeMigrationAndWalWithoutReplacingKeys(): void
    {
        $directory=new TemporaryDirectory('tablo-composition-key-'); $vault = null; $db = null;
        try {
            $key=$directory->path.'/selected.key'; $wrong=$directory->path.'/wrong.key';
            $bytes=random_bytes(32); file_put_contents($key,$bytes); file_put_contents($wrong,random_bytes(32));
            foreach (['endpoint','inline-git','saved-git','notification-endpoint','notification-bearer','notification-slot'] as $family) {
                $vault=new TokenVault($key,true); $cipher=$vault->encrypt('synthetic-established-secret');
                $path=$directory->path.'/'.$family.'.sqlite';
                $db=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
                $name=$family==='endpoint'?'endpoint5':'notification7';
                $db->exec(\Tablo\RequiredFile::bytes(file_get_contents(dirname(__DIR__).'/fixtures/historical-schema/'.$name.'.sql')));
                $db->exec("INSERT INTO sites(id,name,url,repository,enabled) VALUES(1,'Historical','https://example.test/','fixture/public',0)");
                if (str_starts_with($family,'notification-')) {
                    $db->exec("INSERT INTO notification_settings(id,installation_id) VALUES(1,'0123456789abcdef0123456789abcdef')");
                }
                $sql=match($family) {
                    'endpoint'=>'UPDATE sites SET endpoint_token=? WHERE id=1',
                    'inline-git'=>'UPDATE sites SET github_token=? WHERE id=1',
                    'saved-git'=>"INSERT INTO git_tokens(name,provider,encrypted_token) VALUES('Original','github',?)",
                    'notification-endpoint'=>'UPDATE notification_settings SET endpoint_cipher=? WHERE id=1',
                    'notification-bearer'=>'UPDATE notification_settings SET bearer_cipher=? WHERE id=1',
                    'notification-slot'=>"INSERT INTO notification_slots(site_id,event,event_id,source_id,config_revision,channel_revision,payload,
                        private_cipher,created_at,expires_at,due_at,status) VALUES(1,'unavailable','original',1,0,0,'{}',?,1000,2000,1000,'pending')",
                };
                \Tablo\SqlStatement::checked($db->prepare($sql))->execute([$cipher]);
                Assert::true(SiteKeyState::hasEstablishedKeyState($db),$family);
                $schema=\Tablo\SqlStatement::checked($db->query('SELECT sql FROM sqlite_schema ORDER BY name'))->fetchAll();
                $vault = null; $db = null; $before=hash_file('sha256',$path);
                $error=null;
                try { Database::connect($path,new TokenVault($wrong,true)); }
                catch (SharedKeyFailure $caught) { $error=$caught->getCode(); unset($caught); }
                Assert::same($error,SharedKeyFailure::UNVERIFIED,$family);
                Assert::same(hash_file('sha256',$path),$before,$family);
                Assert::same(file_get_contents($key),$bytes);
                $db=Database::connect($path,new TokenVault($key,true));
                Assert::same((int)\Tablo\SqlStatement::checked($db->query('PRAGMA user_version'))->fetchColumn(),8);
                Assert::true(SiteKeyState::hasEstablishedKeyState($db),$family);
                Assert::same(file_get_contents($key),$bytes);
                Assert::false(is_file($directory->path.'/github-token.key'));
                $vault = null; $db = null;
            }
        } finally { $vault = null; $db = null; $directory->close(); }
    }
}
