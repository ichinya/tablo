<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use RuntimeException;
use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Test;

require_once dirname(__DIR__, 2) . '/tools/reviewed-dependencies.php';

final class ReviewedDependenciesTest
{
    private static function fixture(TemporaryDirectory $directory): void
    {
        $root = dirname(__DIR__, 2);
        mkdir($directory->path . '/contracts');
        mkdir($directory->path . '/app');
        mkdir($directory->path . '/bin');
        mkdir($directory->path . '/tools');
        mkdir($directory->path . '/public');
        foreach (array_keys(\requiredReviewedDependencies()) as $path) {
            copy($root . '/' . $path, $directory->path . '/' . $path);
        }
        foreach (['reviewed-dependencies.php', 'capture-reviewed-dependencies.php'] as $file) {
            copy($root . '/tools/' . $file, $directory->path . '/tools/' . $file);
        }
    }

    private static function refusal(string $root, string $message): void
    {
        $refusal = null;
        try { \checkReviewedDependencies($root); }
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
            \checkReviewedDependencies($directory->path);
            Assert::same(Subprocess::run($command, $directory, [])['exit_code'], 0);
            Assert::same(file_get_contents($path), $captured);
            foreach (array_keys(\requiredReviewedDependencies()) as $dependency) {
                $source = $directory->path . '/' . $dependency;
                $original = file_get_contents($source);
                file_put_contents($source, $original . "\n// unreviewed drift\n");
                self::refusal($directory->path, 'Unreviewed dependency source drift: ' . $dependency);
                Assert::same(file_get_contents($path), $captured, 'verification cannot recapture');
                file_put_contents($source, $original);
                \checkReviewedDependencies($directory->path);
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
            $original = file_get_contents($path);
            $manifest = json_decode($original, true, 64, JSON_THROW_ON_ERROR);
            $variants = [null, [], ['schema' => $manifest['schema'], 'dependencies' => []]];
            foreach (['path' => '../app/password-service.php', 'owner' => 'fake-owner', 'consumers' => ['fake-consumer'],
                'fingerprint' => 'sha256:invalid'] as $field => $value) {
                $changed = $manifest;
                $changed['dependencies'][0][$field] = $value;
                $variants[] = $changed;
            }
            $changed = $manifest; $changed['dependencies'][1] = $changed['dependencies'][0]; $variants[] = $changed;
            $changed = $manifest; unset($changed['dependencies'][0]); $variants[] = $changed;
            $changed = $manifest; $changed['dependencies'][0]['extra'] = 'ignored'; $variants[] = $changed;
            $changed = $manifest; $changed['schema'] = 'lekalo-declaring-owners'; $variants[] = $changed;
            foreach ($variants as $variant) {
                file_put_contents($path, json_encode($variant, JSON_THROW_ON_ERROR));
                self::refusal($directory->path, 'Invalid project-local');
            }
            file_put_contents($path, '{');
            self::refusal($directory->path, 'Malformed project-local');
            file_put_contents($path, $original);
            \checkReviewedDependencies($directory->path);
        } finally { $directory->close(); }
    }
}
