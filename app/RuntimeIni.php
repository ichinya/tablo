<?php
declare(strict_types=1);

namespace Tablo;

/** Runtime privacy, session and native transport settings whose previous values remain restorable. */
final class RuntimeIni
{
    public static function set(string $option, string|int|float|bool|null $value): string|false
    {
        if (!in_array($option, ['display_errors', 'log_errors', 'session.use_strict_mode',
            'session.use_only_cookies', 'zend.exception_ignore_args', 'zend.exception_string_param_max_len', 'mysqlnd.net_read_buffer_size'], strict: true)) {
            throw new \InvalidArgumentException('Unsupported runtime privacy setting.');
        }
        // @mago-expect lint:no-ini-set
        return ini_set($option, $value);
    }
}
