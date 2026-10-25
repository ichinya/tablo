<?php
declare(strict_types=1);

namespace Tablo;

/** Isolated test-only interception; unmatched paths always use the native operation. */
function link(#[\SensitiveParameter] string $from, #[\SensitiveParameter] string $to): bool
{ return \Tablo\Tests\Support\TransferPublicationFault::link($from, $to); }

function unlink(#[\SensitiveParameter] string $path): bool
{ return \Tablo\Tests\Support\TransferPublicationFault::unlink($path); }
