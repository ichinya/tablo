<?php
declare(strict_types=1);

require_once __DIR__ . '/receipt.php';
require_once __DIR__ . '/frozen.php';

/** Called only by the diagnostic copy's observed controller, after standard service preparation.
 * No Testo acceptance method, native suite, retry, cross engine or second measured actor is invoked. */
function cold_phase_cell(array $context, array &$safe): void
{
    $plan = $context['plan']; $root = \Tablo\SqlColumn::string($plan, 'source');
    $engine = \Tablo\SqlColumn::string($plan, 'selectedEngine');
    if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 5 || ($plan['cross'] ?? null) !== false
        || array_keys($plan['services'] ?? []) !== [$engine]) { throw new RuntimeException('Exactly one PHP8.5 engine required.'); }
    cold_phase_assert_frozen($root);
    require_once $root . '/tools/verify-server.php';
    $identity = 'Tablo\\Tests\\Server\\EngineTransferTest::completeNativeRoundTripOverEightMiBUsesAtMostTwoMiBOfPhpWorkingData';
    $map = server_verify_manifest($root);
    $spec = server_verify_fixture_declaration($map, $identity, 'main');
    if (($spec['deadlineSeconds'] ?? null) !== 8
        || ($spec['fixtures'] ?? null) !== [['alias' => 'transfer-working-data', 'engine' => 'selected', 'initial' => 'ready']]) {
        throw new ColdPhaseFailure('FIXTURE_DECLARATION');
    }
    // The original pool, TLS, role grants, migration, observer, deadlines and metadata controls remain.
    $catalogs = server_cell_catalogs($context);
    $prepared = server_cell_prepare($context, $catalogs, $spec, ['engine' => $engine, 'lane' => 'main', 'index' => 0]);
    $lease = server_cell_observer_begin(8, 'case/main/0');
    server_cell_with_environment($prepared['environment'], static function () use ($context, $prepared, $root, $engine, &$safe): array {
        cold_phase_prepare_and_run($context, $prepared, $root, $engine, $safe);
        return []; // The shared receipt is never replaced by a return value captured before finally.
    }, $plan['services']);
    if ($safe['actorClosed'] === true && $safe['actorExitObserved'] === true) {
        try {
            \Tablo\Tools\server_fixture_catalog_descendants_closed($prepared['closureDirectory']);
            server_cell_observer_finish($lease, closed: true);
        } catch (Throwable $error) { cold_phase_note_failure($safe, 'OBSERVER_CLOSURE', $error); }
    }
    // Deliberately never call ServerFixtureCatalog::complete(): it requires real native acceptance.
    // This catalog is consumed once and destroyed with the owned service; it is never reused.
}

function cold_phase_prepare_and_run(array $context, array $prepared, string $root, string $engine, array &$safe): void
{
    require_once $root . '/tests/Server/EngineTransferTest.php';
    $fixture = new \Tablo\Tests\Support\EngineFixture('transfer-working-data');
    $source = null;
    try {
        // Exact original synthetic generator, outside the fresh actor's cold measurement.
        $caseRoot = \Tablo\Tests\Support\EngineSchedule::directory() . '/cases/transfer-working-data';
        $source = \Tablo\Tests\Server\EngineTransferTest::source($caseRoot . '/source.sqlite', $fixture->vault);
        \Tablo\Tests\Server\engine_transfer_working_fixture($source);
        if (\Tablo\DatabaseTransferSequence::scalar($source, 'SELECT COUNT(*) AS value FROM sites') !== 128
            || \Tablo\DatabaseTransferSequence::scalar($source, 'SELECT SUM(length(CAST(last_error AS BLOB))) AS value FROM sites') !== 11_520_000) {
            throw new ColdPhaseFailure('FIXTURE_VOLUME');
        }
        $source = null;
        $directory = new \Tablo\Tests\Support\TemporaryDirectory('tablo-cold-phase-', $context['plan']['runtime'] . '/tmp');
        $start = hrtime(true);
        try {
            // Exactly one call site, once; same original six-second child bound. No retry branch.
            $child = \Tablo\Tests\Support\Subprocess::run([PHP_BINARY, $root . '/diagnostics/cold-phase/actor.php', 'transfer-working-data', 'transfer-working-data'],
                $directory, $prepared['environment'], new \Tablo\Tests\Support\CommandOptions(timeout: 6, replacementEnvironment: $prepared['environment']));
            $receipt = \Tablo\Tests\Support\SubprocessReceipt::read($child['files']['receipt']);
            $safe = cold_phase_project($engine, $child['stdout'], $child['stderr'], [
                'actorLaunchCount' => is_int($receipt['pid']) && $receipt['pid'] > 0 ? 1 : 0,
                'actorExit' => $receipt['exit_code'], 'actorClosed' => $receipt['closed'], 'actorExitObserved' => $receipt['root_exit_observed'],
                'actorElapsedMilliseconds' => (int) ((hrtime(true) - $start) / 1_000_000)]);
        } catch (Throwable $error) {
            cold_phase_note_failure($safe, 'ACTOR', $error);
            // A timeout/failure has no fabricated phase counters or exit/closure claim.
            // The standard Subprocess and observer retain private direct-child closure evidence.
            $safe['actorLaunchCount'] = null;
            $safe['actorElapsedMilliseconds'] = (int) ((hrtime(true) - $start) / 1_000_000);
        } finally {
            try { $directory->close(); }
            catch (Throwable $error) { cold_phase_note_failure($safe, 'FIXTURE_CLEANUP', $error); }
        }
    } finally {
        $source = null;
        try { $fixture->close(); }
        catch (Throwable $error) { cold_phase_note_failure($safe, 'FIXTURE_CLEANUP', $error); }
    }
}

/** No success-shaped cell-result.json or native acceptance summary is ever written. */
function cold_phase_verify_and_close(array $context): never
{
    $safe = cold_phase_empty_receipt(\Tablo\SqlColumn::string($context['plan'], 'selectedEngine'));
    try { cold_phase_cell($context, $safe); }
    catch (Throwable $error) { cold_phase_note_failure($safe, 'CELL_SETUP', $error); }
    try {
        $custody = server_cell_post_custody($context['plan']);
        $safe['sourceCustodyUnchanged'] = ($custody['sourceUnchanged'] ?? null) === true;
    } catch (Throwable $error) { cold_phase_note_failure($safe, 'SOURCE_CUSTODY', $error); }
    try { server_cell_cleanup($context); $safe['ownedServicesClosed'] = true; }
    catch (Throwable $error) { cold_phase_note_failure($safe, 'OWNED_CLEANUP', $error); }
    try {
        server_cell_observer_audit($context['plan']['runtime'] . '/evidence/closure');
        $safe['observerClosureKnown'] = true;
    } catch (Throwable $error) { cold_phase_note_failure($safe, 'OBSERVER_CLOSURE', $error); }
    server_cell_private_write(dirname($context['plan']['runtime']) . '/safe-cold-phase.json', json_encode($safe, JSON_THROW_ON_ERROR) . "\n");
    // Existing supervisor rejects an engine phase without native acceptance. This is intentional.
    throw new RuntimeException('Diagnostic measurement complete or unavailable; original actor acceptance NOTRUN.');
}
