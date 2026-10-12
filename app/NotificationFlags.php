<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-type Flags array{enabled: int, include_name: int, include_url: int, recovery: int, remove_bearer: int, remove_endpoint: int, unavailable: int, version_lag: int} */
final class NotificationFlags
{
    /** @return Flags */
    public static function parse(#[\SensitiveParameter] array $input): array
    {
        $flags = [];
        foreach (['enabled','unavailable','recovery','version_lag','include_name','include_url','remove_endpoint','remove_bearer'] as $key) {
            if (!is_string($input[$key] ?? '0') || !in_array($input[$key] ?? '0', ['0','1'], strict: true)) { NotificationFormInput::invalid(); }
            $flags[$key] = (int) ($input[$key] ?? '0');
        }
        return $flags;
    }
}
