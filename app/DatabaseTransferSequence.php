<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

final class DatabaseTransferSequence
{
    /**
 * @return array{sites: int, git_tokens: int, check_history: int} */
    public static function floors(PDO $db): array
    {
        return database_transfer_sequence_floors($db);
    }

    public static function watermark(PDO $db): int
    {
        return database_transfer_sequence_watermark($db);
    }

    /**
 * @param array{sites: int, git_tokens: int, check_history: int} $floors */
    public static function apply(PDO $db, array $floors, int $watermark): void
    {
        database_transfer_sequence_apply($db, $floors, $watermark);
    }

    public static function scalar(PDO $db, string $sql): int
    {
        database_transfer_phase_mark(115);
        $value = database_transfer_sequence_scalar($db, $sql);
        database_transfer_phase_mark(116);
        return $value;
    }
}

// Internal operations for this fixed transfer module.

/**
 * @return array{sites: int, git_tokens: int, check_history: int} */
function database_transfer_sequence_floors(PDO $db): array
{
    $floors = [];
    foreach (['sites', 'git_tokens', 'check_history'] as $table) {
        $maximum = database_transfer_sequence_scalar($db, 'SELECT COALESCE(MAX(id),0) AS value FROM ' . $table);
        $allocated = database_transfer_persistence_allocated($db, $table);
        $floor = max($maximum, $allocated);
        if ($floor < 0 || $floor === PHP_INT_MAX) { throw new DatabaseFailure('schema'); }
        $floors[$table] = $floor;
    }
    return ['sites' => $floors['sites'], 'git_tokens' => $floors['git_tokens'], 'check_history' => $floors['check_history']];
}


function database_transfer_sequence_watermark(PDO $db): int
{
    $values = [database_transfer_sequence_scalar($db, sql: 'SELECT COALESCE(MAX(id),0) AS value FROM check_history'),
        database_transfer_sequence_scalar($db, sql: 'SELECT COALESCE(MAX(id),0) AS value FROM incidents'),
        database_transfer_sequence_scalar($db, sql: 'SELECT COALESCE(MAX(last_history_id),0) AS value FROM incident_checkpoints'),
        database_transfer_sequence_scalar($db, sql: 'SELECT COALESCE(MAX(last_history_id),0) AS value FROM notification_checkpoints'),
        database_transfer_sequence_scalar($db, sql: 'SELECT COALESCE(MAX(source_id),0) AS value FROM notification_slots'),
        database_transfer_sequence_scalar($db, sql: 'SELECT COALESCE(MAX(activation_id),0) AS value FROM notification_settings'),
        database_transfer_sequence_scalar($db, sql: 'SELECT COALESCE(MAX(recovery_history_id),0) AS value FROM incidents'),
        database_transfer_sequence_scalar($db, sql: 'SELECT COALESCE(MAX(interruption_history_id),0) AS value FROM incidents')];
    if (DatabaseSession::server($db)) {
        $committed = database_transfer_sequence_scalar($db, sql: 'SELECT committed_history_high_watermark AS value FROM tablo_database_state WHERE id = 1');
        if ($committed < max($values) || $committed === PHP_INT_MAX) { throw new DatabaseFailure('schema'); }
        return $committed;
    }
    $values[] = database_transfer_persistence_allocated($db, table: 'check_history');
    $maximum = max($values);
    if ($maximum < 0 || $maximum === PHP_INT_MAX) { throw new DatabaseFailure('schema'); }
    return $maximum;
}


/**
 * @param array{sites: int, git_tokens: int, check_history: int} $floors */
function database_transfer_sequence_apply(PDO $db, array $floors, int $watermark): void
{
    $floors['check_history'] = max($floors['check_history'], $watermark);
    foreach ($floors as $table => $floor) {
        if ($floor < 0 || $floor === PHP_INT_MAX) { throw new DatabaseFailure('schema'); }
        if (DatabaseSession::server($db)) { $db->exec('ALTER TABLE `' . $table . '` AUTO_INCREMENT = ' . ($floor + 1)); continue; }
        ServerSchema::rows($db, 'DELETE FROM sqlite_sequence WHERE name = ?', [$table]);
        ServerSchema::rows($db, 'INSERT INTO sqlite_sequence (name,seq) VALUES (?,?)', [$table, $floor]);
    }
}


