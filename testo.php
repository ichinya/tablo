<?php
declare(strict_types=1);

use Testo\Application\Config\ApplicationConfig;
use Testo\Application\Config\SuiteConfig;

return new ApplicationConfig(suites: [
    new SuiteConfig(name: 'Unit', location: [__DIR__ . '/tests/Unit']),
    new SuiteConfig(name: 'Network', location: [__DIR__ . '/tests/Network']),
    new SuiteConfig(name: 'Http', location: [__DIR__ . '/tests/Http']),
]);
