<?php
declare(strict_types=1);

namespace Tablo;

/** Runtime privacy and session settings whose previous values remain restorable. */
final class RuntimeIni
{
    // COLD_NESTED_BEGIN 900
    public const COLD_NESTED_NO_TABLE = '';
    /** @var \Closure(int, int, string=, int=): void|null */
    public static ?\Closure $coldNestedProbe = null;
    // COLD_NESTED_END 900
    public static function set(string $option, string|int|float|bool|null $value): string|false
    {
        if (!in_array($option, ['display_errors', 'log_errors', 'session.use_strict_mode',
            'session.use_only_cookies', 'zend.exception_ignore_args', 'zend.exception_string_param_max_len'], strict: true)) {
            throw new \InvalidArgumentException('Unsupported runtime privacy setting.');
        }
        // @mago-expect lint:no-ini-set
        return ini_set($option, $value);
    }
}
