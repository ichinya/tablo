<?php
declare(strict_types=1);

// The owner closes only the stderr OS pipe reader before releasing this child.
// stdout remains an independent file. No PHP stream constant or write is replaced.
if (fgets(STDIN) !== "go\n") { exit(70); }
require __DIR__ . '/check-transport.php';
