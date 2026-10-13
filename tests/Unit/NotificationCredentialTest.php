<?php
declare(strict_types=1);
namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\NotificationSettings;
use Tablo\SharedKeyFailure;
use Tablo\SiteKeyState;
use Tablo\SiteProjection;
use Tablo\TokenVault;
use Tablo\ValidationException;
use Tablo\Tests\Support\IncidentFixtures;
use Tablo\Tests\Support\NotificationFixture as F;
use Testo\Assert;
use Testo\Test;

final class NotificationCredentialTest
{
    #[Test]
    public function bearerAndPrivateSlotAloneAuthenticateExternalStartupWitness(): void
    {
        foreach (['bearer','private'] as $only) {
            $f=new F(); $external = null; $db = null;
            try {
                $f->configure(['bearer'=>bin2hex(random_bytes(24)),'include_name'=>'1']);
                $f->accept(0,0); $f->accept(0,60);
                \Tablo\Tests\Support\FixtureValue::object($f->db)->exec('UPDATE notification_settings SET enabled=0,endpoint_cipher=NULL'.($only==='private'?',bearer_cipher=NULL':''));
                if ($only==='bearer') { \Tablo\Tests\Support\FixtureValue::object($f->db)->exec('UPDATE notification_slots SET private_cipher=NULL'); }
                Assert::true(SiteKeyState::hasEstablishedKeyState(\Tablo\Tests\Support\FixtureValue::object($f->db)));
                $key=$f->directory->path.'/github-token.key'; $path=$f->directory->path.'/db.sqlite';
                $f->db = null; $f->vault = null; $f->sites = null;
                $external=new TokenVault($key,true); $db=Database::connect($path,$external);
                Assert::same((int)\Tablo\SqlStatement::checked($db->query('PRAGMA user_version'))->fetchColumn(),\Tablo\Database::CURRENT_SCHEMA_VERSION);
                $external = null; $db = null; file_put_contents($key,random_bytes(32)); $external=new TokenVault($key,true);
                Assert::instanceOf(IncidentFixtures::error(static fn()=>Database::connect($path,$external)),SharedKeyFailure::class);
            } finally { $external = null; $db = null; $f->close(); }
        }
    }

    #[Test]
    public function secretsEncryptedBlankKeepRemoveConflictTypeAndLengthSafe(): void
    {
        $f=new F(); $settings=null;
        try {
            $secret=bin2hex(random_bytes(24)); $settings=new NotificationSettings(\Tablo\Tests\Support\FixtureValue::object($f->db),$f->vault);
            $f->configure(['bearer'=>$secret]); $before=\Tablo\SqlRows::one(\Tablo\SqlStatement::checked(\Tablo\Tests\Support\FixtureValue::object($f->db)->query('SELECT * FROM notification_settings'))->fetch(), \Tablo\SqlNotificationSettingsActivationIdBearerCipherBlockedRow::checked(...));
            Assert::true( !hash_equals(\Tablo\Tests\Support\FixtureValue::string(\Tablo\Tests\Support\FixtureValue::fetched($before)['bearer_cipher']), $secret) );
            Assert::true( hash_equals(\Tablo\Tests\Support\FixtureValue::object($f->vault)->decrypt(\Tablo\Tests\Support\FixtureValue::string(\Tablo\Tests\Support\FixtureValue::fetched($before)['bearer_cipher'])), $secret) );
            Assert::false(str_contains(\Tablo\Tests\Support\FixtureValue::string(json_encode($settings->get())),$secret));
            $f->configure(['endpoint'=>'','bearer'=>'']); $kept=\Tablo\SqlRows::one(\Tablo\SqlStatement::checked(\Tablo\Tests\Support\FixtureValue::object($f->db)->query('SELECT * FROM notification_settings'))->fetch(), \Tablo\SqlNotificationSettingsActivationIdBearerCipherBlockedRow::checked(...));
            Assert::true(\Tablo\Tests\Support\FixtureValue::fetched($kept)['bearer_cipher']===\Tablo\Tests\Support\FixtureValue::fetched($before)['bearer_cipher'] && \Tablo\Tests\Support\FixtureValue::fetched($kept)['endpoint_cipher']===\Tablo\Tests\Support\FixtureValue::fetched($before)['endpoint_cipher']);
            foreach([['revision'=>'0'],['endpoint'=>[]],['bearer'=>[]],['bearer'=>str_repeat('x',times: 513)],
                ['endpoint'=>'http://receiver.example/hook'],['endpoint'=>'https://receiver.example/'.str_repeat('x',times: 501)],
                ['enabled'=>[]],['unavailable'=>'2'],['endpoint'=>'https://receiver.example/hook','remove_endpoint'=>'1']] as $bad){
                $error=IncidentFixtures::error(static fn()=>$f->configure($bad)); Assert::instanceOf($error,ValidationException::class);
                Assert::false(str_contains($error->getMessage(),$secret)); unset($error);
            }
            $f->configure(['enabled'=>'0','endpoint'=>'','bearer'=>'','remove_endpoint'=>'1','remove_bearer'=>'1']);
            Assert::same($settings->get()['endpoint_present'],0); Assert::same($settings->get()['bearer_present'],0);
        } finally { $settings=null; $f->close(); }
    }