function database_transfer_sequence_scalar(PDO $db, string $sql): int
{
    $rows = ServerSchema::rows($db, $sql);
    return DatabaseInteger::checked($rows[0]['value'] ?? null);
}


function database_transfer_persistence_allocated(PDO $db, string $table): int
{
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $rows = ServerSchema::rows($db, 'SELECT seq AS value FROM sqlite_sequence WHERE name = ?', [$table]);
        return $rows === [] ? 0 : DatabaseInteger::checked($rows[0]['value'] ?? null);
    }
    // MySQL caches INFORMATION_SCHEMA table statistics for24 hours by default.
    // An offline transfer must preserve allocations beyond MAX(id), including gaps.
    if (DatabaseSession::get($db)?->configuration->engine === 'mysql') { $db->exec('SET SESSION information_schema_stats_expiry = 0'); }
    $rows = ServerSchema::rows($db, 'SELECT AUTO_INCREMENT AS value FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
    $next = DatabaseInteger::checked($rows[0]['value'] ?? null);
    if ($next < 1) { throw new DatabaseFailure('schema'); }
    return $next - 1;
}

/** Temporary R74 observer. Declarations load naturally with the original first scalar call. */
function database_transfer_phase_mark(int $phase): void
{
    static $operation = 0, $lifecycle = 0, $factory = 0, $modules = 0;
    static $previous = 0, $flags = 1, $stopped = false;
    if ($stopped || ($lifecycle === 0 && $phase !== 115) || database_transfer_phase_initial_ignored($phase, ($lifecycle >> 4) & 0xF)) { return; }
    $handle = false;
    try {
        $used = memory_get_usage(false); $peak = memory_get_peak_usage(false);
        $handle = database_transfer_phase_handle($phase);
        if (!is_resource($handle) || !database_transfer_phase_memory($used, $peak)) { $stopped = true; database_transfer_phase_close($handle); return; }
        $kind = $lifecycle === 0 ? 1 : 2; $from = $previous;
        $terminal = database_transfer_phase_transition($phase, $operation, $lifecycle, $factory, $modules);
        if ($terminal === 903) { $flags |= 4; }
        if ($terminal !== 901 && $terminal !== 903) { $previous = $phase; }
        $frame = database_transfer_phase_frame($kind, $operation, $from, $phase);
        if (!database_transfer_phase_sample($handle, $frame, $used, $peak, $flags)) { $stopped = true; database_transfer_phase_close($handle); return; }
        if (database_transfer_phase_stop($handle, $operation, $previous, $terminal, $flags)) { $stopped = true; return; }
        database_transfer_phase_leave($phase, $operation, $lifecycle, $factory);
    } catch (\Throwable $ignored) {
        unset($ignored); $stopped = true; database_transfer_phase_close($handle);
    }
}

/** One fixed scalar call frame, never an event list or a public protocol field. */
function database_transfer_phase_frame(int $kind, int $operation, int $from, int $phase): int
{ return $kind | ($operation << 2) | (($phase & 0x3FF) << 5) | ($from << 15); }

/** @param resource $handle */
function database_transfer_phase_sample(mixed $handle, int $frame, int $used, int $peak, int &$flags): bool
{
    static $high = 0;
    $kind = $frame & 3; $operation = ($frame >> 2) & 7; $phase = ($frame >> 5) & 0x3FF; $from = $frame >> 15;
    if (database_transfer_phase_sampling($kind, $flags, $phase, $peak, $high)) {
        $fields = $operation . "\t" . $from . "\t" . $phase . "\t" . database_transfer_phase_values($used, $peak, $flags);
        if (!database_transfer_phase_emit($handle, $kind, $fields, $flags)) { return false; }
    }
    $high = max($high, $peak);
    $previous = $phase === 901 || ($flags & 4) !== 0 ? $from : $phase;
    return database_transfer_phase_observe($handle, $operation, $previous, $high, $flags);
}

/** @param resource $handle */
function database_transfer_phase_stop(mixed $handle, int $operation, int $previous, int $terminal, int $flags): bool
{
    if ($terminal === 0) { return false; }
    database_transfer_phase_finish($handle, $operation, $previous, $terminal, $flags);
    database_transfer_phase_close($handle);
    return true;
}

function database_transfer_phase_sampling(int $kind, int $flags, int $phase, int $peak, int $high): bool
{
    return $kind === 1 || (($flags & 4) === 0 && database_transfer_phase_known($phase) && $peak > $high);
}

