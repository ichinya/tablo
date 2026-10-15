<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

/**
 * @phpstan-type Additions list<array{string, string}>
 * @phpstan-type Owners array<string, array{string, Additions}>
 * @phpstan-type Copy array{path: string, ownerSha256: string, instrumentedSha256: string, reversedSha256: string, additions: Additions}
 * @phpstan-type Instrumentation array{owners: array<string, Copy>, schemaSha256: string}
 */
final class ScheduleSourceCopy
{
    /** @param Additions $additions
     * @return Copy */
    public static function write(TemporaryDirectory $directory,string $name,string $path,array $additions): array
    {
        $source = file_get_contents($path);
        if ($source === false) { throw new \RuntimeException('Cannot read declaring scheduling owner'); }
        $instrumented = $source;
        foreach ($additions as [$anchor,$replacement]) {
            if (substr_count($instrumented,$anchor) !== 1) { throw new \RuntimeException('Database scheduling anchor changed'); }
            $instrumented = str_replace($anchor,$replacement,$instrumented);
        }
        $reversed = $instrumented;
        foreach (array_reverse($additions) as [$anchor,$replacement]) { $reversed = str_replace($replacement,$anchor,$reversed); }
        if ($reversed !== $source) { throw new \RuntimeException('Scheduling additions do not reverse to declaring owner'); }
        file_put_contents($directory->path . '/source/app/'.$name.'.php',$instrumented);
        return ['path'=>$path,'ownerSha256'=>hash('sha256',$source),'instrumentedSha256'=>hash('sha256',$instrumented),
            'reversedSha256'=>hash('sha256',$reversed),'additions'=>$additions];
    }
}
