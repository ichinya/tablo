<?php
declare(strict_types=1);
namespace Tablo;

final class WebhookFailure extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Webhook attempt failed.'); // Never attach native previous exceptions.
    }
}
