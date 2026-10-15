<?php
declare(strict_types=1);

namespace Tablo;

final class EndpointRequestHeaders
{
    public static function validate(#[\SensitiveParameter] array $headers, int $maxRedirects): void
    {
        if ($maxRedirects < 1 || $maxRedirects > 3) { throw new HttpFailure('check-error'); }
        if ($headers !== [] && (count($headers) !== 1 || !is_string($headers[0] ?? null)
            || !preg_match('/\AAuthorization: Bearer [\x21-\x7e]{1,512}\z/', $headers[0]))) { throw new HttpFailure('check-error'); }
    }

}
