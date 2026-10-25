<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use PDO;
use Tablo\ApiSiteDto;
use Tablo\Database;
use Tablo\SqlStatement;
use Testo\Assert;

final class ReadApiAssertions
{
    public const NOW = '2026-10-14T10:00:00Z';
    public const ROOT_KEYS = ['id','config_revision','name','origin','provider','repository','branch','enabled','sort_order','comparison_mode','health_check_mode','health_target','tracks_version','created_at','updated_at','latest'];
    public const LATEST_KEYS = ['status','checked_at','freshness','online','health_error_code','health_http_status','response_time_ms','open_issues','open_prs','comparison'];

    public static function database(): PDO
    {
        $db = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        Database::migrate($db);
        self::administrator($db);
        return $db;
    }

    public static function administrator(PDO $db): void
    {
        $statement = SqlStatement::checked($db->prepare('INSERT INTO users(id,password_hash) VALUES(1,?)'));
        try { $statement->execute([password_hash('synthetic-read-api-administrator', PASSWORD_DEFAULT)]); }
        finally { $statement->closeCursor(); }
    }

    public static function insert(PDO $db, int $id = 1, array $replace = []): void
    {
        $values = array_replace(['id' => $id, 'name' => 'Example site', 'url' => 'https://example.test/private/path', 'repository' => 'fixture/public',
            'created_at' => self::NOW, 'updated_at' => self::NOW], $replace);
        $statement = SqlStatement::checked($db->prepare('INSERT INTO sites(' . implode(',', array_keys($values)) . ') VALUES(' . implode(',', array_fill(0, count($values), value: '?')) . ')'));
        try { $statement->execute(array_values($values)); }
        finally { $statement->closeCursor(); }
    }

    /** @return \Tablo\ApiSiteDto::Dto */
    public static function dto(array $replace = []): array { return ApiSiteDto::fromRow(read_api_fixture_row($replace), self::NOW); }

    public static function exactDto(array $value): void
    {
        Assert::same(array_keys($value), self::ROOT_KEYS);
        $latest = \Tablo\JsonRecord::checked($value['latest'] ?? null);
        Assert::same(array_keys($latest), self::LATEST_KEYS);
        Assert::true(is_string($value['id'] ?? null) && is_string($value['config_revision'] ?? null));
        Assert::true(is_bool($value['enabled'] ?? null) && is_bool($value['tracks_version'] ?? null));
    }

    /** @return array{api_version: int, generated_at: string, data: list<\Tablo\ApiSiteDto::Dto>, page: array{next_after_id: ?string}} */
    public static function page(string $body): array
    {
        $value = \Tablo\JsonRecord::checked(json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR));
        Assert::same(array_keys($value), ['api_version','generated_at','data','page']);
        read_api_fixture_page_data($value['data'] ?? null);
        /** @var array{api_version: int, generated_at: string, data: list<\Tablo\ApiSiteDto::Dto>, page: array{next_after_id: ?string}} $value Validated synthetic API page. */
        return $value;
    }

    /** @return array{api_version: int, generated_at: string, data: \Tablo\ApiSiteDto::Dto} */
    public static function detail(string $body): array
    {
        $value = \Tablo\JsonRecord::checked(json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR));
        Assert::same(array_keys($value), ['api_version','generated_at','data']); self::exactDto(\Tablo\JsonRecord::checked($value['data'] ?? null));
        /** @var array{api_version: int, generated_at: string, data: \Tablo\ApiSiteDto::Dto} $value Validated synthetic API detail. */
        return $value;
    }

    public static function reject(\Closure $operation): void
    {
        $refused = false;
        try { $operation(); } catch (\RuntimeException | \InvalidArgumentException) { $refused = true; }
        Assert::true($refused, 'Required refusal was not reached.');
    }

    public static function snapshot(PDO $db): array
    {
        $result = ['schema' => self::rows($db, 'SELECT type,name,tbl_name,sql FROM main.sqlite_schema ORDER BY type,name'),
            'version' => self::rows($db, 'PRAGMA main.user_version')];
        foreach (self::rows($db, "SELECT name FROM main.sqlite_schema WHERE type='table' ORDER BY name") as $object) {
            $table = FixtureValue::string($object['name'] ?? null);
            if (preg_match('/^[a-z_]+$/D', $table) !== 1) { throw new \RuntimeException('Invalid synthetic table name.'); }
            $result[$table] = self::rows($db, 'SELECT * FROM main.' . $table . ' ORDER BY rowid');
        }
        return $result;
    }

    /** @return list<array> */
    public static function rows(PDO $db, string $sql): array
    {
        $statement = SqlStatement::checked($db->query($sql));
        try { return \Tablo\SqlRows::all($statement->fetchAll(PDO::FETCH_ASSOC), static fn (array $row): array => $row); }
        finally { $statement->closeCursor(); }
    }
}

function read_api_fixture_row(array $replace = []): array
{
    return array_replace(['id' => '1', 'config_revision' => '0', 'name' => 'Example site', 'url' => 'https://example.test/private', 'provider' => 'github',
        'repository' => 'fixture/public', 'branch' => 'main', 'enabled' => 1, 'sort_order' => 0, 'comparison_mode' => 'release', 'health_check_mode' => 'http',
        'health_target_configured' => 1, 'tracks_version' => 0, 'created_at' => ReadApiAssertions::NOW, 'updated_at' => ReadApiAssertions::NOW, 'checked_at' => null,
        'online' => null, 'health_error_code' => null, 'health_http_status' => null, 'response_time_ms' => null, 'open_issues' => null, 'open_prs' => null,
        'deployed_version' => null, 'latest_release' => null, 'deployed_commit' => null, 'latest_commit' => null], $replace);
}

function read_api_fixture_page_data(mixed $data): void
{
    if (!is_array($data) || !array_is_list($data)) { throw new \RuntimeException('Site page array required.'); }
    foreach (array_keys($data) as $key) { ReadApiAssertions::exactDto(\Tablo\JsonRecord::checked($data[$key] ?? null)); }
}
