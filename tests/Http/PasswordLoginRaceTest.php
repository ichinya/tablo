<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;


use Testo\Test;

final class PasswordLoginRaceTest extends PasswordHttpFixtureCase
{


    #[Test]
    public function verifiedOldLoginCannotAcquireTheReplacementSnapshot(): void
    {
        $this->loginRace(\Tablo\Tests\Support\PasswordRacePhase::VerifiedLogin);
    }



    #[Test]
    public function rehashCallerCannotAcquireAConcurrentResetSnapshot(): void
    {
        $this->loginRace(\Tablo\Tests\Support\PasswordRacePhase::PasswordRehash);
    }
}
