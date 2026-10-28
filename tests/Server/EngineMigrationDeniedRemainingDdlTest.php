<?php
declare(strict_types=1);

namespace Tablo\Tests\Server;

use Testo\Test;

/** Separate native8s cases; the reviewed explicit fixture role is required, with no skip fallback. */
final class EngineMigrationDeniedRemainingDdlTest
{
    #[Test]
    public function deniedCreateStep09WorkerRuntime(): void
    { EngineMigrationFailureTest::deniedDdlAt(9); }

    #[Test]
    public function deniedCreateStep10WorkerProgress(): void
    { EngineMigrationFailureTest::deniedDdlAt(10); }

    #[Test]
    public function deniedCreateStep11CheckHistory(): void
    { EngineMigrationFailureTest::deniedDdlAt(11); }

    #[Test]
    public function deniedCreateStep12Incidents(): void
    { EngineMigrationFailureTest::deniedDdlAt(12); }

    #[Test]
    public function deniedCreateStep13IncidentCheckpoints(): void
    { EngineMigrationFailureTest::deniedDdlAt(13); }

    #[Test]
    public function deniedCreateStep14NotificationSettings(): void
    { EngineMigrationFailureTest::deniedDdlAt(14); }

    #[Test]
    public function deniedCreateStep15NotificationCheckpoints(): void
    { EngineMigrationFailureTest::deniedDdlAt(15); }

    #[Test]
    public function deniedCreateStep16NotificationSlots(): void
    { EngineMigrationFailureTest::deniedDdlAt(16); }

    #[Test]
    public function deniedCreateStep17ReadApiToken(): void
    { EngineMigrationFailureTest::deniedDdlAt(17); }

    #[Test]
    public function deniedCreateStep18SelfUpdate(): void
    { EngineMigrationFailureTest::deniedDdlAt(18); }
}
