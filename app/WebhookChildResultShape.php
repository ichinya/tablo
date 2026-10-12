<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-type Result array{code: string, http_status?: ?int} */
final class WebhookChildResultShape
{
    public static function validate(mixed $result): void
    {
        self::checked($result);
    }

    /** @return Result */
    public static function checked(mixed $result): array
    {
            $allowed=['sent','http','redirect','timeout','dns','ssrf','tls','refused','network','size','invalid-url','invalid-data','invalid-response'];
            if (!is_array($result) || array_diff(array_keys($result),['code','http_status'])!==[]
                || !in_array($result['code']??null,$allowed,strict: true)
                || ((($result['http_status'] ?? null) !== null) && (!is_int($result['http_status']) || $result['http_status']<100 || $result['http_status']>599))) { throw new WebhookFailure('child-frame'); }
            /** @var Result $validated */
            $validated = $result;
            if ($validated['code']==='sent' && (!(($validated['http_status'] ?? null) !== null) || $validated['http_status']<200 || $validated['http_status']>=300)) { throw new WebhookFailure('child-frame'); }
            return $validated;
    }
}