    #[Test]
    public function endpointOnlyIsEstablishedWitnessAndWrongKeyFailsBeforeWrites(): void
    {
        $f=new F(); $settings = null; $external = null; $db = null;
        try {
            $f->configure(); Assert::true(SiteKeyState::hasEstablishedKeyState(\Tablo\Tests\Support\FixtureValue::object($f->db)));
            $before=\Tablo\SqlRows::one(\Tablo\SqlStatement::checked(\Tablo\Tests\Support\FixtureValue::object($f->db)->query('SELECT * FROM notification_settings'))->fetch(), \Tablo\SqlNotificationSettingsActivationIdBearerCipherBlockedRow::checked(...));
            $key=$f->directory->path.'/github-token.key'; $original=file_get_contents($key);
            $path=$f->directory->path.'/db.sqlite';
            $f->db = null; $f->vault = null; $f->sites = null;
            $external=new TokenVault($key,true); $db=Database::connect($path,$external);
            Assert::true(\Tablo\SqlStatement::checked($db->query('SELECT endpoint_cipher FROM notification_settings'))->fetchColumn()===\Tablo\Tests\Support\FixtureValue::fetched($before)['endpoint_cipher']);
            $external = null; $db = null; file_put_contents($key,random_bytes(32)); $external=new TokenVault($key,true);
            Assert::instanceOf(IncidentFixtures::error(static fn()=>Database::connect($path,$external)),SharedKeyFailure::class);
            $external=null; file_put_contents($key,$original);
            $db=Database::connect($path); $settings=new NotificationSettings($db,new TokenVault($key));
            unlink($key); Assert::instanceOf(IncidentFixtures::error(static fn()=>$settings->update(['revision'=>'1','endpoint'=>'https://replacement.example/hook'])),SharedKeyFailure::class);
            Assert::false(is_file($key)); Assert::true(\Tablo\SqlStatement::checked($db->query('SELECT endpoint_cipher FROM notification_settings'))->fetchColumn()===\Tablo\Tests\Support\FixtureValue::fetched($before)['endpoint_cipher']);
        } finally { $settings = null; $external = null; $db = null; $f->close(); }
    }

    #[Test]
    public function optInPrivatePayloadIsEncryptedAndOriginOnly(): void
    {
        $f=new F();
        try {
            \Tablo\Tests\Support\FixtureValue::object($f->sites)->save(array_replace(\Tablo\Tests\Support\FixtureValue::row(SiteProjection::withoutEndpointCredential(\Tablo\Tests\Support\FixtureValue::object($f->sites)->find($f->id))),['name'=>'<private-name>','url'=>'https://example.com/private']),$f->id);
            $f->configure(['include_name'=>'1','include_url'=>'1']); $f->accept(0,0); $f->accept(0,60);
            $slot=\Tablo\Tests\Support\FixtureValue::at($f->slots(), 0); Assert::false(str_contains($slot['payload'],'private-name'));
            $private=\Tablo\JsonRecord::checked(json_decode(\Tablo\Tests\Support\FixtureValue::object($f->vault)->decrypt(\Tablo\Tests\Support\FixtureValue::string($slot['private_cipher'])),associative: true));
            Assert::true(\Tablo\Tests\Support\FixtureValue::at($private, 'display_name')==='<private-name>'); Assert::true(\Tablo\Tests\Support\FixtureValue::at($private, 'site_origin')==='https://example.com');
            Assert::false(str_contains(\Tablo\Tests\Support\FixtureValue::string(json_encode($slot)),'credential=excluded'));
        } finally { $f->close(); }
    }

}
