<?php
declare(strict_types=1);

namespace Tablo;

final class ServerExpressionTokens
{
    /** @var array<string, true> */
    private const IDENTIFIERS = ['plan' => true, 'step' => true, 'checksum' => true, 'predecessor' => true, 'target' => true, 'before_predicate' => true, 'after_predicate' => true, 'phase' => true, 'cursor' => true, 'failure_category' => true, 'id' => true, 'format' => true, 'logical_version' => true, 'layout_checksum' => true, 'installation_id' => true, 'key_verifier' => true, 'committed_history_high_watermark' => true, 'password_hash' => true, 'created_at' => true, 'name' => true, 'provider' => true, 'encrypted_token' => true, 'updated_at' => true, 'name_key' => true, 'url' => true, 'repository' => true, 'github_token' => true, 'git_token_id' => true, 'branch' => true, 'health_path' => true, 'version_path' => true, 'version_json_path' => true, 'health_check_mode' => true, 'health_json_path' => true, 'health_json_operator' => true, 'health_json_expected_value' => true, 'comparison_mode' => true, 'enabled' => true, 'sort_order' => true, 'online' => true, 'deployed_version' => true, 'deployed_commit' => true, 'latest_release' => true, 'latest_commit' => true, 'open_issues' => true, 'open_prs' => true, 'response_time_ms' => true, 'last_error' => true, 'checked_at' => true, 'health_error_code' => true, 'health_http_status' => true, 'config_revision' => true, 'endpoint_token' => true, 'key' => true, 'failures' => true, 'window_start' => true, 'scope' => true, 'resource' => true, 'eligible_at' => true, 'check_interval_minutes' => true, 'session_idle_timeout_minutes' => true, 'run_id' => true, 'stop_requested' => true, 'fairness_turn' => true, 'site_id' => true, 'version_status' => true, 'version_error_code' => true, 'version_http_status' => true, 'release_error_code' => true, 'release_http_status' => true, 'commit_error_code' => true, 'commit_http_status' => true, 'opened_at' => true, 'recovery_history_id' => true, 'recovered_at' => true, 'interruption_history_id' => true, 'end_reason' => true, 'uncertain' => true, 'clock_invalid' => true, 'unresolved_site_id' => true, 'last_history_id' => true, 'watermark' => true, 'revision' => true, 'activation_id' => true, 'blocked' => true, 'endpoint_cipher' => true, 'bearer_cipher' => true, 'unavailable' => true, 'recovery' => true, 'version_lag' => true, 'include_name' => true, 'include_url' => true, 'state' => true, 'event' => true, 'event_id' => true, 'source_id' => true, 'channel_revision' => true, 'payload' => true, 'private_cipher' => true, 'expires_at' => true, 'due_at' => true, 'status' => true, 'attempts' => true, 'claim' => true, 'lease_until' => true, 'last_code' => true, 'http_status' => true, 'acknowledged_at' => true, 'coalesced' => true, 'secret_hash' => true, 'administrator_fingerprint' => true, 'ascii' => true, 'substring' => true, 'cast' => true, 'binary' => true, 'unsigned' => true, 'replace' => true, 'locate' => true, 'octet_length' => true, 'mod' => true, 'case' => true, 'when' => true, 'then' => true, 'else' => true, 'end' => true, 'null' => true, 'not' => true, 'and' => true, 'or' => true, 'is' => true, 'in' => true, 'between' => true, 'as' => true, 'substr' => true, 'length' => true, 'char' => true, 'charset' => true];
    /** @return list<string> */
    public static function tokenize(string $sql): array
    {
        return self::scan($sql, '/\G(?:`[a-zA-Z0-9_]+`|(?:(?i:_binary)\s*)?\x27(?:\x27\x27|[^\x27])*\x27|(?:(?i:_binary)\s+)?[xX]\x27(?:[0-9a-fA-F]{2})*\x27|(?i:_binary)\s+0x[0-9a-fA-F]+|[a-zA-Z_][a-zA-Z0-9_]*|0x[0-9a-fA-F]+|[0-9]+|<=>|<>|!=|<=|>=|[(),=<>+*%\/-])/A');
    }
    /** MySQL CHECK metadata only; introduced literals retain a distinct AST tag.
     * @return list<string> */
    public static function mysqlCheckTokens(string $sql): array
    {
        return self::scan($sql, '/\G(?:`[a-zA-Z0-9_]+`|(?i:_utf8mb4)\s*\x27(?:\x27\x27|[^\x27])*\x27|(?:(?i:_binary)\s*)?\x27(?:\x27\x27|[^\x27])*\x27|(?:(?i:_binary)\s+)?[xX]\x27(?:[0-9a-fA-F]{2})*\x27|(?i:_binary)\s+0x[0-9a-fA-F]+|[a-zA-Z_][a-zA-Z0-9_]*|0x[0-9a-fA-F]+|[0-9]+|<=>|<>|!=|<=|>=|[(),=<>+*%\/-])/A');
    }
    /** @return list<string> */
    private static function scan(string $sql, string $pattern): array
    {
        if (strlen($sql) > 16_384) { throw new DatabaseFailure('schema'); }
        $tokens = [];
        $offset = 0;
        while ($offset < strlen($sql)) {
            $match = [];
            if (preg_match('/\G\s+/A', $sql, $match, flags: 0, offset: $offset) === 1) { $offset += strlen(RegexCapture::text($match, 0)); continue; }
            if (preg_match($pattern, $sql, $match, flags: 0, offset: $offset) !== 1) { throw new DatabaseFailure('schema'); }
            $tokens[] = RegexCapture::text($match, 0); $offset += strlen(RegexCapture::text($match, 0));
        }
        if (count($tokens) > 2048) { throw new DatabaseFailure('schema'); }
        foreach ($tokens as $index => $lexeme) {
            if (preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/D', $lexeme) !== 1) { continue; }
            if (array_key_exists(strtolower($lexeme), self::IDENTIFIERS)) { continue; }
            // Distinct fixed origins classify unsupported printer forms without retaining their text.
            if (strtolower($lexeme) === '_utf8mb4' && str_starts_with($tokens[$index + 1] ?? '', "'")) { throw new DatabaseFailure('schema'); }
            if (str_starts_with($lexeme, '_')) { throw new DatabaseFailure('schema'); }
            throw new DatabaseFailure('schema');
        }
        return $tokens;
    }
    /** @param list<string> $tokens */
    public static function consume(array $tokens, int &$position, string $expected): void
    {
        if (strtolower($tokens[$position++] ?? '') !== $expected) { throw new DatabaseFailure('schema'); }
    }
    public static function admitCall(string $name): void
    {
        if (!in_array($name, ['ascii', 'substring', 'cast', 'replace', 'locate', 'octet_length', 'mod'], strict: true)) { throw new DatabaseFailure('schema'); }
    }


}