function database_transfer_phase_values(int $used, int $peak, int $flags): string
{ return $used . "\t" . $peak . "\t" . $flags; }

/** @return resource|false */
function database_transfer_phase_handle(int $phase): mixed
{
    static $attempted = false, $handle = false;
    if ($attempted) { return $handle; }
    if ($phase !== 115) { return false; }
    $attempted = true;
    return $handle = database_transfer_phase_sink();
}

function database_transfer_phase_initial_ignored(int $phase, int $initial): bool
{
    return $initial === 2 && ($phase === 115 || $phase === 116);
}

/** Lifecycle packs initial/completed stages and completion/unwind bits in a fixed integer. */
function database_transfer_phase_transition(int $phase, int &$operation, int &$lifecycle, int &$factory, int &$modules): int
{
    if ($phase === 901) { return 901; }
    if (!database_transfer_phase_step($phase, $operation, $lifecycle, $factory, $modules)) { return 903; }
    return $phase === 15 && $operation === 6 ? 900 : 0;
}

function database_transfer_phase_step(int $phase, int &$operation, int &$lifecycle, int &$factory, int &$modules): bool
{
    if ($phase === 115 || $phase === 116) { return database_transfer_phase_initial($phase, $operation, $lifecycle); }
    if (($lifecycle & 0xF0) !== 32 || !database_transfer_phase_known($phase)) { return false; }
    $completed = $lifecycle & 0xF;
    if ($phase >= 10 && $phase <= 17) {
        if (($lifecycle & (32 << 8)) !== 0) { return false; }
        $next = database_transfer_phase_operation($phase, $operation, $completed, $factory, $modules);
        if ($next < 0) { return false; }
        $operation = $next;
        if (in_array($phase, [10, 12, 14, 16], strict: true)) { $factory = 0; }
        return true;
    }
    return database_transfer_phase_active($phase, $operation, $lifecycle, $factory, $modules);
}

function database_transfer_phase_initial(int $phase, int $operation, int &$lifecycle): bool
{
    $expected = $phase === 115 ? 0 : 16;
    if ($lifecycle !== $expected || $operation !== 0) { return false; }
    $lifecycle += 16; return true;
}

function database_transfer_phase_active(int $phase, int $operation, int &$lifecycle, int &$factory, int &$modules): bool
{
    $witness = $lifecycle >> 8;
    $valid = match (true) {
        $phase >= 120 && $phase <= 128 => $operation === 0 && database_transfer_phase_factory($phase, $lifecycle & 0xF, $factory, $modules, $witness),
        $operation === 0 && $factory <= 0 => false,
        $phase >= 130 => database_transfer_phase_scope($phase, $modules, $witness),
        default => $operation >= 1 && $operation <= 6 && ($phase < 100 || $phase > 114 || in_array($operation, [1, 4], strict: true)),
    };
    $lifecycle = ($lifecycle & 0xFF) | ($witness << 8);
    return $valid;
}

function database_transfer_phase_operation(int $phase, int $operation, int $completed, int $factory, int $modules): int
{
    if ($modules !== 0) { return -1; }
    if ($operation === 0) {
        return match ($phase) {
            10 => match (true) { $completed === 0 && $factory === 0 => 1, $completed === 3 && $factory === -1 => 4, default => -1 },
            12 => $completed === 1 && $factory === -1 ? 2 : -1,
            14 => match (true) { $completed === 2 && $factory === -1 => 3, $completed === 5 && $factory === 0 => 6, default => -1 },
            16 => $completed === 4 && $factory === 0 ? 5 : -1,
            default => -1,
        };
    }
    return match ($phase) {
        11 => in_array($operation, [1, 4], strict: true) ? $operation : -1,
        13 => $operation === 2 ? $operation : -1,
        15 => in_array($operation, [3, 6], strict: true) ? $operation : -1,
        17 => $operation === 5 ? $operation : -1,
        default => -1,
    };
}

function database_transfer_phase_leave(int $phase, int &$operation, int &$lifecycle, int &$factory): void
{
    if (!in_array($phase, [11, 13, 15, 17], strict: true)) { return; }
    $lifecycle = ($lifecycle & ~0xF) | $operation; $operation = 0; $factory = 0;
}

