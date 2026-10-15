<?php
declare(strict_types=1);
namespace Tablo\Tests\Support;

/** Copies only a sealed object created by the exact synthetic retention fixture. */
final class PasswordHelperDatabaseExport
{
    /** @return array{path: string, bytes: int, sha256: string} */
    public static function capture(PasswordHelperDatabaseFixture $fixture, string $case): array
    {
        if (preg_match('/^case-[a-f0-9]{24}$/D', $case) !== 1) { throw new \RuntimeException('Synthetic database case identity refused'); }
        $fixture->requireSealedSource();
        $target = PasswordHelperDatabaseTarget::create();
        $copy = PasswordHelperEvidenceFiles::copy($fixture->path, $target . '/synthetic.sqlite');
        if ($copy['sha256'] !== $fixture->sha256 || $copy['bytes'] !== $fixture->bytes) { throw new \RuntimeException('Synthetic database export custody mismatch'); }
        PasswordHelperEvidenceFiles::record($target . '/provenance.json', ['utc' => gmdate('c'), 'controller_pid' => getmypid(),
            'case' => $case, 'classification' => 'PRIVATE known newly seeded synthetic marker database only',
            'source_fixture' => $fixture->path, 'source_class' => PasswordHelperDatabaseFixture::class, 'seed_recipe' => $fixture->scenario,
            'source_sha256' => $fixture->sha256, 'source_bytes' => $fixture->bytes, 'journal_mode' => $fixture->journalMode,
            'handles' => 'known fixture/probe SQLite handles closed before export', 'copy' => $copy,
            'default_ci_artifact' => 'EXCLUDED; output is outside the dedicated helper upload path']);
        return $copy;
    }
}
