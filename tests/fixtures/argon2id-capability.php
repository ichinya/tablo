<?php
declare(strict_types=1);

// Operational preflight: global native functions only; never an absence simulation.
$options = ['memory_cost' => 65_536, 'time_cost' => 3, 'threads' => 1];
$result = ['classification' => 'actual global-native capability', 'php' => PHP_VERSION, 'binary' => PHP_BINARY,
    'binarySha256' => hash_file('sha256', PHP_BINARY), 'sapi' => PHP_SAPI, 'algorithms' => password_algos(),
    'extensions' => get_loaded_extensions(), 'ini' => php_ini_loaded_file(), 'scannedIni' => php_ini_scanned_files()];
try {
    if (!defined('PASSWORD_ARGON2ID') || !in_array('argon2id', password_algos(), strict: true)) {
        $result['status'] = 'unregistered';
        echo json_encode($result, JSON_THROW_ON_ERROR) . "\n";
        exit(2);
    }
    $input = 'tablo-argon2id-fixed-nonsecret-capability';
    $hash = password_hash($input, 'argon2id', $options);
    $info = password_get_info($hash);
    $result += ['algorithm' => $info['algoName'], 'options' => $info['options'], 'v19' => str_starts_with($hash, '$argon2id$v=19$'),
        'encodedPolicy' => str_starts_with($hash, '$argon2id$v=19$m=65536,t=3,p=1$'),
        'correct' => password_verify($input, $hash), 'wrong' => password_verify($input . '-wrong', $hash)];
    $result['status'] = [$result['algorithm'], $result['options'], $result['v19'], $result['encodedPolicy'], $result['correct'], $result['wrong']]
        === ['argon2id', $options, true, true, true, false] ? 'qualified' : 'failed';
    echo json_encode($result, JSON_THROW_ON_ERROR) . "\n";
    exit($result['status'] === 'qualified' ? 0 : 1);
} catch (Throwable) {
    $result['status'] = 'runtime-failure';
    echo json_encode($result, JSON_THROW_ON_ERROR) . "\n";
    exit(1);
}
