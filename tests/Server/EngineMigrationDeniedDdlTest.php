<?php
declare(strict_types=1);

namespace Tablo\Tests\Server;

use Testo\Test;

/** Separate native8s cases; the reviewed explicit fixture role is required, with no skip fallback. */
final class EngineMigrationDeniedDdlTest
{
    #[Test]
    public function deniedCreateStep00TabloMigrationJournal(): void
    { EngineMigrationFailureTest::deniedDdlAt(0); }

    #[Test]
    public function deniedCreateStep01TabloDatabaseState(): void
    { EngineMigrationFailureTest::deniedDdlAt(1); }

    #[Test]
    public function deniedCreateStep02TabloWriterGuard(): void
    { EngineMigrationFailureTest::deniedDdlAt(2); }

    #[Test]
    public function deniedCreateStep03Users(): void
    { EngineMigrationFailureTest::deniedDdlAt(3); }

    #[Test]
    public function deniedCreateStep04GitTokens(): void
    { EngineMigrationFailureTest::deniedDdlAt(4); }

    #[Test]
    public function deniedCreateStep05Sites(): void
    { EngineMigrationFailureTest::deniedDdlAt(5); }

    #[Test]
    public function deniedCreateStep06LoginLimits(): void
    { EngineMigrationFailureTest::deniedDdlAt(6); }

    #[Test]
    public function deniedCreateStep07GithubCooldowns(): void
    { EngineMigrationFailureTest::deniedDdlAt(7); }

    #[Test]
    public function deniedCreateStep08InstallationSettings(): void
    { EngineMigrationFailureTest::deniedDdlAt(8); }
}
