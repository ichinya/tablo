<?php
declare(strict_types=1);

namespace Tablo;

final class GitRateEvidence
{
    public static function integer(mixed $value): ?int
    {
        if (!is_string($value) || !preg_match('/^[0-9]{1,18}$/D', $value)) { return null; }
        return (int) $value;
    }
    public static function secondaryMessage(string $body): bool
    {
        return self::hasSecondaryMessage(json_decode($body, associative: true, depth: 32));
    }
    private static function hasSecondaryMessage(mixed $document): bool
    {
        return is_array($document) && is_string($document['message'] ?? null)
            && stripos($document['message'], needle: 'secondary rate limit') !== false;
    }

}
