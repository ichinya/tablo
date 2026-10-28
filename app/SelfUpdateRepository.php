<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-import-type Row from SelfUpdateRow */
final class SelfUpdateRepository
{
    public function __construct(private readonly \PDO $db) {}

    /** @return Row */
    public function get(): array
    {
        $rows = ServerSchema::rows($this->db, 'SELECT * FROM self_update WHERE id = 1');
        if (count($rows) !== 1) { throw new \RuntimeException('Invalid self-update state.'); }
        return SelfUpdateRow::checked($rows[0]);
    }

    public function configure(mixed $enabled, mixed $minutes): void
    { self_update_configure($this->db, $enabled, $minutes); }

    /** @return Row|null */
    public function claim(BuildMetadata $build, int $now): ?array
    {
        $observed = $this->get();
        if ($observed['enabled'] !== 1 || ($observed['build_fingerprint'] === $build->fingerprint()
            && ($observed['next_due'] > $now || $observed['claim_until'] > $now))) { return null; }
        return WriteTransaction::run($this->db, function () use ($build, $now): ?array {
            $row = $this->get();
            if ($row['enabled'] !== 1) { return null; }
            $fingerprint = $build->fingerprint();
            if ($row['build_fingerprint'] !== $fingerprint) {
                ServerSchema::rows($this->db, "UPDATE self_update SET build_fingerprint = ?, revision = revision + 1, observed_sha = NULL, status = 'no_data', latest_sha = NULL, new_commits = NULL, successful_at = NULL, attempted_at = NULL, error_code = NULL, failures = 0, next_due = 0, claim_token = NULL, claim_until = 0 WHERE id = 1", [$fingerprint]);
                $row = $this->get();
            }
            if ($row['next_due'] > $now || $row['claim_until'] > $now) { return null; }
            $token = bin2hex(random_bytes(16));
            ServerSchema::rows($this->db, 'UPDATE self_update SET claim_token = ?, claim_until = ?, attempted_at = ? WHERE id = 1', [$token, $now + 60, $now]);
            $row['claim_token'] = $token;
            return $row;
        });
    }

    /** @param Row $claim */
    public function complete(array $claim, BuildMetadata $build, int $now, SelfUpdateResult $result): bool
    { return self_update_complete($this->db, $claim, $build, $now, $result); }
}

function self_update_configure(\PDO $db, mixed $enabled, mixed $minutes): void
{
        if (!is_string($minutes) || preg_match('/\A[0-9]{1,6}\z/D', $minutes) !== 1 || (int) $minutes < 1 || (int) $minutes > 525_600) {
            throw ValidationException::field('self_update_interval_minutes', 'Укажите целое число минут от 1 до 525600.');
        }
        if ($enabled !== null && $enabled !== '1') { throw ValidationException::field('self_update_enabled', 'Некорректное значение.'); }
        WriteTransaction::run($db, function () use ($db, $enabled, $minutes): void {
            $row = (new SelfUpdateRepository($db))->get();
            if ($row['enabled'] === ($enabled === '1' ? 1 : 0) && $row['interval_minutes'] === (int) $minutes) { return; }
            ServerSchema::rows($db, 'UPDATE self_update SET enabled = ?, interval_minutes = ?, revision = revision + 1, next_due = 0, claim_token = NULL, claim_until = 0 WHERE id = 1', [$enabled === '1' ? 1 : 0, (int) $minutes]);
        });
    }

/** @param \Tablo\SelfUpdateRow::Row $claim */
function self_update_complete(\PDO $db, array $claim, BuildMetadata $build, int $now, SelfUpdateResult $result): bool
{
        return WriteTransaction::run($db, function () use ($db, $claim, $build, $now, $result): bool {
            $latest = $result->latest; $status = $result->status; $count = $result->count; $error = $result->error; $eligibleAt = $result->eligibleAt;
            $row = (new SelfUpdateRepository($db))->get();
            if ($row['revision'] !== $claim['revision'] || $row['build_fingerprint'] !== $build->fingerprint()
                || !hash_equals($row['claim_token'] ?? '', $claim['claim_token'] ?? '') || $row['enabled'] !== 1) { return false; }
            $interval = $row['interval_minutes'] * 60;
            $failures = $error === null ? 0 : min(4, $row['failures'] + 1);
            $delay = $error === null || $failures > 3 ? $interval : min($interval, match ($failures) { 1 => 300, 2 => 1800, default => 7200 });
            $next = max($now + $delay, $eligibleAt ?? 0);
            if ($error === null) {
                ServerSchema::rows($db, 'UPDATE self_update SET observed_sha = ?, status = ?, latest_sha = ?, new_commits = ?, successful_at = ?, error_code = NULL, failures = 0, next_due = ?, claim_token = NULL, claim_until = 0 WHERE id = 1', [$status === 'unknown' ? $claim['observed_sha'] : $latest, $status, $latest, $count, $status === 'no_data' ? null : $now, $next]);
                return true;
            }
                ServerSchema::rows($db, 'UPDATE self_update SET error_code = ?, failures = ?, next_due = ?, claim_token = NULL, claim_until = 0 WHERE id = 1', [$error, $failures, $next]);
            return true;
        });
    }
