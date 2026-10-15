<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-import-type Result from WebhookChildResultShape */
final class WebhookChildResult
{
    /** @return Result */
    public static function read(WebhookProcess $child): array
    {
            $stdout=stream_get_contents($child->pipe(1),length: 1025); $stderr=stream_get_contents($child->pipe(2),length: 1025);
            if (!is_string($stdout) || strlen($stdout)>1024 || $stderr!=='' || $child->observed()['exitcode']!==0) { throw new WebhookFailure('child-frame'); }
            return WebhookChildResultShape::checked(json_decode($stdout,associative: true,depth: 8,flags: JSON_THROW_ON_ERROR));
    }
}
