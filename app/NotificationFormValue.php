<?php
declare(strict_types=1);

namespace Tablo;

final class NotificationFormValue
{
    public static function string(#[\SensitiveParameter] mixed $value): string
    {
        if (!is_string($value)) { NotificationFormInput::invalid(); }
        return $value;
    }
    public static function revision(mixed $value): string
    {
        $revision = self::string($value);
        if (!preg_match('/^(0|[1-9][0-9]{0,15})$/D', $revision)) { NotificationFormInput::invalid(); }
        return $revision;
    }
}
