<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Tests\Support\TemporaryDirectory;

/** Retain the one owned scenario's complete direct-child receipt even on failure. */
/** @phpstan-import-type Receipt from \Tablo\Tests\Support\SubprocessReceiptData */
final class ResponsePrivacyRecord
{
    public static function record(string $file, string $function, array $command, TemporaryDirectory $directory, float $timeout): void
    {
        $target = getenv('TABLO_RESPONSE_PRIVACY_REPORT');
        if ($target === false || $target === '') { return; }
        $receipts = \Tablo\Tests\Support\FixtureFiles::matches($directory->path . '/command-*.json');
        $receipt = count($receipts) === 1
            ? \Tablo\Tests\Support\SubprocessReceipt::read(\Tablo\Tests\Support\FixtureValue::at($receipts, 0)) : null;
        $record = ['file' => $file, 'function' => $function, 'command' => $command, 'scenario_timeout_seconds' => $timeout,
            'test_source_sha256' => hash_file('sha256', $file), 'bindings_sha256' => hash_file('sha256', filename: 'contracts/php-bindings.json'),
            'reviewed_dependencies_sha256' => hash_file('sha256', filename: 'contracts/reviewed-dependencies.json'),
            'receipt_count' => count($receipts), 'receipt' => $receipt,
            'stdout' => self::stream($receipt, 'stdout'), 'stderr' => self::stream($receipt, 'stderr')];
        file_put_contents($target, json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    }

    /** @param Receipt|null $receipt
     * @param 'stdout'|'stderr' $stream */
    private static function stream(?array $receipt, string $stream): ?string
    {
        if ($receipt === null) { return null; }
        $selected = $stream === 'stdout' ? $receipt['streams']['stdout'] : $receipt['streams']['stderr'];
        return \Tablo\RequiredFile::bytes(file_get_contents($selected['path']));
    }
}