function database_transfer_phase_factory(int $phase, int $completed, int &$factory, int &$modules, int &$witness): bool
{
    if ($completed < 1 || $completed > 3) { return false; }
    if (($witness & 32) !== 0 && $phase !== 128) { return false; }
    if ($phase === 120) {
        if ($factory !== 0 || $modules !== 0) { return false; }
        $factory = 120; $witness = 0; return true;
    }
    if ($phase === 128) { return database_transfer_phase_factory_finally($completed, $factory, $modules, $witness); }
    if (!database_transfer_phase_factory_advance($phase, $completed, $factory, $modules, $witness)) { return false; }
    if ($phase === 122) { $witness &= ~1; }
    if ($phase === 123) { $witness &= ~4; }
    $factory = $phase; return true;
}

function database_transfer_phase_factory_finally(int $completed, int &$factory, int &$modules, int $witness): bool
{
    if ($factory < 120 || $factory > 127) { return false; }
    $expected = match ($completed) { 1 => 124, 2 => 126, 3 => 127, default => -1 };
    $factory = $factory === $expected && $modules === 0 && ($witness & 32) === 0 ? -1 : -2;
    $modules = 0;
    return true;
}

function database_transfer_phase_factory_advance(int $phase, int $completed, int $factory, int $modules, int $witness): bool
{
    if ($modules !== 0 || $factory !== $phase - 1 || ($phase >= 125 && $completed === 1)
        || ($phase === 127 && $completed !== 3)) { return false; }
    return match ($phase) { 122 => ($witness & 2) !== 0, 123 => ($witness & 1) !== 0, 125 => ($witness & 4) !== 0, default => true };
}

/** Nine fixed four-bit module stages; no event array or dynamically growing stack. */
function database_transfer_phase_scope(int $phase, int &$modules, int &$witness): bool
{
    if (($witness & 32) !== 0 && !in_array($phase, [156, 164], strict: true)) { return false; }
    if (!database_transfer_phase_scope_context($phase, $modules)) { return false; }
    if (($phase === 144 && ($witness & 16) === 0) || ($phase === 151 && ($witness & 1) === 0)
        || ($phase === 161 && ($witness & 8) === 0)) { return false; }
    $before = $modules;
    $valid = match (true) {
        $phase <= 145 => database_transfer_phase_scope_connection($phase, $modules),
        $phase <= 164 => database_transfer_phase_scope_admission($phase, $modules),
        default => database_transfer_phase_scope_checks($phase, $modules),
    };
    if (!$valid) { return false; }
    database_transfer_phase_witness($phase, $before, $witness);
    return true;
}

/** Fixed parent masks encode the nine actual module relationships, without an event history or stack. */
function database_transfer_phase_scope_context(int $phase, int $modules): bool
{
    if (in_array($phase, [156, 164], strict: true)) { return true; }
    return match (true) {
        $phase <= 132 => database_transfer_phase_parent_native($modules & ~0xF),
        $phase <= 143 => database_transfer_phase_parent_descriptor($modules & ~(0xF << 4)),
        $phase <= 145 => database_transfer_phase_parent_descriptor($modules & ~(0xF << 8)),
        $phase <= 156 => database_transfer_phase_parent_metadata($modules & ~(0xF << 12)),
        $phase <= 164 => ($modules & ~(0xF << 16)) === 0,
        $phase <= 172 => database_transfer_phase_parent_table_rows($modules & ~(0xF << 20)),
        $phase <= 182 => database_transfer_phase_parent_table_rows($modules & ~(0xF << 24)),
        $phase <= 185 => database_transfer_phase_parent_raw($modules & ~(0xF << 28)),
        default => database_transfer_phase_parent_metadata_checks($modules & ~(0xF << 32)),
    };
}

function database_transfer_phase_parent_native(int $parents): bool
{
    return $parents === 0 || ((($parents >> 12) & 0xF) === 1
        && database_transfer_phase_parent_metadata($parents & ~(0xF << 12)));
}

function database_transfer_phase_parent_descriptor(int $parents): bool
{
    return match (true) {
        $parents === 0 || $parents === (1 << 16) => true,
        (($parents >> 12) & 0xF) === 4 => database_transfer_phase_parent_metadata($parents & ~(0xF << 12)),
        (($parents >> 32) & 0xF) === 1 => database_transfer_phase_parent_metadata_checks($parents & ~(0xF << 32)),
        default => false,
    };
}

