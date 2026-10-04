<?php
declare(strict_types=1);

namespace Tablo;

final class GitProviders
{
    // Provider IDs also identify saved credentials. Add adapters here when supported.
    public static function available(): array { return ['github' => 'GitHub']; }

    public static function requireSupported(string $provider): void
    {
        if ((self::available()[$provider] ?? null) === null) {
            throw new ValidationException(['provider' => 'Этот Git-провайдер пока не поддерживается.']);
        }
    }
}
