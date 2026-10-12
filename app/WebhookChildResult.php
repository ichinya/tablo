<?php
declare(strict_types=1);

namespace Tablo;

final class WebhookChildResult
{
    public static function read(WebhookProcess $child): array
    {
            $stdout=stream_get_contents($child->pipes[1],length: 1025); $stderr=stream_get_contents($child->pipes[2],length: 1025);
            if (!is_string($stdout) || strlen($stdout)>1024 || $stderr!=='' || $child->status['exitcode']!==0) { throw new WebhookFailure('child-frame'); }
            $result=json_decode($stdout,associative: true,depth: 8,flags: JSON_THROW_ON_ERROR);
            WebhookChildResultShape::validate($result);
            return $result;
    }
}
