<?php
declare(strict_types=1);

namespace Tablo;

/**
 * @phpstan-type Geometry array{width:int,height:int,left:int,right:int,top:int,bottom:int,y_max:int,y_ticks:list<array{y:int,label:string}>,x_ticks:list<array{x:int,label:string}>}
 * @phpstan-import-type Period from AvailabilityPeriod
 */
final class AvailabilityGeometry
{
    public const LEFT = 64;
    public const RIGHT = 944;
    public const TOP = 20;
    public const BOTTOM = 252;

    /** @param Period $period
     * @return Geometry */
    public static function chart(array $period, int $maximum): array
    {
        $maximum = max(1, $maximum);
        $from = new \DateTimeImmutable($period['from']);
        $middle = $from->add(new \DateInterval('PT' . intdiv($period['seconds'], num2: 2) . 'S'));
        return ['width' => 960, 'height' => 300, 'left' => self::LEFT, 'right' => self::RIGHT, 'top' => self::TOP,
            'bottom' => self::BOTTOM, 'y_max' => $maximum,
            'y_ticks' => [['y' => self::BOTTOM, 'label' => '0'],
                ['y' => intdiv(self::TOP + self::BOTTOM, num2: 2), 'label' => self::milliseconds($maximum / 2)],
                ['y' => self::TOP, 'label' => (string) $maximum]],
            'x_ticks' => [['x' => self::LEFT, 'label' => $from->format('d.m H:i')],
                ['x' => intdiv(self::LEFT + self::RIGHT, num2: 2), 'label' => $middle->format('d.m H:i')],
                ['x' => self::RIGHT, 'label' => (new \DateTimeImmutable($period['to']))->format('d.m H:i')]]];
    }

    public static function x(int $index): int
    { return (int) round(self::LEFT + ($index + 0.5) * (self::RIGHT - self::LEFT) / 120); }

    public static function y(float $mean, int $maximum): int
    { return max(self::TOP, min(self::BOTTOM, (int) round(self::BOTTOM - $mean / max(1, $maximum) * (self::BOTTOM - self::TOP)))); }

    public static function milliseconds(float $value): string
    { return number_format($value, decimals: 1, decimal_separator: ',', thousands_separator: ''); }
}
