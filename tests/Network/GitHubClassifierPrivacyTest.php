<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\GitHubRateLimit;
use Testo\Assert;
use Testo\Test;

final class GitHubClassifierPrivacyTest extends GitHubPrivacyFixtureCase
{


    #[Test]
    public function classifierBoundaryMasksTheCompleteResponseOnATypeFailure(): void
    {
        $original = ini_get('zend.exception_ignore_args');
        \Tablo\RuntimeIni::set('zend.exception_ignore_args', value: '0');
        try {
            $marker = bin2hex(random_bytes(24));
            $error = null;
            // A deliberate body-contract error captures the otherwise returning classifier frame.
            try { GitHubRateLimit::fromResponse('core', ['status' => 403, 'body' => [$marker], $marker => 'safe'], time()); }
            catch (\TypeError $caught) { $error = $caught; }
            Assert::instanceOf($error, \TypeError::class);
            $masked = false;
            foreach ($error->getTrace() as $frame) {
                if (!(($frame['class'] ?? '') === GitHubRateLimit::class && ($frame['function'] ?? '') === 'fromResponse')) { continue; }

                    $masked = ($frame['args'][1] ?? null) instanceof \SensitiveParameterValue;

            }
            Assert::true($masked, 'actual classifier frame masks the whole response, including keys');
        } finally { unset($frame, $caught, $error); \Tablo\RuntimeIni::set('zend.exception_ignore_args', $original); }
    }
}
