<?php
declare(strict_types=1);
namespace Tablo\Tests\Network;

use Tablo\SiteRepository;

final class WorkerCredentialObservation
{
    public static function read(SiteRepository $sites, \PDO $db, int $id): array
    {
        return [$sites->find($id)['checked_at'] !== null,
            (int) $db->query('SELECT COUNT(*) FROM check_history WHERE site_id=' . $id)->fetchColumn()];
    }
}