function database_transfer_phase_parent_metadata(int $parents): bool
{
    return $parents === 0 || $parents === (2 << 16) || ((($parents >> 24) & 0xF) === 1
        && database_transfer_phase_parent_table_rows($parents & ~(0xF << 24)));
}

function database_transfer_phase_parent_table_rows(int $parents): bool
{ return $parents === 0 || $parents === (3 << 16); }

function database_transfer_phase_parent_raw(int $parents): bool
{
    return match (true) {
        $parents === 0 => true,
        (($parents >> 32) & 0xF) === 1 => database_transfer_phase_parent_metadata_checks($parents & ~(0xF << 32)),
        (($parents >> 24) & 0xF) === 1 => database_transfer_phase_parent_table_rows($parents & ~(0xF << 24)),
        default => false,
    };
}

function database_transfer_phase_parent_metadata_checks(int $parents): bool
{
    return (($parents >> 12) & 0xF) === 6 && database_transfer_phase_parent_metadata($parents & ~(0xF << 12));
}

/** Five completion bits and one sticky partial-unwind bit; none are public protocol fields. */
function database_transfer_phase_witness(int $phase, int $before, int &$witness): void
{
    $clear = match ($phase) { 130 => 1, 140 => 16, 150 => 1 | 2 | 8 | 16, 160 => 4 | 8 | 16, default => 0 };
    $witness &= ~$clear;
    $complete = database_transfer_phase_scope_success($phase, $before);
    if (!$complete) { $witness |= 32; }
    $set = match ($phase) {
        132 => 1, 143 => 16, 145 => 8,
        156 => $complete ? 2 : 0, 164 => $complete ? 4 : 0,
        default => 0,
    };
    $witness |= $set;
}

function database_transfer_phase_scope_success(int $phase, int $before): bool
{
    $parents = (0xF << 12) | (0xF << 16) | (0xF << 24);
    return match ($phase) {
        156 => (($before >> 12) & 0xF) === 6 && ($before & ~$parents) === 0,
        164 => (($before >> 16) & 0xF) === 4 && ($before & ~(0xF << 16)) === 0,
        default => true,
    };
}

function database_transfer_phase_scope_connection(int $phase, int &$modules): bool
{
    return match ($phase) {
        130 => database_transfer_phase_move($modules, shift: 0, from: 0, to: 1),
        131 => database_transfer_phase_move($modules, shift: 0, from: 1, to: 2),
        132 => database_transfer_phase_move($modules, shift: 0, from: 2, to: 0),
        140 => database_transfer_phase_move($modules, shift: 4, from: 0, to: 1),
        141 => database_transfer_phase_move($modules, shift: 4, from: 1, to: 2),
        142 => database_transfer_phase_move($modules, shift: 4, from: 2, to: 3),
        143 => database_transfer_phase_move($modules, shift: 4, from: 3, to: 0),
        144 => database_transfer_phase_move($modules, shift: 8, from: 0, to: 1),
        145 => database_transfer_phase_move($modules, shift: 8, from: 1, to: 0),
        default => false,
    };
}

function database_transfer_phase_scope_admission(int $phase, int &$modules): bool
{
    $metadataParents = (0xF << 12) | (0xF << 16) | (0xF << 24);
    $tableParents = 0xF << 16;
    return match ($phase) {
        150 => database_transfer_phase_move($modules, shift: 12, from: 0, to: 1),
        151, 152, 153, 154, 155 => ($modules & ~$metadataParents) === 0
            && database_transfer_phase_move($modules, shift: 12, from: $phase - 150, to: $phase - 149),
        156 => database_transfer_phase_finally($modules, shift: 12),
        160 => database_transfer_phase_move($modules, shift: 16, from: 0, to: 1),
        161, 162, 163 => ($modules & ~$tableParents) === 0
            && database_transfer_phase_move($modules, shift: 16, from: $phase - 160, to: $phase - 159),
        164 => database_transfer_phase_finally($modules, shift: 16),
        default => false,
    };
}

