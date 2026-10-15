<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-import-type Record from CheckSiteOutput */
final class CheckSettlementOutput
{
    /** @param Record $record */
    public static function settle(array &$record,CheckSettlementState $state): void
    {
        $stored=$state->stored; $failed=$state->failed;
        $acceptedAt = null;
        if ($stored) {
            if ($record['observation'] === null) { CheckScalar::invalid(); }
            $acceptedAt = $record['observation']['checked_at'];
        }
                        $record['reason'] = self::reason($state);
                        $record['attention'] = $failed;
                        $record['persistence']['status'] = $stored ? 'accepted' : 'rejected-stale';
                        $record['persistence']['accepted'] = $stored;
                        $record['persistence']['accepted_checked_at'] = $acceptedAt;
    }
    private static function reason(CheckSettlementState $state): string
    {
        if (!$state->stored) { return 'stale-result'; }
        return $state->failed ? 'attention' : 'ok';
    }
}
