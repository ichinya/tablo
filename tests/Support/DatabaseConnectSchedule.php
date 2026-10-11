<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use RuntimeException;

/** Scheduling additions in an exact copy of the declaring owner; no product seam. */
final class DatabaseConnectSchedule
{
    public readonly array $instrumentation;

    public function __construct(private readonly TemporaryDirectory $directory, ?string $owner = null)
    {
        $root = dirname(__DIR__, 2);
        $owner ??= $root . '/app/sqlite-database.php';
        $source = file_get_contents($owner);
        if ($source === false) { throw new RuntimeException('Cannot read declaring Database owner'); }
        $additions = [
            ["        \$db->exec('BEGIN IMMEDIATE');\n        try {",
                "        \\DatabaseScheduleChild::beforeBegin();\n        \$db->exec('BEGIN IMMEDIATE');\n        \\DatabaseScheduleChild::afterBegin();\n        try {"],
        ];
        $candidate = str_contains($source, 'private static function ensureWal(PDO $db): void');
        if ($candidate) {
            $additions[] = ["            self::ensureWal(\$db);", "            \\DatabaseScheduleChild::beforeWal();\n            self::ensureWal(\$db);"];
            $additions[] = ["                // Cleanup failure never enters", "                \\DatabaseScheduleChild::attempt(\$error, \$cleanupFailed, isset(\$statement));\n                // Cleanup failure never enters"];
            $additions[] = ["        \$deadline = hrtime(true) + 5_000_000_000;", "        \$deadline = hrtime(true) + 5_000_000_000;\n        \\DatabaseScheduleChild::stage('phase-start', ['deadline' => \$deadline]);"];
            $additions[] = ["                        \$statement = \$db->prepare(\$sql);", "                        \\DatabaseScheduleChild::stage('attempt-start');\n                        \$statement = \$db->prepare(\$sql);"];
        } else {
            $start = "            \$statement = \$db->query('PRAGMA journal_mode = WAL');";
            $end = "            @chmod(\$path, 0600);";
            $additions[] = [$start, "            \\DatabaseScheduleChild::beforeWal();\n            try {\n" . $start];
            $additions[] = [$end, "            } catch (\\PDOException \$observed) {\n                \\DatabaseScheduleChild::attempt(\$observed, false, isset(\$statement));\n                throw \$observed;\n            }\n" . $end];
        }
        $instrumented = $source;
        foreach ($additions as [$anchor, $replacement]) {
            if (substr_count($instrumented, $anchor) !== 1) { throw new RuntimeException('Database scheduling anchor changed'); }
            $instrumented = str_replace($anchor, $replacement, $instrumented);
        }
        $reversed = $instrumented;
        foreach (array_reverse($additions) as [$anchor, $replacement]) { $reversed = str_replace($replacement, $anchor, $reversed); }
        if ($reversed !== $source) { throw new RuntimeException('Scheduling additions do not reverse to declaring owner'); }
        mkdir($directory->path . '/source/app', 0700, true);
        mkdir($directory->path . '/source/database', 0700);
        file_put_contents($directory->path . '/source/app/sqlite-database.php', $instrumented);
        $schema = file_get_contents($root . '/database/schema.sql');
        file_put_contents($directory->path . '/source/database/schema.sql', $schema);
        $this->instrumentation = ['ownerSha256' => hash('sha256', $source), 'instrumentedSha256' => hash('sha256', $instrumented),
            'reversedSha256' => hash('sha256', $reversed), 'schemaSha256' => hash('sha256', $schema),
            'candidate' => $candidate, 'additions' => $additions];
    }

    private function child(string $name, string $path, string $kind): MigrationProcess
    {
        return new MigrationProcess($this->directory, $name, $kind, $path,
            ['entry' => dirname(__DIR__) . '/fixtures/database-connect-schedule.php', 'arguments' => [$this->directory->path . '/source']]);
    }

    private function collect(MigrationProcess $child, string $name, ?MigrationProcess $holder = null): array
    {
        $result = $child->wait(holder: $holder);
        $result['data'] = json_decode($result['stdout'], true, 32, JSON_THROW_ON_ERROR);
        $result['events'] = array_map(static fn (string $line): array => json_decode($line, true, 32, JSON_THROW_ON_ERROR),
            file($this->directory->path . '/' . $name . '.events', FILE_IGNORE_NEW_LINES));
        return $result;
    }

    public function race(string $path): array
    {
        $a = $b = null;
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

    public function current(string $path, bool $release): array
    {
        $holder = $a = null;
        try {
            $holder = new MigrationProcess($this->directory, 'holder', 'writer-held', $path);
            $holder->awaitSignal('holder.locked');
            $a = $this->child('A', $path, 'current');
            $a->awaitSignal('A.attempt-finished');
            $holder->assertRunning();
            if ($release) { $holder->release(); $held = $holder->wait(); }
            $a->proceed('retry');
            $first = $this->collect($a, 'A', $release ? null : $holder);
            if (!$release) {
                $holder->assertRunning();
                $holder->release();
                $held = $holder->wait();
            }
            return ['A' => $first, 'holder' => $held, 'instrumentation' => $this->instrumentation];
        } finally { $a?->close(); $holder?->close(); }
    }
}
