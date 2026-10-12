<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;




final class HistoricalSchemas
{
    public static function all(): array
    {
        $historic = \Tablo\Tests\Support\DatabaseAssertions::schema(\Tablo\Tests\Support\SchemaFixture::Historic); // Exact schema from d964304, before JSON settings.
        $withoutSaved = preg_replace('/CREATE TABLE IF NOT EXISTS git_tokens \(.*?\);\s*/s', replacement: '', subject: $historic);
        $withoutSaved = preg_replace('/^\s*git_token_id INTEGER[^\r\n]*\R/m', replacement: '', subject: $withoutSaved);
        return [$historic, $withoutSaved, preg_replace('/^\s*github_token TEXT,\R/m', replacement: '', subject: $historic),
            preg_replace('/^\s*github_token TEXT,\R/m', replacement: '', subject: $withoutSaved), \Tablo\Tests\Support\DatabaseAssertions::schema()];
    }
}
