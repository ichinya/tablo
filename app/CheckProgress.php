<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-import-type Record from CheckSiteOutput */
final class CheckProgress
{
    public ?array $active=null;
    /** @var Record|null */
    public ?array $record=null;
    public bool $storeAttempted=false;
    public string $phase='checking';
    /** @var list<string> */
    public array $fragments=[];
}
