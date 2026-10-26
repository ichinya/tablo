<?php
declare(strict_types=1);

use Testo\Application\Config\ApplicationConfig;
use Testo\Application\Config\SuiteConfig;

return new ApplicationConfig(suites: [
    new SuiteConfig(name: 'Server', location: [__DIR__ . '/tests/Server']),
    new SuiteConfig(name: 'ServerCross', location: [__DIR__ . '/tests/ServerCross']),
]);
