<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;







final class TraceProtectedState extends TracePrivateState
{
    public string $uninitialized;
    public function __construct(protected readonly string $marker) { parent::__construct('safe'); }
}