function database_transfer_phase_scope_checks(int $phase, int &$modules): bool
{
    $checkParents = (0xF << 16) | (0xF << 24);
    $metadataCheckParents = (0xF << 12) | (0xF << 16) | (0xF << 24) | (0xF << 32);
    return match ($phase) {
        170 => database_transfer_phase_move($modules, shift: 20, from: 0, to: 1),
        171 => database_transfer_phase_move($modules, shift: 20, from: 1, to: 2),
        172 => database_transfer_phase_move($modules, shift: 20, from: 2, to: 0),
        180 => database_transfer_phase_move($modules, shift: 24, from: 0, to: 1),
        181 => ($modules & ~$checkParents) === 0 && database_transfer_phase_move($modules, shift: 24, from: 1, to: 2),
        182 => ($modules & ~$checkParents) === 0 && database_transfer_phase_move($modules, shift: 24, from: 2, to: 0),
        183 => database_transfer_phase_move($modules, shift: 28, from: 0, to: 1),
        184 => database_transfer_phase_move($modules, shift: 28, from: 1, to: 2),
        185 => database_transfer_phase_move($modules, shift: 28, from: 2, to: 0),
        186 => database_transfer_phase_move($modules, shift: 32, from: 0, to: 1),
        187 => ($modules & ~$metadataCheckParents) === 0 && database_transfer_phase_move($modules, shift: 32, from: 1, to: 0),
        default => false,
    };
}

function database_transfer_phase_move(int &$modules, int $shift, int $from, int $to): bool
{
    if ((($modules >> $shift) & 0xF) !== $from) { return false; }
    $modules = ($modules & ~(0xF << $shift)) | ($to << $shift);
    return true;
}

function database_transfer_phase_finally(int &$modules, int $shift): bool
{
    if ((($modules >> $shift) & 0xF) === 0) { return false; }
    // Metadata preserves its table/check callers; table layout is a root scope.
    $modules = $shift === 12 ? $modules & ((0xF << 16) | (0xF << 24)) : 0;
    return true;
}

function database_transfer_phase_known(int $phase): bool
{
    return database_transfer_phase_legacy($phase) || ($phase >= 115 && $phase <= 116) || ($phase >= 120 && $phase <= 128)
        || database_transfer_phase_module($phase);
}

function database_transfer_phase_legacy(int $phase): bool
{
    return ($phase >= 10 && $phase <= 17) || ($phase >= 20 && $phase <= 33) || ($phase >= 40 && $phase <= 42)
        || ($phase >= 50 && $phase <= 53) || ($phase >= 60 && $phase <= 71) || ($phase >= 80 && $phase <= 91)
        || ($phase >= 100 && $phase <= 114);
}

function database_transfer_phase_module(int $phase): bool
{
    return ($phase >= 130 && $phase <= 132) || ($phase >= 140 && $phase <= 145) || ($phase >= 150 && $phase <= 156)
        || ($phase >= 160 && $phase <= 164) || ($phase >= 170 && $phase <= 172) || ($phase >= 180 && $phase <= 187);
}

/** @param resource $handle */
function database_transfer_phase_emit(mixed $handle, int $kind, string $fields, int &$flags): bool
{
    static $records = 0, $bytes = 0;
    $length = strlen($kind . "\t" . ($records + 1) . "\t" . $fields . "\n");
    if ($kind !== 4 && ($records >= 63 || $bytes + $length > 8064)) { $flags |= 2; return true; }
    if ($records >= 64 || $length > 128 || $bytes + $length > 8192) { return false; }
    $written = database_transfer_phase_record($handle, $kind, $records + 1, $fields);
    if ($written !== $length) { return false; }
    $records++; $bytes += $written;
    return true;
}

/** @param resource $handle */
function database_transfer_phase_observe(mixed $handle, int $operation, int $previous, int &$high, int &$flags): bool
{
    $used = memory_get_usage(false); $peak = memory_get_peak_usage(false);
    if (!database_transfer_phase_memory($used, $peak)) { return false; }
    if ($peak > $high) {
        $flags |= 1;
        $fields = $operation . "\t" . $previous . "\t1\t" . $used . "\t" . $peak . "\t" . $flags;
        if (!database_transfer_phase_emit($handle, kind: 3, fields: $fields, flags: $flags)) { return false; }
    }
    $high = memory_get_peak_usage(false);
    return $high <= 1_099_511_627_776;
}

/** @param resource $handle */
function database_transfer_phase_finish(mixed $handle, int $operation, int $previous, int $terminal, int $flags): void
{
    $flags |= 1;
    $terminal = database_transfer_phase_terminal($terminal, $flags);
    $used = memory_get_usage(false); $peak = memory_get_peak_usage(false);
    if (!database_transfer_phase_memory($used, $peak)) { return; }
    $fields = $operation . "\t" . $previous . "\t" . $terminal . "\t" . $used . "\t" . $peak . "\t" . $flags;
    database_transfer_phase_emit($handle, kind: 4, fields: $fields, flags: $flags);
}

