<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-import-type Frame from GitHubFrame */
final class GitSecondaryEvidence
{
    /** @param Frame $response */
    public static function limited(#[\SensitiveParameter] array $response,?int $retry,?int $remaining): bool
    {
        return in_array($response['status'],[403,429],strict: true) && ($retry !== null
            || ($response['status'] === 429 && $remaining !== 0) || GitRateEvidence::secondaryMessage($response['body']));
    }
}
