<?php
declare(strict_types=1);

namespace Tablo;

final class EndpointResponse
{
    public static function validate(#[\SensitiveParameter] array $response): void
    {
        if (!is_int($response['status'] ?? null) || $response['status'] < 100 || $response['status'] > 599
            || !is_string($response['body'] ?? null)) { throw new HttpFailure('invalid-response'); }
    }

}
