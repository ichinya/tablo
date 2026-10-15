<?php
declare(strict_types=1);

namespace Tablo;

final class CheckSettlementOutput
{
    public static function settle(array &$record,CheckSettlementState $state): void
    {
        $stored=$state->stored; $failed=$state->failed;
                        $record['reason'] = self::reason($state);
                        $record['attention'] = $failed;
                        $record['persistence']['status'] = $stored ? 'accepted' : 'rejected-stale';
                        $record['persistence']['accepted'] = $stored;
                        $record['persistence']['accepted_checked_at'] = $stored ? $record['observation']['checked_at'] : null;
    }
    private static function reason(CheckSettlementState $state): string
    {
        if (!$state->stored) { return 'stale-result'; }
        return $state->failed ? 'attention' : 'ok';
    }
}
