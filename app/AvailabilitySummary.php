<?php
declare(strict_types=1);

namespace Tablo;

/**
 * @phpstan-import-type Sample from AvailabilityData
 * @phpstan-type Summary array{total:int,online:int,offline:int,unknown:int,known:int,availability_state:string,availability_label:string,known_label:string,response_count:int,known_without_time:int,revision_count:int,explanation:string}
 */
final class AvailabilitySummary
{
    /** @param list<Sample> $rows
     * @return Summary */
    public static function of(array $rows): array
    {
        $online = 0; $offline = 0; $responses = 0; $missing = 0; $revisions = [];
        foreach ($rows as $row) {
            $revisions[$row['config_revision']] = true;
            if ($row['online'] === null) { continue; }
            $online += (int) ($row['online'] === 1); $offline += (int) ($row['online'] === 0);
            $missing += (int) ($row['response_time_ms'] === null); $responses += (int) ($row['response_time_ms'] !== null);
        }
        $known = $online + $offline; $total = count($rows);
        $state = match ($known) { 0 => 'none', 1 => 'insufficient', default => 'available' };
        return ['total' => $total, 'online' => $online, 'offline' => $offline, 'unknown' => $total - $known, 'known' => $known,
            'availability_state' => $state, 'availability_label' => self::label($online, $known),
            'known_label' => $known . ' из ' . $total, 'response_count' => $responses, 'known_without_time' => $missing,
            'revision_count' => count($revisions), 'explanation' => self::explanation($total, $known)];
    }

    private static function label(int $online, int $known): string
    {
        if ($known < 2) { return '—'; }
        if ($online === 0) { return '0%'; }
        if ($online === $known) { return '100%'; }
        if ($online * 1000 < $known) { return '<0,1%'; }
        if ($online * 1000 > $known * 999) { return '>99,9%'; }
        return number_format(100 * $online / $known, decimals: 1, decimal_separator: ',', thousands_separator: '') . '%';
    }

    private static function explanation(int $total, int $known): string
    {
        if ($total === 0) { return 'За выбранный период сохранённых проверок нет.'; }
        if ($known === 0) { return 'Все сохранённые результаты — Unknown; доступность неизвестна.'; }
        if ($known === 1) { return 'Недостаточно данных: нужен результат хотя бы двух известных проверок.'; }
        return 'Доля Online среди известных результатов проверок. Unknown исключён из знаменателя. Это не доля времени доступности и не SLA.';
    }
}
