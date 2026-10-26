<?php
declare(strict_types=1);

namespace Tablo\Tests\Server;

use Testo\Assert;
use Testo\Test;

final class EngineSourceInventoryTest
{
    #[Test]
    public function sourceInventoryCompositionFiltersPrivateNamesBeforeAnyFileRead(): void
    {
        require_once dirname(__DIR__, levels: 2) . '/tools/ServerFixturePlan.php';
        $paths = ['app/nonexistent-source.php', '.env.example', 'storage/.gitkeep', 'storage/private.sqlite',
            'artifacts/result.json', 'vendor/code.php', '.git/HEAD', '.env', 'nested/.env', 'real.env', 'nested/real.env', 'tests/nonexistent-test.php'];
        Assert::same(\Tablo\Tools\ServerFixturePlan::sourcePaths($paths), ['app/nonexistent-source.php', '.env.example', 'tests/nonexistent-test.php']);
        Assert::same(\Tablo\Tools\ServerFixturePlan::sourcePaths([]), []);
        foreach (['', '../outside.php', '/absolute.php', 'C:/private.php', 'app\\file.php', "app/\0.php", 'app/../file.php'] as $path) {
            $caught = null;
            try { \Tablo\Tools\ServerFixturePlan::sourcePaths([$path]); } catch (\RuntimeException $error) { $caught = $error; }
            Assert::same($caught?->getMessage(), 'Malformed source inventory path.');
            Assert::same($caught?->getPrevious(), null);
        }
    }
}
