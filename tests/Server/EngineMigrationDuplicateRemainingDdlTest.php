<?php
declare(strict_types=1);

namespace Tablo\Tests\Server;

use Testo\Test;

/** Separate native8s cases; the reviewed explicit fixture role is required, with no skip fallback. */
final class EngineMigrationDuplicateRemainingDdlTest
{
    #[Test]
    public function duplicateCreateAfterStep09WorkerRuntime(): void
    { EngineMigrationFailureTest::duplicateDdlAt(9); }

    #[Test]
    public function duplicateCreateAfterStep10WorkerProgress(): void
    { EngineMigrationFailureTest::duplicateDdlAt(10); }

    #[Test]
    public function duplicateCreateAfterStep11CheckHistory(): void
    { EngineMigrationFailureTest::duplicateDdlAt(11); }

    #[Test]
    public function duplicateCreateAfterStep12Incidents(): void
    { EngineMigrationFailureTest::duplicateDdlAt(12); }

    #[Test]
    public function duplicateCreateAfterStep13IncidentCheckpoints(): void
    { EngineMigrationFailureTest::duplicateDdlAt(13); }

    #[Test]
    public function duplicateCreateAfterStep14NotificationSettings(): void
    { EngineMigrationFailureTest::duplicateDdlAt(14); }

    #[Test]
    public function duplicateCreateAfterStep15NotificationCheckpoints(): void
    { EngineMigrationFailureTest::duplicateDdlAt(15); }

    #[Test]
    public function duplicateCreateAfterStep16NotificationSlots(): void
    { EngineMigrationFailureTest::duplicateDdlAt(16); }

    #[Test]
    public function duplicateCreateAfterStep17ReadApiToken(): void
    { EngineMigrationFailureTest::duplicateDdlAt(17); }

    #[Test]
    public function duplicateCreateAfterStep18SelfUpdate(): void
    { EngineMigrationFailureTest::duplicateDdlAt(18); }
}
