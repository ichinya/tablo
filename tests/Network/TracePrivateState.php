<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;







class TracePrivateState
{
    public function __construct(private string $marker) {}
    public function privateMarker(): string { return $this->marker; }
}
