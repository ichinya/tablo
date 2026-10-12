<?php
declare(strict_types=1);

namespace Tablo;

final class UriPath
{
    public static function removeDots(#[\SensitiveParameter] string $input): string
    {
        // RFC 3986 section 5.2.4; encoded dots/separators are left encoded.
        $output = '';
        while ($input !== '') {
            switch (true) {
case (str_starts_with($input, '../')):
 $input = substr($input, offset: 3);
    break;
case (str_starts_with($input, './')):
 $input = substr($input, offset: 2);
    break;
case (str_starts_with($input, '/./')):
 $input = substr($input, offset: 2);
    break;
case ($input === '/.'):
 $input = '/';
    break;
case (str_starts_with($input, '/../') || $input === '/..'):

                $input = $input === '/..' ? '/' : substr($input, offset: 3);
                $last = strrpos($output, needle: '/');
                $output = $last === false ? '' : substr($output, offset: 0, length: $last);

    break;
case ($input === '.' || $input === '..'):
 $input = '';
    break;
default:

                $next = strpos($input, needle: '/', offset: str_starts_with($input, '/') ? 1 : 0);
                $length = $next === false ? strlen($input) : $next;
                $output .= substr($input, offset: 0, length: $length);
                $input = substr($input, $length);

    break;
}
        }
        return $output;
    }
}
