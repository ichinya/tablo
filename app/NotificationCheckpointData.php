<?php
declare(strict_types=1);

namespace Tablo;

/**
 * @phpstan-type State array{channel: int, watermark: string, wall: int, health: ?int,
 * health_since: ?string, health_wall: ?int, health_count: int, health_handled: ?int,
 * recovery_handled: ?int, lag: ?int, lag_since: ?string, lag_wall: ?int,
 * lag_count: int, lag_handled: ?int, ...}
 */
final class NotificationCheckpointData
{
    /**
     * @param State|null $replacement
     * @return array{channel: int, ...}
     */
    public static function epoch(string $json, ?array $replacement): array
    {
        return self::decodedEpoch(json_decode($json, associative: true, depth: 16, flags: JSON_THROW_ON_ERROR), $replacement);
    }

    /**
     * @param State|null $replacement
     * @return array{channel: int, ...}
     */
    private static function decodedEpoch(mixed $decoded, ?array $replacement): array
    {
        // A new site epoch discards old state after syntax decoding; old field shape is immaterial.
        if ($replacement !== null) { return $replacement; }
        $state = JsonRecord::checked($decoded);
        $state['channel'] = SqlColumn::integer($state, 'channel');
        return $state;
    }

    /** @return array{channel: int, ...} */
    public static function channel(string $json): array
    {
        $state = JsonRecord::decode($json);
        $state['channel'] = SqlColumn::integer($state, 'channel');
        return $state;
    }

    /** @return State */
    public static function current(array $state): array
    {
        $state['channel'] = SqlColumn::integer($state, 'channel');
        $state['watermark'] = SqlColumn::string($state, 'watermark');
        $state['wall'] = SqlColumn::integer($state, 'wall');
        $state['health'] = SqlColumn::nullableInteger($state, 'health');
        $state['health_since'] = SqlColumn::nullableString($state, 'health_since');
        $state['health_wall'] = SqlColumn::nullableInteger($state, 'health_wall');
        $state['health_count'] = SqlColumn::integer($state, 'health_count');
        $state['health_handled'] = SqlColumn::nullableInteger($state, 'health_handled');
        $state['recovery_handled'] = SqlColumn::nullableInteger($state, 'recovery_handled');
        $state['lag'] = SqlColumn::nullableInteger($state, 'lag');
        $state['lag_since'] = SqlColumn::nullableString($state, 'lag_since');
        $state['lag_wall'] = SqlColumn::nullableInteger($state, 'lag_wall');
        $state['lag_count'] = SqlColumn::integer($state, 'lag_count');
        $state['lag_handled'] = SqlColumn::nullableInteger($state, 'lag_handled');
        return $state;
    }
}
