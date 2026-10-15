<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\ValidationException;
use Testo\Assert;

final class KeyFailureTraceAssertions
{
    public static function redacted(#[\SensitiveParameter] ValidationException $error, #[\SensitiveParameter] string $secret): void
    {
                        for ($cause = $error; $cause !== null; $cause = $cause->getPrevious()) {
                            $trace = json_encode([$cause->getTrace(), (string) $cause], JSON_THROW_ON_ERROR);
                            Assert::false(str_contains($trace, $secret), 'complete propagated and previous traces protect manual token');
                            Assert::false(str_contains($cause->getMessage(), $secret));
                            unset($trace);
                        }
                        Assert::same(array_keys($error->errors), ['github_token']);
    }
}
