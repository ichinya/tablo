<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\SharedKeyFailure;
use Tablo\SiteRepository;
use Tablo\SiteKeyState;
use Tablo\EndpointCredential;
use Tablo\TokenVault;
use Tablo\ValidationException;
use Tablo\WorkerStateRepository;
use Tablo\Tests\Support\EndpointFixture;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;
use Testo\Test;

final class EndpointTokenRepositoryTest
{
    #[Test]
    public function validatesExactBytesTypesAndConflictingIntentBeforeKeyCreation(): void
    {
        $directory = new TemporaryDirectory('tablo-endpoint-validation-');
        $vault = null; $sites = null; $db = null;
        try {
            $db = Database::connect(':memory:');
            $vault = new TokenVault($directory->path . '/key');
            $sites = new SiteRepository($db, $vault);
            foreach ([null, [], 0, 1.0, true, new \stdClass(), ' ', "x y", " x", "x ", "x\t", "x\r", "x\n", "x\0", chr(127), chr(128), str_repeat(chr(90), times: 513)] as $invalid) {
                $caught = false;
                try { $sites->save([...UnitFixtures::site(), 'endpoint_token' => $invalid]); }
                catch (ValidationException $error) { $caught = (($error->errors['endpoint_token'] ?? null) !== null); }
                Assert::true($caught, 'invalid endpoint credential must fail with a fixed field error');
                Assert::false(is_file($directory->path . '/key'));
                Assert::same((int) \Tablo\SqlStatement::checked($db->query('SELECT count(*) FROM sites'))->fetchColumn(), 0);
            }
            $caught = false;
            try { $sites->save([...UnitFixtures::site(), 'endpoint_token' => EndpointFixture::tokenA(), 'remove_endpoint_token' => \Tablo\FormCheckbox::VALUE]); }
            catch (ValidationException $error) { $caught = (($error->errors['endpoint_token'] ?? null) !== null); }
            Assert::true($caught);
            Assert::false(is_file($directory->path . '/key'));
            $allPrintable = implode('', array_map('chr', range(33, end: 126)));
            Assert::true(hash_equals(EndpointCredential::validateEndpointToken($allPrintable), $allPrintable));
            Assert::same(strlen(EndpointCredential::validateEndpointToken(str_repeat(chr(90), times: 512))), 512);
            Assert::same(EndpointCredential::validateEndpointToken(''), '');
        } finally { $vault = null; $db = null; $sites = null; $directory->close(); }
    }

    #[Test]
    public function preservesBlankCiphertextReplacesRemovesAndRejectsStaleSettlementIncludingAba(): void
    {
        $directory = new TemporaryDirectory('tablo-endpoint-repository-');
        $worker = null; $vault = null; $sites = null; $db = null;
        try {
            $db = Database::connect(':memory:');
            $vault = new TokenVault($directory->path . '/key');
            $sites = new SiteRepository($db, $vault);
            $input = UnitFixtures::site();
            $id = $sites->save($input);
            Assert::same(\Tablo\Tests\Support\FixtureValue::row($sites->find($id))['endpoint_token'], null);
            $sites->save([...$input, 'endpoint_token' => EndpointFixture::tokenA()], $id);
            $initial = $sites->find($id);
            $cipher = \Tablo\Tests\Support\FixtureValue::row($initial)['endpoint_token'];
            Assert::true(is_string($cipher) && str_starts_with($cipher, 'v1:'));
            Assert::true(hash_equals($vault->decrypt(\Tablo\Tests\Support\FixtureValue::string($cipher)), EndpointFixture::tokenA()));
            foreach ([$input, [...$input, 'endpoint_token' => '']] as $keep) {
                $sites->save($keep, $id);
                Assert::true(hash_equals(\Tablo\Tests\Support\FixtureValue::string(\Tablo\Tests\Support\FixtureValue::row($sites->find($id))['endpoint_token']), \Tablo\Tests\Support\FixtureValue::string($cipher)));
            }
            $worker = new WorkerStateRepository($db, $sites);
            $result = ['online' => 1, 'checked_at' => gmdate('c'), 'worker_service' => ['latest_release' => true]];
            $snapshot = $sites->find($id);
            Assert::true($worker->settle(\Tablo\Tests\Support\FixtureValue::row($snapshot), $result));
            Assert::same($worker->turns($id, \Tablo\Tests\Support\FixtureValue::row($snapshot)['config_revision'])['latest_release'], 1);
            foreach ([['endpoint_token' => EndpointFixture::tokenB()], ['endpoint_token' => EndpointFixture::tokenA()],
                ['url' => 'https://other.example/'], ['url' => $input['url']], ['remove_endpoint_token' => \Tablo\FormCheckbox::VALUE],
                ['endpoint_token' => EndpointFixture::tokenA()], ['enabled' => '0'], ['enabled' => '1']] as $change) {
                $snapshot = $sites->find($id);
                $sites->save(array_replace($input, $change), $id);
                Assert::same(\Tablo\Tests\Support\FixtureValue::row($sites->find($id))['config_revision'], \Tablo\Tests\Support\FixtureValue::row($snapshot)['config_revision'] + 1);
                Assert::same(\Tablo\Tests\Support\FixtureValue::row($sites->find($id))['checked_at'], null);
                $turn = \Tablo\Tests\Support\FixtureValue::integer(\Tablo\SqlStatement::checked($db->query('SELECT fairness_turn FROM worker_runtime'))->fetchColumn());
                Assert::false($sites->storeCheck(\Tablo\Tests\Support\FixtureValue::row($snapshot), $result));
                Assert::false($worker->settle(\Tablo\Tests\Support\FixtureValue::row($snapshot), $result));
                Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT fairness_turn FROM worker_runtime'))->fetchColumn(), $turn);
            }
            Assert::false($worker->settle(\Tablo\Tests\Support\FixtureValue::row($initial), $result), 'A to B to A still refuses the first snapshot');
            $snapshot = $sites->find($id);
            Assert::true($worker->settle(\Tablo\Tests\Support\FixtureValue::row($snapshot), $result));
            $sites->delete($id);
            Assert::false($worker->settle(\Tablo\Tests\Support\FixtureValue::row($snapshot), $result));
            Assert::same((int) \Tablo\SqlStatement::checked($db->query('SELECT count(*) FROM worker_progress'))->fetchColumn(), 0);
        } finally { $vault = null; $db = null; $sites = null; $worker = null; $directory->close(); }
    }