function database_transfer_phase_terminal(int $terminal, int $flags): int
{
    if (($flags & 4) !== 0) { return 903; }
    if (($flags & 2) !== 0) { return 902; }
    return $terminal;
}

function database_transfer_phase_memory(int $used, int $peak): bool
{
    return $used >= 0 && $used <= $peak && $peak <= 1_099_511_627_776;
}

function database_transfer_phase_integer_bound(int $maximum): bool
{ return $maximum >= 1_099_511_627_776; }

function database_transfer_phase_cohort(string $sapi, string $platform, int $version, string $engine): bool
{
    return $sapi === 'cli' && $platform === 'Linux' && database_transfer_phase_integer_bound(PHP_INT_MAX)
        && (($version >= 80_200 && $version < 80_300) || ($version >= 80_500 && $version < 80_600))
        && in_array($engine, ['mysql', 'mariadb'], strict: true);
}

function database_transfer_phase_gate(): bool
{
    $engine = getenv('TABLO_TEST_DB_DRIVER');
    return is_string($engine) && database_transfer_phase_cohort(PHP_SAPI, PHP_OS_FAMILY, PHP_VERSION_ID, $engine)
        && getenv('TABLO_TEST_ENGINE_CASE') === 'transfer-working-data'
        && ($_SERVER['argc'] ?? null) === 3 && is_array($_SERVER['argv'] ?? null)
        && ($_SERVER['argv'][1] ?? null) === 'transfer-working-data' && ($_SERVER['argv'][2] ?? null) === 'transfer-working-data';
}

function database_transfer_phase_directory(): ?string
{
    $source = database_transfer_phase_source();
    $fixture = getenv('TABLO_TEST_RUNTIME_ROOT'); $sink = getenv('TABLO_SESSION_EVIDENCE_DIRECTORY');
    if ($source === null || !is_string($fixture) || !is_string($sink)) { return null; }
    if (!database_transfer_phase_case($source, $fixture, $sink) || !database_transfer_phase_ancestors($source, $fixture, $sink)
        || !database_transfer_phase_plan($source)) { return null; }
    return $sink;
}

function database_transfer_phase_source(): ?string
{
    $nonce = getenv('TABLO_TEST_RUN_NONCE'); $source = getenv('TABLO_TEST_SOURCE_ROOT');
    if (!is_string($nonce) || preg_match('/\A[0-9a-f]{32}\z/D', $nonce) !== 1 || !is_string($source)) { return null; }
    if ($source !== str_replace('\\', replace: '/', subject: dirname(__DIR__)) || basename($source) !== 'source'
        || basename(dirname($source)) !== 'tablo-server-' . $nonce) { return null; }
    $script = $source . '/tests/server-child.php';
    if ($_SERVER['SCRIPT_FILENAME'] !== $script || is_link($script) || realpath($script) !== $script || !is_file($script)) { return null; }
    return $source;
}

function database_transfer_phase_case(string $source, string $fixture, string $sink): bool
{
    $nonce = getenv('TABLO_TEST_RUN_NONCE'); $engine = getenv('TABLO_TEST_DB_DRIVER');
    if (!is_string($nonce) || preg_match('/\A[0-9a-f]{32}\z/D', $nonce) !== 1 || !in_array($engine, ['mysql', 'mariadb'], strict: true)) { return false; }
    $base = dirname($fixture, levels: 2);
    return dirname($base) === dirname($source) . '/cases' && preg_match('/\Amain-(?:0|[1-9][0-9]{0,3})\z/D', basename($base)) === 1
        && $fixture === $base . '/' . $engine . '/tablo-server-' . $nonce && $sink === $base . '/closure';
}

function database_transfer_phase_ancestors(string $source, string $fixture, string $sink): bool
{
    $runtime = dirname($source); $base = dirname($fixture, levels: 2); $engine = getenv('TABLO_TEST_DB_DRIVER');
    if (!is_string($engine)) { return false; }
    foreach ([$runtime, $source, $source . '/tests', $runtime . '/evidence', $runtime . '/cases', $base, $base . '/' . $engine, $fixture, $sink] as $directory) {
        if (!database_transfer_phase_regular_directory($directory)) { return false; }
    }
    $mode = fileperms($sink);
    return is_int($mode) && ($mode & 0o777) === 0o700;
}

