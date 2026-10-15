<?php
declare(strict_types=1);

namespace Tablo;

final class GitOutputInvariant
{

    public static function validate(string $field, mixed $value, string $status, string $reason): void
    {
        if (($status === 'absent' && ($field !== 'latest_release' || $value !== null || $reason !== 'no-release'))
            || ($status === 'ok' && ($value === null || $reason !== 'ok'))
            || ($status === 'failed' && ($value !== null || in_array($reason, ['ok', 'no-release'], strict: true)))) { CheckScalar::invalid(); }
    }

}
