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
        $document = json_decode($body, associative: true, depth: 32);
        $message = is_array($document) ? ($document['message'] ?? null) : null;
        return is_string($message) && stripos($message, needle: 'secondary rate limit') !== false;
    }

}
