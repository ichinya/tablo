<?php
declare(strict_types=1);

namespace Tablo;

final class UriSyntax
{
    public static function validate(#[\SensitiveParameter] string $value): void
    {
        if ($value === '' || strlen($value) > 8192 || preg_match('/[^A-Za-z0-9\-._~!$&\x27()*+,;=:@\/?%\[\]]/', $value)
            || preg_match('/%(?![0-9a-fA-F]{2})/', $value)) { throw new HttpFailure('invalid-url'); }
    }
}
