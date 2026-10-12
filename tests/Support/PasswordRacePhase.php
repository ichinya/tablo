<?php
declare(strict_types=1);
namespace Tablo\Tests\Support;
enum PasswordRacePhase
{
    case VerifiedLogin;
    case PasswordRehash;
}
