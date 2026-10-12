<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\PasswordService;
use Testo\Assert;
use Testo\Test;

final class PasswordServiceTest
{
    #[Test]
    public function validatesExactBytesAndConfirmationWithoutTrimming(): void
    {
        foreach ([str_repeat('x', times: 12), str_repeat('x', times: 72), str_repeat('ж', times: 36), '  spaces remain  ', "line\nbreak-secret"] as $password) {
            $hash = PasswordService::hash($password, $password);
            Assert::true(password_verify($password, $hash));
            Assert::true($hash !== PasswordService::hash($password, $password), 'generated salts');
        }
        foreach ([str_repeat('x', times: 11), str_repeat('x', times: 73), str_repeat('ж', times: 37), "synthetic\0secret"] as $password) {
            (new \Tablo\Tests\Support\FixtureRejection(\Closure::fromCallable(static fn () => PasswordService::hash($password, $password))))->verify('invalid byte input accepted');
        }
        (new \Tablo\Tests\Support\FixtureRejection(\Closure::fromCallable(static fn () => PasswordService::hash('  spaces remain  ', 'spaces remain'))))->verify('trimmed confirmation accepted');
    }
}
