<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;


/** Scheduling additions in an exact copy of the declaring owner; no product seam. */
final class DatabaseConnectSchedule
{
    public readonly array $instrumentation;
    public function __construct(private readonly TemporaryDirectory $directory, ?string $owner = null)
    {
        $root = dirname(__DIR__, levels: 2);
        $owner ??= $root . '/app/Database.php';
        $sources = DatabaseScheduleOwners::anchors($root,$owner);
        mkdir($directory->path . '/source/app', permissions: 0o700, recursive: true);
        mkdir($directory->path . '/source/database', permissions: 0o700);
        $copies = [];
        foreach ($sources as $name => [$path,$additions]) { $copies[$name] = ScheduleSourceCopy::write($directory,$name,$path,$additions); }
        $schema = file_get_contents($root . '/database/schema.sql');
        file_put_contents($directory->path . '/source/database/schema.sql', $schema);
        $this->instrumentation = ['owners'=>$copies,'schemaSha256'=>hash('sha256',$schema)];
    }



    private function child(string $name, string $path, string $kind): MigrationProcess
    {
        return new MigrationProcess($this->directory, $name, $kind, $path,
            ['entry' => dirname(__DIR__) . '/fixtures/DatabaseScheduleChild.php', 'arguments' => [$this->directory->path . '/source']]);
    }

    private function collect(MigrationProcess $child, string $name, ?MigrationProcess $holder = null): array
    {
        $result = $child->wait(holder: $holder);
        $result['data'] = json_decode($result['stdout'], associative: true, depth: 32, flags: JSON_THROW_ON_ERROR);
        $result['events'] = array_map(static fn (string $line): array => json_decode($line, associative: true, depth: 32, flags: JSON_THROW_ON_ERROR),
            file($this->directory->path . '/' . $name . '.events', FILE_IGNORE_NEW_LINES));
        return $result;
    }

    public function race(string $path): array
    {
        $b = null; $a = null;
        try {
            $b = $this->child('B', $path, 'race');
            $b->awaitSignal('B.before-begin');
            $a = $this->child('A', $path, 'race');
            $a->awaitSignal('A.after-migrate');
            $b->proceed('begin');
            $b->awaitSignal('B.after-begin');
            $a->proceed('wal');
            $a->awaitSignal('A.attempt-finished'); // Real BUSY and completed cursor cleanup, before releasing B.
            $b->assertRunning();
            $b->release();
            $second = $this->collect($b, 'B'); // Observe B's actual exit before A's own retry resumes.
            $a->proceed('retry');
            $first = $this->collect($a, 'A');
            return ['A' => $first, 'B' => $second, 'instrumentation' => $this->instrumentation];
        } finally { $a?->close(); $b?->close(); }
    }

    public function current(string $path, WalHolderPolicy $policy): array
    {
        $a = null; $holder = null;
        try {
            $holder = new MigrationProcess($this->directory, 'holder', 'writer-held', $path);
            $holder->awaitSignal('holder.locked');
            $a = $this->child('A', $path, 'current');
            $a->awaitSignal('A.attempt-finished');
            $holder->assertRunning();
            if ($policy === WalHolderPolicy::ReleaseBeforeRetry) { $holder->release(); $held = $holder->wait(); }
            $a->proceed('retry');
            $first = $this->collect($a, 'A', $policy === WalHolderPolicy::ReleaseBeforeRetry ? null : $holder);
            if ($policy === WalHolderPolicy::HoldThroughTimeout) {
                $holder->assertRunning();
                $holder->release();
                $held = $holder->wait();
            }
            return ['A' => $first, 'holder' => $held, 'instrumentation' => $this->instrumentation];
        } finally { $a?->close(); $holder?->close(); }
    }
}
