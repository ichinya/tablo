<?php
declare(strict_types=1);

namespace Tablo;

final class CheckAbortProjection
{
    public static function fragments(CheckProgress $progress,string $reason,array $snapshot,int $index): void
    {
        $active=$progress->active; $record=$progress->record; $storeAttempted=$progress->storeAttempted; $fragments = &$progress->fragments;
            if ($active !== null) {
                try {
                    if ($record !== null) {
                        $record['status'] = 'failed'; $record['reason'] = $reason; $record['attention'] = true;
                        if ($record['persistence']['accepted'] === null) {
                            $record['persistence']['status'] = $storeAttempted ? 'unknown' : 'not-attempted';
                        }
                    }
                    if ($record === null) { $record = CheckOutput::site($active, 'failed', $reason, true); }
                    // The record is the already validated scalar projection. Preserve
                    // actual settlement even if the ordinary fragment encoder failed.
                    $fragments[] = \json_encode($record, JSON_THROW_ON_ERROR);
                } catch (\Throwable $discardedCleanup) { /* An invalid ID is unrepresentable; earlier safe fragments survive. */  unset($discardedCleanup); }
            }
            foreach (array_slice($snapshot, $index + 1) as $site) {
                try { $fragments[] = CheckOutput::encode(CheckOutput::site($site, 'skipped', 'run-aborted', null)); }
                catch (\Throwable $discardedCleanup) { /* Preserve every representable snapshot ID. */  unset($discardedCleanup); }
            }
    }
}
