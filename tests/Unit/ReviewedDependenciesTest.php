<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use RuntimeException;
use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Test;

require_once dirname(__DIR__, levels: 2) . '/tools/reviewed-dependencies.php';

final class ReviewedDependenciesTest
{
    private static function fixture(TemporaryDirectory $directory): void
    {
        $root = dirname(__DIR__, levels: 2);
        mkdir($directory->path . '/contracts');
        mkdir($directory->path . '/app');
        mkdir($directory->path . '/bin');
        mkdir($directory->path . '/tools');
        mkdir($directory->path . '/public');
        mkdir($directory->path . '/deploy');
        foreach (array_keys(\required_reviewed_dependencies()) as $path) {
            $destination = $directory->path . '/' . $path;
            if (!is_dir(dirname($destination))) { mkdir(dirname($destination), permissions: 0o700, recursive: true); }
            reviewed_dependencies_fixture_source($root, $path, $destination);
        }
    }

    private static function refusal(string $root, string $message): void
    {
        $refusal = null;
        try { \check_reviewed_dependencies($root); }
        catch (RuntimeException $error) { $refusal = $error->getMessage(); }
        Assert::true(is_string($refusal) && str_contains($refusal, $message), 'required reviewed boundary must refuse');
    }

    #[Test]
    public function explicitCaptureIsDeterministicAndEveryRequiredDependencyIsGuarded(): void
    {
        $directory = new TemporaryDirectory('tablo-reviewed-source-');
        try {
            self::fixture($directory);
            self::refusal($directory->path, 'Missing project-local');
            $command = [PHP_BINARY, $directory->path . '/tools/capture-reviewed-dependencies.php'];
            Assert::same(Subprocess::run($command, $directory, [])['exit_code'], 0);
            $path = $directory->path . '/contracts/reviewed-dependencies.json';
            $captured = file_get_contents($path);
            \check_reviewed_dependencies($directory->path);
            Assert::same(Subprocess::run($command, $directory, [])['exit_code'], 0);
            Assert::same(file_get_contents($path), $captured);
            foreach (array_keys(\required_reviewed_dependencies()) as $dependency) {
                $source = $directory->path . '/' . $dependency;
                $original = \Tablo\RequiredFile::bytes(file_get_contents($source));
                file_put_contents($source, $original . "\n// unreviewed drift\n");
                self::refusal($directory->path, 'Unreviewed dependency source drift: ' . $dependency);
                Assert::same(file_get_contents($path), $captured, 'verification cannot recapture');
                file_put_contents($source, $original);
                \check_reviewed_dependencies($directory->path);
            }
        } finally { $directory->close(); }
    }

    #[Test]
    public function malformedCoverageAndOwnershipCannotBypassTheRequiredSet(): void
    {
        $directory = new TemporaryDirectory('tablo-reviewed-manifest-');
        try {
            self::fixture($directory);
            Assert::same(Subprocess::run([PHP_BINARY, $directory->path . '/tools/capture-reviewed-dependencies.php'], $directory, [])['exit_code'], 0);
            $path = $directory->path . '/contracts/reviewed-dependencies.json';
            $original = \Tablo\RequiredFile::bytes(file_get_contents($path));
            $manifest = \Tablo\JsonRecord::checked(json_decode($original, associative: true, depth: 64, flags: JSON_THROW_ON_ERROR));
            $manifest['dependencies'] = \Tablo\SqlRows::all(\Tablo\JsonRecord::checked(\Tablo\Tests\Support\FixtureValue::at($manifest, 'dependencies')), \Tablo\JsonRecord::checked(...));
            $variants = [null, [], ['schema' => \Tablo\Tests\Support\FixtureValue::at($manifest, 'schema'), 'dependencies' => []]];
            foreach (['path' => '../app/PasswordService.php', 'owner' => 'fake-owner', 'consumers' => ['fake-consumer'],
                'fingerprint' => 'sha256:invalid'] as $field => $value) {
                $changed = $manifest;
                $changed['dependencies'][0][$field] = $value;
                $variants[] = $changed;
            }
            $changed = $manifest; $changed['dependencies'][1] = \Tablo\Tests\Support\FixtureValue::at(\Tablo\JsonRecord::checked(\Tablo\Tests\Support\FixtureValue::at($changed, 'dependencies')), 0); $variants[] = $changed;
            $changed = $manifest; unset($changed['dependencies'][0]); $variants[] = $changed;
            $changed = $manifest; $changed['dependencies'][0]['extra'] = 'ignored'; $variants[] = $changed;
            $changed = $manifest; $changed['schema'] = 'lekalo-declaring-owners'; $variants[] = $changed;
            foreach ($variants as $variant) {
                file_put_contents($path, json_encode($variant, JSON_THROW_ON_ERROR));
                self::refusal($directory->path, 'Invalid project-local');
            }
            file_put_contents($path, data: '{');
            self::refusal($directory->path, 'Malformed project-local');
            file_put_contents($path, $original);
            \check_reviewed_dependencies($directory->path);
        } finally { $directory->close(); }
    }
}

/** These fixtures exercise every catalog path's integrity, ownership and coverage, without executing its application code.
 * Only the actual capture/guard bootstrap needs executable project bytes. */
function reviewed_dependencies_fixture_source(string $root, string $path, string $destination): void
{
    $bootstrap = ['tools/capture-reviewed-dependencies.php', 'tools/reviewed-dependencies.php', 'tools/reviewed-dependency-map.json',
        'tools/ReviewedDependencyManifest.php', 'tools/ReviewedDependencyCatalog.php', 'tools/ReviewedDependencyEntry.php',
        'tools/ReviewedDependencyCoverage.php', 'tools/MagoSource.php', 'app/RequiredFile.php'];
    if (in_array($path, $bootstrap, strict: true)) {
        if (!copy($root . '/' . $path, $destination)) { throw new RuntimeException('Cannot copy reviewed fixture bootstrap.'); }
        return;
    }
    if (file_put_contents($destination, data: "<?php\n// Fixed public integrity fixture bytes.\n") === false) {
        throw new RuntimeException('Cannot create reviewed integrity fixture.');
    }
}
