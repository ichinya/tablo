<?php
declare(strict_types=1);
require __DIR__ . '/frozen.php';

// No actor, autoload, service, Docker, environment discovery or installation data is touched.
$root = dirname(__DIR__, levels: 2); $temporary = null;
try {
    require_once $root . '/tools/ServerFixturePlan.php';
    cold_phase_assert_frozen($root);
    $record = json_decode(file_get_contents(__DIR__ . '/frozen-source.json'), true, 8, JSON_THROW_ON_ERROR);
    $names = $record['basePaths'];
    foreach (new DirectoryIterator(__DIR__) as $file) {
        if ($file->isFile() && !$file->isLink()) { $names[] = 'diagnostics/cold-phase/' . $file->getFilename(); }
    }
    $selected = cold_phase_selected_names($names);
    if (in_array('storage/.gitkeep', $selected, true)) { throw new ColdPhaseFailure('FROZEN_PATH_SELECTION'); }
    $temporary = sys_get_temp_dir() . '/tablo-materialized-cold-' . bin2hex(random_bytes(12));
    if (!mkdir($temporary, 0700)) { throw new ColdPhaseFailure('MATERIALIZED_SETUP'); }
    foreach ($selected as $name) {
        $target = $temporary . '/' . $name;
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0700, true)) { throw new ColdPhaseFailure('MATERIALIZED_SETUP'); }
        if (!copy($root . '/' . $name, $target)) { throw new ColdPhaseFailure('MATERIALIZED_SETUP'); }
    }
    cold_phase_assert_frozen($temporary);
    if (file_exists($temporary . '/storage')) { throw new ColdPhaseFailure('FROZEN_PATH_SELECTION'); }
    $probe = array_key_first($record['files']);
    unlink($temporary . '/' . $probe);
    $negative = false;
    try { cold_phase_assert_frozen($temporary); }
    catch (ColdPhaseFailure $error) { $negative = $error->category === 'FROZEN_FILE_MISMATCH'; }
    if (!$negative) { throw new ColdPhaseFailure('MATERIALIZED_NEGATIVE_CONTROL'); }
    copy($root . '/' . $probe, $temporary . '/' . $probe);
    cold_phase_assert_frozen($temporary);
    $ownerMutationsRefused = 0;
    foreach (array_keys($record['instrumentedOriginals']) as $name) {
        $target = $temporary . '/' . $name;
        file_put_contents($target, file_get_contents($target) . "\n// Unmarked synthetic source drift.\n");
        $refused = false;
        try { cold_phase_assert_frozen($temporary); }
        catch (ColdPhaseFailure $error) { $refused = $error->category === 'ORIGINAL_ACTOR_DRIFT'; }
        if (!$refused) { throw new ColdPhaseFailure('MATERIALIZED_NEGATIVE_CONTROL'); }
        $ownerMutationsRefused++;
        copy($root . '/' . $name, $target);
    }
    cold_phase_assert_frozen($temporary);
    echo json_encode(['schema' => 'tablo/cold-phase-materialized-control/v1', 'status' => 'passed',
        'baseHead' => $record['baseHead'], 'frozenFiles' => count($record['files']), 'materializedFiles' => count($selected),
        'storageExcluded' => true, 'missingFileRefused' => true, 'ownerMutationsRefused' => $ownerMutationsRefused, 'actorLaunchCount' => 0, 'issueAccepted' => false], JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    $category = $error instanceof ColdPhaseFailure ? $error->category : 'MATERIALIZED_SETUP';
    fwrite(STDERR, json_encode(['status' => 'failed', 'category' => $category, 'actorLaunchCount' => 0], JSON_THROW_ON_ERROR) . "\n");
    exit(1);
} finally {
    if ($temporary !== null && is_dir($temporary)) {
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) { if ($entry->isDir() && !$entry->isLink()) { rmdir($entry->getPathname()); } else { unlink($entry->getPathname()); } }
        rmdir($temporary);
    }
}
