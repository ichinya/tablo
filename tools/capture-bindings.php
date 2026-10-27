<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/RequiredFile.php';
require_once __DIR__ . '/CaptureModel.php';

// Explicit contract capture, never called by verification. Review the diff after model/code changes.
$root = dirname(__DIR__);
$definitions = \Tablo\Tools\CaptureModel::definitions($root);
$mapping = [
    'change_admin_password' => ['app/auth.php', 'public function changePassword'],
    'setup' => ['app/auth.php', 'public function setupSession'],
    'login' => ['app/auth.php', 'public function loginSession'],
    'logout' => ['app/authentication-pages.php', "post('/logout'"],
    'create_site' => ['app/site-pages.php', 'private function save'],
    'update_site' => ['app/site-pages.php', 'private function save'],
    'delete_site' => ['app/site-repository.php', 'public function delete'],
    'check_site' => ['app/site-checker.php', 'public function check'],
    'run_checks' => ['app/check-command.php', 'public function run'],
    'list_sites' => ['app/site-repository.php', 'public function all'],
    'read_dashboard_state' => ['app/dashboard-state-page.php', 'public function state'],
    'read_availability' => ['app/availability-repository.php', 'public function read'],
    'list_branches' => ['app/git-hub-connection.php', 'public function branches'],
    'user' => ['database/schema.sql', 'CREATE TABLE IF NOT EXISTS users'],
    'site' => ['app/schema-layout-upgrade.php', 'ALTER TABLE sites ADD COLUMN endpoint_token TEXT'],
    'git_token' => ['database/schema.sql', 'CREATE TABLE IF NOT EXISTS git_tokens'],
    'github_cooldown' => ['app/base-schema.php', 'public static function migrateToVersionThree'],
    'list_git_tokens' => ['app/git-token-repository.php', 'public function all'],
    'create_git_token' => ['app/git-token-repository.php', 'public function save'],
    'update_git_token' => ['app/git-token-repository.php', 'public function save'],
    'delete_git_token' => ['app/git-token-repository.php', 'public function delete'],
    'get_settings' => ['app/settings-repository.php', 'public function get'],
    'update_settings' => ['app/settings-repository.php', 'public function updateInterval'],
    'get_idle_timeout' => ['app/settings-repository.php', 'public function getIdleTimeout'],
    'update_idle_timeout' => ['app/settings-repository.php', 'public function updateIdleTimeout'],
    'run_worker_pass' => ['app/periodic-worker.php', 'public function runPass'],
    'request_worker_stop' => ['app/worker-state-repository.php', 'public function requestStop'],
    'installation_settings' => ['app/schema-layout-upgrade.php', 'ALTER TABLE installation_settings ADD COLUMN'],
    'worker_runtime' => ['app/base-schema.php', 'CREATE TABLE worker_runtime'],
    'worker_progress' => ['app/base-schema.php', 'CREATE TABLE worker_progress'],
    'check_history' => ['app/history-schema.php', 'CREATE TABLE check_history'],
    'store_check' => ['app/site-repository.php', 'public function storeCheck'],
    'settle_worker_check' => ['app/worker-state-repository.php', 'public function settle'],
    'read_history' => ['app/check-history-repository.php', 'public function page'],
    'prune_history' => ['app/check-history-repository.php', 'public function prune('],
    'incident' => ['app/incident-schema.php', 'CREATE TABLE incidents'],
    'incident_checkpoint' => ['app/incident-schema.php', 'CREATE TABLE incident_checkpoints'],
    'read_incidents' => ['app/incident-repository.php', 'public function page'],
    'notification_settings' => ['app/notification-schema.php', 'CREATE TABLE notification_settings'],
    'notification_checkpoint' => ['app/notification-schema.php', 'CREATE TABLE notification_checkpoints'],
    'notification_slot' => ['app/notification-schema.php', 'CREATE TABLE notification_slots'],
    'get_notifications' => ['app/notification-settings.php', 'public function get'],
    'update_notifications' => ['app/notification-settings.php', 'public function update'],
    'project_notifications' => ['app/notification-projector.php', 'public function accepted'],
    'read_notification_status' => ['app/notification-outbox.php', 'public function status'],
    'claim_notification' => ['app/notification-outbox.php', 'public function claim'],
    'ack_notification' => ['app/notification-outbox.php', 'public function acknowledge'],
    'deliver_notification' => ['app/notification-delivery.php', 'public function runOne'],
    'read_api_sites' => ['app/read-api-repository.php', 'function read_api_sites('],
    'read_api_site' => ['app/read-api-repository.php', 'function read_api_site('],
    'authenticate_read_api' => ['app/read-api-token-repository.php', 'function authenticate_read_api'],
    'read_api_token_metadata' => ['app/read-api-token-repository.php', 'function read_api_token_metadata'],
    'rotate_read_api_token' => ['app/read-api-token-repository.php', 'function rotate_read_api_token'],
    'revoke_read_api_token' => ['app/read-api-token-repository.php', 'function revoke_read_api_token'],
    'read_api_token' => ['app/read-api-token-schema.php', 'CREATE TABLE read_api_token'],
];
$symbols = [];
foreach ($mapping as $name => [$path, $needle]) {
    $definition = $definitions['dashboard.' . $name] ?? throw new RuntimeException('Missing dashboard definition: ' . $name);
    $source = \Tablo\RequiredFile::bytes(file_get_contents($root . '/' . $path));
    $offset = strpos($source, $needle);
    if ($offset === false) { throw new RuntimeException('Missing implementation: ' . $name); }
    $signature = null;
    $effects = [];
    if (in_array($definition['kind'], ['command', 'query'], strict: true)) {
        $signature = ['inputs' => [], 'output' => (($definition['returns'] ?? null) !== null) ? \Tablo\Tools\CaptureModel::typeName($definition['returns']) : null, 'reads' => $definition['reads'] ?? []];
        foreach ($definition['input'] ?? [] as $input) {
            $signature['inputs'][] = ['name' => $input['name'], 'type' => \Tablo\Tools\CaptureModel::typeName($input['type']), 'required' => !(($input['type']['optional'] ?? null) !== null)];
        }
        foreach ($definition['effects'] ?? [] as $id) {
            $effect = $definitions[$id] ?? throw new RuntimeException('Missing dashboard effect: ' . $id);
            $effects[] = ['kind' => $effect['operation'] ?? throw new RuntimeException('Missing effect operation: ' . $id),
                'subject' => $effect['entity'] ?? throw new RuntimeException('Missing effect entity: ' . $id)];
        }
    }
    $symbol = ['id' => $definition['id'], 'kind' => $definition['kind'],
        'source' => ['path' => $path, 'line' => substr_count(substr($source, offset: 0, length: $offset), needle: "\n") + 1],
        'fingerprint' => 'sha256:' . hash('sha256', $source), 'signature' => $signature, 'effects' => $effects];
    if ($definition['kind'] === 'entity') {
        $symbol['shape'] = ['fields' => array_map(\Tablo\Tools\CaptureModel::field(...),
            $definition['fields'] ?? throw new RuntimeException('Missing entity fields: ' . $name))];
    }
    $symbols[] = $symbol;
}
$declaration = ['schemaVersion' => 'lekalo/contracted-declaration/v0.4.0',
    'adapter' => ['id' => 'tablo-phalcon-bindings', 'version' => '0.1.0'], 'project' => 'tablo',
    'revision' => 'sha256:' . hash('sha256', json_encode($definitions, JSON_THROW_ON_ERROR)), 'symbols' => $symbols];
if (!is_dir($root . '/contracts')) { mkdir($root . '/contracts'); }
file_put_contents($root . '/contracts/php-bindings.json', json_encode($declaration, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
require __DIR__ . '/reviewed-dependencies.php';
$dependencies = [];
foreach (worker_dependencies() as $path => $metadata) {
    $dependencies[] = ['path' => $path, ...$metadata, 'fingerprint' => dependency_hash($root, $path)];
}
file_put_contents($root . '/contracts/reviewed-dependencies.json', json_encode([
    'schema' => 'tablo/reviewed-dependencies/v1', 'dependencies' => $dependencies,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo 'Captured ' . count($symbols) . " source bindings; review contracts/php-bindings.json before accepting.\n";
