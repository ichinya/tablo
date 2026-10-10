<?php
declare(strict_types=1);

namespace Tablo;

use DateTimeImmutable;
use InvalidArgumentException;
use OutOfBoundsException;
use PDO;

final class CheckHistoryRepository
{
    public function __construct(private readonly PDO $db) {}

    public function page(int $siteId, string $from, string $to, int $limit = 50, ?string $cursor = null): array
    {
        $from = HistoryTime::canonical($from);
        $to = HistoryTime::canonical($to);
        if ($from >= $to || $limit < 1 || $limit > 100) { throw new InvalidArgumentException('Invalid history query.'); }
        $this->existingSite($siteId);
        $position = $cursor === null ? null : $this->decodeCursor($cursor, $siteId, $from, $to);
        if ($position === null) {
            $statement = $this->db->query('SELECT MAX(id) FROM check_history');
            try { $ceiling = (int) $statement->fetchColumn(); }
            finally { $statement->closeCursor(); }
        } else { $ceiling = $position['ceiling']; }
        $sql = 'SELECT * FROM check_history WHERE site_id = :site AND checked_at >= :from AND checked_at < :to
            AND id <= :ceiling';
        if ($position !== null) { $sql .= ' AND (checked_at, id) < (:time, :id)'; }
        $statement = $this->db->prepare($sql . ' ORDER BY checked_at DESC, id DESC LIMIT :limit');
        $statement->bindValue(':site', $siteId, PDO::PARAM_INT);
        $statement->bindValue(':from', $from, PDO::PARAM_STR);
        $statement->bindValue(':to', $to, PDO::PARAM_STR);
        $statement->bindValue(':ceiling', $ceiling, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit + 1, PDO::PARAM_INT);
        if ($position !== null) {
            $statement->bindValue(':time', $position['time'], PDO::PARAM_STR);
            $statement->bindValue(':id', $position['id'], PDO::PARAM_INT);
        }
        try { $statement->execute(); $rows = $statement->fetchAll(); }
        finally { $statement->closeCursor(); }
        $next = null;
        if (count($rows) > $limit) {
            array_pop($rows);
            $last = $rows[array_key_last($rows)];
            $next = rtrim(strtr(base64_encode(json_encode(['site' => $siteId, 'from' => $from, 'to' => $to,
                'time' => $last['checked_at'], 'id' => $last['id'], 'ceiling' => $ceiling], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        }
        return ['rows' => $rows, 'next_cursor' => $next];
    }

    private function decodeCursor(string $cursor, int $siteId, string $from, string $to): array
    {
        if ($cursor === '' || strlen($cursor) > 1024 || !preg_match('/^[A-Za-z0-9_-]+$/D', $cursor)) { self::invalidCursor(); }
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($decoded === false || rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=') !== $cursor) { self::invalidCursor(); }
        try { $value = json_decode($decoded, true, 8, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { self::invalidCursor(); }
        if (!is_array($value) || array_keys($value) !== ['site', 'from', 'to', 'time', 'id', 'ceiling']
            || $value['site'] !== $siteId || $value['from'] !== $from || $value['to'] !== $to
            || !is_int($value['id']) || $value['id'] < 1 || !is_int($value['ceiling']) || $value['ceiling'] < $value['id']) {
            self::invalidCursor();
        }
        try { $time = HistoryTime::canonical($value['time']); }
        catch (InvalidArgumentException) { self::invalidCursor(); }
        if ($time !== $value['time'] || $time < $from || $time >= $to) { self::invalidCursor(); }
        return $value;
    }

    private static function invalidCursor(): never
    {
        throw new InvalidArgumentException('Invalid history cursor.');
    }

    private function existingSite(int $siteId): void
    {
        if ($siteId < 1) { throw new InvalidArgumentException('Invalid history site.'); }
        $statement = $this->db->prepare('SELECT id FROM sites WHERE id = ?');
        try {
            $statement->execute([$siteId]);
            if ($statement->fetchColumn() === false) { throw new OutOfBoundsException('History site not found.'); }
        } finally { $statement->closeCursor(); }
    }

    public function prune(int $days = 30, ?int $siteId = null, ?DateTimeImmutable $now = null): array
    {
        $cutoff = HistoryRetention::cutoff($days, $now); // Capture once, before destructive work.
        if ($siteId !== null) { $this->existingSite($siteId); }
        $deleted = 0;
        for ($batch = 0; $batch < 10; $batch++) {
            $count = $this->pruneBatch($cutoff, $siteId);
            $deleted += $count;
            if ($count < 500) { return ['deleted' => $deleted, 'capped' => false]; }
        }
        return ['deleted' => $deleted, 'capped' => true];
    }

    public function pruneBatch(string $cutoff, ?int $siteId = null): int
    {
        $cutoff = HistoryTime::canonical($cutoff);
        if ($siteId !== null) { $this->existingSite($siteId); }
        $this->db->exec('BEGIN IMMEDIATE'); // Failed BEGIN never rolls back caller-owned writes.
        try {
            $sql = 'DELETE FROM check_history WHERE id IN (SELECT id FROM check_history WHERE checked_at < :cutoff';
            if ($siteId !== null) { $sql .= ' AND site_id = :site'; }
            $statement = $this->db->prepare($sql . ' ORDER BY checked_at, id LIMIT :limit)');
            $statement->bindValue(':cutoff', $cutoff, PDO::PARAM_STR);
            $statement->bindValue(':limit', 500, PDO::PARAM_INT);
            if ($siteId !== null) { $statement->bindValue(':site', $siteId, PDO::PARAM_INT); }
            try { $statement->execute(); $deleted = $statement->rowCount(); }
            finally { $statement->closeCursor(); }
            $this->db->exec('COMMIT');
            return $deleted;
        } catch (\Throwable $error) {
            try { $this->db->exec('ROLLBACK'); }
            catch (\Throwable) { /* Preserve the original batch failure; prior batches stay committed. */ }
            throw $error;
        }
    }
}