    #[Test]
    public function endpointOnlyDisabledCiphertextRetainsEstablishedKeyCustody(): void
    {
        $directory = new TemporaryDirectory('tablo-endpoint-key-');
        $fresh = null; $vault = null; $sites = null; $db = null;
        try {
            $db = Database::connect(':memory:');
            $keyPath = $directory->path . '/key';
            $vault = new TokenVault($keyPath);
            $sites = new SiteRepository($db, $vault);
            $input = [...UnitFixtures::site(), 'enabled' => '0', 'endpoint_token' => EndpointFixture::tokenA()];
            $id = $sites->save($input);
            $original = file_get_contents($keyPath);
            $cipher = \Tablo\Tests\Support\FixtureValue::row($sites->find($id))['endpoint_token'];
            Assert::true(SiteKeyState::hasEstablishedKeyState($db));
            foreach ([null, chr(88), random_bytes(32)] as $replacement) {
                unlink($keyPath);
                if ($replacement !== null) { file_put_contents($keyPath, $replacement); }
                $before = is_file($keyPath) ? hash_file('sha256', $keyPath) : null;
                $failed = false;
                try { $sites->save([...$input, 'endpoint_token' => EndpointFixture::tokenB()], $id); }
                catch (SharedKeyFailure) { $failed = true; }
                Assert::true($failed, 'known live key loss/corruption/replacement must refuse');
                Assert::true(hash_equals(\Tablo\Tests\Support\FixtureValue::string(\Tablo\Tests\Support\FixtureValue::row($sites->find($id))['endpoint_token']), \Tablo\Tests\Support\FixtureValue::string($cipher)));
                Assert::same(is_file($keyPath) ? hash_file('sha256', $keyPath) : null, $before);
                if ($replacement !== null) { unlink($keyPath); }
                file_put_contents($keyPath, $original);
                $sites->assertWorkerKeyAvailable();
            }
            unlink($keyPath);
            $fresh = new SiteRepository($db, new TokenVault($keyPath));
            $failed = false;
            try { $fresh->save([...$input, 'endpoint_token' => EndpointFixture::tokenB()], $id); }
            catch (SharedKeyFailure) { $failed = true; }
            Assert::true($failed, 'fresh repository sees endpoint-only established state');
            Assert::false(is_file($keyPath));
            file_put_contents($keyPath, $original);
            Assert::true(hash_equals($vault->decrypt(\Tablo\Tests\Support\FixtureValue::string($cipher)), EndpointFixture::tokenA()));
        } finally { $vault = null; $db = null; $sites = null; $fresh = null; $directory->close(); }
    }
}
