<?php
declare(strict_types=1);

namespace Tablo;

final class ServerExpressionTokens
{
    /** Delimited native identifiers; lookup follows the complete ASCII identifier guard. */
    private const IDENTIFIERS = '|plan|step|checksum|predecessor|target|before_predicate|after_predicate|phase|cursor|failure_category|id|format|logical_version|layout_checksum|installation_id|key_verifier|committed_history_high_watermark|password_hash|created_at|name|provider|encrypted_token|updated_at|name_key|url|repository|github_token|git_token_id|branch|health_path|version_path|version_json_path|health_check_mode|health_json_path|health_json_operator|health_json_expected_value|comparison_mode|enabled|sort_order|online|deployed_version|deployed_commit|latest_release|latest_commit|open_issues|open_prs|response_time_ms|last_error|checked_at|health_error_code|health_http_status|config_revision|endpoint_token|key|failures|window_start|scope|resource|eligible_at|check_interval_minutes|session_idle_timeout_minutes|run_id|stop_requested|fairness_turn|site_id|version_status|version_error_code|version_http_status|release_error_code|release_http_status|commit_error_code|commit_http_status|opened_at|recovery_history_id|recovered_at|interruption_history_id|end_reason|uncertain|clock_invalid|unresolved_site_id|last_history_id|watermark|revision|activation_id|blocked|endpoint_cipher|bearer_cipher|unavailable|recovery|version_lag|include_name|include_url|state|event|event_id|source_id|channel_revision|payload|private_cipher|expires_at|due_at|status|attempts|claim|lease_until|last_code|http_status|acknowledged_at|coalesced|secret_hash|administrator_fingerprint|ascii|substring|cast|binary|unsigned|replace|locate|octet_length|mod|case|when|then|else|end|null|not|and|or|is|in|between|as|substr|length|char|charset|';
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
            if (str_contains(self::IDENTIFIERS, '|' . strtolower($lexeme) . '|')) { continue; }
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
