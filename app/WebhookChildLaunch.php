<?php
declare(strict_types=1);

namespace Tablo;

final class WebhookChildLaunch
{
    public static function start(WebhookProcess $child,?string $script): void
    {
            $child->process=proc_open([PHP_BINARY,'-d','display_errors=0','-d','log_errors=0',
                $script??dirname(__DIR__).'/bin/webhook-attempt.php'],
                [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$child->pipes,cwd: null,
                env_vars: PHP_OS_FAMILY==='Windows'?['SystemRoot'=>self::environmentValue('SystemRoot'), 'WINDIR'=>self::environmentValue('WINDIR')]:[],
                options: ['bypass_shell'=>true,'suppress_errors'=>true]);
            if (!is_resource($child->process)) { throw new WebhookFailure('child-start'); }
        WebhookCustody::active($child);
    }

    private static function environmentValue(string $name): string
    {
        $value = getenv($name);
        return $value === false ? '' : $value;
    }
}
