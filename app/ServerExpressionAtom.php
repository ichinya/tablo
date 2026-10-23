<?php
declare(strict_types=1);

namespace Tablo;

final class ServerExpressionAtom
{
    /**
     * @param list<string> $lexemes
     * @return array
     */
    public static function parse(array $lexemes, int &$position, ServerExpressionOperator $operator): array
    {
        $lexeme = $lexemes[$position++] ?? ''; $lower = server_expression_token_name(strtolower($lexeme), $lexemes[$position] ?? '');
        if ($lexeme === '(') {
            $left = $operator->parse($lexemes, $position); ServerExpressionTokens::consume($lexemes, $position, ')');
            return $left;
        }
        if (in_array($lower, ['not', '-'], strict: true)) { return [$lower, $operator->parse($lexemes, $position, minimum: 60)]; }
        if ($lower === 'case') { return self::conditional($lexemes, $position, $operator); }
        if (in_array($lower, ['char', 'charset'], strict: true)) { throw new DatabaseFailure('schema'); }
        if (($lexemes[$position] ?? '') === '(') {
            return self::call($lower, $lexemes, $position, $operator);
        }
        if (str_starts_with($lower, '0x')) { throw new DatabaseFailure('schema'); }
        if (str_contains($lexeme, "'") || str_starts_with($lower, '_binary')) { return server_expression_literal($lexeme); }
        return ['atom', str_replace('`', replace: '', subject: $lower)];
    }

    /** @param list<string> $lexemes */
    private static function conditional(array $lexemes, int &$position, ServerExpressionOperator $operator): array
    {
        ServerExpressionTokens::consume($lexemes, $position, 'when'); $condition = $operator->parse($lexemes, $position);
        ServerExpressionTokens::consume($lexemes, $position, 'then'); $yes = $operator->parse($lexemes, $position);
        ServerExpressionTokens::consume($lexemes, $position, 'else'); $no = $operator->parse($lexemes, $position);
        ServerExpressionTokens::consume($lexemes, $position, 'end');
        return ['case', $condition, $yes, $no];
    }

    /** @param list<string> $lexemes */
    private static function call(string $name, array $lexemes, int &$position, ServerExpressionOperator $operator): array
    {
        ServerExpressionTokens::admitCall($name);
        $position++; $arguments = [];
        if ($name === 'cast') {
            $arguments[] = $operator->parse($lexemes, $position); ServerExpressionTokens::consume($lexemes, $position, 'as');
            $target = strtolower($lexemes[$position++] ?? '');
            if ($target === 'char') {
                ServerExpressionTokens::consume($lexemes, $position, 'charset');
                ServerExpressionTokens::consume($lexemes, $position, 'binary');
                $target = 'binary';
            }
            if (!in_array($target, ['binary', 'unsigned'], strict: true)) { throw new DatabaseFailure('schema'); }
            $arguments[] = $target;
            ServerExpressionTokens::consume($lexemes, $position, ')');
            return ['call', $name, $arguments];
        }
        return ['call', $name, server_expression_arguments($name, $lexemes, $position, $operator)];
    }

}

/** Server printer aliases apply only in function position; LENGTH never becomes a new column or CAST target. */
function server_expression_token_name(string $name, string $next): string
{
    if ($next === '(') { return match ($name) { 'substr' => 'substring', 'length' => 'octet_length', default => $name }; }
    if (trim($name, characters: '`') === 'length') { throw new DatabaseFailure('schema'); }
    return $name;
}

/** @param list<string> $lexemes */
function server_expression_arguments(string $name, array $lexemes, int &$position, ServerExpressionOperator $operator): array
{
    $arguments = [];
    do {
        $arguments[] = server_expression_argument($name, count($arguments), $lexemes, $position, $operator);
        if (($lexemes[$position] ?? '') !== ',') { break; }
        $position++;
    } while (true);
    ServerExpressionTokens::consume($lexemes, $position, ')');
    if ($name === 'replace' && count($arguments) !== 3) { throw new DatabaseFailure('schema'); }
    if ($name === 'octet_length' && count($arguments) !== 1) { throw new DatabaseFailure('schema'); }
    if ($name === 'locate' && !in_array(count($arguments), [2, 3], strict: true)) { throw new DatabaseFailure('schema'); }
    return $arguments;
}

/** @param list<string> $lexemes */
function server_expression_argument(string $name, int $index, array $lexemes, int &$position, ServerExpressionOperator $operator): array
{
    $candidate = $lexemes[$position] ?? '';
    $stringArgument = $name === 'replace' || ($name === 'locate' && $index < 2);
    if ($stringArgument && str_starts_with($candidate, '0x') && in_array($lexemes[$position + 1] ?? '', [',', ')'], strict: true)) {
        // These complete arguments are consumed as strings; LOCATE's optional position is numeric.
        $position++;
        return server_expression_literal('_binary ' . $candidate);
    }
    return $operator->parse($lexemes, $position);
}

/** Decode only a string-literal token already admitted by the finite lexer. */
function server_expression_literal(string $lexeme): array
{
    $start = strpos($lexeme, needle: "'");
    if ($start === false) {
        // A bare 0x value has numeric-context semantics; only an explicit binary string is admitted.
        $match = [];
        if (preg_match('/\A(?i:_binary)\s+0x([0-9a-fA-F]+)\z/D', $lexeme, $match) !== 1) { throw new DatabaseFailure('schema'); }
        $hex = strtolower(RegexCapture::text($match, 1));
        return ['literal', strlen($hex) % 2 === 0 ? $hex : '0' . $hex];
    }
    $value = str_replace("''", replace: "'", subject: substr($lexeme, offset: $start + 1, length: -1));
    $prefix = strtolower(trim(substr($lexeme, offset: 0, length: $start)));
    if ($prefix === '_utf8mb4') {
        // No collation conversion or connection escape mode is inferred for metadata literals.
        if (preg_match('/\A[\x20-\x5b\x5d-\x7e]*\z/D', $value) !== 1) { throw new DatabaseFailure('schema'); }
        return ['mysql-utf8mb4', bin2hex($value)];
    }
    if ($prefix === '' || $prefix === '_binary') { return ['literal', bin2hex($value)]; }
    $decoded = hex2bin($value);
    if ($decoded === false) { throw new DatabaseFailure('schema'); }
    return ['literal', bin2hex($decoded)];
}
