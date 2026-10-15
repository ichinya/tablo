<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use Tablo\ValidationException;
use Testo\Assert;

/** Owns the active callback so a failed assertion carries scalar trace arguments. */
final class FixtureRejection
{
    public function __construct(private readonly \Closure $action) {}

    public function verify(string $message): void
    {
        $exception = null;
        try { ($this->action)(); } catch (ValidationException $error) { $exception = $error; }
        Assert::instanceOf($exception, ValidationException::class, $message);
    }
}
