<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Tests\Support\TemporaryDirectory;

final class InstallationSelectionCases
{
    /** @return list<string> */
    public static function admitted(string $path, string $uri): array
    {
        return [$path, 'space ж.sqlite', $uri . '?mode=rw', $uri . '?mode=rw&immutable=false&nolock=0',
                $uri . '?mode=rw&nolock=false&nolock=0&immutable=no&immutable=off',
                $uri . '?mode=rw&mode.x=ro&mode_x=ro&immutable+=1&no.lock=1&nolock_x=1',
                $uri . '?mode=rw#&immutable=1&nolock=1&vfs=unix-none',
                $uri . '?mode=rw&VFS=unix-none&vfs+=unix-none&vfs.x=unix-none&vfs_x=unix-none',
                $uri . '?mode=rw&cache=private&psow=0', $uri . '?mode=rw&cache=shared&psow=1'];
    }
    /** @return list<string> */
    public static function refused(TemporaryDirectory $directory, string $uri): array
    {
        return [':memory:', 'file::memory:?cache=shared', 'file:transient?mode=memory', $directory->path . '/missing/sub.sqlite',
                'file:' . $directory->path . '/missing.sqlite?mode=rwc', $uri . '?mode=ro', $uri . '?mode=%72%6f',
                $uri . '?%6dode=memory', $uri . '?mode=rw&immutable=1', $uri . '?%69mmutable=TrUe',
                $uri . '?nolock=1', $uri . '?nolock=2', $uri . '?nolock=unknown', $uri . '?mode=rw%00',
                $uri . '?mode=rw&immutable=0&nolock=on', $uri . '?nolock=0&nolock=1',
                $uri . '?nolock=1&nolock=0', $uri . '?immutable=0&immutable=1', $uri . '?immutable=1&immutable=0',
                $uri . '?mode=rw&mode=ro', $uri . '?mode=ro&mode=rw', $uri . '?nolock%00suffix=1',
                $uri . '?immutable%00suffix=1', $uri . '?mode%00suffix=ro', $uri . '%00suffix?mode=rw',
                $uri . '?private=ignored%00suffix'];
    }
}
