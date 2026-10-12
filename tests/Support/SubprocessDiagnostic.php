<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;


final class SubprocessDiagnostic
{
    public static function describe(SubprocessState $state): string
    {
        $parts = ['receipt=' . $state->files['receipt']];
        foreach (['stdout', 'stderr'] as $stream) {
            $bytes = \Tablo\RequiredFile::bytes(file_get_contents(\Tablo\Tests\Support\FixtureValue::at($state->files, $stream)));
            $bytes = str_replace($state->secrets, replace: '[stdin redacted]', subject: $bytes);
            $bytes = preg_replace('/\$(?:2[aby]|argon2id|argon2i)\$[^\s"<>]+/', replacement: '[hash redacted]', subject: $bytes);
            $parts[] = $stream . '=' . \Tablo\Tests\Support\FixtureValue::string(json_encode(substr(\Tablo\Tests\Support\FixtureValue::string($bytes), offset: 0, length: 2048), JSON_INVALID_UTF8_SUBSTITUTE));
        }
        return implode('; ', $parts);
    }
}
