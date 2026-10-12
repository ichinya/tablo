<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

/** Required handles are returned unchanged, without opening or reconnecting streams. */
final class FixturePipes
{
    /**
     * @param array<int, resource|null> $pipes
     * @return resource
     */
    public static function pipe(array $pipes, int $descriptor): mixed
    {
        $pipe = $pipes[$descriptor] ?? null;
        if (!is_resource($pipe)) { throw new \RuntimeException('Required fixture pipe is unavailable'); }
        return $pipe;
    }
}
