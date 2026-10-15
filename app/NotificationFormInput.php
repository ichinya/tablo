<?php
declare(strict_types=1);

namespace Tablo;

final class NotificationFormInput
{
    public static function parse(#[\SensitiveParameter] array $input): array
    {
        $revision = $input['revision'] ?? null;
        if (!is_string($revision) || !preg_match('/^(0|[1-9][0-9]{0,15})$/D', $revision)) { self::invalid(); }
        $endpoint = $input['endpoint'] ?? '';
        $bearer = $input['bearer'] ?? '';
        if (!is_string($endpoint) || !is_string($bearer)) { self::invalid(); }
        $flags = NotificationFlags::parse($input);
        if (($flags['remove_endpoint'] && $endpoint !== '') || ($flags['remove_bearer'] && $bearer !== '')) { self::invalid(); }
        if ($endpoint !== '') { WebhookAddress::parts($endpoint); }
        if ($bearer !== '' && !preg_match('/^[\x21-\x7e]{1,512}$/D', $bearer)) { self::invalid(); }
        return ['revision' => $revision, 'endpoint' => $endpoint, 'bearer' => $bearer, 'flags' => $flags];
    }
    public static function invalid(): never
    {
        throw ValidationException::field(field: 'notifications', message: 'Проверьте адрес HTTPS, выбранные события и значения полей.');
    }
}
