<?php
declare(strict_types=1);

// Explicit contract capture, never called by verification. Review the diff after model/code changes.
$root = dirname(__DIR__);
$definitions = [];
foreach (glob($root . '/lekalo/modules/dashboard/*.yaml') as $file) {
    foreach (json_decode(file_get_contents($file), true, 64, JSON_THROW_ON_ERROR)['definitions'] as $definition) {
        $definitions[$definition['id']] = $definition;
    }
}
$mapping = [
    'setup' => ['app/auth-service.php', 'public function setup'],
    'login' => ['app/auth-service.php', 'public function login'],
    'logout' => ['app/web-application.php', "post('/logout'"],
    'create_site' => ['app/web-application.php', 'private function save'],
    'update_site' => ['app/web-application.php', 'private function save'],
    'delete_site' => ['app/site-repository.php', 'public function delete'],
    'check_site' => ['app/site-checker.php', 'public function check'],
    'list_sites' => ['app/site-repository.php', 'public function all'],
    'list_branches' => ['app/github-connection.php', 'public function branches'],
    'user' => ['database/schema.sql', 'CREATE TABLE IF NOT EXISTS users'],
    'site' => ['app/sqlite-database.php', 'private static function migrateToVersionFour'],
    'git_token' => ['database/schema.sql', 'CREATE TABLE IF NOT EXISTS git_tokens'],
    'github_cooldown' => ['app/sqlite-database.php', 'private static function migrateToVersionThree'],
    'list_git_tokens' => ['app/git-token-repository.php', 'public function all'],
    'create_git_token' => ['app/git-token-repository.php', 'public function save'],
    'update_git_token' => ['app/git-token-repository.php', 'public function save'],
    'delete_git_token' => ['app/git-token-repository.php', 'public function delete'],
    'get_settings' => ['app/settings-repository.php', 'public function get'],
    'update_settings' => ['app/settings-repository.php', 'public function updateInterval'],
    'run_worker_pass' => ['app/periodic-worker.php', 'public function runPass'],
    'request_worker_stop' => ['app/worker-state-repository.php', 'public function requestStop'],
    'installation_settings' => ['app/sqlite-database.php', 'CREATE TABLE installation_settings'],
    'worker_runtime' => ['app/sqlite-database.php', 'CREATE TABLE worker_runtime'],
    'worker_progress' => ['app/sqlite-database.php', 'CREATE TABLE worker_progress'],
];
function typeName(array $type): string
{
    if (isset($type['ref'])) { return $type['ref']; }
    foreach (['optional', 'list'] as $wrapper) {
        if (isset($type[$wrapper])) { return $wrapper . '(' . typeName($type[$wrapper]) . ')'; }
    }
    throw new RuntimeException('Unmapped type');
}
$symbols = [];
foreach ($mapping as $name => [$path, $needle]) {
    $definition = $definitions['dashboard.' . $name];
    $source = file_get_contents($root . '/' . $path);
    $offset = strpos($source, $needle);
    if ($offset === false) { throw new RuntimeException('Missing implementation: ' . $name); }
    $signature = null;
    $effects = [];
    if (in_array($definition['kind'], ['command', 'query'], true)) {
        $signature = ['inputs' => [], 'output' => isset($definition['returns']) ? typeName($definition['returns']) : null, 'reads' => $definition['reads'] ?? []];
        foreach ($definition['input'] ?? [] as $input) {
            $signature['inputs'][] = ['name' => $input['name'], 'type' => typeName($input['type']), 'required' => !isset($input['type']['optional'])];
        }
        foreach ($definition['effects'] ?? [] as $id) {
            $effect = $definitions[$id];
            $effects[] = ['kind' => $effect['operation'], 'subject' => $effect['entity']];
        }
    }
    $symbol = ['id' => $definition['id'], 'kind' => $definition['kind'],
        'source' => ['path' => $path, 'line' => substr_count(substr($source, 0, $offset), "\n") + 1],
        'fingerprint' => 'sha256:' . hash('sha256', $source), 'signature' => $signature, 'effects' => $effects];
    if ($definition['kind'] === 'entity') {
        $symbol['shape'] = ['fields' => array_map(fn (array $field) => ['name' => $field['name'],
            'type' => typeName($field['type']), 'required' => $field['required'] ?? false], $definition['fields'])];
    }
    $symbols[] = $symbol;
}
$declaration = ['schemaVersion' => 'lekalo/contracted-declaration/v0.4.0',
    'adapter' => ['id' => 'tablo-phalcon-bindings', 'version' => '0.1.0'], 'project' => 'tablo',
    'revision' => 'sha256:' . hash('sha256', json_encode($definitions, JSON_THROW_ON_ERROR)), 'symbols' => $symbols];
if (!is_dir($root . '/contracts')) { mkdir($root . '/contracts'); }
file_put_contents($root . '/contracts/php-bindings.json', json_encode($declaration, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo 'Captured ' . count($symbols) . " source bindings; review contracts/php-bindings.json before accepting.\n";
