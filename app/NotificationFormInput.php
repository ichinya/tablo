<?php
declare(strict_types=1);

namespace Tablo;

/**
 * @phpstan-import-type Flags from NotificationFlags
 * @phpstan-type Form array{revision: string, endpoint: string, bearer: string, flags: Flags}
 */
final class NotificationFormInput
{
    /** @return Form */
    public static function parse(#[\SensitiveParameter] array $input): array
    {
        $revision = NotificationFormValue::revision($input['revision'] ?? null);
        $endpoint = NotificationFormValue::string($input['endpoint'] ?? '');
        $bearer = NotificationFormValue::string($input['bearer'] ?? '');
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