function database_transfer_phase_plan(string $source): bool
{
    $plan = getenv('TABLO_TEST_CELL_PLAN'); $expected = getenv('TABLO_TEST_CELL_PLAN_SHA256');
    if ($plan !== dirname($source) . '/evidence/started-cell.json' || !is_string($expected)
        || preg_match('/\A[0-9a-f]{64}\z/D', $expected) !== 1) { return false; }
    $before = lstat($plan);
    if (!is_array($before) || ($before['mode'] & 0o170_000) !== 0o100_000 || is_link($plan) || realpath($plan) !== $plan) { return false; }
    $digest = hash_file('sha256', $plan); clearstatcache(true, $plan); $after = lstat($plan);
    return is_string($digest) && hash_equals($expected, $digest) && is_array($after)
        && $before['dev'] === $after['dev'] && $before['ino'] === $after['ino'] && $before['size'] === $after['size'];
}

function database_transfer_phase_regular_directory(string $directory): bool
{
    return !is_link($directory) && is_dir($directory) && str_replace('\\', replace: '/', subject: (string) realpath($directory)) === $directory;
}

/** @return resource|false */
function database_transfer_phase_sink(): mixed
{
    try {
        if (!database_transfer_phase_gate()) { return false; }
        return database_transfer_phase_ignore(static fn () => database_transfer_phase_sink_guarded());
    } catch (\Throwable $ignored) { unset($ignored); return false; }
}

/** @return resource|false */
function database_transfer_phase_sink_guarded(): mixed
{
    $directory = database_transfer_phase_directory();
    if ($directory === null) { return false; }
    $path = $directory . '/transfer-phase-r74-v1.tsv';
    if (is_link($path) || file_exists($path)) { return false; }
    $handle = fopen($path, mode: 'xb');
    if (!is_resource($handle)) { return false; }
    $admitted = false;
    try {
        if (!database_transfer_phase_file($handle, $path) || !chmod($path, permissions: 0o600)) { return false; }
        stream_set_write_buffer($handle, size: 0);
        $stat = fstat($handle);
        if (!is_array($stat) || ($stat['mode'] & 0o777) !== 0o600 || !database_transfer_phase_file($handle, $path)) { return false; }
        $admitted = true; return $handle;
    } finally { if (!$admitted) { database_transfer_phase_close($handle); } }
}

/** @param resource $handle */
function database_transfer_phase_file(mixed $handle, string $path): bool
{
    $opened = fstat($handle); clearstatcache(true, $path); $named = lstat($path);
    return is_array($opened) && is_array($named) && ($opened['mode'] & 0o170_000) === 0o100_000
        && ($named['mode'] & 0o170_000) === 0o100_000 && $opened['size'] === 0 && $opened['nlink'] === 1 && $named['nlink'] === 1
        && $opened['dev'] === $named['dev'] && $opened['ino'] === $named['ino'];
}

/** @param resource $handle */
function database_transfer_phase_record(mixed $handle, int $kind, int $sequence, string $fields): int
{
    try {
        $line = $kind . "\t" . $sequence . "\t" . $fields . "\n"; $bytes = strlen($line);
        if ($sequence < 1 || $sequence > 64 || $bytes > 128
            || database_transfer_phase_ignore(static fn (): int|false => fwrite($handle, $line)) !== $bytes
            || !database_transfer_phase_ignore(static fn (): bool => fflush($handle))) { return 0; }
        return $bytes;
    } catch (\Throwable $ignored) { unset($ignored); return 0; }
}

function database_transfer_phase_close(mixed $handle): void
{
    if (!is_resource($handle)) { return; }
    try { database_transfer_phase_ignore(static fn (): bool => fflush($handle)); } catch (\Throwable $ignored) { unset($ignored); }
    try { database_transfer_phase_ignore(static fn (): bool => fclose($handle)); } catch (\Throwable $ignored) { unset($ignored); }
}

/** Observer-only warning boundary; no additional APP helper is autoloaded.
 * @template T
 * @param \Closure(): T $operation
 * @return T */
function database_transfer_phase_ignore(\Closure $operation): mixed
{
    set_error_handler(static fn (): bool => true);
    try { return $operation(); }
    finally { restore_error_handler(); }
}
