<?php
declare(strict_types=1);

namespace Tablo;

// Installation custody failure, distinct from one damaged encrypted credential.
final class SharedKeyFailure extends \RuntimeException {}
