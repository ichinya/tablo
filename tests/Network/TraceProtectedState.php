<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;







final class TraceProtectedState extends TracePrivateState
{
    // Intentional native typed-slot fault proves that trace inspection cannot claim complete coverage.
    // @mago-expect analysis:uninitialized-property(1)
    public string $uninitialized;
    public function __construct(protected readonly string $marker) { parent::__construct('safe'); }
    public function protectedMarker(): string { return $this->marker; }
}
