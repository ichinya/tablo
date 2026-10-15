<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\PasswordService;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;
use Testo\Test;

final class PasswordServiceTest
{
    #[Test]
    public function validatesExactBytesAndConfirmationWithoutTrimming(): void
    {
        foreach ([str_repeat('x', 12), str_repeat('x', 72), str_repeat('ж', 36), '  spaces remain  ', "line\nbreak-secret"] as $password) {
            $hash = PasswordService::hash($password, $password);
            Assert::true(password_verify($password, $hash));
            Assert::true($hash !== PasswordService::hash($password, $password), 'generated salts');
        }
        foreach ([str_repeat('x', 11), str_repeat('x', 73), str_repeat('ж', 37), "synthetic\0secret"] as $password) {
            UnitFixtures::rejects(fn () => PasswordService::hash($password, $password), 'invalid byte input accepted');
        }
        UnitFixtures::rejects(fn () => PasswordService::hash('  spaces remain  ', 'spaces remain'), 'trimmed confirmation accepted');
    }
}
