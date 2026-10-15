<?php
declare(strict_types=1);

namespace Tablo;

final class NotificationFlags
{
    public static function parse(#[\SensitiveParameter] array $input): array
    {
        $flags = [];
        foreach (['enabled','unavailable','recovery','version_lag','include_name','include_url','remove_endpoint','remove_bearer'] as $key) {
            $value = $input[$key] ?? '0';
            if (!is_string($value) || !in_array($value, ['0','1'], strict: true)) { NotificationFormInput::invalid(); }
            $flags[$key] = (int) $value;
        }
        return $flags;
    }
}
