<?php
declare(strict_types=1);

namespace Tablo;

final class CheckProgress
{
    public ?array $active=null;
    public ?array $record=null;
    public bool $storeAttempted=false;
    public string $phase='checking';
    public array $fragments=[];
}
