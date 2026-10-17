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
        foreach ([str_repeat('x', times: 12), str_repeat('x', times: 72), str_repeat('x', times: 73), str_repeat('x', times: 1023), str_repeat('x', times: 1024), str_repeat('ж', times: 512), '  spaces remain  ', "line\nbreak-secret"] as $password) {
            $hash = PasswordService::hash($password, $password);
            Assert::same(password_get_info($hash)['algoName'], 'argon2id');
            Assert::same(password_get_info($hash)['options'], ['memory_cost' => 65_536, 'time_cost' => 3, 'threads' => 1]);
            Assert::true(password_verify($password, $hash));
            Assert::false(PasswordService::verify($password . 'x', $hash), 'the full suffix participates');
            Assert::true($hash !== PasswordService::hash($password, $password), 'generated salts');
        }
        foreach ([str_repeat('x', times: 11), str_repeat('x', times: 1025), str_repeat('ж', times: 513), "synthetic\0secret"] as $password) {
            (new \Tablo\Tests\Support\FixtureRejection(\Closure::fromCallable(static fn () => PasswordService::hash($password, $password))))->verify('invalid byte input accepted');
        }
        (new \Tablo\Tests\Support\FixtureRejection(\Closure::fromCallable(static fn () => PasswordService::hash('  spaces remain  ', 'spaces remain'))))->verify('trimmed confirmation accepted');
        foreach ([str_repeat('x', times: 1025), "confirmation\0secret"] as $confirmation) {
            (new \Tablo\Tests\Support\FixtureRejection(\Closure::fromCallable(static fn () => PasswordService::hash('valid-password', $confirmation))))->verify('unbounded confirmation accepted');
        }
    }

    #[Test]
    public function preservesRawLegacyBytesAndRefusesBcryptPrefixAliasesBeforeVerification(): void
    {
        foreach (['', 'short', "\xFFraw\nbytes", str_repeat('x', times: 72), str_repeat('x', times: 71) . "\xD0"] as $password) {
          foreach (['2y', '2a', '2b'] as $prefix) {
            $hash = '$' . $prefix . '$' . substr(password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]), offset: 4);
            Assert::true(password_verify($password, $hash), 'independent native ' . $prefix . ' control');
            Assert::true(PasswordService::verify($password, $hash));
            Assert::false(password_verify('incorrect-password', $hash));
            Assert::false(PasswordService::verify('incorrect-password', $hash));
            Assert::false(PasswordService::verify($password . "\0", $hash));
            if (strlen($password) === 72) {
                Assert::true(password_verify($password . 'suffix', $hash), 'independent native truncation control');
                Assert::false(PasswordService::verify($password . 'suffix', $hash));
            }
          }
        }
        $crossing = str_repeat('x', times: 71) . 'ж';
        $hash = password_hash(substr($crossing, offset: 0, length: 72), PASSWORD_BCRYPT, ['cost' => 4]);
        Assert::true(password_verify($crossing, $hash));
        Assert::false(PasswordService::verify($crossing, $hash));
        $composed = PasswordService::hash('é-raw-password', 'é-raw-password');
        Assert::false(PasswordService::verify("e\u{0301}-raw-password", $composed));
        Assert::false(PasswordService::verify('  valid-password ', PasswordService::hash(' valid-password ', ' valid-password ')));
    }
}
