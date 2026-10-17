<?php
declare(strict_types=1);

namespace Tablo;

final class PasswordHashPolicy
{
    /** @return array{memory_cost: int, time_cost: int, threads: int} */
    public static function creationOptions(): array
    {
        return ['memory_cost' => 65_536, 'time_cost' => 3, 'threads' => 1];
    }

    // A lexical Argon result permits unsupported-runtime diagnosis only.
    // Verification and replacement additionally require native recognition.
    public static function storedAlgorithm(#[\SensitiveParameter] string $hash): ?string
    {
        if (strlen($hash) > 512) { return null; }
        if (preg_match('/^\$2[aby]\$(?:0[4-9]|[12][0-9]|3[01])\$[.\/A-Za-z0-9]{53}$/D', $hash) === 1) {
            return password_get_info($hash)['algo'] === '2y' ? '2y' : null;
        }
        if (self::encodedOptions($hash) === null) { return null; }
        return str_starts_with($hash, '$argon2id$') ? 'argon2id' : 'argon2i';
    }

    /** @return ?array{memory_cost: int, time_cost: int, threads: int} */
    public static function upgradeOptions(#[\SensitiveParameter] string $hash): ?array
    {
        $algorithm = self::storedAlgorithm($hash);
        if ($algorithm === '2y') { return self::creationOptions(); }
        if ($algorithm === null) { return null; }
        $options = self::encodedOptions($hash);
        /** @var array{algo: ?string, options: array{memory_cost?: int, time_cost?: int, threads?: int}, ...} $info */
        $info = password_get_info($hash);
        if ($options === null || [$info['algo'], $info['options']] !== [$algorithm, $options]) { return null; }
        if ([$algorithm, $options['memory_cost'] >= 65_536, $options['time_cost'] >= 3] === ['argon2id', true, true]) {
            return null;
        }
        return ['memory_cost' => max(65_536, $options['memory_cost']), 'time_cost' => max(3, $options['time_cost']), 'threads' => 1];
    }

    /** @return ?array{memory_cost: int, time_cost: int, threads: int} */
    private static function encodedOptions(#[\SensitiveParameter] string $hash): ?array
    {
        $parts = [];
        if (preg_match('/^\$argon2(?:id|i)\$v=19\$m=([1-9][0-9]*),t=([1-9][0-9]*),p=([1-9][0-9]*)\$[A-Za-z0-9+\/]+\$[A-Za-z0-9+\/]+$/D', $hash, $parts) !== 1) { return null; }
        $memory = filter_var(RegexCapture::text($parts, 1), FILTER_VALIDATE_INT);
        $time = filter_var(RegexCapture::text($parts, 2), FILTER_VALIDATE_INT);
        $threads = filter_var(RegexCapture::text($parts, 3), FILTER_VALIDATE_INT);
        if (!is_int($memory) || !is_int($time) || !is_int($threads)) { return null; }
        return ['memory_cost' => $memory, 'time_cost' => $time, 'threads' => $threads];
    }
}
