<?php
declare(strict_types=1);
namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\SiteRepository;
use Tablo\Tests\Network\WorkerCredentialAssertions;
use Tablo\Tests\Network\WorkerCredentialObservation;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;
use Testo\Test;

final class AssertionResourceCustodyTest
{
    #[Test]
    public function failedScalarAssertionRetainsOriginalExceptionAndAllowsOwnedDatabaseCleanup(): void
    {
        $directory = new TemporaryDirectory('tablo-assertion-scalar-custody-');
        $original = ini_get('zend.exception_ignore_args');
        \Tablo\RuntimeIni::set('zend.exception_ignore_args', '0');
        $db = null; $sites = null; $failure = null;
        try {
            $db = Database::connect($directory->path . '/db.sqlite');
            $sites = new SiteRepository($db);
            $id = $sites->save(UnitFixtures::site());
            $observation = WorkerCredentialObservation::read($sites, $db, $id);
            WorkerCredentialAssertions::settled($observation, 'site-token');
            try { WorkerCredentialAssertions::settled($observation, 'rename-only'); }
            catch (\Testo\Assert\State\Assertion\ComparisonFailure $caught) { $failure = $caught; unset($caught); }
            Assert::true($failure !== null);
            $path = $directory->path;
            $sites = null; $db = null;
            $directory->close();
            Assert::false(is_dir($path));
            Assert::true(str_contains(\Tablo\Tests\Support\FixtureValue::object($failure)->getMessage(), 'expected `true`, got `false`'));
        } finally {
            $sites = null; $db = null; $failure = null;
            \Tablo\RuntimeIni::set('zend.exception_ignore_args', $original);
            $directory->close();
        }
    }

    #[Test]
    public function failedActiveOwnerAssertionKeepsOrderedOperationsAndReleasesBothConnections(): void
    {
        $directory = new TemporaryDirectory('tablo-assertion-operation-custody-');
        $original = ini_get('zend.exception_ignore_args');
        \Tablo\RuntimeIni::set('zend.exception_ignore_args', '0');
        $a = null; $b = null; $failure = null;
        try {
            $a = new \PDO('sqlite:' . $directory->path . '/a.sqlite');
            $b = new \PDO('sqlite:' . $directory->path . '/b.sqlite');
            foreach ([$a, $b] as $connection) {
                $connection->exec('CREATE TABLE worker_progress(id INTEGER); CREATE TABLE worker_runtime(fairness_turn INTEGER); INSERT INTO worker_runtime VALUES(0)');
            }
            unset($connection);
            (new ConnectionOwnershipAssertions($a, $b))->noCredit();
            $a->exec('INSERT INTO worker_progress VALUES(1)');
            try { (new ConnectionOwnershipAssertions($a, $b))->noCredit(); }
            catch (\Testo\Assert\State\Assertion\ComparisonFailure $caught) { $failure = $caught; unset($caught); }
            Assert::true($failure !== null);
            $path = $directory->path;
            $a = null; $b = null;
            $directory->close();
            Assert::false(is_dir($path));
            Assert::true(str_contains(\Tablo\Tests\Support\FixtureValue::object($failure)->getMessage(), 'expected `0`, got `1`'));
        } finally {
            $a = null; $b = null; $failure = null;
            \Tablo\RuntimeIni::set('zend.exception_ignore_args', $original);
            $directory->close();
        }
    }
}
