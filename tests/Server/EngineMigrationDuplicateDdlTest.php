<?php
declare(strict_types=1);

namespace Tablo\Tests\Server;

use Testo\Test;

/** Separate native8s cases; the reviewed explicit fixture role is required, with no skip fallback. */
final class EngineMigrationDuplicateDdlTest
{
    #[Test]
    public function duplicateCreateAfterStep00TabloMigrationJournal(): void
    { EngineMigrationFailureTest::duplicateDdlAt(0); }

    #[Test]
    public function duplicateCreateAfterStep01TabloDatabaseState(): void
    { EngineMigrationFailureTest::duplicateDdlAt(1); }

    #[Test]
    public function duplicateCreateAfterStep02TabloWriterGuard(): void
    { EngineMigrationFailureTest::duplicateDdlAt(2); }

    #[Test]
    public function duplicateCreateAfterStep03Users(): void
    { EngineMigrationFailureTest::duplicateDdlAt(3); }

    #[Test]
    public function duplicateCreateAfterStep04GitTokens(): void
    { EngineMigrationFailureTest::duplicateDdlAt(4); }

    #[Test]
    public function duplicateCreateAfterStep05Sites(): void
    { EngineMigrationFailureTest::duplicateDdlAt(5); }

    #[Test]
    public function duplicateCreateAfterStep06LoginLimits(): void
    { EngineMigrationFailureTest::duplicateDdlAt(6); }

    #[Test]
    public function duplicateCreateAfterStep07GithubCooldowns(): void
    { EngineMigrationFailureTest::duplicateDdlAt(7); }

    #[Test]
    public function duplicateCreateAfterStep08InstallationSettings(): void
    { EngineMigrationFailureTest::duplicateDdlAt(8); }
}
