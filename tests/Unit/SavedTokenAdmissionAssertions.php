<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\GitTokenRepository;
use Tablo\GitProviders;

use Testo\Assert;

final class SavedTokenAdmissionAssertions
{
    public function __construct(private readonly GitTokenRepository $tokens) {}

    public function refuses(array $input): void
    {
            (new \Tablo\Tests\Support\FixtureRejection(\Closure::fromCallable(fn () => $this->tokens->save(array_replace($input, ['name' => 'SHARED TOKEN'])))))->verify('duplicate name accepted');
            (new \Tablo\Tests\Support\FixtureRejection(\Closure::fromCallable(fn () => $this->tokens->save(array_replace($input, ['name' => 'Another', 'token' => ''])))))->verify('empty new token accepted');
            (new \Tablo\Tests\Support\FixtureRejection(\Closure::fromCallable(fn () => $this->tokens->save(array_replace($input, ['provider' => 'gitlab'])))))->verify('unimplemented provider accepted');
            Assert::true(GitProviders::available() === ['github' => 'GitHub'], 'providers misrepresented');

    }
}
