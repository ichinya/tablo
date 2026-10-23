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
        $lexeme = $lexemes[$position++] ?? ''; $lower = strtolower($lexeme);
        if ($lexeme === '(') {
            $left = $operator->parse($lexemes, $position); ServerExpressionTokens::consume($lexemes, $position, ')');
            return $left;
        }
        if (in_array($lower, ['not', '-'], strict: true)) { return [$lower, $operator->parse($lexemes, $position, minimum: 60)]; }
        if ($lower === 'case') { return self::conditional($lexemes, $position, $operator); }
        if (($lexemes[$position] ?? '') === '(') {
            // Both supported server printers use SUBSTR for the standard SUBSTRING call.
            return self::call($lower === 'substr' ? 'substring' : $lower, $lexemes, $position, $operator);
        }
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
            $arguments[] = strtolower($lexemes[$position++] ?? '');
            ServerExpressionTokens::consume($lexemes, $position, ')');
            return ['call', $name, $arguments];
        }
        do {
            $arguments[] = $operator->parse($lexemes, $position);
            if (($lexemes[$position] ?? '') !== ',') { break; }
            $position++;
        } while (true);
        ServerExpressionTokens::consume($lexemes, $position, ')');
        return ['call', $name, $arguments];
    }

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
    if ($prefix === '' || $prefix === '_binary') { return ['literal', bin2hex($value)]; }
    $decoded = hex2bin($value);
    if ($decoded === false) { throw new DatabaseFailure('schema'); }
    return ['literal', bin2hex($decoded)];
}
