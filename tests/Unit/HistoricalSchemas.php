<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;




final class HistoricalSchemas
{
    /** @return list<string> */
    public static function all(): array
    {
        $historic = \Tablo\Tests\Support\DatabaseAssertions::schema(\Tablo\Tests\Support\SchemaFixture::Historic); // Exact schema from d964304, before JSON settings.
        $withoutSaved = \Tablo\Tests\Support\FixtureValue::string(preg_replace('/CREATE TABLE IF NOT EXISTS git_tokens \(.*?\);\s*/s', replacement: '', subject: $historic));
        $withoutSaved = \Tablo\Tests\Support\FixtureValue::string(preg_replace('/^\s*git_token_id INTEGER[^\r\n]*\R/m', replacement: '', subject: $withoutSaved));
        return [$historic, $withoutSaved, \Tablo\Tests\Support\FixtureValue::string(preg_replace('/^\s*github_token TEXT,\R/m', replacement: '', subject: $historic)),
            \Tablo\Tests\Support\FixtureValue::string(preg_replace('/^\s*github_token TEXT,\R/m', replacement: '', subject: $withoutSaved)), \Tablo\Tests\Support\DatabaseAssertions::schema()];
    }
}
