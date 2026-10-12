<?php
declare(strict_types=1);

namespace Tablo;

final class WebhookProcess
{
    public mixed $process = null;
    public array $pipes = [];
    public ?array $status = null;
}
