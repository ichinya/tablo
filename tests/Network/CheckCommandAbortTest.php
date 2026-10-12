<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Testo\Assert;
use Testo\Test;

final class CheckCommandAbortTest extends CheckCommandFixtureCase
{



    #[Test]
    public function lateSharedAndEncodingFailuresRetainPriorCompleteFragments(): void
    {
        foreach (['drop', 'key-loss', 'encode', 'encode-after-store', 'timestamp', 'scalar'] as $failure) {
            try {
                $this->start(); $this->row(['github_token' => $this->marker]);
                $this->row(['health_path' => $failure === 'scalar' ? '' : (match (true) { (in_array($failure, ['drop', 'key-loss'], strict: true)) => '/' . $failure, default => '/ok' })]);
                $this->row();
                $result = $this->run(changes: ['TABLO_CHECK_INJECT' => in_array($failure, ['drop', 'key-loss'], strict: true) ? '' : $failure]);
                $document = $this->document($result, 'fatal', 1); Assert::same(count($document['sites']), 3);
                Assert::same(\Tablo\Tests\Support\FixtureValue::at($document['sites'], 0)['persistence']['accepted'], true); Assert::same(\Tablo\Tests\Support\FixtureValue::row(\Tablo\Tests\Support\FixtureValue::at($document['sites'], 0))['reason'], 'ok');
                Assert::same(\Tablo\Tests\Support\FixtureValue::at($document['sites'], 1)['status'], 'failed'); Assert::same(\Tablo\Tests\Support\FixtureValue::at($document['sites'], 2)['status'], 'skipped');
                Assert::same($document['run']['reason'], match ($failure) { 'drop' => 'storage-failed', 'key-loss' => 'key-unavailable', default => 'serialization-failed' });
                if ($failure === 'encode') { Assert::same(\Tablo\Tests\Support\FixtureValue::row(\Tablo\Tests\Support\FixtureValue::at($document['sites'], 1))['persistence']['status'], 'not-attempted'); }
                if ($failure === 'encode-after-store') { Assert::same(\Tablo\Tests\Support\FixtureValue::row(\Tablo\Tests\Support\FixtureValue::at($document['sites'], 1))['persistence']['accepted'], true); }
                if ($failure === 'key-loss') { Assert::false(is_file(\Tablo\Tests\Support\FixtureValue::object($this->directory)->path . '/github-token.key')); }
            } catch (\Throwable $error) { $this->directory?->retainFailure($error); throw $error; }
            finally { $this->close(); }
        }
    }
}
